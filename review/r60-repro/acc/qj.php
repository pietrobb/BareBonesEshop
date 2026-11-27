<?php
$db = new PDO('sqlite:' . getenv('DB')); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (array_slice($argv, 1) as $q) foreach ($db->query($q)->fetchAll(PDO::FETCH_ASSOC) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "\n";
