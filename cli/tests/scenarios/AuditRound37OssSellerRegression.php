<?php

echo "\n--- R37 OSS seller: turnover and crossing lock use the invoice's legal seller ---\n";
$r37sOriginalDb = isset($pdo) && $pdo instanceof PDO ? $pdo : null;
$r37sStores = Bootstrap::stores();
$r37sContext = StoreContext::current();
$r37sProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r37sRoot = $r37sStores;
$r37sDefault = $r37sRoot['stores']['default'];
$r37sDefault['oss_enabled'] = false;
$r37sDefault['legal']['country'] = 'SK';
$r37sDefault['legal']['seller'] = 'default';
$r37sOutlet = $r37sDefault;
unset($r37sOutlet['legal']);
$r37sExplicit = $r37sDefault;
$r37sExplicit['legal'] = ['seller' => 'default', 'name' => 'Same legal seller'];
$r37sOther = $r37sDefault;
$r37sOther['legal']['seller'] = 'other';
// R38 (medium 5): another seller is another VAT payer; the same IČ DPH would share the threshold.
$r37sOther['legal']['ic_dph'] = 'SK2029999999';
$r37sRoot['default_store'] = 'default';
$r37sRoot['stores'] = ['default' => $r37sDefault, 'outlet' => $r37sOutlet, 'explicit' => $r37sExplicit, 'other' => $r37sOther];
$r37sProp->setValue(null, $r37sRoot);
try {
    foreach (['sqlite', 'mysql'] as $r37sDriver) {
        if ($r37sDriver === 'mysql' && !getenv('TEST_MYSQL_DSN')) { print "  [SKIP] R37 OSS seller native MySQL (set TEST_MYSQL_DSN)\n"; continue; }
        $r37sDb = $r37sDriver === 'mysql'
            ? new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
            : new PDO('sqlite::memory:');
        $r37sDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $r37sDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $r37sTarget = 'bbe_r37s_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
        if ($r37sDriver === 'mysql') { $r37sDb->exec("CREATE DATABASE `{$r37sTarget}` CHARACTER SET utf8mb4"); $r37sDb->exec("USE `{$r37sTarget}`"); }
        Db::init($r37sDb, $r37sDriver);
        try {
            (new Migrator($r37sDb, dirname(TEST_CLI_DIR) . '/migrations', test_fixture_path('r37s-' . $r37sDriver) . '.lock'))->apply();
            $r37sYear = (int)ShopTime::date('Y');
            $r37sInsert = $r37sDb->prepare('INSERT INTO oss_threshold_totals (store_id, year, currency, net) VALUES (?, ?, ?, ?)');
            foreach (['default' => 900000, 'outlet' => 200000, 'other' => 300000] as $store => $net) $r37sInsert->execute([$store, $r37sYear, 'eur', $net]);
            foreach (['default', 'outlet', 'explicit', 'other'] as $r37sId) {
                Bootstrap::initStoreContext('/', '', $r37sId);
                VatService::reset();
                $r37sExpected = $r37sId === 'other' ? 300000 : 1100000;
                assert_equals($r37sId === 'other' ? 'other' : 'default', InvoiceService::sellerCode($r37sRoot, $r37sId, 'eur'), "R37 OSS seller {$r37sDriver}/{$r37sId}: invoice seller fixture");
                assert_equals($r37sExpected, OssThreshold::worstYear($r37sDb, $r37sId, $r37sRoot['stores'][$r37sId], $r37sRoot['stores'])['sum'],
                    "R37 OSS seller {$r37sDriver}/{$r37sId}: doctor count combines only the invoice seller's stores");
                assert_equals($r37sId !== 'other', OssThreshold::exceeded(), "R37 OSS seller {$r37sDriver}/{$r37sId}: checkout sees the shared threshold");
            }
            // Below the threshold: the lock must be identical for all storefronts of this seller, but not another one.
            $r37sDb->exec('UPDATE oss_threshold_totals SET net = 100');
            $r37sLocks = [];
            $r37sSnapshot = ['scenario' => 'domestic_subthreshold', 'currency' => 'eur', 'totals' => ['net' => 100], 'components' => [['type' => 'product', 'net' => 100]]];
            foreach (['default', 'outlet', 'explicit', 'other'] as $r37sId) {
                Bootstrap::initStoreContext('/', '', $r37sId);
                VatService::reset();
                $r37sLock = OssThreshold::guardOrder($r37sDb, $r37sSnapshot, ['address' => ['country' => 'AT']], [['product_type' => 'simple']]);
                $r37sLocks[$r37sId] = $r37sLock;
                OssThreshold::releaseOrderLock($r37sDb, $r37sLock);
            }
            if ($r37sDriver === 'mysql') {
                assert_true($r37sLocks['default'] !== null && $r37sLocks['default'] === $r37sLocks['outlet'] && $r37sLocks['default'] === $r37sLocks['explicit'] && $r37sLocks['default'] !== $r37sLocks['other'],
                    'R37 OSS seller mysql: implicit and explicit storefronts use the same lock, a different seller its own');
                // A second native connection cannot acquire the outlet lock while the default store holds it.
                Bootstrap::initStoreContext('/', '', 'default');
                $held = OssThreshold::guardOrder($r37sDb, $r37sSnapshot, ['address' => ['country' => 'AT']], [['product_type' => 'simple']]);
                try {
                    $peer = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false]);
                    $attempt = $peer->prepare('SELECT GET_LOCK(?, 0)');
                    $attempt->execute([$r37sLocks['outlet']]);
                    $acquired = (int)$attempt->fetchColumn();
                    if ($acquired === 1) $peer->prepare('SELECT RELEASE_LOCK(?)')->execute([$r37sLocks['outlet']]);
                    assert_equals(0, $acquired, 'R37 OSS seller mysql: a simultaneous outlet cannot bypass the default seller lock');
                } finally { OssThreshold::releaseOrderLock($r37sDb, $held); }
            }
        } finally {
            if ($r37sDb->inTransaction()) $r37sDb->rollBack();
            if ($r37sDriver === 'mysql') $r37sDb->exec("DROP DATABASE IF EXISTS `{$r37sTarget}`");
        }
    }
} finally {
    if ($r37sOriginalDb !== null) Db::init($r37sOriginalDb, (string)$r37sOriginalDb->getAttribute(PDO::ATTR_DRIVER_NAME)); else Db::reset();
    $r37sProp->setValue(null, $r37sStores);
    (new ReflectionProperty(StoreContext::class, 'current'))->setValue(null, $r37sContext);
    VatService::reset();
}
