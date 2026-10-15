<?php
declare(strict_types=1);

class ClientMain extends AppController
{
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();
        $this->uses(['PluginManager']);
        $this->components(['Record']);
    }

    public function status()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            header('Allow: GET');
            return $this->respond(405);
        }
        $rawId = $this->get[0] ?? null;
        $orderId = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_string($rawId) || !$orderId || (string)$orderId !== $rawId) {
            return $this->respond(404);
        }
        $grants = $this->Session->read('xmrpay_checkout');
        $grant = is_array($grants) ? ($grants[$orderId] ?? null) : null;
        $companyId = (int)Configure::get('Blesta.company_id');
        $clientId = $this->Session->read('blesta_client_id');
        if (!is_array($grant) || ($grant['company_id'] ?? null) !== $companyId
            || !is_int($grant['client_id'] ?? null) || $grant['client_id'] < 1
            || !is_int($grant['gateway_id'] ?? null) || $grant['gateway_id'] < 1
            || ($clientId && (int)$clientId !== $grant['client_id'])) {
            return $this->respond(404);
        }
        try {
            $plugins = $this->PluginManager->getByDir('xmrpay_watch', $companyId);
            if (empty($plugins[0]->enabled)) {
                return $this->respond(404);
            }
            $order = $this->Record->query("SELECT orders.status, orders.state, orders.transaction_id
                FROM gateway_xmrpay_orders orders
                INNER JOIN gateways ON gateways.id = orders.gateway_id
                    AND gateways.company_id = orders.company_id AND gateways.class = 'xmrpay'
                WHERE orders.id = ? AND orders.company_id = ? AND orders.client_id = ? AND orders.gateway_id = ?", [
                $orderId, $companyId, $grant['client_id'], $grant['gateway_id']
            ])->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                return $this->respond(404);
            }
            $status = 'review';
            $transactionId = filter_var($order['transaction_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($order['status'] === 'paid' && $transactionId) {
                $status = 'paid';
            } elseif ($order['status'] === 'pending') {
                $state = json_decode($order['state'], true);
                $invoiceStatus = is_array($state) ? ($state['invoice_status'] ?? null) : null;
                if ($invoiceStatus === 'created') {
                    $fundedAt = $state['funded_at'] ?? null;
                    $fundedAmount = $state['funded_amount_atomic'] ?? null;
                    $now = time();
                    if (($state['scan_complete'] ?? null) === false
                        && is_int($fundedAt) && $fundedAt > 0 && $fundedAt <= $now
                        && is_string($fundedAmount) && preg_match('/^[1-9][0-9]*$/D', $fundedAmount)) {
                        $status = 'detected';
                    } elseif ($fundedAt === null && $fundedAmount === null) {
                        $status = 'waiting';
                        $expiresAt = $state['expires_at'] ?? null;
                        if (is_int($expiresAt) && $expiresAt > 0 && $expiresAt <= $now) {
                            $status = 'review';
                        }
                    }
                } elseif ($invoiceStatus === 'processing') {
                    $status = 'detected';
                }
            }
            return $this->respond(200, ['status' => $status]);
        } catch (Throwable $error) {
            return $this->respond(503);
        }
    }

    private function respond(int $code, ?array $body = null)
    {
        http_response_code($code);
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=UTF-8');
        echo $this->outputAsJson($body ?? ['error' => 'Payment status is unavailable.']);
        return false;
    }
}
