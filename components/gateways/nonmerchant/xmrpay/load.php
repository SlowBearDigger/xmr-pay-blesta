<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/xmrpay/engine/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'XmrPay\\Adapter\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/vendor/xmrpay/core/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
