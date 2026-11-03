<?php
/**
 * BareBones Shop — Simple class autoloader
 * Maps class names to src/{ClassName}.php files.
 */
spl_autoload_register(function (string $class) {
    // src/ classes (Db, Products, Orders, etc.)
    $file = __DIR__ . '/' . $class . '.php';
    if (file_exists($file)) { require_once $file; return; }

    // Payment capability interfaces are declared next to PaymentAdapter.
    if ($class === 'IdempotentRefundAdapter' || $class === 'HostedCheckoutAdapter') {
        require_once __DIR__ . '/../adapters/PaymentAdapter.php';
        return;
    }

    // Adapter interfaces (PaymentAdapter, StorageAdapter, etc.)
    $file = __DIR__ . '/../adapters/' . $class . '.php';
    if (file_exists($file)) { require_once $file; return; }

    // Adapter implementations (StripePayment, LocalStorage, etc.)
    $file = __DIR__ . '/../adapters/contrib/' . $class . '.php';
    if (file_exists($file)) { require_once $file; return; }
});

// cli/update.php apply/rollback in progress: no web request runs half-updated code (T20).
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && MaintenanceMode::active(dirname(__DIR__))) MaintenanceMode::respond();
