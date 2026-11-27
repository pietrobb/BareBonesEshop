<?php
require $argv[1] . '/src/autoload.php';
$log = $argv[2]; @unlink($log); ini_set('error_log', $log);
$r = new ReflectionMethod('CheckoutService','logSafeMessage'); $r->setAccessible(true);
$input = [
 'customer_name' => 'Ján Novák',
 'billing_street' => 'Hlavná 5',
 'billing_city' => 'Trnava',
 'billing_zip' => '917 01',
 'email' => 'jan.novak@firma.sk',
 'phone' => '+421 905 123 456',
 'vat_id' => 'SK2020123456',
 'notes' => 'Leave at reception, code 4711',
 'company' => 'Woodle s.r.o.',
 'card_token' => 'tok_1HxAbCdEfGhIjKlMnOpQrStUv',
 'return_url' => 'https://shop.example/pay?session=cs_live_51HxAbCdEfGhIjKlMnOpQrStUvWx&download=0123456789abcdef0123456789abcdef',
];
// Messages that could embed the typed values or tokens
$msgs = [
 'addr_in_msg' => 'Packeta refused: Ján Novák, Hlavná 5, 917 01 Trnava',
 'note_in_msg' => "Validation failed for notes: Leave at reception, code 4711",
 'company_only' => 'Insert failed for company Woodle s.r.o. duplicate',
 'session_qs' => 'Redirect built https://shop.example/pay?session=cs_live_51HxAbCdEfGhIjKlMnOpQrStUvWx&x=1',
 'download_tok' => 'bad url /download.php?token=0123456789abcdef0123456789abcdef',
 'email_plain' => 'customer jan.novak@firma.sk not found',
 'phone_plain' => 'phone +421 905 123 456 invalid',
 'iban_plain' => 'IBAN SK3112000000198742637541 refused',
 'vat_plain' => 'VAT SK2020123456 / ICO 12345678 mismatch',
 'card_tok' => 'card token tok_1HxAbCdEfGhIjKlMnOpQrStUv declined',
 'name_glued' => 'column customer_name value "Ján Novák" too long',
 'zip_city' => 'geo lookup 917 01 Trnava failed',
];
foreach ($msgs as $k=>$m){
  $safe = $r->invoke(null, $m, $input);
  Logger::phpLog("Checkout failed [cid] RuntimeException @X.php:1: $k :: $safe");
}
