<?php
// php shipsave.php <shopRoot> <method> <json post>: run Admin -> Shipping file-method save logic, print stored + effective
$root = $argv[1]; chdir($root); require $root . '/src/autoload.php';
Bootstrap::initStoreContext('/', '', 'default');
require_once $root . '/src/Entrypoints/Admin/ShippingFile.php';
$all = DataOverrides::shippingMethods()['methods'] ?? [];
$r = shippingFileMethodSettings($argv[2], json_decode($argv[3], true), $all);
if ($r['error']) { echo "ERROR: {$r['error']}\n"; exit(1); }
DataOverrides::saveShippingMethods($r['settings']); Shipping::forget();
echo json_encode($r['settings'][$argv[2]]), "\n";
$m = Shipping::withSettings(Shipping::fileConfig(), DataOverrides::shippingMethods());
foreach (['standard','express'] as $k) echo $k, ': ', json_encode(['active'=>$m['methods'][$k]['active'] ?? null, 'free'=>$m['methods'][$k]['free_above'] ?? null, 'days'=>$m['methods'][$k]['delivery_days'] ?? null, 'rates'=>$m['methods'][$k]['rates']]), "\n";
