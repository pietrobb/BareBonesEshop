<?php
/** Clean product URL adapter; rendering is owned by the canonical storefront route. */
$_GET['page'] = 'product';
require __DIR__ . '/src/Entrypoints/Storefront.php';
