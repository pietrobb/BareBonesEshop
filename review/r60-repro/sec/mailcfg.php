<?php
chdir($argv[1]); require $argv[1] . '/src/autoload.php';
require_once $argv[1] . '/adapters/MailAdapter.php'; require_once $argv[1] . '/adapters/contrib/SmtpMail.php';
$cfg = require AppConfig::path($argv[1]);
echo 'mail.json: ', MailSettings::path($cfg), ' exists=', var_export(is_file((string)MailSettings::path($cfg)), true), "\n";
echo 'provider: ', MailSettings::providerCode($cfg), "\n";
$m = new SmtpMail($cfg); $r = new ReflectionObject($m);
foreach (['host','port','username','password','encryption'] as $p) { $x = $r->getProperty($p); $x->setAccessible(true); echo "  $p=", $x->getValue($m), "\n"; }
