<?php
declare(strict_types=1);

class AdminMain extends AppController
{
    private int $pluginId;

    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();
        $this->requireLogin();
        $this->view->view = 'default';
        Language::loadLang('admin_main', null, PLUGINDIR . 'xmrpay_watch' . DS . 'language' . DS);
        $this->uses(['PluginManager', 'Permissions', 'GatewayManager']);
        $this->components(['Record']);
        $this->helpers(['Form']);

        $plugins = $this->PluginManager->getByDir('xmrpay_watch', $this->company_id);
        $this->pluginId = (int)($plugins[0]->id ?? 0);
        $action = $this->action ?: 'index';
        // Blesta permits actions with no registered ACL, including files copied before an upgrade.
        if (!$this->Session->read('blesta_staff_id') || empty($plugins[0]->enabled)
            || !$this->Permissions->getByAlias('xmrpay_watch.admin_main', $this->pluginId, $action)
            || !$this->authorized('xmrpay_watch.admin_main', $action)) {
            $this->flashMessage('error', Language::_('AdminMain.!error.access', true));
            $this->redirect($this->base_uri);
        }
        $this->structure->set('page_title', Language::_('AdminMain.index.page_title', true));
    }

    public function index()
    {
        $filter = $this->get['filter'] ?? 'all';
        if (!is_string($filter) || !in_array($filter, ['all', 'pending', 'review', 'paused'], true)) {
            $filter = 'all';
        }
        $watchHours = [];
        foreach ($this->GatewayManager->getByClass('xmrpay', $this->company_id) as $gateway) {
            $watchHours[(int)$gateway->id] = 24;
            foreach ($gateway->meta as $field) {
                if ($field->key === 'abandoned_hours') {
                    $watchHours[(int)$gateway->id] = (int)$field->value;
                }
            }
        }
        $where = " FROM gateway_xmrpay_orders orders
            INNER JOIN gateways ON gateways.id = orders.gateway_id
                AND gateways.company_id = orders.company_id AND gateways.class = 'xmrpay'
            WHERE orders.company_id = ?";
        if ($filter !== 'all') {
            $where .= " AND orders.status = 'pending'";
        }
        if ($filter === 'review') {
            $where .= " AND CASE WHEN JSON_VALID(orders.state)
                THEN JSON_UNQUOTE(JSON_EXTRACT(orders.state, '$.invoice_status')) END = 'review'";
        }
        $parameters = [(int)$this->company_id];
        if ($filter === 'paused') {
            $paused = [];
            foreach ($watchHours as $gatewayId => $hours) {
                $paused[] = '(orders.gateway_id = ? AND orders.watch_paused_hours >= ?)';
                $parameters[] = $gatewayId;
                $parameters[] = $hours;
            }
            $where .= ' AND (' . ($paused ? implode(' OR ', $paused) : '0 = 1') . ')';
        }
        $total = (int)$this->Record->query('SELECT COUNT(*)' . $where, $parameters)->fetchColumn();
        $page = filter_var($this->get[0] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $page = min($page, max(1, (int)ceil($total / 25)));
        $offset = ($page - 1) * 25;
        $orders = $this->Record->query('SELECT orders.id, orders.gateway_id, orders.client_id, orders.amount,
            orders.currency, orders.xmr_amount, orders.status, orders.state, orders.last_scanned_at, orders.watch_paused_hours'
            . $where . ' ORDER BY orders.id DESC LIMIT 25 OFFSET ' . $offset, $parameters)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$order) {
            $state = json_decode($order['state'], true);
            $order['scan_state'] = is_array($state) ? $state : [];
            $order['watch_paused'] = $order['status'] === 'pending'
                && (int)$order['watch_paused_hours'] >= $watchHours[(int)$order['gateway_id']];
            $received = $order['scan_state']['received_pico'] ?? null;
            $order['received_xmr'] = is_string($received) && preg_match('/^[0-9]{1,32}$/D', $received)
                ? bcdiv($received, '1000000000000', 12) : null;
            unset($order['state']);
        }
        unset($order);

        $paginationGet = $this->get;
        $paginationGet[0] = $page;
        $this->setPagination($paginationGet, array_merge(Configure::get('Blesta.pagination'), [
            'total_results' => $total,
            'results_per_page' => 25,
            'uri' => $this->base_uri . 'plugin/xmrpay_watch/admin_main/index/[p]/',
            'params' => ['filter' => $filter]
        ]));
        $canAccept = $this->Permissions->getByAlias('xmrpay_watch.admin_main', $this->pluginId, 'accept')
            && $this->authorized('xmrpay_watch.admin_main', 'accept');
        $this->set('orders', $orders);
        $this->set('filter', $filter);
        $this->set('can_accept', $canAccept);
        return $this->renderAjaxWidgetIfAsync(isset($this->get[0]));
    }

    public function accept()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect($this->base_uri . 'plugin/xmrpay_watch/admin_main/');
        }
        $resuming = false;
        try {
            $operation = $this->post['operation'] ?? 'accept';
            if (!in_array($operation, ['accept', 'resume'], true)) {
                throw new InvalidArgumentException('Invalid payment review action.');
            }
            $resuming = $operation === 'resume';
            $orderId = filter_var($this->post['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $gatewayId = filter_var($this->post['gateway_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $reason = $this->post['reason'] ?? null;
            if (!$orderId || !$gatewayId || !is_string($reason) || trim($reason) === '' || strlen($reason) > 500) {
                throw new InvalidArgumentException('Invalid late payment request.');
            }
            $gateway = $this->GatewayManager->get($gatewayId);
            if (!$gateway || $gateway->class !== 'xmrpay' || (int)$gateway->company_id !== (int)$this->company_id) {
                throw new InvalidArgumentException('Invalid company gateway.');
            }
            $config = [];
            foreach ($gateway->meta as $field) {
                $config[$field->key] = $field->value;
            }
            foreach (['minimum_nodes', 'min_confirmations', 'index_offset', 'invoice_minutes'] as $name) {
                if (isset($config[$name])) {
                    $config[$name] = (int)$config[$name];
                }
            }
            require_once COMPONENTDIR . 'gateways' . DS . 'nonmerchant' . DS . 'xmrpay' . DS . 'XmrpayOrderStore.php';
            $store = new XmrpayOrderStore($gatewayId, $config);
            if ($resuming) {
                $store->resumeWatching($orderId, (int)$this->Session->read('blesta_staff_id'), trim($reason));
            } else {
                $store->acceptLatePayment($orderId, (int)$this->Session->read('blesta_staff_id'), trim($reason));
            }
            $this->flashMessage('message', Language::_($resuming ? 'AdminMain.!success.resumed' : 'AdminMain.!success.accepted', true));
        } catch (Throwable $error) {
            $this->flashMessage('error', Language::_($resuming ? 'AdminMain.!error.resume' : 'AdminMain.!error.accept', true));
        }
        $this->redirect($this->base_uri . 'plugin/xmrpay_watch/admin_main/');
    }
}
