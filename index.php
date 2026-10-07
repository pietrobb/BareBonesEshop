<?php
/**
 * BareBones Shop — thin storefront HTTP entrypoint.
 * The implementation lives under the web-denied src tree.
 */
require_once __DIR__ . '/src/autoload.php';
UrlAliases::redirectInternal();
require __DIR__ . '/src/Entrypoints/Storefront.php';
