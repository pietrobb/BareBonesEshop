<?php
/**
 * BareBones Shop — thin storefront HTTP entrypoint.
 * The implementation lives under the web-denied src tree.
 */
require_once __DIR__ . '/src/autoload.php';
// A package uploaded without config.php starts with the web installer.
if (!is_file(__DIR__ . '/config.php') && is_file(__DIR__ . '/install.php')) {
    header('Location: install.php', true, 302);
    exit;
}
WebWorker::afterResponse();
UrlAliases::redirectInternal();
require __DIR__ . '/src/Entrypoints/Storefront.php';
