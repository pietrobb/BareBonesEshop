<?php
/**
 * BareBones Shop — Simple class autoloader
 * Maps class names to src/{ClassName}.php files.
 */
spl_autoload_register(function (string $class) {
    $file = __DIR__ . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
