<?php

// Review round 15, medium: content, e-mails and GDPR.
echo "\n--- Audit round 15: content, e-mails and GDPR ---\n";

// 1. The order confirmation's legal information cannot be hidden by colour: white text, the text colour equal to the
//    background or <font color> made it unreadable while the guard counted it as seen.
$r15cLocale = I18n::locale();
I18n::setLocale('en');
$r15cLegal = '{{seller_name}}, {{seller_address}} <a href="{{withdrawal_url}}">withdraw</a> <a href="{{terms_url}}">terms</a>';
$r15cRequiredPrefix = (string)strtok(I18n::t('admin.email.msg.required_vars'), '{');
$r15cRefused = static fn(string $html): bool => array_filter(EmailTemplates::validate('order_confirmation', 'Order {{order_number}}', $html, ''),
    static fn(string $e): bool => str_starts_with($e, $r15cRequiredPrefix)) !== [];
$r15cCases = [
    // refused: the text cannot be told from its background
    'color:white' => ['<p style="color:white">' . $r15cLegal . '</p>', true],
    'color:#FFF' => ['<div style="font-size:13px; COLOR: #FFF">' . $r15cLegal . '</div>', true],
    'same text and background' => ['<p style="color:#2b6cb0;background-color:#2b6cb0">' . $r15cLegal . '</p>', true],
    'font color' => ['<font color="#ffffff">' . $r15cLegal . '</font>', true],
    'font color named' => ['<font color=snow>' . $r15cLegal . '</font>', true],
    'near white' => ['<p style="color:#f4f4f4">' . $r15cLegal . '</p>', true],
    'transparent rgba' => ['<p style="color:rgba(0,0,0,.04)">' . $r15cLegal . '</p>', true],
    'hsl white' => ['<p style="color:hsl(0 0% 100%)">' . $r15cLegal . '</p>', true],
    'entity-encoded colour' => ['<p style="color:&#x23;fff">' . $r15cLegal . '</p>', true],
    'class rule' => ['<style>.fine { color: #fefefe }</style><p class="note fine">' . $r15cLegal . '</p>', true],
    'body rule' => ['<style>body { color: #fff }</style>' . $r15cLegal, true],
    'child rule' => ['<style>.box > p { color: #fff }</style><div class="box"><p>' . $r15cLegal . '</p></div>', true],
    '!important rule over inline' => ['<style>p { color: #fff !important }</style><p style="color:#000">' . $r15cLegal . '</p>', true],
    'text-fill transparent' => ['<p style="-webkit-text-fill-color:transparent">' . $r15cLegal . '</p>', true],
    'unknown colour (var)' => ['<p style="color:var(--x)">' . $r15cLegal . '</p>', true],
    'dark cell, dark text' => ['<table><tr><td bgcolor="#000000"><span style="color:#111">' . $r15cLegal . '</span></td></tr></table>', true],
    'default link blue on a dark cell' => ['<table><tr><td bgcolor="#1a202c"><font color="#fff">' . $r15cLegal . '</font></td></tr></table>', true],
    // accepted: readable
    'plain' => ['<p>' . $r15cLegal . '</p>', false],
    'grey footer' => ['<p style="color:#666;font-size:12px">' . $r15cLegal . '</p>', false],
    'white on a dark cell' => ['<table><tr><td bgcolor="#1a202c"><font color="#fff">' . str_replace('<a ', '<a style="color:#90cdf4" ', $r15cLegal) . '</font></td></tr></table>', false],
    'white on a dark rule background' => ['<style>.dark td { background: #000 url(x.png) no-repeat; color: #fff }</style><table class="dark"><tr><td>' . $r15cLegal . '</td></tr></table>', false],
    'readable child of a white parent' => ['<div style="color:#fff"><p style="color:#222">' . $r15cLegal . '</p></div>', false],
    'hover colour only' => ['<style>a:hover { color: #fff }</style><p>' . $r15cLegal . '</p>', false],
];
$r15cGot = $r15cWant = [];
foreach ($r15cCases as $r15cName => [$r15cHtml, $r15cRefuse]) {
    $r15cWant[$r15cName] = $r15cRefuse;
    $r15cGot[$r15cName] = $r15cRefused($r15cHtml);
}
assert_equals($r15cWant, $r15cGot, 'R15 e-mail guard: legal text in a colour that cannot be read against its background counts as missing');
$r15cWhiteLink = str_replace('<a href="{{withdrawal_url}}">', '<a href="{{withdrawal_url}}" style="color:#fff">', '<p>' . $r15cLegal . '</p>');
assert_true($r15cRefused($r15cWhiteLink), 'R15 e-mail guard: a withdrawal link in white text does not count as the link');
I18n::setLocale($r15cLocale);
assert_equals([21.0, 1.0, true], [round(CssColor::contrast(CssColor::BLACK, CssColor::WHITE), 2), round(CssColor::contrast(CssColor::WHITE, CssColor::WHITE), 2),
    CssColor::contrast((array)CssColor::parse('#fff'), (array)CssColor::parse('#4299e1')) >= CssColor::MIN_CONTRAST],
    'R15 e-mail guard: WCAG contrast (black/white 21, same 1; the shipped white-on-blue button stays readable)');

// 2. A legal text the merchant edits announces the day of the edit: the editor starts from the shipped text and its
//    <!-- legal-updated --> date, so an edited page kept the shipped date. Changing the seller details the pages print
//    dates them too, and a store-wide legal.updated_at no longer hides a newer text.
$r15cTmp = test_fixture_path('r15-content');
mkdir($r15cTmp . '/data', 0777, true);
try {
    copy(dirname(TEST_CLI_DIR) . '/stores.example.json', $r15cTmp . '/stores.json');
    StoreSettings::setPathForTesting($r15cTmp . '/stores.json');
    DataOverrides::setDirForTesting($r15cTmp . '/data');
    $r15cToday = date('Y-m-d');
    $r15cShippedHtml = (string)file_get_contents(ConsumerNotice::legalTemplatePath('terms', 'en', 'default'));
    preg_match('/<!--\s*legal-updated:\s*(\d{4}-\d{2}-\d{2})\s*-->/', $r15cShippedHtml, $r15cShipped);
    $r15cDateShown = static fn(string $date, array $legal = []): bool => str_contains(ConsumerNotice::renderLegal('terms', 'en', ['name' => 'R15C s.r.o.'] + $legal, 'default'), I18n::date($date, 'en'));
    assert_true(isset($r15cShipped[1]) && $r15cShipped[1] < $r15cToday && $r15cDateShown($r15cShipped[1]), 'R15 legal date fixture: the shipped terms show their own older date');

    $r15cEdited = str_replace('</h1>', "</h1>\n<p>R15C extra clause.</p>", $r15cShippedHtml);
    assert_true(DataOverrides::saveLegal('terms', 'en', ConsumerNotice::stampLegalText('terms', 'en', $r15cEdited, 'default'), 'default')
        && $r15cDateShown($r15cToday) && !$r15cDateShown($r15cShipped[1]), 'R15 legal date: the edited terms show the day of the edit, not the shipped date');

    $r15cOld = "<!-- legal-updated: 2026-01-15 -->\n<h1>R15C terms of {{name}}</h1>\n<p>{{updated_at}}</p>\n";
    DataOverrides::saveLegal('terms', 'en', $r15cOld, 'default');
    assert_equals([$r15cOld, $r15cOld], [ConsumerNotice::stampLegalText('terms', 'en', $r15cOld, 'default'),
        ConsumerNotice::stampLegalText('terms', 'en', "<h1>R15C terms of {{name}}</h1>\r\n<p>{{updated_at}}</p>\r\n", 'default')],
        'R15 legal date: saving the text unchanged keeps its date (also without the marker or with CRLF)');
    assert_true(str_starts_with(ConsumerNotice::stampLegalText('terms', 'en', "<!-- legal-updated: 2020-01-01 -->\n<h1>R15C changed {{name}}</h1>", 'default'), "<!-- legal-updated: {$r15cToday} -->\n<h1>R15C changed"),
        'R15 legal date: a typed-in old marker does not back-date a changed text');
    assert_equals(['', '  '], [ConsumerNotice::stampLegalText('terms', 'en', '', 'default'), ConsumerNotice::stampLegalText('terms', 'en', '  ', 'default')],
        'R15 legal date: an empty text stays empty (reset to the shipped text)');
    assert_equals('x', ConsumerNotice::stampLegalText('../../config', 'en', 'x', 'default'), 'R15 legal date: an unknown page is not read or stamped');
    assert_equals([true, true], [$r15cDateShown('2026-01-15', ['updated_at' => '2020-01-01']), $r15cDateShown('2026-02-01', ['updated_at' => '2026-02-01'])],
        'R15 legal date: the page shows the later of its text date and the store\'s legal.updated_at');

    $r15cLegal = StoreSettings::load()['stores']['default']['legal'];
    $r15cInput = ['store_name' => 'R15C Shop'] + array_map('strval', array_filter($r15cLegal, 'is_scalar')) + ['payment_terms_days' => '14', 'vat_registered' => '1'];
    $r15cInput['iban'] = 'SK31 1200 0000 1987 4263 7541';
    $r15cSaved = StoreSettings::save('default', ['street' => 'Nová 15'] + $r15cInput);
    assert_true($r15cSaved['ok'] && (StoreSettings::load()['stores']['default']['legal']['updated_at'] ?? null) === $r15cToday,
        'R15 legal date: changing the seller address dates the legal pages (' . implode(' ', $r15cSaved['errors']) . ')');
    $r15cData = json_decode((string)file_get_contents($r15cTmp . '/stores.json'), true);
    $r15cData['stores']['default']['legal']['updated_at'] = '2020-01-01';
    file_put_contents($r15cTmp . '/stores.json', json_encode($r15cData));
    StoreSettings::save('default', ['street' => 'Nová 15', 'bic' => 'TATRSKBX'] + $r15cInput);
    assert_equals('2020-01-01', StoreSettings::load()['stores']['default']['legal']['updated_at'] ?? null, 'R15 legal date: a bank detail the pages do not print does not re-date them');
} finally {
    StoreSettings::setPathForTesting(null);
    DataOverrides::setDirForTesting(null);
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r15cTmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r15cItem) {
        $r15cItem->isDir() ? @rmdir($r15cItem->getPathname()) : @unlink($r15cItem->getPathname());
    }
    @rmdir($r15cTmp);
}
