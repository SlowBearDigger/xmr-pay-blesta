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

    public function install()
    {
        foreach (['gmp', 'bcmath', 'curl', 'pdo_mysql'] as $extension) {
            if (!extension_loaded($extension)) {
                $this->Input->setErrors(['xmrpay' => ['extension' => 'XMRPay requires GMP, BCMath, cURL and PDO MySQL.']]);
                return;
            }
        }
        require_once __DIR__ . '/XmrpayOrderStore.php';
        Loader::loadComponents($this, ['Record']);
        try {
            XmrpayOrderStore::installSchema($this->Record);
        } catch (Throwable $error) {
            $this->Input->setErrors(['xmrpay' => ['database' => 'Could not create XMRPay payment tables.']]);
        }
    }

    public function upgrade($current_version)
    {
        $this->install();
    }

    public function encryptableFields()
    {
        return ['view_key', 'nodes', 'agent_token', 'webhook_secret'];
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
        $meta['show_credit'] = ($meta['show_credit'] ?? '1') === '0' ? '0' : '1';
        if (!empty($meta['address']) || !empty($meta['view_key']) || !empty($meta['nodes'])) {
            return $this->nativeSettings($meta);
        }
        Loader::loadComponents($this, ['Record']);
        try {
            $pending = $this->Record->query("SELECT 1 FROM gateway_xmrpay_orders
                WHERE company_id = ? AND status = 'pending' LIMIT 1", [
                (int)Configure::get('Blesta.company_id')
            ])->fetchColumn();
            if ($pending) {
                $this->Input->setErrors(['xmrpay' => ['wallet' => 'Keep native wallet settings while issued XMRPay payments remain pending.']]);
            }
        } catch (PDOException $error) {
            if ($error->getCode() !== '42S02') {
                $this->Input->setErrors(['xmrpay' => ['database' => 'Could not verify pending XMRPay payments.']]);
            }
            $this->Record->reset();
        }
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
            'network' => (string)($meta['network'] ?? ''),
            'show_credit' => $meta['show_credit']
        ];
    }

    private function nativeSettings(array $meta): array
    {
        require_once __DIR__ . '/load.php';
        $settings = [
            'address' => trim((string)($meta['address'] ?? '')),
            'view_key' => strtolower(trim((string)($meta['view_key'] ?? ''))),
            'nodes' => trim((string)($meta['nodes'] ?? '')),
            'network' => (string)($meta['network'] ?? ''),
            'show_credit' => $meta['show_credit'],
        ];
        try {
            foreach ([
                'min_confirmations' => [10, 1, 720],
                'minimum_nodes' => [2, 1, 8],
                'index_offset' => [1000000, 0, 2147483647],
                'invoice_minutes' => [60, 1, 10080]
            ] as $name => [$default, $minimum, $maximum]) {
                $value = (string)($meta[$name] ?? $default);
                if (!preg_match('/^[0-9]+$/D', $value) || (int)$value < $minimum || (int)$value > $maximum) {
                    throw new InvalidArgumentException('Invalid native payment setting.');
                }
                $settings[$name] = (int)$value;
            }
            if (!in_array($settings['network'], ['mainnet', 'stagenet', 'testnet'], true)
                || strlen($settings['address']) !== 95
                || !preg_match('/^[a-f0-9]{64}$/D', $settings['view_key'])) {
                throw new InvalidArgumentException('Invalid wallet configuration.');
            }
            $gateway = new \XmrPay\Adapter\Gateway($settings);
            $keys = $gateway->verifyKeys();
            $crypto = new \MoneroIntegrations\MoneroPhp\Cryptonote($settings['network']);
            $decoded = $crypto->decode_address($settings['address']);
            $primaryPrefixes = ['mainnet' => '12', 'stagenet' => '18', 'testnet' => '35'];
            if (!$gateway->cryptoReady() || empty($keys['address_valid']) || empty($keys['key_match'])
                || ($decoded['networkByte'] ?? '') !== $primaryPrefixes[$settings['network']]) {
                throw new InvalidArgumentException('Wallet address and view key do not match.');
            }
            require_once __DIR__ . '/XmrpayOrderStore.php';
            Loader::loadComponents($this, ['Record']);
            $pending = $this->Record->query("SELECT 1 FROM gateway_xmrpay_orders
                WHERE company_id = ? AND status = 'pending' AND wallet_id <> ? LIMIT 1", [
                (int)Configure::get('Blesta.company_id'), XmrpayOrderStore::walletId($settings)
            ])->fetchColumn();
            if ($pending) {
                $this->Input->setErrors(['xmrpay' => ['wallet' => 'Keep the wallet, network and index range unchanged while issued XMRPay payments remain pending.']]);
            }
            foreach (['agent_url', 'agent_token', 'webhook_secret'] as $name) {
                if (!empty($meta[$name])) {
                    $settings[$name] = (string)$meta[$name];
                }
            }
        } catch (Throwable $error) {
            $this->Input->setErrors(['xmrpay' => ['settings' => 'Enter a matching primary address and private view key, valid nodes, network and payment limits.']]);
        }
        return $settings;
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
            if (!empty($this->meta['address']) && !empty($this->meta['view_key'])) {
                return $this->nativeProcess((int)$contact_info['client_id'], (string)$amount, $currency, $invoice_amounts ?? []);
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
            $id = $this->encodeOrder($data);
            $order = $this->agent('/order', ['id' => $id, 'amount' => $xmr, 'label' => 'Blesta invoice']);
            if (!isset($order['address'], $order['amount']) || bccomp((string)$order['amount'], $xmr, 12) !== 0) {
                throw new RuntimeException('Agent did not create the order.');
            }
            return $this->renderProcess($order['address'], $xmr);
        } catch (XmrpayInvoiceBalanceChanged $error) {
            $this->Input->setErrors(['xmrpay' => ['checkout' => 'The invoice balance has changed. Return to your invoices and start a new payment.']]);
            return null;
        } catch (Throwable $e) {
            error_log('XMRPay checkout failed: ' . get_class($e));
            $this->Input->setErrors(['xmrpay' => ['checkout' => 'Could not create a Monero payment. Please try again.']]);
            return null;
        }
    }

    private function nativeProcess(int $clientId, string $amount, string $currency, array $allocations): string
    {
        require_once __DIR__ . '/XmrpayOrderStore.php';
        Loader::loadModels($this, ['PluginManager', 'CronTasks']);
        $task = $this->CronTasks->getTaskRunByKey('scan_payments', 'xmrpay_watch');
        if (Configure::get('Xmrpay.checkout_guard') !== true
            || !$this->PluginManager->getByDir('xmrpay_watch', Configure::get('Blesta.company_id'))
            || !$task || (int)$task->enabled !== 1) {
            throw new RuntimeException('Install, upgrade and enable XMRPay Watch before accepting payments.');
        }
        $config = $this->meta;
        foreach (['minimum_nodes', 'min_confirmations', 'index_offset', 'invoice_minutes'] as $name) {
            if (isset($config[$name])) {
                $config[$name] = (int)$config[$name];
            }
        }
        $gateway = new \XmrPay\Adapter\Gateway($config);
        $gateway->scanner()->set_time_budget(8);
        $health = $gateway->scanner()->node_health();
        if (empty($health['ready'])) {
            throw new RuntimeException('Configured Monero nodes are not ready.');
        }
        $birthday = max(1, (int)$health['nodes'][0]['height'] - 2);
        $store = new XmrpayOrderStore((int)$this->gateway_id, $config);
        $order = $store->checkout($clientId, $amount, $currency, $allocations,
            fn(): string => $this->quote($amount, $currency), $birthday);
        Loader::loadComponents($this, ['Session']);
        $grants = $this->Session->read('xmrpay_checkout');
        $grants = is_array($grants) ? $grants : [];
        unset($grants[$order['id']]);
        $grants[$order['id']] = [
            'company_id' => $order['company_id'],
            'client_id' => $order['client_id'],
            'gateway_id' => $order['gateway_id']
        ];
        $this->Session->write('xmrpay_checkout', array_slice($grants, -20, null, true));
        $statusUrl = WEBDIR . Configure::get('Route.client')
            . '/plugin/xmrpay_watch/client_main/status/' . $order['id'] . '/';
        return $this->renderProcess($order['address'], $order['xmr_amount'], $order['expires_at'], $statusUrl);
    }

    private function renderProcess(string $address, string $xmr, ?int $expiresAt = null, ?string $statusUrl = null): string
    {
        $this->view = new View('process', 'default');
        $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'nonmerchant' . DS . 'xmrpay' . DS);
        Loader::loadHelpers($this, ['Html']);
        $this->view->set('address', $address);
        $this->view->set('xmr_amount', $xmr);
        $this->view->set('network', $this->meta['network']);
        $this->view->set('expires_at', $expiresAt);
        $this->view->set('status_url', $statusUrl);
        $this->view->set('show_credit', ($this->meta['show_credit'] ?? '1') !== '0');
        $this->view->set('payment_uri', 'monero:' . $address . '?tx_amount=' . rawurlencode($xmr));
        return $this->view->fetch();
    }

    public function validate(array $get, array $post)
    {
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        $signature = $_SERVER['HTTP_X_XMR_PAY_SIGNATURE'] ?? '';
        $response = $this->validateEvent($raw, $signature);
        if ($response === null && !$this->errors()) {
            // Blesta 6 continues processing null responses without gateway errors.
            http_response_code(200);
            exit;
        }
        return $response;
    }

    private function validateEvent(string $raw, string $signature)
    {
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
        Loader::loadModels($this, ['Transactions', 'Invoices']);
        $transactionId = $this->transactionId($id);
        Loader::loadComponents($this, ['Record']);
        $lock = hash('sha256', 'xmrpay:' . $transactionId);
        $acquired = $this->Record->query('SELECT GET_LOCK(?, 0)', [$lock])->fetchColumn();
        if ((int)$acquired !== 1) {
            http_response_code(503);
            $this->Input->setErrors(['xmrpay' => ['busy' => 'Payment callback is already processing.']]);
            return null;
        }
        register_shutdown_function(function () use ($lock) {
            $this->Record->query('SELECT RELEASE_LOCK(?)', [$lock]);
        });
        $transaction = $this->Transactions->getByTransactionId($transactionId);
        $invoices = $data['invoices'];
        if ($transaction && $transaction->status === 'approved') {
            $invoices = $this->remainingInvoices($transaction->id, $invoices);
            if (!$invoices) {
                return null;
            }
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
        ob_start();

        register_shutdown_function(function () use ($transactionId, $data) {
            try {
                $transaction = $this->Transactions->getByTransactionId($transactionId);
                if (!$transaction || $transaction->status !== 'approved') {
                    http_response_code(503);
                    return;
                }
                if ($this->remainingInvoices($transaction->id, $data['invoices'])) {
                    http_response_code(503);
                }
            } catch (Throwable $e) {
                http_response_code(503);
            }
        });
        return [
            'client_id' => $data['client'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'invoices' => $invoices,
            'status' => 'approved',
            'transaction_id' => $transactionId
        ];
    }

    public function success(array $get, array $post)
    {
        return ['status' => 'pending'];
    }

    private function remainingInvoices($transactionId, array $invoices): array
    {
        $applied = [];
        foreach ($this->Transactions->getApplied($transactionId) as $row) {
            $applied[$row->invoice_id] = bcadd($applied[$row->invoice_id] ?? '0', (string)$row->applied_amount, 12);
        }
        $remaining = [];
        foreach ($invoices as $invoice) {
            $amount = bcsub((string)$invoice['amount'], $applied[$invoice['id']] ?? '0', 12);
            if (bccomp($amount, '0', 12) > 0) {
                $current = $this->Invoices->get($invoice['id']);
                if (!$current || !isset($current->due)) { throw new RuntimeException('Invoice balance unavailable.'); }
                if ($current->status === 'void' || bccomp((string)$current->due, '0', 12) <= 0) { continue; }
                if (bccomp($amount, (string)$current->due, 12) > 0) { $amount = (string)$current->due; }
                $remaining[] = ['id' => $invoice['id'], 'amount' => $amount];
            }
        }
        return $remaining;
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
        $result = json_decode($response ?: '', true);
        if ($http !== 200 || !is_array($result)) {
            throw new RuntimeException('XMRPay agent unavailable.');
        }
        return $result;
    }
}
