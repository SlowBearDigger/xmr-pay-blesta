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
        Loader::loadModels($this, ['GatewayManager']);
        $deadline = microtime(true) + 20;
        foreach ($this->GatewayManager->getByClass('xmrpay', Configure::get('Blesta.company_id')) as $installed) {
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
                foreach ($store->loadPending() as $order) {
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) {
                        break;
                    }
                    $store->markScanAttempt($order['id']);
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
    }
}
