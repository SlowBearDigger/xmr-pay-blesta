<?php
declare(strict_types=1);

require_once __DIR__ . '/load.php';

use XmrPay\Adapter\Gateway;
use XmrPay\Adapter\InvoicePolicy;
use XmrPay\Adapter\OutputOrderStore;
use XmrPay\Util;

class XmrpayInvoiceBalanceChanged extends RuntimeException
{
}

class XmrpayOrderStore implements OutputOrderStore
{
    public object $Record;
    public object $Transactions;
    public object $Invoices;
    public object $Clients;
    public object $GatewayManager;
    private PDO $connection;
    private int $gatewayId;
    private int $companyId;
    private array $config;
    private string $walletId;

    public function __construct(int $gatewayId, array $config)
    {
        Loader::loadComponents($this, ['Record']);
        Loader::loadModels($this, ['Transactions', 'Invoices', 'Clients', 'GatewayManager']);
        $gateway = $this->GatewayManager->get($gatewayId);
        if (!$gateway || $gateway->class !== 'xmrpay'
            || (int)$gateway->company_id !== (int)Configure::get('Blesta.company_id')) {
            throw new InvalidArgumentException('Payment gateway does not belong to this company.');
        }
        $this->gatewayId = $gatewayId;
        $this->companyId = (int)$gateway->company_id;
        $this->config = $config;
        $this->walletId = self::walletId($config);
        $this->connection = $this->Record->connect();
        if ($this->Transactions->connect() !== $this->connection || $this->Invoices->connect() !== $this->connection) {
            throw new RuntimeException('Payment models must share one database connection.');
        }
    }

    public static function walletId(array $config): string
    {
        return hash('sha256', implode('|', [
            $config['network'], $config['address'], (string)($config['index_offset'] ?? 1000000)
        ]));
    }

    public static function installSchema(object $record): void
    {
        $record->query("CREATE TABLE IF NOT EXISTS gateway_xmrpay_orders (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            gateway_id INT UNSIGNED NOT NULL,
            company_id INT UNSIGNED NOT NULL,
            client_id INT UNSIGNED NOT NULL,
            wallet_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            active_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
            amount DECIMAL(19,4) NOT NULL,
            currency CHAR(3) CHARACTER SET ascii NOT NULL,
            xmr_amount VARCHAR(32) CHARACTER SET ascii NOT NULL,
            address VARCHAR(95) CHARACTER SET ascii NOT NULL,
            allocations TEXT NOT NULL,
            status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'pending',
            state LONGTEXT NOT NULL,
            transaction_id INT UNSIGNED DEFAULT NULL,
            created_at BIGINT UNSIGNED NOT NULL,
            last_scanned_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY active_quote (active_key),
            KEY pending_scan (gateway_id, status, last_scanned_at, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $record->query("CREATE TABLE IF NOT EXISTS gateway_xmrpay_outputs (
            network VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            txid CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            output_index INT UNSIGNED NOT NULL,
            out_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            order_id INT UNSIGNED NOT NULL,
            amount_atomic VARCHAR(32) CHARACTER SET ascii NOT NULL,
            PRIMARY KEY (network, txid, output_index),
            UNIQUE KEY output_key (network, out_key),
            KEY payment_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function checkout(int $clientId, string $amount, string $currency, array $allocations, string|Closure $xmrAmount, int $birthday): array
    {
        $this->validateAmount($amount, 4);
        if (!in_array($currency, ['USD', 'EUR', 'XMR'], true) || $birthday < 1 || count($allocations) > 100) {
            throw new InvalidArgumentException('Invalid payment request.');
        }
        $this->validateInvoices($clientId, $currency, $allocations, $amount);
        usort($allocations, static fn(array $left, array $right): int => (int)$left['id'] <=> (int)$right['id']);
        $activeKey = hash('sha256', json_encode([
            $this->gatewayId, $this->walletId, $clientId, bcadd($amount, '0', 4), $currency, $allocations
        ], JSON_THROW_ON_ERROR));
        $lock = 'xmrpay_quote_' . substr($activeKey, 0, 48);
        if ((int)$this->Record->query('SELECT GET_LOCK(?, 3)', [$lock])->fetchColumn() !== 1) {
            throw new RuntimeException('Payment checkout is already being created.');
        }
        try {
            $this->begin();
            $row = $this->Record->query('SELECT * FROM gateway_xmrpay_orders WHERE active_key = ? FOR UPDATE', [$activeKey])->fetch(PDO::FETCH_ASSOC);
            $this->validateInvoices($clientId, $currency, $allocations, $amount, lock: true, requireBalance: true);
            if ($row) {
                $order = $this->decodeOrder($row);
                if ($order['expires_at'] > time() || $order['funded_at'] !== null) {
                    $this->connection->commit();
                    return $order;
                }
                $this->Record->query('UPDATE gateway_xmrpay_orders SET active_key = NULL WHERE id = ?', [$order['id']]);
            }
            if ($xmrAmount instanceof Closure) {
                $xmrAmount = $xmrAmount();
            }
            $this->validateAmount($xmrAmount, 12);
            $now = time();
            $state = [
                'birthday_height' => $birthday,
                'scanned_to' => $birthday - 1,
                'matches' => [],
                'received_pico' => '0',
                'invoice_status' => 'created',
                'expires_at' => $now + (int)($this->config['invoice_minutes'] ?? 60) * 60,
                'funded_at' => null,
                'funded_amount_atomic' => null
            ];
            $this->Record->query('INSERT INTO gateway_xmrpay_orders
                (gateway_id, company_id, client_id, wallet_id, active_key, amount, currency, xmr_amount, address, allocations, state, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $this->gatewayId, $this->companyId, $clientId, $this->walletId, $activeKey, $amount, $currency,
                $xmrAmount, '', json_encode($allocations, JSON_THROW_ON_ERROR), json_encode($state, JSON_THROW_ON_ERROR), $now
            ]);
            $id = (int)$this->connection->lastInsertId();
            if ($id + (int)($this->config['index_offset'] ?? 1000000) > 2147483647) {
                throw new RuntimeException('Payment address index range is exhausted.');
            }
            $address = (new Gateway($this->config))->subaddressForOrder($id);
            if (strlen($address) !== 95) {
                throw new RuntimeException('Could not derive the receiving address.');
            }
            $this->Record->query('UPDATE gateway_xmrpay_orders SET address = ? WHERE id = ?', [$address, $id]);
            $order = $this->loadOne($id);
            $this->connection->commit();
            return $order;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        } finally {
            $this->Record->query('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    public function loadOne(int $orderId): array
    {
        $row = $this->Record->query('SELECT * FROM gateway_xmrpay_orders WHERE id = ? AND gateway_id = ? AND company_id = ?', [
            $orderId, $this->gatewayId, $this->companyId
        ])->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Payment request does not belong to this gateway.');
        }
        return $this->decodeOrder($row);
    }

    public function loadPending(): iterable
    {
        $rows = $this->Record->query("SELECT * FROM gateway_xmrpay_orders
            WHERE gateway_id = ? AND company_id = ? AND wallet_id = ? AND status = 'pending'
            ORDER BY last_scanned_at, id LIMIT 25", [$this->gatewayId, $this->companyId, $this->walletId])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            try {
                $order = $this->decodeOrder($row);
            } catch (Throwable $error) {
                $this->markScanAttempt((int)$row['id']);
                error_log('XMRPay Watch could not decode a pending payment.');
                continue;
            }
            yield $order;
        }
    }

    public function markScanAttempt(int $orderId): void
    {
        $this->Record->query('UPDATE gateway_xmrpay_orders SET last_scanned_at = ?
            WHERE id = ? AND gateway_id = ? AND company_id = ? AND status = ?', [
            time(), $orderId, $this->gatewayId, $this->companyId, 'pending'
        ]);
    }

    public function saveProgress(int $orderId, array $patch): void
    {
        $this->begin();
        try {
            $order = $this->lockOrder($orderId);
            if ($order['status'] !== 'paid') {
                $allowed = ['birthday_height', 'scanned_to', 'scan_checkpoint', 'partial_block', 'scan_complete',
                    'matches', 'received_pico', 'invoice_status', 'funded_at', 'funded_amount_atomic', 'closed_reason'];
                $patch = array_intersect_key($patch, array_flip($allowed));
                foreach ($order['allocations'] as $allocation) {
                    $invoice = $this->Invoices->get($allocation['id']);
                    if (!$invoice || $invoice->status === 'void') {
                        $patch['invoice_status'] = 'cancelled';
                        $patch['closed_reason'] = 'invoice-closed';
                        break;
                    }
                }
                $state = InvoicePolicy::mergeProgress($order['scan_state'], $patch);
                $this->Record->query('UPDATE gateway_xmrpay_orders SET state = ?, last_scanned_at = ? WHERE id = ?', [
                    json_encode($state, JSON_THROW_ON_ERROR), time(), $orderId
                ]);
            }
            $this->connection->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function isSettled(string $txid): bool
    {
        return (bool)$this->Record->query('SELECT 1 FROM gateway_xmrpay_outputs WHERE network = ? AND txid = ? LIMIT 1', [
            $this->config['network'], $txid
        ])->fetchColumn();
    }

    public function isOutputSettled(array $receipt): bool
    {
        return (bool)$this->Record->query('SELECT 1 FROM gateway_xmrpay_outputs
            WHERE network = ? AND ((txid = ? AND output_index = ?) OR out_key = ?) LIMIT 1', [
            $this->config['network'], $receipt['txid'], $receipt['output_index'], $receipt['out_key']
        ])->fetchColumn();
    }

    public function markPaid(int $orderId, string $txid, array $verdict): void
    {
        throw new RuntimeException('Native payments require verified individual outputs.');
    }

    public function markPaidFromOutputs(int $orderId, array $receipts, array $verdict): void
    {
        $this->begin();
        try {
            $order = $this->lockOrder($orderId);
            if ($order['status'] === 'paid') {
                $this->connection->commit();
                return;
            }
            if ($order['wallet_id'] !== $this->walletId) {
                throw new RuntimeException('Payment wallet configuration changed.');
            }
            InvoicePolicy::assertPayable($order, $verdict);
            $this->validateInvoices($order['client_id'], $order['currency'], $order['allocations'], $order['amount'], true);
            $received = '0';
            foreach ($receipts as $receipt) {
                if (!preg_match('/^[a-f0-9]{64}$/D', (string)($receipt['txid'] ?? ''))
                    || !preg_match('/^[a-f0-9]{64}$/D', (string)($receipt['out_key'] ?? ''))
                    || !is_int($receipt['output_index'] ?? null) || $receipt['output_index'] < 0
                    || !preg_match('/^[0-9]{1,32}$/D', (string)($receipt['amount_atomic'] ?? ''))
                    || bccomp($receipt['amount_atomic'], '0', 0) <= 0) {
                    throw new InvalidArgumentException('Invalid verified payment output.');
                }
                $received = bcadd($received, $receipt['amount_atomic'], 0);
                $this->Record->query('INSERT INTO gateway_xmrpay_outputs
                    (network, txid, output_index, out_key, order_id, amount_atomic) VALUES (?, ?, ?, ?, ?, ?)', [
                    $this->config['network'], $receipt['txid'], $receipt['output_index'], $receipt['out_key'], $orderId, $receipt['amount_atomic']
                ]);
            }
            if (!$receipts || bccomp($received, $verdict['confirmed_pico'], 0) !== 0) {
                throw new RuntimeException('Verified output total does not match payment evidence.');
            }
            $reference = 'bx_native_' . $orderId;
            if ($this->Transactions->getByTransactionId($reference, $order['client_id'], $this->gatewayId)) {
                throw new RuntimeException('Payment accounting requires manual reconciliation.');
            }
            $expected = Util::xmr_to_pico_exact($order['xmr_amount']);
            $creditedAmount = bcdiv(bcmul($order['amount'], $received, 4), $expected, 4);
            $transactionId = $this->Transactions->add([
                'client_id' => $order['client_id'], 'amount' => $creditedAmount, 'currency' => $order['currency'],
                'type' => 'other', 'gateway_id' => $this->gatewayId, 'transaction_id' => $reference,
                'status' => 'approved', 'message' => 'Monero received: ' . Util::pico_to_string($received) . ' XMR'
            ]);
            if (!$transactionId || $this->Transactions->errors() || !$this->connection->inTransaction()) {
                throw new RuntimeException('Could not record the native payment transaction.');
            }
            $this->assertAccounting((int)$transactionId, $order, $creditedAmount, $reference);
            $amounts = [];
            $remainingDue = [];
            foreach ($order['allocations'] as $allocation) {
                $invoice = $this->Invoices->get($allocation['id']);
                $amount = bccomp($allocation['amount'], (string)$invoice->due, 4) > 0 ? (string)$invoice->due : $allocation['amount'];
                if (bccomp($amount, '0', 4) > 0) {
                    $amounts[] = ['invoice_id' => $allocation['id'], 'amount' => $amount];
                    $remainingDue[$allocation['id']] = bcsub((string)$invoice->due, $amount, 4);
                }
            }
            if ($amounts) {
                $this->Transactions->apply($transactionId, ['amounts' => $amounts]);
                if ($this->Transactions->errors() || !$this->connection->inTransaction()) {
                    throw new RuntimeException('Could not apply the native payment transaction.');
                }
                $applied = [];
                foreach ($this->Transactions->getApplied($transactionId) as $allocation) {
                    $applied[(int)$allocation->invoice_id] = (string)$allocation->applied_amount;
                }
                foreach ($amounts as $allocation) {
                    $invoice = $this->Invoices->get($allocation['invoice_id']);
                    if (bccomp($applied[$allocation['invoice_id']] ?? '0', $allocation['amount'], 4) !== 0
                        || !$invoice || !in_array($invoice->status, ['active', 'proforma'], true)
                        || bccomp((string)$invoice->due, $remainingDue[$allocation['invoice_id']], 4) !== 0
                        || (bccomp((string)$invoice->due, '0', 4) === 0 && $invoice->date_closed === null)) {
                        throw new RuntimeException('Native invoice application did not complete.');
                    }
                }
            }
            $state = $order['scan_state'];
            $state['invoice_status'] = 'settled';
            $state['received_pico'] = $received;
            $state['overpaid_pico'] = bcsub($received, $expected, 0);
            $this->Record->query("UPDATE gateway_xmrpay_orders SET status = 'paid', active_key = NULL,
                state = ?, transaction_id = ? WHERE id = ?", [json_encode($state, JSON_THROW_ON_ERROR), $transactionId, $orderId]);
            $this->assertAccounting((int)$transactionId, $order, $creditedAmount, $reference);
            $this->assertApplications((int)$transactionId, $amounts);
            $this->connection->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function assertAccounting(int $transactionId, array $order, string $amount, string $reference): void
    {
        $transaction = $this->Record->query('SELECT client_id, gateway_id, transaction_id, currency, type, status, amount
            FROM transactions WHERE id = ?', [$transactionId])->fetch(PDO::FETCH_OBJ);
        if (!$transaction || !$this->connection->inTransaction()
            || (int)$transaction->client_id !== (int)$order['client_id']
            || (int)$transaction->gateway_id !== $this->gatewayId
            || $transaction->transaction_id !== $reference
            || $transaction->currency !== $order['currency']
            || $transaction->type !== 'other' || $transaction->status !== 'approved'
            || bccomp((string)$transaction->amount, $amount, 4) !== 0) {
            throw new RuntimeException('Native payment accounting does not match the verified payment.');
        }
    }

    private function assertApplications(int $transactionId, array $amounts): void
    {
        $applications = $this->Record->query('SELECT invoice_id, amount FROM transaction_applied
            WHERE transaction_id = ?', [$transactionId])->fetchAll(PDO::FETCH_ASSOC);
        if (count($applications) !== count($amounts)) {
            throw new RuntimeException('Native payment allocations do not match the verified payment.');
        }
        $expected = [];
        foreach ($amounts as $allocation) {
            $expected[(int)$allocation['invoice_id']] = $allocation['amount'];
        }
        foreach ($applications as $application) {
            $invoiceId = (int)$application['invoice_id'];
            if (!isset($expected[$invoiceId]) || bccomp($application['amount'], $expected[$invoiceId], 4) !== 0) {
                throw new RuntimeException('Native payment allocations do not match the verified payment.');
            }
            unset($expected[$invoiceId]);
        }
    }

    private function validateInvoices(int $clientId, string $currency, array $allocations, string $amount, bool $lock = false, bool $requireBalance = false): void
    {
        $total = '0';
        $ids = [];
        foreach ($allocations as $allocation) {
            $id = (int)($allocation['id'] ?? 0);
            $value = (string)($allocation['amount'] ?? '');
            $this->validateAmount($value, 4);
            if ($id < 1 || isset($ids[$id])) {
                throw new InvalidArgumentException('Invalid invoice allocation.');
            }
            $ids[$id] = $value;
            $total = bcadd($total, $value, 4);
        }
        if (bccomp($total, $amount, 4) > 0) {
            throw new InvalidArgumentException('Invoice allocations exceed the payment amount.');
        }
        $invoiceIds = array_keys($ids);
        sort($invoiceIds);
        if ($lock) {
            // Lock all invoices before model reads establish a repeatable-read snapshot.
            foreach ($invoiceIds as $id) {
                $this->Record->query('SELECT id FROM invoices WHERE id = ? FOR UPDATE', [$id]);
            }
        }
        $client = $this->Clients->get($clientId, false);
        if (!$client || (int)$client->company_id !== $this->companyId || $client->status !== 'active') {
            throw new InvalidArgumentException('Invalid payment client.');
        }
        foreach ($invoiceIds as $id) {
            $invoice = $this->Invoices->get($id);
            if (!$invoice || (int)$invoice->client_id !== $clientId || $invoice->currency !== $currency
                || !in_array($invoice->status, ['active', 'proforma'], true)) {
                throw new InvalidArgumentException('Invoice is not eligible for this payment.');
            }
            if ($requireBalance && bccomp($ids[$id], (string)$invoice->due, 4) > 0) {
                throw new XmrpayInvoiceBalanceChanged('Invoice balance changed before checkout.');
            }
        }
    }

    private function validateAmount(string $amount, int $precision): void
    {
        if (!preg_match('/^[0-9]{1,15}(?:\.[0-9]{1,12})?$/D', $amount)
            || bccomp($amount, '0', 12) <= 0 || bccomp($amount, bcadd($amount, '0', $precision), 12) !== 0) {
            throw new InvalidArgumentException('Invalid exact payment amount.');
        }
    }

    private function decodeOrder(array $row): array
    {
        $state = json_decode($row['state'], true, 512, JSON_THROW_ON_ERROR);
        $row['allocations'] = json_decode($row['allocations'], true, 512, JSON_THROW_ON_ERROR);
        foreach (['id', 'gateway_id', 'company_id', 'client_id'] as $name) {
            $row[$name] = (int)$row[$name];
        }
        $row['transaction_id'] = $row['transaction_id'] === null ? null : (int)$row['transaction_id'];
        $row['scan_state'] = $state;
        return array_merge($state, $row);
    }

    private function lockOrder(int $orderId): array
    {
        $this->Record->query('SELECT id FROM gateway_xmrpay_orders WHERE id = ? AND gateway_id = ? AND company_id = ? FOR UPDATE', [
            $orderId, $this->gatewayId, $this->companyId
        ]);
        return $this->loadOne($orderId);
    }

    private function begin(): void
    {
        if ($this->connection->inTransaction()) {
            throw new RuntimeException('Payment settlement cannot own an existing database transaction.');
        }
        $this->connection->beginTransaction();
    }

    private function rollback(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
        $this->Record->reset();
        $this->Transactions->Record->reset();
        $this->Invoices->Record->reset();
    }
}
