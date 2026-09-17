<?php

class Xmrpay extends NonmerchantGateway
{
    private $meta = [];
    private $currency;

    public function __construct()
    {
        $this->loadConfig(__DIR__ . DS . 'config.json');
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('xmrpay', null, __DIR__ . DS . 'language' . DS);
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    public function setMeta(?array $meta = null)
    {
        $this->meta = $meta ?? [];
    }

    public function encryptableFields()
    {
        return ['agent_token', 'webhook_secret'];
    }

    public function getSettings(?array $meta = null)
    {
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'nonmerchant' . DS . 'xmrpay' . DS);
        Loader::loadHelpers($this, ['Form', 'Html']);
        $this->view->set('meta', $meta ?? []);
        return $this->view->fetch();
    }

    public function editSettings(array $meta)
    {
        $url = rtrim((string)($meta['agent_url'] ?? ''), '/');
        if (!preg_match('~^http://127\.0\.0\.1:[0-9]{2,5}$~', $url)
            || strlen((string)($meta['agent_token'] ?? '')) < 32
            || strlen((string)($meta['webhook_secret'] ?? '')) < 32
            || !in_array($meta['network'] ?? '', ['stagenet', 'mainnet'], true)) {
            $this->Input->setErrors(['xmrpay' => ['settings' => 'Enter a loopback agent URL, secrets of at least 32 characters, and a valid network.']]);
        }
        return [
            'agent_url' => $url,
            'agent_token' => (string)($meta['agent_token'] ?? ''),
            'webhook_secret' => (string)($meta['webhook_secret'] ?? ''),
            'network' => (string)($meta['network'] ?? '')
        ];
    }

    public function buildProcess(array $contact_info, $amount, ?array $invoice_amounts = null, ?array $options = null)
    {
        try {
            $currency = strtoupper((string)$this->currency);
            if (!in_array($currency, ['USD', 'EUR', 'XMR'], true)
                || !isset($contact_info['client_id'])
                || !preg_match('/^\d+(?:\.\d{1,12})?$/', (string)$amount)
                || bccomp((string)$amount, '0', 12) <= 0) {
                throw new RuntimeException('Invalid payment amount or currency.');
            }
            $xmr = $this->quote((string)$amount, $currency);
            $data = [
                'client' => (int)$contact_info['client_id'],
                'amount' => (string)$amount,
                'currency' => $currency,
                'invoices' => $invoice_amounts ?? [],
                'xmr' => $xmr,
                'network' => $this->meta['network'],
                'nonce' => bin2hex(random_bytes(8))
            ];
            // ponytail: a checkout reload creates a fresh address; persist quotes if stable retries become necessary.
            $id = $this->encodeOrder($data);
            $order = $this->agent('/order', ['id' => $id, 'amount' => $xmr, 'label' => 'Blesta invoice']);
            if (!isset($order['address'], $order['amount']) || bccomp((string)$order['amount'], $xmr, 12) !== 0) {
                throw new RuntimeException('Agent did not create the order.');
            }
            $this->view = new View('process', 'default');
            $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'nonmerchant' . DS . 'xmrpay' . DS);
            Loader::loadHelpers($this, ['Html']);
            $this->view->set('address', $order['address']);
            $this->view->set('xmr_amount', $xmr);
            $this->view->set('network', $this->meta['network']);
            $this->view->set('payment_uri', 'monero:' . $order['address'] . '?tx_amount=' . rawurlencode($xmr));
            return $this->view->fetch();
        } catch (Throwable $e) {
            error_log('XMRPay checkout: ' . $e->getMessage());
            $this->Input->setErrors(['xmrpay' => ['checkout' => 'Could not create a Monero payment. Please try again.']]);
            return null;
        }
    }

    public function validate(array $get, array $post)
    {
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $signature = $_SERVER['HTTP_X_XMR_PAY_SIGNATURE'] ?? '';
        $expected = 'sha256=' . hash_hmac('sha256', $raw, $this->meta['webhook_secret'] ?? '');
        if (strlen($raw) > 65536 || strlen($this->meta['webhook_secret'] ?? '') < 32 || !hash_equals($expected, $signature)) {
            http_response_code(400);
            $this->Input->setErrors(['xmrpay' => ['signature' => 'Invalid payment signature.']]);
            return null;
        }
        $event = json_decode($raw, true);
        $id = $event['order_id'] ?? '';
        if (!is_array($event) || ($event['event'] ?? '') !== 'order.paid'
            || ($event['network'] ?? '') !== ($this->meta['network'] ?? '')
            || !is_string($id)) {
            http_response_code(400);
            $this->Input->setErrors(['xmrpay' => ['event' => 'Invalid payment event.']]);
            return null;
        }
        $data = $this->decodeOrder($id);
        if (!is_array($data) || !isset($data['client'], $data['amount'], $data['currency'], $data['invoices'], $data['xmr'])
            || ($data['network'] ?? '') !== ($this->meta['network'] ?? '')
            || !preg_match('/^\d+(?:\.\d{1,12})?$/', (string)$data['xmr'])
            || !preg_match('/^\d+(?:\.\d{1,12})?$/', (string)($event['amount_xmr'] ?? ''))
            || bccomp((string)$data['xmr'], (string)($event['amount_xmr'] ?? ''), 12) !== 0) {
            http_response_code(400);
            $this->Input->setErrors(['xmrpay' => ['amount' => 'Payment amount mismatch.']]);
            return null;
        }
        Loader::loadModels($this, ['Transactions']);
        $transactionId = $this->transactionId($id);
        if ($this->Transactions->getByTransactionId($transactionId)) {
            return null;
        }
        try {
            $status = $this->agent('/order/' . rawurlencode($id));
        } catch (Throwable $e) {
            http_response_code(503);
            $this->Input->setErrors(['xmrpay' => ['agent' => 'Payment agent unavailable.']]);
            return null;
        }
        if (($status['paid'] ?? false) !== true
            || !preg_match('/^\d+(?:\.\d{1,12})?$/', (string)($status['amount'] ?? ''))
            || bccomp((string)$status['amount'], (string)$data['xmr'], 12) !== 0) {
            http_response_code(503);
            $this->Input->setErrors(['xmrpay' => ['status' => 'Payment is not settled by the agent.']]);
            return null;
        }
        // Blesta can acknowledge a callback even when storing or applying its transaction fails.
        register_shutdown_function(function () use ($transactionId, $data) {
            try {
                $transaction = $this->Transactions->getByTransactionId($transactionId);
                if (!$transaction || $transaction->status !== 'approved') {
                    http_response_code(503);
                    return;
                }
                $applied = [];
                foreach ($this->Transactions->getApplied($transaction->id) as $row) {
                    $applied[$row->invoice_id] = $row->applied_amount;
                }
                foreach ($data['invoices'] as $invoice) {
                    if (bccomp((string)($applied[$invoice['id']] ?? '0'), (string)$invoice['amount'], 4) < 0) {
                        http_response_code(503);
                        return;
                    }
                }
            } catch (Throwable $e) {
                http_response_code(503);
            }
        });
        return [
            'client_id' => $data['client'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'invoices' => $data['invoices'],
            'status' => 'approved',
            'transaction_id' => $transactionId
        ];
    }

    public function success(array $get, array $post)
    {
        return ['status' => 'pending'];
    }

    private function encodeOrder(array $data): string
    {
        $encoded = rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        return 'bx_' . $encoded . '.' . hash_hmac('sha256', $encoded, $this->meta['webhook_secret']);
    }

    private function decodeOrder(string $id): ?array
    {
        if (!preg_match('/^bx_([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $id, $parts)
            || !hash_equals(hash_hmac('sha256', $parts[1], $this->meta['webhook_secret']), $parts[2])) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function transactionId(string $orderId): string
    {
        return 'bx_' . hash('sha256', $orderId);
    }

    private function quote(string $amount, string $currency): string
    {
        if ($currency === 'XMR') {
            return rtrim(rtrim(bcadd($amount, '0', 12), '0'), '.');
        }
        $curl = curl_init('https://api.coingecko.com/api/v3/simple/price?ids=monero&vs_currencies=' . strtolower($currency));
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'XMRPay-Blesta/1.0 (+https://xmrpay.shop/)']);
        $body = curl_exec($curl);
        $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $price = json_decode($body ?: '', true)['monero'][strtolower($currency)] ?? null;
        if ($http !== 200 || !is_numeric($price) || $price <= 0) {
            throw new RuntimeException('XMR exchange rate unavailable.');
        }
        $unit = number_format((float)$price, 8, '.', '');
        $xmr = bcdiv($amount, $unit, 12);
        if (bccomp(bcmul($xmr, $unit, 12), $amount, 12) < 0) {
            $xmr = bcadd($xmr, '0.000000000001', 12);
        }
        return rtrim(rtrim($xmr, '0'), '.');
    }

    private function agent(string $path, ?array $body = null): array
    {
        $curl = curl_init($this->meta['agent_url'] . $path);
        $headers = ['Authorization: Bearer ' . $this->meta['agent_token']];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
        $response = curl_exec($curl);
        $http = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $result = json_decode($response ?: '', true);
        if ($http !== 200 || !is_array($result)) {
            throw new RuntimeException('XMRPay agent unavailable.');
        }
        return $result;
    }
}
