<?php
/**
 * BareBones Shop — thin admin HTTP entrypoint.
 * The implementation lives under the web-denied src tree.
 */
if (!is_file(__DIR__ . '/config.php') && is_file(__DIR__ . '/install.php')) {
    header('Location: install.php', true, 302);
    exit;
}
require __DIR__ . '/src/Entrypoints/Admin.php';
