<?php

// Round 6 review (2026-10-19), low findings: customer session per store, changePassword and reset links, logout and
// the cart, emails.log rotation, GDPR retention GC scheduling, migration lock per database, Packeta pick-up point
// search beyond the nearest 50, API key scope whitelist and per-key rate limit. (README "safe to re-run" is covered
// by the install smoke test.)
echo "\n--- Audit round 6 (low): sessions, reset links, logout, mail log, GDPR gc, migration lock, Packeta, API keys ---\n";
Bootstrap::initStoreContext('/', '', 'default');
$r6lDir = test_fixture_path('r6-low');
mkdir($r6lDir);
// Own freshly migrated SQLite database (the suite schema is built up and altered by earlier scenarios).
foreach ($GLOBALS as $r6lValue) if ($r6lValue instanceof PDOStatement) $r6lValue->closeCursor();
$r6lSuiteDb = Db::get();
$r6lSuiteDriver = Db::driver();
$r6lDb = new PDO('sqlite:' . $r6lDir . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Db::init($r6lDb, 'sqlite');
(new Migrator($r6lDb, TEST_CLI_DIR . '/../migrations', $r6lDir . '/migrate.lock'))->apply(static fn($m) => null);
$r6lStore = static function (string $id): void { StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), null, null, $id)); };
$r6lRaised = static function (string $section, Throwable $e): void {
    assert_true(false, "Round 6 low regression ({$section}) raised " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
};

// --- Customer session: visiting another store neither logs out nor lends the login -----------------------------
try {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    [$r6lRegOk, $r6lCustomer] = Customers::register('r6l-shopper@example.test', 'R6l-pass-123', 'Shopper');
    [$r6lLoginOk] = Customers::login('r6l-shopper@example.test', 'R6l-pass-123');
    assert_true($r6lRegOk && $r6lLoginOk && (int)(Customers::current()['id'] ?? 0) === (int)$r6lCustomer, 'R6 session: logged in to the default store');
    $r6lStore('cz');
    assert_equals(null, Customers::current(), "R6 session: another store does not see the default store's login");
    $r6lStore('default');
    assert_equals((int)$r6lCustomer, (int)(Customers::current()['id'] ?? 0), 'R6 session: back in the default store the shopper is still logged in');

    // --- Logout empties the cart and ends the login in every store of the session -----------------------------
    $_SESSION['cart'] = ['r6l-item' => ['qty' => 2]];
    $_SESSION['cart_email'] = 'r6l-shopper@example.test';
    $_SESSION['checkout_coupon'] = 'R6L';
    $r6lStore('cz');
    Customers::current(); // parks the default store's login
    Customers::logout();
    $r6lStore('default');
    assert_equals(null, Customers::current(), 'R6 logout: logging out ends the login parked for another store too');
    assert_true(!isset($_SESSION['cart']) && !isset($_SESSION['cart_email']) && !isset($_SESSION['checkout_coupon']),
        'R6 logout: the cart, its contact e-mail and the coupon are not left to the next person at the computer');

    // --- changePassword closes open reset links -----------------------------------------------------------------
    Customers::login('r6l-shopper@example.test', 'R6l-pass-123');
    $r6lLink = Customers::startPasswordReset('r6l-shopper@example.test');
    assert_true($r6lLink !== null && Customers::verifyResetToken($r6lLink) === (int)$r6lCustomer, 'R6 changePassword: a reset link is open before the change');
    [$r6lChanged] = Customers::changePassword((int)$r6lCustomer, 'R6l-pass-123', 'R6l-new-pass-456');
    assert_true($r6lChanged && Customers::verifyResetToken((string)$r6lLink) === null,
        'R6 changePassword: a reset link mailed before the change can no longer undo it');
    Customers::logout();
} catch (Throwable $e) {
    $r6lRaised('customers', $e);
} finally {
    $r6lStore('default');
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
}

// --- emails.log rotation ---------------------------------------------------------------------------------------
try {
    $r6lLog = $r6lDir . '/emails.log';
    $r6lMail = new LogMail(['log_file' => $r6lLog, 'log_max_bytes' => 2048]);
    for ($i = 0; $i < 12; $i++) {
        $r6lMail->send(['to' => 'r6l@example.test', 'subject' => "R6L {$i}", 'text' => str_repeat('x', 400)]);
    }
    clearstatcache();
    assert_true(is_file($r6lLog) && filesize($r6lLog) < 2048 + 1024 && is_file($r6lLog . '.1') && filesize($r6lLog . '.1') < 2048 + 1024,
        'R6 emails.log: the capture file rotates to .1 at log_max_bytes instead of growing without bound');
    assert_true(!is_file($r6lLog . '.2'), 'R6 emails.log: only one older generation is kept');
    LogMail::clearCaptured();
} catch (Throwable $e) {
    $r6lRaised('mail log', $e);
}

// --- GDPR retention GC is scheduled and doctor notices when it never ran --------------------------------------
try {
    $r6lRetention = static function () use ($r6lDb): array {
        foreach (Setup::doctor(Security::config(), TEST_CLI_DIR . '/..', $r6lDb)['checks'] as $check) {
            if ($check['name'] === 'operations.gdpr_retention_gc') return $check;
        }
        return [];
    };
    $r6lDb->exec('DELETE FROM data_retention_runs');
    assert_equals('warn', $r6lRetention()['status'] ?? null, 'R6 GDPR gc: doctor warns when the retention GC has never been applied');
    $r6lDb->prepare("INSERT INTO data_retention_runs (run_at, mode, policy_key, affected_rows) VALUES (?, 'apply', 'r6l', 0)")->execute([gmdate('Y-m-d H:i:s')]);
    assert_equals('pass', $r6lRetention()['status'] ?? null, 'R6 GDPR gc: a recent applied run passes');
    $r6lCompose = (string)file_get_contents(TEST_CLI_DIR . '/../deploy/docker-compose.yml');
    assert_true(str_contains($r6lCompose, 'php cli/gdpr-gc.php --apply'), 'R6 GDPR gc: the Docker worker runs the retention GC daily');
} catch (Throwable $e) {
    $r6lRaised('gdpr gc', $e);
}

// --- Migration lock: one lock per SQLite database, whichever app directory migrates it ------------------------
try {
    $r6lMigrations = $r6lDir . '/migrations';
    mkdir($r6lMigrations);
    $r6lSqlite = $r6lDir . '/shared.sqlite';
    $r6lMigrator = new Migrator(new PDO('sqlite:' . $r6lSqlite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), $r6lMigrations);
    $r6lLockFile = (new ReflectionProperty(Migrator::class, 'lockFile'))->getValue($r6lMigrator);
    assert_equals(str_replace('\\', '/', $r6lSqlite . Migrator::LOCK_FILENAME), str_replace('\\', '/', (string)$r6lLockFile),
        'R6 migration lock: SQLite locks next to the database file, so two checkouts sharing one database exclude each other');
    $r6lMigrator = null;
    $r6lMigratorSrc = (string)file_get_contents(TEST_CLI_DIR . '/../src/Migrator.php');
    assert_true(str_contains($r6lMigratorSrc, "SELECT GET_LOCK(?, ?)") && str_contains($r6lMigratorSrc, "SELECT RELEASE_LOCK(?)"),
        'R6 migration lock: MySQL/MariaDB take a server-wide GET_LOCK (exercised by the MySQL CI run)');
} catch (Throwable $e) {
    $r6lRaised('migration lock', $e);
}

// --- Packeta: a point beyond the nearest 50 can be found and chosen --------------------------------------------
try {
    $r6lPacketa = new PacketaShipping(['private_cache_dir' => $r6lDir . '/cache']);
    $r6lBranches = [];
    for ($i = 1; $i <= 60; $i++) {
        $r6lBranches[] = ['id' => (string)(9000 + $i), 'country' => 'sk', 'status' => ['statusId' => '1'], 'displayFrontend' => '1',
            'name' => $i === 60 ? 'Z-BOX Žilina Námestie' : "Z-BOX Bratislava {$i}", 'street' => "Ulica {$i}",
            'city' => $i === 60 ? 'Žilina' : 'Bratislava', 'zip' => $i === 60 ? '010 01' : sprintf('8%04d', $i)];
    }
    $r6lPacketa->storePickupPoints('SK', $r6lBranches);
    $r6lNear = array_column($r6lPacketa->pickupPoints('SK', '81101'), 'id');
    assert_true(count($r6lNear) === 50 && !in_array('9060', $r6lNear, true), 'R6 Packeta: the address list holds the nearest 50 points (precondition)');
    assert_equals(['9060'], array_column($r6lPacketa->pickupPoints('SK', 'zilina'), 'id'), 'R6 Packeta: a search by town without diacritics finds a point beyond the nearest 50');
    ShippingService::setAdapters([$r6lPacketa]);
    $r6lRate = ['carrier' => 'packeta', 'service_type' => 'pickup'];
    $r6lChoice = ShippingService::choosePickupPoint($r6lRate, 'SK', '81101', '9060');
    assert_true(($r6lChoice['point']['id'] ?? null) === '9060' && $r6lChoice['error'] === null, 'R6 Packeta: the found point is accepted at checkout although it is not among the nearest 50');
    assert_equals('pickup_point_invalid', ShippingService::choosePickupPoint($r6lRate, 'SK', '81101', '1234567')['error'], 'R6 Packeta: an id outside the feed is still refused');
    $r6lView = CheckoutService::pickupView([$r6lRate], $r6lRate, 'SK', '81101', '9060', '');
    assert_true(in_array('9060', array_column($r6lView['pickup_points'], 'id'), true) && !empty($r6lView['pickup_search_capable']),
        'R6 Packeta: the chosen far point stays in the re-rendered list and the search field is offered');
} catch (Throwable $e) {
    $r6lRaised('packeta', $e);
} finally {
    ShippingService::setAdapters([]);
}

// --- API keys: scope whitelist and a per-key rate limit ---------------------------------------------------------
try {
    $r6lTypo = null;
    try { ApiAuth::issueClient('R6L typo', 'default', ['product:read']); } catch (InvalidArgumentException $e) { $r6lTypo = $e->getMessage(); }
    assert_true($r6lTypo !== null && str_contains($r6lTypo, 'products:read'), 'R6 API keys: an unknown scope (typo) is refused and the allowed scopes are listed');
    $r6lUnused = null;
    try { ApiAuth::issueClient('R6L unused', 'default', ['widgets:write']); } catch (InvalidArgumentException $e) { $r6lUnused = $e; }
    assert_true($r6lUnused !== null, 'R6 API keys: a well-formed scope no endpoint checks is refused too');
    $r6lKey = ApiAuth::issueClient('R6L ok', 'default', ['products:read']);
    $r6lDb->prepare('UPDATE api_clients SET scopes = ? WHERE id = ?')->execute([json_encode(['products:read', 'legacy:read']), $r6lKey['id']]);
    $r6lAuth = ApiAuth::authenticate('Bearer ' . $r6lKey['token']);
    assert_equals(['products:read'], $r6lAuth['scopes'], 'R6 API keys: a stored scope this version does not know grants nothing and does not lock the key out');

    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $r6lAllowed = 0;
    for ($i = 0; $i < 5; $i++) $r6lAllowed += ApiAuth::withinClientRateLimit($r6lAuth, ['api_client_rate_limit' => 3]) ? 1 : 0;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.77'; // another source address: the budget is per key
    $r6lOtherIp = ApiAuth::withinClientRateLimit($r6lAuth, ['api_client_rate_limit' => 3]);
    unset($_SERVER['REMOTE_ADDR']);
    assert_true($r6lAllowed === 3 && $r6lOtherIp === false, 'R6 API keys: the per-key budget holds across source addresses');
    $r6lExternal = (string)file_get_contents(TEST_CLI_DIR . '/../src/Entrypoints/ApiExternal.php');
    assert_true(str_contains($r6lExternal, 'ApiAuth::withinClientRateLimit($client)'), 'R6 API keys: the external API enforces the per-key limit (429)');
} catch (Throwable $e) {
    $r6lRaised('api keys', $e);
} finally {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    Db::init($r6lSuiteDb, $r6lSuiteDriver);
}
