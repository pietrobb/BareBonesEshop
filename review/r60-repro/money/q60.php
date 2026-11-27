<?php
require __DIR__ . '/lib50.php';
$db = r50_boot($argv[1], $argv[2], false);
foreach ($db->query($argv[3])->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r, JSON_UNESCAPED_SLASHES), "\n";
