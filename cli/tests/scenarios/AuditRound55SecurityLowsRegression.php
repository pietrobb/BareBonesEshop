<?php

// Review round 20 (R55), security lows:
//  - VIES: after the title was dropped for a sole trader's trade ("Ing. Ján Novák - STAVBY"), the first name alone matched.
//  - API v1 PATCH checked the name before the bidi controls were stripped: "\u{202E}" alone was saved as an empty name.
//  - reviews, returns and complaints stored text that is not UTF-8 (shown empty by esc()).
//  - the product editor, B2B companies and the customer profile let bidi controls / U+200E, U+200F, U+061C through.
//  - the log masked every 00-number as a phone: EANs, variable symbols and tracking numbers were lost.
//  - the doctor passed any five words as operations.worker_cron, and a line running another script.

$pdo ??= test_commerce_fixture();
$r55sCall = static function (string $class, string $method, array $args) {
    $m = new ReflectionMethod($class, $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
};

// --- VIES: a sole trader is matched by first name and surname, not the first name alone ------------------------------------
$pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'SK5555%'");
try {
    $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, 'SK', 'valid', ?, ?, 86400)")
        ->execute(['SK5555555501', 'Ing. Ján Novák - STAVBY', gmdate('Y-m-d H:i:s')]);
    assert_equals(['name', 'name', null], [
        VatService::vatIdHolderMismatch('SK5555555501', 'Ján'),
        VatService::vatIdHolderMismatch('SK5555555501', 'Ing. Ján'),
        VatService::vatIdHolderMismatch('SK5555555501', 'Ján Novák'),
    ], 'R55 VIES: "Ján" or "Ing. Ján" is not the holder "Ing. Ján Novák - STAVBY" (was: matched, no VAT charged); "Ján Novák" is');
} finally {
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'SK5555%'");
}

// --- API v1 PATCH: the name is checked as stored ----------------------------------------------------------------------------
$r55sPatch = static function (string $name) use ($r55sCall): string {
    try {
        $r55sCall(ApiV1::class, 'patchProduct', [1, ['name' => $name], 'r55s-key', [], '/api/v1/products/1']);
        return 'accepted';
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    } catch (Throwable $e) {
        return 'past validation';
    }
};
assert_equals(['Invalid product name', 'Invalid product name'], [$r55sPatch("\u{202E}\u{2066}"), $r55sPatch("Kniha \xC3\x28")],
    'R55 API: a name of bidi controls only, or not UTF-8, is refused (422; was: saved empty)');

// --- Text that is not UTF-8 is no text --------------------------------------------------------------------------------------
assert_equals(['', 'Ján', ''], [Security::typedText("\xC3\x28 recenzia"), Security::typedText(" Ján\u{200F} "), Security::typedText("\u{202E}")],
    'R55 typed text: not UTF-8 or bidi controls only is empty, so the required check refuses it');
$r55sProduct = (int)$pdo->query("SELECT id FROM products WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
assert_equals(['body_too_short', 'author_required'], [
    Reviews::validationError($r55sProduct, ['rating' => 5, 'author_name' => 'Ján', 'body' => "Výborný produkt \xC3\x28 odporúčam."]),
    Reviews::validationError($r55sProduct, ['rating' => 5, 'author_name' => "Ján \xFF", 'body' => 'Výborný produkt, odporúčam.']),
], 'R55 reviews: a body or name that is not UTF-8 is refused (was: stored, shown empty)');

// --- Product editor, B2B companies, customer profile ------------------------------------------------------------------------
assert_true(is_string(ProductEditor::validate(['name' => "\u{202E}\u{200E}", 'slug' => 'r55s-x'] + array_fill_keys(['sku', 'price', 'images', 'gtin', 'mpn', 'seo_title', 'meta_description', 'category_id', 'brand_id'], ''), [], 0, null)),
    'R55 product editor: a name of bidi controls only is refused (was: saved, an empty name in the shop)');
$r55sB2b = static function (string $value) use ($r55sCall): string {
    try {
        return (string)$r55sCall(B2bAdmin::class, 'text', [$value, true, 'Company']);
    } catch (DomainException $e) {
        return 'refused';
    }
};
assert_equals(['refused', 'refused', 'refused', 'Novák s.r.o.'], [$r55sB2b("Novák\u{202E} s.r.o."), $r55sB2b("Novák\u{200F} s.r.o."), $r55sB2b("Novák \xC3\x28"), $r55sB2b('Novák s.r.o.')],
    'R55 B2B: bidi controls, directional marks and broken UTF-8 in a company field are refused (was: saved)');
assert_equals(['first_name' => 'account.err_invalid_text', 'last_name' => 'account.err_invalid_text'],
    $r55sCall(Customers::class, 'invalidTextErrors', [['first_name' => "Ján\u{200E}", 'last_name' => "Novák\u{061C}", 'city' => 'Košice'], ['first_name', 'last_name', 'city']]),
    'R55 profile: the directional marks U+200E/U+200F/U+061C are refused as the bidi controls are (was: saved)');

// --- Log masking: a 00-number counts as a phone written in groups only ------------------------------------------------------
$r55sLog = Logger::redact(['detail' => 'EAN 0012345678905, VS 0012345678, tracking 00340434161094042557, tel. 00421 905 123 456']);
$r55sText = (string)$r55sLog['detail'];
assert_true(str_contains($r55sText, '0012345678905') && str_contains($r55sText, 'VS 0012345678') && str_contains($r55sText, '00340434161094042557')
    && !str_contains($r55sText, '905 123'), "R55 log: EAN, variable symbol and tracking number stay, the phone is masked ({$r55sText}; was: all [phone])");

// --- Doctor: operations.worker_cron is a valid schedule, alone or running cli/worker.php -------------------------------------
$r55sCron = static fn(string $cron): bool => (bool)$r55sCall(Setup::class, 'workerCronValid', [$cron]);
assert_equals([true, true, true, true, false, false, false, false, false], [
    $r55sCron('* * * * * php /srv/shop/cli/worker.php'),
    $r55sCron('*/2 8-18 * jan-dec mon-fri'),
    $r55sCron('@hourly php /srv/shop/cli/worker.php --max-jobs=50'),
    $r55sCron('0,30 * 1 1 0'),
    $r55sCron('a b c d e'),
    $r55sCron('61 * * * *'),
    $r55sCron('* * * * * php /srv/shop/cli/backup.php'),
    $r55sCron('*/0 * * * *'),
    $r55sCron('@reboot php /srv/shop/cli/worker.php'),
], 'R55 doctor: only a valid schedule, alone or running worker.php, passes (was: any five words, and another script)');
