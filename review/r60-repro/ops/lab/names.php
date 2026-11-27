<?php
$root = $argv[1]; chdir($root); require $root . '/src/autoload.php';
Bootstrap::initStoreContext('/', '', 'default');
require_once $root . '/src/Entrypoints/Admin/Operations.php';
foreach (["EU zóna", "EU\u{2028}zóna", "\u{200B}", "\u{E000}\u{E001}", "Zóna – EU (DPD)", "Zone\tEU", " Packeta\u{00A0}", "Kurýr <b>DPD</b>", "Zásielkovňa 📦"] as $n) {
    $_POST['name'] = $n;
    echo json_encode($n, JSON_UNESCAPED_UNICODE), ' -> ', json_encode(postedMethodName(), JSON_UNESCAPED_UNICODE), "\n";
}
