<?php
$root = $argv[1]; chdir($root); require $root . '/src/autoload.php';
Bootstrap::initStoreContext('/', '', 'default');
foreach (glob($root . '/src/Entrypoints/Admin/*.php') as $f) if (basename($f) === 'ShippingFile.php') require_once $f;
if (!function_exists('esc')) { function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES); } }
ob_start(); shippingFileMethods(); $h = ob_get_clean();
echo strlen($h), " bytes; edited-flag=", substr_count($h, 'data-shipping-file-edited'), "\n";
preg_match_all('/name="delivery_days"[^>]*value="([^"]*)"|value="([^"]*)"[^>]*name="delivery_days"/', $h, $m); echo 'delivery_days fields: ', json_encode(array_filter(array_merge($m[1], $m[2]))), "\n";
