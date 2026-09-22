<?php
// Check signed callbacks, serialized processing and interrupted invoice application.
if (PHP_SAPI === 'cli-server' && parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/callback') {
    header('Content-Type: application/json');
    echo json_encode(['paid' => true, 'amount' => '0.002']);
    return;
}

const DS = DIRECTORY_SEPARATOR;
class NonmerchantGateway
{
    public $Input;
    public $Record;
    public $Transactions;
    public $Invoices;
    protected function loadConfig($path) {}
    public function errors() { return $this->Input->errors; }
}
class Input {
    public $errors = [];
    public function setErrors($errors) { $this->errors = $errors; }
}
class Record {
    public static $available = true;
    public static $queries = [];
    public function query($sql, $args) { self::$queries[] = [$sql, $args]; return $this; }
    public function fetchColumn() { return self::$available ? 1 : 0; }
}
class Transactions {
    public $transaction;
    public $applied = [];
    public function getByTransactionId($id) { return $this->transaction; }
    public function getApplied($id) { return $this->applied; }
}
class Invoices {
    public $due = '1';
    public function get($id) { return (object)['due' => $this->due, 'status' => 'active']; }
}
class Loader
{
    public static function loadComponents($object, $names)
    {
        foreach ($names as $name) { $object->$name = new $name(); }
    }
    public static function loadModels($object, $names) {
        foreach ($names as $name) { if (!isset($object->$name)) $object->$name = new $name(); }
    }
}
class Language
{
    public static function loadLang($name, $language, $path) {}
}

require __DIR__ . '/../components/gateways/nonmerchant/xmrpay/xmrpay.php';

$gateway = new Xmrpay();
$gateway->setMeta(['webhook_secret' => str_repeat('a', 64)]);
$data = [
    'client' => 7,
    'amount' => '1.00',
    'currency' => 'USD',
    'invoices' => [['id' => 9, 'amount' => '1.00']],
    'xmr' => '0.002',
    'network' => 'stagenet',
    'nonce' => 'sample'
];
$encode = new ReflectionMethod(Xmrpay::class, 'encodeOrder');
$decode = new ReflectionMethod(Xmrpay::class, 'decodeOrder');
$transactionId = new ReflectionMethod(Xmrpay::class, 'transactionId');
$check = function ($condition) {
    if (!$condition) {
        throw new RuntimeException('Gateway smoke check failed');
    }
};
$id = $encode->invoke($gateway, $data);
$check($decode->invoke($gateway, $id) === $data);
$check($decode->invoke($gateway, $id . 'x') === null);
$check($decode->invoke($gateway, 'bx_invalid.' . str_repeat('0', 64)) === null);
$check(strlen($transactionId->invoke($gateway, $id)) === 67);
$check($transactionId->invoke($gateway, $id) !== $transactionId->invoke($gateway, $id . 'x'));

$quote = new ReflectionMethod(Xmrpay::class, 'quote');
$check($quote->invoke($gateway, '0.010000000000', 'XMR') === '0.01');
$check($quote->invoke($gateway, '1', 'XMR') === '1');
$gateway->setMeta(['webhook_secret' => str_repeat('a', 64), 'network' => 'stagenet']);
$gateway->Transactions = new Transactions();
$gateway->Invoices = new Invoices();
$remaining = new ReflectionMethod(Xmrpay::class, 'remainingInvoices');
$gateway->Transactions->applied = [(object)['invoice_id' => 9, 'applied_amount' => '0.4']];
$check($remaining->invoke($gateway, 1, $data['invoices']) === [['id' => 9, 'amount' => '0.600000000000']]);
$gateway->Invoices->due = '0.2';
$check($remaining->invoke($gateway, 1, $data['invoices']) === [['id' => 9, 'amount' => '0.2']]);
$gateway->Invoices->due = '0';
$check($remaining->invoke($gateway, 1, $data['invoices']) === []);
$gateway->Invoices->due = '1';
$gateway->Transactions->applied[] = (object)['invoice_id' => 9, 'applied_amount' => '0.6'];
$check($remaining->invoke($gateway, 1, $data['invoices']) === []);
$validate = new ReflectionMethod(Xmrpay::class, 'validateEvent');
$body = json_encode(['event' => 'order.paid', 'order_id' => $id, 'network' => 'stagenet', 'amount_xmr' => '0.002']);
$signature = 'sha256=' . hash_hmac('sha256', $body, str_repeat('a', 64));
if (PHP_SAPI === 'cli-server') {
    $gateway->Transactions->transaction = (object)['id' => 1, 'status' => 'approved'];
    $gateway->validate([], []);
    echo 'Callback continued into native payment processing';
    return;
}
$check($validate->invoke($gateway, $body, 'invalid') === null && http_response_code() === 400);
Record::$available = false;
$check($validate->invoke($gateway, $body, $signature) === null && http_response_code() === 503);
$check(Record::$queries[0][0] === 'SELECT GET_LOCK(?, 0)');
Record::$available = true;
$gateway->Transactions->transaction = (object)['id' => 1, 'status' => 'approved'];
http_response_code(200);
$check($validate->invoke($gateway, $body, $signature) === null && http_response_code() === 200);
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = proc_open([PHP_BINARY, '-S', $address, __FILE__], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
        if ($ready) { fclose($ready); break; }
        usleep(20000);
    }
    $check((bool)$ready);
    $curl = curl_init('http://' . $address . '/callback');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['X-XMR-Pay-Signature: ' . $signature, 'Content-Type: application/json']]);
    $ack = curl_exec($curl);
    $check($ack === '' && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200);
    $gateway->setMeta(['webhook_secret' => str_repeat('a', 64), 'network' => 'stagenet', 'agent_url' => 'http://' . $address, 'agent_token' => 'test-token']);
    $gateway->Transactions->transaction = null;
    $gateway->Transactions->applied = [];
    $response = $validate->invoke($gateway, $body, $signature);
    $check($response['status'] === 'approved' && $response['invoices'] === $data['invoices']);
    $gateway->Transactions->transaction = (object)['id' => 1, 'status' => 'approved'];
    $gateway->Transactions->applied = [(object)['invoice_id' => 9, 'applied_amount' => '0.4']];
    $response = $validate->invoke($gateway, $body, $signature);
    $check($response['invoices'] === [['id' => 9, 'amount' => '0.600000000000']]);
    $gateway->Transactions->applied[] = (object)['invoice_id' => 9, 'applied_amount' => '0.6'];
    $check($validate->invoke($gateway, $body, $signature) === null);
} finally {
    proc_terminate($server);
    proc_close($server);
}
echo "gateway signatures, amounts, lock contention, agent confirmation, interrupted retries and already-paid invoices passed\n";
