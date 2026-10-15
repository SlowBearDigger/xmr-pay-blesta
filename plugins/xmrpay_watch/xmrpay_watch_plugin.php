<?php
declare(strict_types=1);

class XmrpayWatchPlugin extends Plugin
{
    public function __construct()
    {
        Loader::loadComponents($this, ['Input', 'Record']);
        Language::loadLang('xmrpay_watch_plugin', null, __DIR__ . DS . 'language' . DS);
        $this->loadConfig(__DIR__ . DS . 'config.json');
    }

    public function install($plugin_id)
    {
        Loader::loadModels($this, ['CronTasks']);
        try {
            require_once COMPONENTDIR . 'gateways' . DS . 'nonmerchant' . DS . 'xmrpay' . DS . 'XmrpayOrderStore.php';
            XmrpayOrderStore::installSchema($this->Record);
            $task = $this->CronTasks->getByKey('scan_payments', 'xmrpay_watch');
            $taskId = $task ? $task->id : $this->CronTasks->add([
                'key' => 'scan_payments', 'task_type' => 'plugin', 'dir' => 'xmrpay_watch',
                'name' => Language::_('XmrpayWatchPlugin.scan_payments', true),
                'description' => Language::_('XmrpayWatchPlugin.scan_description', true), 'type' => 'interval'
            ]);
            if (!$taskId) {
                throw new RuntimeException('Could not register the payment task.');
            }
            if (!$this->CronTasks->getTaskRunByKey('scan_payments', 'xmrpay_watch')) {
                $runId = $this->CronTasks->addTaskRun($taskId, ['enabled' => 1, 'interval' => 1]);
                if (!$runId || $this->CronTasks->errors()) {
                    throw new RuntimeException('Could not schedule the payment task.');
                }
            }
        } catch (Throwable $error) {
            $this->Input->setErrors(['xmrpay' => ['install' => 'Could not install XMRPay Watch. Install the matching XMRPay gateway files first.']]);
        }
    }

    public function upgrade($current_version, $plugin_id)
    {
        $this->install($plugin_id);
    }

    public function getEvents()
    {
        return [['event' => 'Appcontroller.preAction', 'callback' => ['this', 'guardCheckout']]];
    }

    public function guardCheckout($event)
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        try {
            $route = Router::routesTo($_SERVER['REQUEST_URI']);
            $action = strtolower($route['action'] ?? 'index');
            if (Loader::toCamelCase($route['controller']) === 'ClientPay'
                && in_array($action, ['index', 'method', 'confirm'], true)) {
                Loader::loadComponents($this, ['Session']);
                Loader::loadModels($this, ['Clients', 'Invoices']);
                $this->validateCheckout($route, $action);
            }
            Configure::set('Xmrpay.checkout_guard', true);
        } catch (Throwable $error) {
            try {
                if (isset($this->Session)) {
                    $this->Session->clear('payment');
                }
                http_response_code($error instanceof InvalidArgumentException ? 400 : 503);
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: no-store');
                echo 'The payment request could not be verified. Return to your invoices and start a new payment.';
            } finally {
                // Blesta catches exceptions from event callbacks and continues dispatching.
                exit;
            }
        }
    }

    private function validateCheckout(array $route, string $action): void
    {
        $get = $route['get'];
        $payment = $this->Session->read('payment');
        if ($payment === '') {
            $payment = [];
        }
        if (!is_array($payment)) {
            throw new InvalidArgumentException('Invalid payment session.');
        }
        $clientId = $this->Session->read('blesta_client_id') ?: ($payment['client_id'] ?? null);
        if (isset($get['sid'])) {
            if (($route['action'] ?? '') !== 'method' || !is_string($get['sid'])) {
                throw new InvalidArgumentException('Invalid payment link.');
            }
            $fields = [];
            $decoded = $this->Invoices->systemDecrypt($get['sid']);
            if (!is_string($decoded)) {
                throw new InvalidArgumentException('Invalid payment link.');
            }
            foreach (explode('|', $decoded) as $field) {
                $pair = explode('=', $field, 2);
                if (count($pair) !== 2 || isset($fields[$pair[0]])) {
                    throw new InvalidArgumentException('Invalid payment link.');
                }
                $fields[$pair[0]] = $pair[1];
            }
            if (!isset($fields['c'], $fields['h']) || !preg_match('/^[a-f0-9]{16}$/D', $fields['h'])
                || !$this->Invoices->verifyPayHash($fields['c'], $get[0] ?? null, $fields['h'])) {
                throw new InvalidArgumentException('Invalid payment link.');
            }
            $clientId = $fields['c'];
        }
        if ($clientId === null || $clientId === false) {
            if ($payment || isset($get['client_id'])) {
                throw new InvalidArgumentException('An authenticated payment owner is required.');
            }
            return;
        }
        $clientId = $this->paymentId($clientId);
        if (!$this->Clients->get($clientId)
            || (isset($get['client_id']) && $this->paymentId($get['client_id']) !== $clientId)) {
            throw new InvalidArgumentException('Invalid payment owner.');
        }
        if (isset($payment['client_id']) && $this->paymentId($payment['client_id']) !== $clientId) {
            $this->Session->clear('payment');
            $payment = [];
        }
        if (isset($payment['amounts'])) {
            if (!is_array($payment['amounts'])) {
                throw new InvalidArgumentException('Invalid payment allocations.');
            }
            $ids = [];
            foreach ($payment['amounts'] as $allocation) {
                if (!is_array($allocation) || !isset($allocation['invoice_id'])) {
                    throw new InvalidArgumentException('Invalid payment allocation.');
                }
                $ids[] = $allocation['invoice_id'];
            }
            $this->validatePaymentInvoices($ids, $clientId);
        }
        if ($action === 'method' && isset($get[0])) {
            $this->validatePaymentInvoices([$get[0]], $clientId);
        }
        if ($action === 'index' && $_POST) {
            $amounts = $_POST['applyamount'] ?? [];
            if (!is_array($amounts) || !is_string($_POST['credit'] ?? '')) {
                throw new InvalidArgumentException('Invalid payment amounts.');
            }
            foreach ($amounts as $amount) {
                if (!is_string($amount)) {
                    throw new InvalidArgumentException('Invalid payment amount.');
                }
            }
            $ids = $_POST['invoice_id'] ?? [];
            if (!is_array($ids)) {
                throw new InvalidArgumentException('Invalid invoice selection.');
            }
            if (($ids[0] ?? null) === 'all') {
                unset($ids[0]);
            }
            $this->validatePaymentInvoices($ids, $clientId);
        }
    }

    private function validatePaymentInvoices(array $ids, string $clientId): void
    {
        $seen = [];
        foreach ($ids as $id) {
            $id = $this->paymentId($id);
            if (isset($seen[$id])) {
                throw new InvalidArgumentException('Repeated invoice selection.');
            }
            $seen[$id] = true;
            $invoice = $this->Invoices->get($id);
            if (!$invoice || (string)$invoice->client_id !== $clientId) {
                throw new InvalidArgumentException('Invalid invoice selection.');
            }
        }
    }

    private function paymentId($value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^0*[1-9][0-9]*$/D', (string)$value)) {
            throw new InvalidArgumentException('Invalid payment identifier.');
        }
        $id = ltrim((string)$value, '0');
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('Invalid payment identifier.');
        }
        return $id;
    }

    public function getActions()
    {
        return [[
            'action' => 'nav_secondary_staff',
            'uri' => 'plugin/xmrpay_watch/admin_main/',
            'name' => 'XmrpayWatchPlugin.payments',
            'options' => ['parent' => 'tools/']
        ]];
    }

    public function getPermissions()
    {
        return [
            [
                'group_alias' => 'admin_tools',
                'name' => Language::_('XmrpayWatchPlugin.permission.view', true),
                'alias' => 'xmrpay_watch.admin_main',
                'action' => 'index'
            ],
            [
                'group_alias' => 'admin_tools',
                'name' => Language::_('XmrpayWatchPlugin.permission.accept', true),
                'alias' => 'xmrpay_watch.admin_main',
                'action' => 'accept'
            ]
        ];
    }

    public function uninstall($plugin_id, $last_instance)
    {
        Loader::loadModels($this, ['CronTasks']);
        $run = $this->CronTasks->getTaskRunByKey('scan_payments', 'xmrpay_watch');
        if ($run) {
            $this->CronTasks->deleteTaskRun($run->id);
        }
        if ($last_instance && ($task = $this->CronTasks->getByKey('scan_payments', 'xmrpay_watch'))) {
            $this->CronTasks->deleteTask($task->id, 'plugin', 'xmrpay_watch');
        }
    }

    public function cron($key)
    {
        if ($key !== 'scan_payments') {
            return;
        }
        require_once COMPONENTDIR . 'gateways' . DS . 'nonmerchant' . DS . 'xmrpay' . DS . 'XmrpayOrderStore.php';
        require_once COMPONENTDIR . 'gateways' . DS . 'nonmerchant' . DS . 'xmrpay' . DS . 'XmrpayReceipts.php';
        Loader::loadModels($this, ['GatewayManager']);
        $deadline = microtime(true) + 20;
        $gateways = $this->GatewayManager->getByClass('xmrpay', Configure::get('Blesta.company_id'));
        foreach ($gateways as $installed) {
            $config = [];
            foreach ($installed->meta as $field) {
                $config[$field->key] = $field->value;
            }
            if (empty($config['address']) || empty($config['view_key'])) {
                continue;
            }
            foreach (['minimum_nodes', 'min_confirmations', 'index_offset', 'invoice_minutes'] as $name) {
                if (isset($config[$name])) {
                    $config[$name] = (int)$config[$name];
                }
            }
            $lock = hash('sha256', 'xmrpay-watch:' . $installed->id);
            if ((int)$this->Record->query('SELECT GET_LOCK(?, 0)', [$lock])->fetchColumn() !== 1) {
                continue;
            }
            try {
                $gateway = new \XmrPay\Adapter\Gateway($config);
                $scanner = $gateway->scanner();
                $scanner->set_time_budget(max(0.01, min(8, $deadline - microtime(true))));
                $health = $scanner->node_health();
                if (empty($health['ready'])) {
                    continue;
                }
                $store = new XmrpayOrderStore((int)$installed->id, $config);
                $firstInBatch = true;
                foreach ($store->loadPending() as $order) {
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) {
                        break;
                    }
                    $store->markScanAttempt($order['id'], $firstInBatch);
                    $firstInBatch = false;
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) {
                        break;
                    }
                    $scanner->set_time_budget($remaining);
                    $settler = new \XmrPay\Adapter\Settler($gateway, $store, [
                        'min_confirmations' => (int)($config['min_confirmations'] ?? 10),
                        'time_budget' => $remaining, 'max_blocks' => 128
                    ]);
                    $report = $settler->settleOrder($order);
                    if ($report['status'] === 'error') {
                        error_log('XMRPay Watch could not persist a payment scan.');
                    }
                }
            } catch (Throwable $error) {
                error_log('XMRPay Watch failed: ' . get_class($error));
            } finally {
                $this->Record->query('SELECT RELEASE_LOCK(?)', [$lock]);
            }
        }
        $receiptDeadline = microtime(true) + 5;
        foreach ($gateways as $installed) {
            if (microtime(true) >= $receiptDeadline) {
                break;
            }
            try {
                (new XmrpayReceipts((int)$installed->id))->sendPending($receiptDeadline);
            } catch (Throwable $error) {
                error_log('XMRPay receipt delivery failed: ' . get_class($error));
            }
        }
    }
}
