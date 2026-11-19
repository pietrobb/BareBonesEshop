<?php
$pdo ??= test_commerce_fixture();

// Review round 19 (R49, low): the VIES holder check refused real holders — Austrian titles ("Mag. pharm.", "DI"), names
// of initials ("J.K. s.r.o."), words written together, "L'ORÉAL", Greek script — and let others through: "Novák Peter"
// for the company "Peter Novák s.r.o.", the first name "Ján" alone for "Ing. Ján Novák".
echo "\n--- R49 VAT: holder names — titles, initials, joined words, apostrophes, Greek; no reversed or partial person ---\n";

$pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'ATU4949%' OR vat_id LIKE 'SK4949%' OR vat_id LIKE 'FR4949%' OR vat_id LIKE 'EL4949%' OR vat_id LIKE 'DE4949%'");
try {
    $r49vCache = $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, ?, 'valid', ?, ?, 86400)");
    $r49vHolders = [
        'ATU49494901' => ['AT', 'Anna Huber'],
        'ATU49494902' => ['AT', 'Hans Gruber'],
        'SK4949494903' => ['SK', 'J.K. s.r.o.'],
        'DE494949404' => ['DE', 'Media Markt GmbH'],
        'FR49494949405' => ['FR', "L'ORÉAL SA"],
        'EL494949406' => ['EL', 'ΠΑΠΑΔΟΠΟΥΛΟΣ ΗΛΙΑΣ'],
        'SK4949494907' => ['SK', 'Peter Novák s.r.o.'],
        'SK4949494908' => ['SK', 'Ing. Ján Novák'],
        'SK4949494909' => ['SK', 'ACME spol. s r.o.'],
    ];
    foreach ($r49vHolders as $r49vId => [$r49vCountry, $r49vName]) $r49vCache->execute([$r49vId, $r49vCountry, $r49vName, gmdate('Y-m-d H:i:s')]);
    $r49vGot = [];
    foreach ([
        'ATU49494901' => ['Mag. pharm. Anna Huber', 'Mag.pharm. Anna Huber', 'Anna Huber'],
        'ATU49494902' => ['DI Hans Gruber', 'DI (FH) Hans Gruber'],
        'SK4949494903' => ['J.K. s.r.o.', 'J. K. s.r.o.', 'JK s.r.o.', 'X.Y. s.r.o.'],
        'DE494949404' => ['MediaMarkt GmbH', 'Media Markt', 'Media Saturn GmbH'],
        'FR49494949405' => ["L'Oréal", 'L’Oréal SA', 'LOREAL', 'Garnier SA'],
        'EL494949406' => ['Papadopoulos Ilias'],
        'SK4949494907' => ['Novák Peter', 'Peter Novák s.r.o.', 'Peter Novák'],
        'SK4949494908' => ['Ján', 'Ján Novák', 'Novák Ján', 'Ing. Ján Novák'],
        'SK4949494909' => ['ACME s.r.o.', 'ACME spol. s r.o.', 'ACME'],
    ] as $r49vId => $r49vNames) {
        foreach ($r49vNames as $r49vName) $r49vGot[$r49vId . ' ' . $r49vName] = VatService::vatIdHolderMismatch($r49vId, $r49vName);
    }
    assert_equals([
        'ATU49494901 Mag. pharm. Anna Huber' => null, 'ATU49494901 Mag.pharm. Anna Huber' => null, 'ATU49494901 Anna Huber' => null,
        'ATU49494902 DI Hans Gruber' => null, 'ATU49494902 DI (FH) Hans Gruber' => null,
        'SK4949494903 J.K. s.r.o.' => null, 'SK4949494903 J. K. s.r.o.' => null, 'SK4949494903 JK s.r.o.' => null, 'SK4949494903 X.Y. s.r.o.' => 'name',
        'DE494949404 MediaMarkt GmbH' => null, 'DE494949404 Media Markt' => null, 'DE494949404 Media Saturn GmbH' => 'name',
        "FR49494949405 L'Oréal" => null, 'FR49494949405 L’Oréal SA' => null, 'FR49494949405 LOREAL' => null, 'FR49494949405 Garnier SA' => 'name',
        'EL494949406 Papadopoulos Ilias' => null,
        'SK4949494907 Novák Peter' => 'name', 'SK4949494907 Peter Novák s.r.o.' => null, 'SK4949494907 Peter Novák' => null,
        'SK4949494908 Ján' => 'name', 'SK4949494908 Ján Novák' => null, 'SK4949494908 Novák Ján' => null, 'SK4949494908 Ing. Ján Novák' => null,
        'SK4949494909 ACME s.r.o.' => null, 'SK4949494909 ACME spol. s r.o.' => null, 'SK4949494909 ACME' => null,
    ], $r49vGot, 'R49 VIES holder: Austrian titles, initials, joined words, apostrophes and Greek match; a reversed company name or a lone first name does not');

    // R49 (low): VIES not answering is explained at checkout (was: home VAT without a word).
    $r49vView = CheckoutService::residenceView(['context' => ['seller_country' => 'SK'], 'scenario' => ['scenario' => VatService::SCENARIO_DOMESTIC]],
        ['vat_id_status' => 'unverified'], ['SK' => 'Slovensko'], '');
    assert_true(str_contains($r49vView['vat_reason'], I18n::t('checkout.vat_reason.vat_id_unverified')),
        'R49 VAT: an unverified VAT ID says why VAT is charged — ' . $r49vView['vat_reason']);
} catch (Throwable $r49vE) {
    assert_true(false, 'R49 VAT name (' . get_class($r49vE) . ': ' . $r49vE->getMessage() . ' @ ' . basename($r49vE->getFile()) . ':' . $r49vE->getLine() . ')');
} finally {
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'ATU4949%' OR vat_id LIKE 'SK4949%' OR vat_id LIKE 'FR4949%' OR vat_id LIKE 'EL4949%' OR vat_id LIKE 'DE4949%'");
}
