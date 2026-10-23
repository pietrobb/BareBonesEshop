<?php
require_once __DIR__ . '/cli-only.php';

/**
 * Dev-only dependency-free static and architecture checks.
 *
 * Budgets:
 * - $entrypointBudgets: web/CLI entrypoints at the repository root stay thin (a fixed line cap; the
 *   implementation lives under src/Entrypoints).
 * - $architectureBudgets: every src/Entrypoints module has a budget for lines, functions, longest function
 *   and coupling (distinct Class:: dependencies). Exceeding any of them fails the gate.
 * - Ratchet: every budget (lines, functions, max_function_lines, coupling) may hold at most
 *   ARCHITECTURE_HEADROOM (15%, rounded up) above the module's current value. When a module shrinks,
 *   lower its budget in the same change, so budgets never silently drift away from the code they guard.
 *   Raising a budget needs a comment saying why (see the entries below).
 * - Every src/Entrypoints module must have a budget.
 */
const ARCHITECTURE_HEADROOM = 0.15;
$root = dirname(__DIR__);
$errors = [];
$phpFiles = [];

foreach (glob($root . '/*.php') ?: [] as $file) $phpFiles[] = $file;
foreach (['src', 'adapters', 'cli', 'migrations', 'tests'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
}
$phpFiles = array_values(array_unique($phpFiles));
sort($phpFiles);

/** @return array{functions: int, max_function_lines: int, coupling: int} */
function architectureMetrics(string $source): array {
    $tokens = token_get_all($source);
    $classes = [];
    $functionLines = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $class = ltrim($token[1], '\\');
            $next = $i + 1;
            while ($next < $count && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $next++;
            if ($class !== '' && ctype_upper($class[0]) && $next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_COLON) {
                $classes[$class] = true;
            }
        }
        if (!is_array($token) || $token[0] !== T_FUNCTION) continue;

        $name = null;
        $body = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j] === '(') break;
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) $name = $tokens[$j][1];
        }
        if ($name === null) continue;
        for (; $j < $count; $j++) {
            if ($tokens[$j] === '{') { $body = $j; break; }
        }
        if ($body === null) continue;

        $line = $token[2];
        $depth = 0;
        for ($j = $i; $j < $count; $j++) {
            $part = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            $line += substr_count($part, "\n");
            if ($j < $body) continue;
            if ($tokens[$j] === '{') $depth++;
            if ($tokens[$j] === '}' && --$depth === 0) {
                $functionLines[] = $line - $token[2] + 1;
                break;
            }
        }
    }

    return [
        'functions' => count($functionLines),
        'max_function_lines' => $functionLines === [] ? 0 : max($functionLines),
        'coupling' => count($classes),
    ];
}

foreach ($phpFiles as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        $errors[] = "Unreadable PHP source: {$file}";
        continue;
    }
    try {
        $tokens = token_get_all($source, TOKEN_PARSE);
    } catch (ParseError $e) {
        $errors[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ': ' . $e->getMessage();
        continue;
    }
    foreach ($tokens as $token) {
        if (is_array($token) && $token[0] === T_EVAL) {
            $errors[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ":{$token[2]} uses eval";
        }
    }
}

$entrypointBudgets = [
    'account.php' => 20,
    'admin.php' => 20,
    'api/v1/index.php' => 20,
    'index.php' => 20,
    'cli/test.php' => 20,
];
foreach ($entrypointBudgets as $relative => $budget) {
    $lines = file($root . '/' . $relative);
    if ($lines === false || count($lines) > $budget) {
        $errors[] = "{$relative} exceeds the {$budget}-line entrypoint budget";
    }
}

$architectureBudgets = [
    // Loads Shared/Request/StoreScope always and only the route's page modules (Admin/Modules.php).
    'src/Entrypoints/Admin.php' => ['lines' => 285, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 4],
    'src/Entrypoints/Admin/AdminUsers.php' => ['lines' => 290, 'functions' => 13, 'max_function_lines' => 90, 'coupling' => 6],
    'src/Entrypoints/Admin/B2b.php' => ['lines' => 180, 'functions' => 9, 'max_function_lines' => 64, 'coupling' => 4],
    // +2 coupling: AdminForm (server-rendered BBF forms replace inline bbf.js) and Import (shared CSV price format).
    // +1 coupling: CatalogScope keeps the catalog_scope-bound editor queries (categories, digital assets, attribute links) out of the entrypoint.
    // +1 coupling: ProductEditor validates and saves brand, GTIN/MPN, SEO, extra categories and translations outside the entrypoint.
    // +1 coupling: CsvExport streams the product export and reports a truncated one (log, incident, 500 when possible).
    // +15 lines: rejected product/CMS saves keep the typed input; product bulk actions report and keep the list filters.
    // +1 line: product save marks the catalog cache stale (Cache::catalogChanged).
    // +1 line: product save emits product.updated for API subscribers (round 6 review).
    // +1 coupling R8: Logger::phpLog masks e-mail addresses and secrets before the PHP error log.
    // +2 lines R9: the product save moves stock by the admin's change, so units sold meanwhile stay sold.
    'src/Entrypoints/Admin/Catalog.php' => ['lines' => 1278, 'functions' => 24, 'max_function_lines' => 210, 'coupling' => 19],
    // +1 coupling: ProductVariants owns variant validation, ledger stock booking and the delete/SKU-change guards.
    // +1 coupling: I18n gives the editor's labels in the admin language (A15.5).
    'src/Entrypoints/Admin/CatalogEditor.php' => ['lines' => 105, 'functions' => 4, 'max_function_lines' => 23, 'coupling' => 4],
    'src/Entrypoints/Admin/Customers.php' => ['lines' => 330, 'functions' => 6, 'max_function_lines' => 133, 'coupling' => 8],
    'src/Entrypoints/Admin/EmailTemplates.php' => ['lines' => 135, 'functions' => 7, 'max_function_lines' => 46, 'coupling' => 3],
    // Data files: route => page modules, side menu and page permissions.
    'src/Entrypoints/Admin/Modules.php' => ['lines' => 65, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 0],
    'src/Entrypoints/Admin/Navigation.php' => ['lines' => 40, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 0],
    'src/Entrypoints/Admin/Permissions.php' => ['lines' => 50, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 0],
    // +lines: warehouse, feed and shipping/payment changes are audited; feeds are refused across stores (admin catalog audit).
    'src/Entrypoints/Admin/Operations.php' => ['lines' => 800, 'functions' => 36, 'max_function_lines' => 136, 'coupling' => 18],
    // +1 coupling: BankTransferInstructions owns the variable symbol a bank transfer is matched by (A13.6).
    // +1 coupling: SalesReport gives the sales overview shop-local day boundaries (A15.1).
    // +1 coupling: OrderExport owns the order list filters shared by the sales overview and the CSV export (A15.2).
    // +1 function, +21 lines: "Retry refund" re-queues a refund request no payment_refund job ever executed (round 4 final review).
    // +1 line: a cancellation that refunds captured money needs orders.refund (round 5 review).
    // +1 function, +14 lines: "Confirm refund" books a GoPay/ThePay partial refund the provider never confirms (round 6 review).
    'src/Entrypoints/Admin/Orders.php' => ['lines' => 920, 'functions' => 16, 'max_function_lines' => 306, 'coupling' => 20],
    'src/Entrypoints/Admin/OrderOperations.php' => ['lines' => 80, 'functions' => 4, 'max_function_lines' => 32, 'coupling' => 7],
    'src/Entrypoints/Admin/PricingRules.php' => ['lines' => 235, 'functions' => 7, 'max_function_lines' => 115, 'coupling' => 5],
    'src/Entrypoints/Admin/Reports.php' => ['lines' => 90, 'functions' => 2, 'max_function_lines' => 74, 'coupling' => 4],
    'src/Entrypoints/Admin/Recovery.php' => ['lines' => 115, 'functions' => 4, 'max_function_lines' => 44, 'coupling' => 7],
    // R8 complaints list and the written decision; the transitions live in Complaints.
    'src/Entrypoints/Admin/Complaints.php' => ['lines' => 70, 'functions' => 2, 'max_function_lines' => 55, 'coupling' => 4],
    'src/Entrypoints/Admin/Request.php' => ['lines' => 90, 'functions' => 1, 'max_function_lines' => 9, 'coupling' => 2],
    // +1 function, +1 coupling: adminCodeLabel shows stored codes (product/page status, attribute type) in the admin language (A15.5).
    // +lines: windowed pagination (first, last and a window around the current page) instead of one link per page.
    'src/Entrypoints/Admin/Shared.php' => ['lines' => 58, 'functions' => 5, 'max_function_lines' => 17, 'coupling' => 1],
    // +1 coupling R8: Logger::phpLog masks e-mail addresses and secrets before the PHP error log.
    'src/Entrypoints/Admin/StoreSettings.php' => ['lines' => 77, 'functions' => 3, 'max_function_lines' => 49, 'coupling' => 5],
    'src/Entrypoints/Admin/Shipments.php' => ['lines' => 277, 'functions' => 11, 'max_function_lines' => 105, 'coupling' => 10],
    // A15.3: store switcher and per-request store activation, limited to the admin's granted stores (AdminStoreScope).
    'src/Entrypoints/Admin/StoreScope.php' => ['lines' => 70, 'functions' => 4, 'max_function_lines' => 23, 'coupling' => 5],
    'src/Entrypoints/Admin/Suppliers.php' => ['lines' => 175, 'functions' => 8, 'max_function_lines' => 75, 'coupling' => 5],
    'src/Entrypoints/Admin/Taxonomy.php' => ['lines' => 140, 'functions' => 7, 'max_function_lines' => 65, 'coupling' => 4],
    // +1 coupling: ApiRoute maps the /api/v1 route groups (storefront, options, webhooks, csp-report) to actions.
    // +1 coupling R8: Logger::phpLog masks e-mail addresses and secrets before the PHP error log.
    'src/Entrypoints/Api.php' => ['lines' => 75, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 7],
    // External key-authenticated API v1 (was api/v1/index.php), loaded by Api.php for non-browser routes.
    // +5 lines: the path below the install subdirectory / store prefix, and the prefix-vs-key store check.
    'src/Entrypoints/ApiExternal.php' => ['lines' => 55, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 6],
    // +1 coupling: Search::filtersFromQuery turns the search route's sort/price/category parameters into search options (A14.2).
    // +6 lines: the ?page=withdrawal route (R8 electronic withdrawal function, Directive 2011/83/EU art. 11a).
    // +6 lines: the ?page=complaint route (R8 complaints, Directive (EU) 2019/771).
    /* R9: Impressum page */ 'src/Entrypoints/Storefront.php' => ['lines' => 164, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 11],
    // Customer account portal behind the thin /account.php (registration, login, reset, GDPR, addresses, orders).
    // +1 coupling: Str::normalizeEmail is the one e-mail normalisation shared with Customers and Pricing.
    // +2 coupling: DigitalDelivery issues the downloads page's links; StoreContext limits the profile languages to the store's.
    'src/Entrypoints/Storefront/Account.php' => ['lines' => 450, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 16],
    // +2 lines: round-4 merge (canonical category links + trailing-slash/past-last-page 404).
    'src/Entrypoints/Storefront/CatalogPages.php' => ['lines' => 137, 'functions' => 10, 'max_function_lines' => 60, /* category page: bounded spec/subcat filter parsing; +1 coupling R8: legal pages render through ConsumerNotice, shared with the confirmation attachment */ 'coupling' => 9],
    // +2 functions: pending-payment page and the gateway cancel page (P0 2026-10-13 checkout redirect).
    // +1 coupling: BankTransferInstructions renders the IBAN / variable symbol / PAY by square block on order-done (A13.6).
    // +1 coupling: I18n::all() gives cart/checkout blocks the catalog their breadcrumb/pagination landmarks are labelled from.
    // +1 function: storefrontCartPrice() formats cart/checkout amounts in the cart currency (shared-catalog stores).
    // +1 line / +1 coupling: round-4 merge (cart-currency formatting + translated free/payment labels).
    // +2 lines (renderCheckout): the form's coupon attempts spend the coupon API's throttle (round 4 final review).
    // +1 coupling R8: Logger::phpLog masks e-mail addresses and secrets before the PHP error log.
    'src/Entrypoints/Storefront/CheckoutPages.php' => ['lines' => 256, 'functions' => 7, 'max_function_lines' => 102, 'coupling' => 13],
    // Ratcheted: canonical/robots/hreflang/JSON-LD moved to StorefrontSeo (storefront SEO audit).
    // +2 coupling: cart currency decimals for the JS config and the one-shot StorefrontFlash notice.
    /* R8: footer ADR body (ConsumerNotice), accessibility link, guest cookie settings; R9: Impressum link */ 'src/Entrypoints/Storefront/Layout.php' => ['lines' => 146, 'functions' => 0, 'max_function_lines' => 0, 'coupling' => 11],
    'src/Entrypoints/Storefront/ProductStatusPages.php' => ['lines' => 20, 'functions' => 1, 'max_function_lines' => 18, 'coupling' => 3],
    // R8 withdrawal function: identify → review → confirm; the rules live in Withdrawal.
    'src/Entrypoints/Storefront/WithdrawalPages.php' => ['lines' => 62, 'functions' => 1, 'max_function_lines' => 60, 'coupling' => 4],
    // R8 complaint form: identify → line, defect, remedy; the rules live in Complaints (order lookup shared with Withdrawal).
    'src/Entrypoints/Storefront/ComplaintPages.php' => ['lines' => 66, 'functions' => 1, 'max_function_lines' => 64, 'coupling' => 7],
];
$forbiddenDependencies = [
    'src/Entrypoints/Admin/Catalog.php' => ['OrderWorkflow::', 'CheckoutService::', 'ApiHandlers'],
    'src/Entrypoints/Admin/Customers.php' => ['Products::', 'CheckoutService::', 'ApiHandlers'],
    'src/Entrypoints/Admin/Operations.php' => ['CheckoutService::', 'Orders::create(', 'ApiHandlers'],
    'src/Entrypoints/Admin/Orders.php' => ['Products::', 'ContentService::', 'ApiHandlers'],
    'src/Entrypoints/Admin/Recovery.php' => ['Products::', 'CheckoutService::', 'ApiHandlers', 'Db::get('],
    'src/Entrypoints/Storefront/CatalogPages.php' => ['CheckoutService::', 'CheckoutSession::', 'Orders::'],
    'src/Entrypoints/Storefront/CheckoutPages.php' => ['Products::', 'Categories::', 'ContentService::'],
    'src/Entrypoints/Storefront/Layout.php' => ['CheckoutService::', 'CheckoutSession::', 'Orders::create('],
];
foreach ($architectureBudgets as $relative => $budget) {
    $path = $root . '/' . $relative;
    $source = is_file($path) ? file_get_contents($path) : false;
    if ($source === false) {
        $errors[] = "Missing architecture module: {$relative}";
        continue;
    }
    $metrics = architectureMetrics($source);
    $metrics['lines'] = count(file($path) ?: []);
    foreach ($budget as $metric => $limit) {
        if ($metrics[$metric] > $limit) {
            $errors[] = "{$relative} {$metric} {$metrics[$metric]} exceeds budget {$limit}";
        }
    }
    foreach ($budget as $metric => $limit) {
        // round() first: 0.15 is not exact in binary, so e.g. 20 * 1.15 must not ceil to 24.
        $ceiling = (int)ceil(round($metrics[$metric] * (1 + ARCHITECTURE_HEADROOM), 6));
        if ($limit > $ceiling) {
            $errors[] = "{$relative} {$metric} budget {$limit} has drifted more than " . (ARCHITECTURE_HEADROOM * 100)
                . "% above its current {$metrics[$metric]}; ratchet it down to at most {$ceiling}";
        }
    }
    foreach ($forbiddenDependencies[$relative] ?? [] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            $errors[] = "{$relative} contains forbidden dependency {$forbidden}";
        }
    }
}
foreach (array_merge(glob($root . '/src/Entrypoints/*.php') ?: [], glob($root . '/src/Entrypoints/*/*.php') ?: []) as $entrypointModule) {
    $relative = substr($entrypointModule, strlen($root) + 1);
    if (!isset($architectureBudgets[$relative])) $errors[] = "{$relative} has no architecture budget";
}
foreach (['src/Entrypoints/Api.php', 'src/Http/ApiHandlers.php', 'cli/TestSuite.php', 'cli/tests/TestHarness.php', 'cli/tests/RegressionSuite.php'] as $relative) {
    if (!is_file($root . '/' . $relative)) $errors[] = "Missing architecture module: {$relative}";
}
// PHP 8.5 deprecations (CI runs 8.5): handle/resource closers that are no-ops on objects, and non-canonical casts.
foreach (['src', 'adapters', 'cli', 'extensions'] as $deprecationDir) {
    if (!is_dir($root . '/' . $deprecationDir)) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $deprecationDir, FilesystemIterator::SKIP_DOTS)) as $deprecationFile) {
        if ($deprecationFile->getExtension() !== 'php') continue;
        $deprecationSource = (string)file_get_contents($deprecationFile->getPathname());
        if (preg_match('/(?<![\w\'"])(curl_close|curl_share_close|finfo_close|imagedestroy)\s*\(|\((integer|boolean|double|binary)\)/', $deprecationSource, $deprecated)) {
            $errors[] = str_replace('\\', '/', substr($deprecationFile->getPathname(), strlen($root) + 1)) . " uses {$deprecated[0]}, deprecated in PHP 8.5";
        }
    }
}
$testRunnerLines = count(file($root . '/cli/TestSuite.php') ?: []);
if ($testRunnerLines > 100) $errors[] = "cli/TestSuite.php lines {$testRunnerLines} exceeds budget 100";
// Thematic *Scenario.php modules are listed in cli/TestSuite.php; *Regression.php files are discovered by
// cli/tests/RegressionSuite.php, so adding either kind needs no change here.
$scenarioFiles = glob($root . '/cli/tests/scenarios/*Scenario.php') ?: [];
$testSuiteSource = (string)file_get_contents($root . "/cli/TestSuite.php");
foreach ($scenarioFiles as $scenarioFile) {
    if (!str_contains($testSuiteSource, "'" . basename($scenarioFile) . "'")) $errors[] = basename($scenarioFile) . " is not run by cli/TestSuite.php";
    $scenarioLines = count(file($scenarioFile) ?: []);
    if ($scenarioLines > 1800) {
        $errors[] = basename($scenarioFile) . " lines {$scenarioLines} exceeds budget 1800";
    }
}

require_once $root . '/src/autoload.php';
foreach ([ErpAdapter::class, PimAdapter::class, WmsAdapter::class] as $contract) {
    if (!interface_exists($contract)) $errors[] = "Missing extension contract: {$contract}";
}
if (!is_subclass_of(IntegrationAdapter::class, ErpAdapter::class, true)
    || !is_subclass_of(IntegrationAdapter::class, PimAdapter::class, true)
    || !is_subclass_of(IntegrationAdapter::class, WmsAdapter::class, true)) {
    $errors[] = 'IntegrationAdapter must compose ERP, PIM, and WMS contracts';
}
$events = DomainEvents::all();
if (count($events) !== count(array_unique($events))) $errors[] = 'Domain event names must be unique';
foreach ($events as $event) {
    if (!preg_match('/^[a-z][a-z0-9_.-]{1,190}$/', $event)) $errors[] = "Invalid domain event name: {$event}";
}

if ($errors !== []) {
    foreach ($errors as $error) fwrite(STDERR, "[FAIL] {$error}\n");
    exit(1);
}

printf("Static analysis passed: %d PHP files, %d stable domain events, %d thin entrypoints, %d bounded-context modules.\n", count($phpFiles), count($events), count($entrypointBudgets), count($architectureBudgets));
