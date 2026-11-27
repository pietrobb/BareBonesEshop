<?php
$db = new PDO('sqlite:' . getenv('DB')); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (array_slice($argv, 1) as $q) { $st = $db->query($q); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) { echo "(none)\n"; continue; } echo implode("\t", array_keys($rows[0])), "\n"; foreach ($rows as $r) echo implode("\t", array_map(fn($v)=>mb_strimwidth((string)$v,0,60,'…'), $r)), "\n"; }
