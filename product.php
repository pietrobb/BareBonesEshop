<?php
/** Clean product URL adapter; rendering is owned by the canonical storefront route. */
require_once __DIR__ . '/src/autoload.php';
UrlAliases::redirectInternal();
$_GET['page'] = 'product';
require __DIR__ . '/src/Entrypoints/Storefront.php';
