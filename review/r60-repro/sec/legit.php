<?php
chdir($argv[1]); require $argv[1] . '/src/autoload.php';
$cfg = require AppConfig::path($argv[1]);
[$v, $e] = MailSettings::validate(['mode'=>'smtp','host'=>'smtp.own-provider.example','port'=>'587','encryption'=>'tls','username'=>'own@x.example','password'=>'OWN-PASS']);
echo json_encode($e), json_encode(MailSettings::save($v, $cfg, 'test')), "\n";
JobQueue::setDir($cfg['jobs_dir']);
echo JobQueue::push('invoice_issue', ['order_id' => 1]), "\n";
