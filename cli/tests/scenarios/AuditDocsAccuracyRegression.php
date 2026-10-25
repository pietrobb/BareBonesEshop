<?php
/**
 * Audit 2026-10-12 / A11.2 / documentation inaccuracies: README claimed "~30 actions / ~30 classes /
 * single-file config"; .env.example promised variables (Stripe/PayU/SMTP/SES/APP_URL ...) that no code
 * reads and a .env file that is never loaded; ESHOP-DESIGN listed shipped features as "out of scope
 * V1"; PaymentAdapter advertised PayPal/GoPay/TatraPay adapters that do not exist.
 */

echo "\n--- Audit A11.2: documentation matches the code ---\n";

$a112Root = dirname(TEST_CLI_DIR);
$a112Source = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a112Root, FilesystemIterator::SKIP_DOTS)) as $a112File) {
    $a112Path = str_replace('\\', '/', substr($a112File->getPathname(), strlen($a112Root) + 1));
    if ($a112File->getExtension() !== 'php' || preg_match('#^(?:releases|vendor|node_modules|tests)/#', $a112Path)
        || $a112Path === 'cli/tests/scenarios/AuditDocsAccuracyRegression.php') continue;
    $a112Source .= (string)file_get_contents($a112File->getPathname()) . "\n";
}

// README: no stale approximations; the API action count it states is the real one.
$a112Readme = (string)file_get_contents($a112Root . '/README.md');
assert_true(!preg_match('/~\s*30\s+(?:actions|classes)/i', $a112Readme), 'A11.2 README: no stale "~30 actions/classes" claims');
assert_true(!str_contains($a112Readme, 'Single-file configuration'), 'A11.2 README: configuration is not described as a single file');
$a112Api = (string)file_get_contents($a112Root . '/src/Entrypoints/Api.php');
preg_match('/match\(\$action\) \{(.*?)\n\s*\};/s', $a112Api, $a112Match);
preg_match_all("/'([a-z0-9_]+)'/", $a112Match[1] ?? '', $a112Actions);
$a112ActionCount = count(array_unique($a112Actions[1]));
if (preg_match('#JSON API\*\* \(`/api/v1`\) — (\d+) actions#', $a112Readme, $a112Stated)) {
    assert_equals($a112ActionCount, (int)$a112Stated[1], 'A11.2 README: stated JSON API action count matches the dispatcher');
} else {
    assert_true(false, 'A11.2 README: JSON API action count is stated from the dispatcher');
}

// .env.example: every variable it documents is read by the code from the process environment,
// and it does not promise that a .env file is loaded.
$a112Env = (string)file_get_contents($a112Root . '/.env.example');
preg_match_all('/^#?\s*([A-Z][A-Z0-9_]+)=/m', $a112Env, $a112Vars);
$a112Unread = [];
foreach (array_unique($a112Vars[1]) as $a112Var) {
    // Direct getenv() or the SMTP/SES adapters' getenv-first `$env('NAME', default)` helper.
    $direct = str_contains($a112Source, "getenv('{$a112Var}')") || str_contains($a112Source, "\$env('{$a112Var}'");
    $pattern = preg_match('/^SHIP_[A-Z0-9]+_WEBHOOK_SECRET$/', $a112Var) === 1 && str_contains($a112Source, "'SHIP_' . strtoupper(str_replace('-', '_', \$carrierId)) . '_WEBHOOK_SECRET'");
    if (!$direct && !$pattern) $a112Unread[] = $a112Var;
}
assert_equals([], $a112Unread, 'A11.2 .env.example: documents only variables the code reads');
assert_true(!preg_match('/^#\s*Copy to \.env\b/mi', $a112Env) && str_contains($a112Env, 'not read from a .env file'), 'A11.2 .env.example: states that no .env file is loaded');

// ESHOP-DESIGN: features that exist are not listed as out of scope.
$a112Design = str_replace("\r\n", "\n", (string)file_get_contents($a112Root . '/ESHOP-DESIGN.md'));
assert_true(str_contains($a112Design, "### Deliberately out of scope for V1\n") && str_contains($a112Design, "### V1 (core)\n"), 'A11.2 ESHOP-DESIGN: feature matrix sections are present');
$a112OutOfScope = '';
if (preg_match('/### Deliberately out of scope for V1\n(.*?)\n---/s', $a112Design, $a112Section)) $a112OutOfScope = $a112Section[1];
foreach ([
    'Customer accounts' => 'account.php',
    'Reviews' => 'src/Reviews.php',
    'Wishlist' => 'src/Wishlist.php',
    'Live carrier rates' => 'adapters/contrib/HttpShipping.php',
    'Partial shipments' => 'migrations/20260801200000_inventory_ledger_fulfillment.php',
    '2FA for admin' => 'src/AdminIdentity.php',
    'Webhooks (outbound)' => 'src/Outbox.php',
    'Multi-warehouse' => 'src/Warehouse.php',
] as $a112Feature => $a112Evidence) {
    assert_true(is_file($a112Root . '/' . $a112Evidence), "A11.2 evidence for {$a112Feature} exists ({$a112Evidence})");
    assert_true(!str_contains($a112OutOfScope, '| ' . $a112Feature), "A11.2 ESHOP-DESIGN: shipped feature '{$a112Feature}' is not listed as out of scope");
}
$a112Matrix = preg_match('/### V1 \(core\)\n(.*?)\n### /s', $a112Design, $a112M) ? $a112M[1] : '';
assert_true(!str_contains($a112Matrix, '| Grouped/Bundle | Yes | V2 |') && !str_contains($a112Matrix, 'No partial shipments') && !str_contains($a112Matrix, 'No live carrier rates'), 'A11.2 ESHOP-DESIGN: V1 matrix does not deny bundles, partial shipments or carrier rates');

// A16.3 / usability 10: the design documentation is in English. Slovak may appear only as data inside code
// (fenced blocks, inline code), e.g. a translated product name in a JSON example.
$a163Slovak = [];
foreach (array_merge(['ESHOP-DESIGN.md', 'README.md'], array_map(static fn(string $p): string => substr($p, strlen($a112Root) + 1),
    array_merge(glob($a112Root . '/docs/*.md') ?: [], glob($a112Root . '/docs/design/*.md') ?: []))) as $a163Doc) {
    $a163Fenced = false;
    foreach (explode("\n", str_replace("\r\n", "\n", (string)file_get_contents($a112Root . '/' . $a163Doc))) as $a163No => $a163Line) {
        if (preg_match('/^\s*(```|~~~)/', $a163Line)) { $a163Fenced = !$a163Fenced; continue; }
        if ($a163Fenced) continue;
        $a163Prose = preg_replace('/`[^`]*`/', '', $a163Line);
        if (preg_match('/[áäčďéíĺľňóôŕšťúýžěřů]/iu', $a163Prose)) $a163Slovak[] = $a163Doc . ':' . ($a163No + 1);
    }
}
assert_equals([], array_slice($a163Slovak, 0, 10), 'A16.3 design docs: no Slovak prose outside code (' . count($a163Slovak) . ' lines)');

// PaymentAdapter: every adapter named in its contract or in the config examples exists.
$a112Named = [];
preg_match_all('/\b([A-Z][A-Za-z]+(?:Payment|Pay))\b/', (string)file_get_contents($a112Root . '/adapters/PaymentAdapter.php'), $a112Names);
foreach (array_unique($a112Names[1]) as $a112Name) {
    if ($a112Name !== 'PaymentAdapter') $a112Named[] = $a112Name;
}
preg_match_all("/=>\s*'([A-Za-z]+Payment)'/", (string)file_get_contents($a112Root . '/config.example.php'), $a112ConfigNames);
$a112Missing = array_values(array_filter(array_unique(array_merge($a112Named, $a112ConfigNames[1])), static fn(string $class): bool => !is_file($a112Root . '/adapters/contrib/' . $class . '.php')));
assert_equals([], $a112Missing, 'A11.2 PaymentAdapter/config.example: no non-existent payment adapters advertised');
preg_match_all('/\b([A-Z][A-Za-z]+Payment)\b/', $a112Design, $a112DesignNames);
$a112DesignMissing = array_values(array_filter(array_unique($a112DesignNames[1]), static fn($name) => $name !== 'PaymentAdapter' && !is_file($a112Root . '/adapters/contrib/' . $name . '.php')));
assert_equals([], $a112DesignMissing, 'A11.2 ESHOP-DESIGN: payment contract does not advertise non-existent adapters');
assert_true(!preg_match('/PayPal|TatraPay/', $a112Design), 'A11.2 ESHOP-DESIGN: no PayPal/TatraPay adapter advertised');

// R10 docs: README states the real SQLite path, test database location and checkout; the worker header names every queue backend.
$r10dExample = (string)file_get_contents($a112Root . '/config.example.php');
assert_true(preg_match("~'db_sqlite_path' => dirname\(__DIR__\) \. '/(bareboneseshop-private/db/shop\.sqlite)'~", $r10dExample, $r10dPath) === 1
    && str_contains($a112Readme, '`../' . $r10dPath[1] . '`') && !str_contains($a112Readme, 'default `db/shop.sqlite`'),
    'R10 docs README: db_sqlite_path is documented as config.example.php sets it, not as a db/shop.sqlite default');
assert_true(str_contains((string)file_get_contents($a112Root . '/cli/tests/scenarios/FoundationScenario.php'), "\$testDbPath = test_fixture_path('suite');")
    && str_contains($a112Readme, 'system temp directory') && !str_contains($a112Readme, 'db/test.sqlite') && !preg_match('/^mkdir -p db\b/m', $a112Readme),
    'R10 docs README: the test database lives in the system temp directory, no db/ directory is needed');
assert_true(!str_contains($a112Readme, 'multi-step checkout') && str_contains($a112Readme, 'single-page checkout'), 'R10 docs README: checkout is described as the single page it is');
foreach (['src/ManualOrder.php' => 'manual orders', 'src/ProductDuplicator.php' => 'descriptions, duplicate)', 'src/SalesChart.php' => 'sales charts',
    'src/Entrypoints/Admin/RichText.php' => 'rich-text', 'src/BackInStock.php' => 'back-in-stock alerts', 'src/Entrypoints/Storefront/GuestPages.php' => 'guest order-status lookup'] as $r10dEvidence => $r10dPhrase) {
    assert_true(is_file($a112Root . '/' . $r10dEvidence) && str_contains($a112Readme, $r10dPhrase), "R10 docs README: feature list mentions {$r10dPhrase} ({$r10dEvidence})");
}
preg_match('~/\*\*(.*?)\*/~s', (string)file_get_contents($a112Root . '/cli/worker.php'), $r10dWorkerDoc);
preg_match_all("~^\s*'([a-z]+)'(?:, '[a-z]+')* => new [A-Za-z]+Queue~m", (string)file_get_contents($a112Root . '/src/JobQueue.php'), $r10dBackends);
$r10dUnnamed = array_values(array_filter(array_merge(['file'], $r10dBackends[1]), static fn(string $b): bool => !str_contains($r10dWorkerDoc[1] ?? '', '`' . $b . '`')));
assert_true(count($r10dBackends[1]) >= 2 && $r10dUnnamed === [], 'R10 docs worker.php: the header names every queue backend (missing: ' . implode(', ', $r10dUnnamed) . ')');
