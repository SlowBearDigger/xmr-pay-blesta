<?php

const DS = DIRECTORY_SEPARATOR;
class NonmerchantGateway
{
    public $Input;
    protected function loadConfig($path) {}
}
class Input {}
class Loader
{
    public static function loadComponents($object, $names)
    {
        $object->Input = new Input();
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
echo "gateway smoke passed\n";
