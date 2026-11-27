<?php
chdir($argv[1]); require $argv[1] . '/src/autoload.php';
$log = $argv[2]; @unlink($log); ini_set('error_log', $log);
$msgs = [
 'email' => "Duplicate entry 'jan.novak+shop@firma.sk-default' for key 'customers.email'",
 'email_idn' => 'payer email Ján@příklad.cz rejected',
 'email_upper' => 'Invalid email JAN@EXAMPLE.COM',
 'iban' => 'Bank transfer refused for IBAN SK31 1200 0000 1987 4263 7541 (BIC TATRSKBX)',
 'iban_compact' => 'IBAN SK3112000000198742637541 invalid',
 'iban_de' => 'payout to DE89370400440532013000 failed',
 'stripe_key' => 'Invalid API Key provided: sk_' . 'live_51HxAbCdEfGhIjKlMnOpQrStUv',
 'stripe_pk' => 'key pk_live_51HxAbCdEfGhIjKlMnOpQr',
 'stripe_rk' => 'restricted rk_live_abcdefgh12345678',
 'gopay_secret' => 'GoPay OAuth failed: client_secret=AbC123xyz grant_type=client_credentials',
 'bearer' => 'HTTP 401 Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc',
 'jwt_bare' => 'token rejected eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
 'password_kv' => 'SQLSTATE[HY000] [1045] Access denied for user shop@10.0.0.5 (using password: YES) password=Hunter2 secret',
 'password_json' => '{"username":"shop","password":"correct horse battery staple"}',
 'url_pw' => 'connect mysql://shop:S3cr3t!pw@db.internal:3306/shop failed',
 'card' => 'card 4242 4242 4242 4242 exp 12/30 cvc=123',
 'card_compact' => 'pan 5555555555554444 declined',
 'phone' => 'Packeta refused phone +421 905 123 456 for Ján Novák, Hlavná 5, 917 01 Trnava',
 'name_addr' => "Incorrect string value: '\\xF0\\x9F\\x98\\x80 J...' for column 'customer_name' (Ján Novák)",
 'apikey_hdr' => 'X-Api-Key: 9f8e7d6c5b4a39281706f5e4d3c2b1a0',
 'apikey_kv' => 'api_key: 9f8e7d6c5b4a39281706f5e4d3c2b1a0',
 'webhook_secret' => 'whsec_abcdefghijklmnop mismatch',
 'hex_secret_bare' => 'signature mismatch, expected 6b86b273ff34fce19d6b804eff5a3f5747ada4eaa22f1d49c01e52ddb7875b4b',
 'session' => 'Cookie: PHPSESSID=abc123def456; bbe_cart=xyz',
 'csrf' => 'csrf_token mismatch 0123456789abcdef0123456789abcdef',
 'download_token' => 'GET /download.php?token=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
 'ico_dic' => 'VAT ID SK2020123456 / ICO 12345678 not valid',
 'newline' => "boom\nFAKE LOG LINE level=critical",
 'ip' => 'from 203.0.113.42 / 2001:db8::1',
];
foreach ($msgs as $k => $m) {
    $e = new RuntimeException($m);
    Logger::phpLog('Checkout failed [cid] ' . get_debug_type($e) . ' @' . basename($e->getFile()) . ':' . $e->getLine() . ': ' . $k . ' :: ' . $e->getMessage());
}
