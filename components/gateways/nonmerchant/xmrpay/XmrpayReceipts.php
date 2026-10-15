<?php
declare(strict_types=1);

class XmrpayReceipts
{
    public object $Record;
    public object $Transactions;
    public object $Clients;
    public object $Contacts;
    public object $Emails;
    public object $Companies;
    public object $Date;
    private PDO $connection;
    private int $companyId;

    public function __construct(private int $gatewayId)
    {
        Loader::loadComponents($this, ['Record']);
        Loader::loadModels($this, ['Transactions', 'Clients', 'Contacts', 'Emails', 'Companies']);
        Loader::loadHelpers($this, ['Date']);
        $this->connection = $this->Record->connect();
        $this->companyId = (int)Configure::get('Blesta.company_id');
    }

    public function sendPending(float $deadline): void
    {
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Receipt delivery requires committed payment accounting.');
        }
        if (microtime(true) >= $deadline) {
            return;
        }
        $orders = $this->Record->query("SELECT orders.id FROM gateway_xmrpay_orders orders
            INNER JOIN gateways ON gateways.id = orders.gateway_id
                AND gateways.company_id = orders.company_id AND gateways.class = 'xmrpay'
            WHERE orders.gateway_id = ? AND orders.company_id = ? AND orders.receipt_due_at <= ?
                AND orders.status = 'paid'
            ORDER BY orders.receipt_due_at, orders.id LIMIT 10", [
            $this->gatewayId, $this->companyId, time()
        ])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as $order) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $orderId = (int)$order['id'];
            $lock = hash('sha256', 'xmrpay-receipt:' . $this->companyId . ':' . $orderId);
            if ((int)$this->Record->query('SELECT GET_LOCK(?, 0)', [$lock])->fetchColumn() !== 1) {
                continue;
            }
            try {
                $this->sendReceipt($orderId, $deadline);
            } catch (Throwable $error) {
                $this->Record->reset();
                error_log('XMRPay receipt ' . $orderId . ' failed: ' . get_class($error));
            } finally {
                $this->Record->query('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
    }

    private function sendReceipt(int $orderId, float $deadline): void
    {
        $order = $this->Record->query("SELECT client_id, transaction_id, state FROM gateway_xmrpay_orders
            WHERE id = ? AND gateway_id = ? AND company_id = ? AND status = 'paid' AND receipt_due_at <= ?", [
            $orderId, $this->gatewayId, $this->companyId, time()
        ])->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            return;
        }
        $this->Record->query('UPDATE gateway_xmrpay_orders SET receipt_due_at = ? WHERE id = ?', [time() + 60, $orderId]);
        $state = json_decode($order['state'], true, 512, JSON_THROW_ON_ERROR);
        $receipt = $state['receipt'] ?? [];
        $sent = is_array($receipt) ? ($receipt['sent_to'] ?? []) : null;
        if (!is_array($state) || !is_array($sent)) {
            throw new RuntimeException('Invalid receipt delivery progress.');
        }
        foreach ($sent as $address) {
            if (!is_string($address) || $address === '') {
                throw new RuntimeException('Invalid receipt recipient progress.');
            }
        }
        $transaction = $this->Transactions->get((int)$order['transaction_id']);
        $client = $this->Clients->get((int)$order['client_id']);
        if (!$transaction || $transaction->status !== 'approved'
            || (int)$transaction->gateway_id !== $this->gatewayId
            || (int)$transaction->client_id !== (int)$order['client_id']
            || $transaction->transaction_id !== 'bx_native_' . $orderId
            || !$client || (int)$client->company_id !== $this->companyId) {
            throw new RuntimeException('Receipt payment accounting does not match.');
        }
        $language = $client->settings['language'];
        $template = $this->Emails->getByType($this->companyId, 'payment_nonmerchant_approved', $language);
        if (!$template) {
            throw new RuntimeException('The payment receipt template is missing.');
        }
        if ($template->status !== 'active') {
            $this->complete($orderId);
            return;
        }
        $contacts = $this->Contacts->getAll((int)$client->id, 'billing');
        if (!$contacts) {
            $contacts = $this->Contacts->getAll((int)$client->id, 'primary');
        }
        if (!$contacts) {
            throw new RuntimeException('The payment receipt has no billing recipient.');
        }
        $this->Date->setTimezone('UTC', Configure::get('Blesta.company_timezone'));
        $this->Date->setFormats(['date_time' => $this->Companies->getSetting($this->companyId, 'datetime_format')->value]);
        $complete = true;
        foreach ($contacts as $contact) {
            if (microtime(true) >= $deadline) {
                return;
            }
            if (in_array($contact->email, $sent, true)) {
                continue;
            }
            if ($this->connection->inTransaction() || (int)$contact->client_id !== (int)$client->id) {
                throw new RuntimeException('Receipt delivery context changed.');
            }
            $tags = [
                'contact' => $contact,
                'transaction' => $transaction,
                'date_added' => $this->Date->cast($transaction->date_added, 'date_time')
            ];
            try {
                $delivered = $this->Emails->send('payment_nonmerchant_approved', $this->companyId, $language,
                    $contact->email, $tags, null, null, null, ['to_client_id' => (int)$client->id]);
            } catch (Throwable $error) {
                $delivered = false;
            }
            if (!$delivered) {
                $complete = false;
                error_log('XMRPay receipt ' . $orderId . ' could not be delivered.');
                continue;
            }
            $sent[] = $contact->email;
            $state['receipt']['sent_to'] = $sent;
            $this->Record->query('UPDATE gateway_xmrpay_orders SET state = ? WHERE id = ?', [
                json_encode($state, JSON_THROW_ON_ERROR), $orderId
            ]);
        }
        if ($complete) {
            $this->complete($orderId);
        }
    }

    private function complete(int $orderId): void
    {
        $this->Record->query('UPDATE gateway_xmrpay_orders SET receipt_due_at = NULL WHERE id = ?', [$orderId]);
    }
}
