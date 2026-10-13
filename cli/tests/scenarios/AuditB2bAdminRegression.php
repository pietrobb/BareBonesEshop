<?php

echo "\n--- Audit A12.6: admin B2B companies, customer groups and customer assignment (store scope, approval, credit, invoice terms, CSRF/role, audit) ---\n";

foreach (['owner' => true, 'manager' => true, 'editor' => false, 'fulfillment' => false, 'viewer' => false] as $a126Role => $a126Allowed) {
    $a126Session = $_SESSION ?? [];
    $_SESSION = ['admin_role' => $a126Role];
    assert_equals($a126Allowed, Security::adminCan('customers.edit'), "A12.6 role: {$a126Role} " . ($a126Allowed ? 'may' : 'may not') . ' manage companies, groups and assignments');
    $_SESSION = $a126Session;
}
$a126Permissions = require dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Permissions.php';
assert_equals(['customers.edit', 'customers.edit', 'customers.view'], [$a126Permissions['companies'] ?? null, $a126Permissions['customer_groups'] ?? null, $a126Permissions['customer'] ?? null],
    'A12.6 role: company and group pages require customers.edit (the customer:b2b handler re-checks it)');

$a126Root = test_fixture_path('admin-b2b');
mkdir($a126Root);
$a126Fixture = $a126Root . '/request.php';
$a126Source = <<<'PHP'
<?php
[, $driver, $phase, $store, $action, $root, $target] = $argv;
require $root . '/src/autoload.php';
require $root . '/src/Entrypoints/Admin/Shared.php';
require $root . '/src/Entrypoints/Admin/Catalog.php';
require $root . '/src/Entrypoints/Admin/Customers.php';
require $root . '/src/Entrypoints/Admin/B2b.php';
require $root . '/src/Entrypoints/Admin/Request.php';
set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
final class A126Pdo extends PDO {
    public int $depth = 0;
    public function beginTransaction(): bool {
        if ($this->depth++ === 0) return parent::beginTransaction();
        $this->exec('SAVEPOINT a126_' . $this->depth);
        return true;
    }
    public function commit(): bool {
        if (--$this->depth === 0) return parent::commit();
        $this->exec('RELEASE SAVEPOINT a126_' . ($this->depth + 1));
        return true;
    }
    public function rollBack(): bool {
        if (--$this->depth === 0) return parent::rollBack();
        $this->exec('ROLLBACK TO SAVEPOINT a126_' . ($this->depth + 1));
        return true;
    }
    public function inTransaction(): bool { return $this->depth > 0; }
}
$db = $driver === 'mysql'
    ? new A126Pdo(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_EMULATE_PREPARES => false])
    : new A126Pdo('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if ($driver === 'mysql') {
    if ($phase === 'setup') $db->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4");
    if ($phase === 'teardown') { $db->exec("DROP DATABASE IF EXISTS `{$target}`"); exit(0); }
    $db->exec("USE `{$target}`");
}
Db::init($db, $driver);
$idOf = static function(string $table, string $column, string $value) use ($db): int {
    $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
    $stmt->execute([$value]);
    return (int)$stmt->fetchColumn();
};
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', dirname(__FILE__) . '/migrate-' . $driver . '.lock'))->apply();
    foreach (['default', 'cz'] as $owner) {
        // mem: has a member; pl: used by a price list; promo: used by a promotion; free: unused.
        foreach (['mem', 'pl', 'promo', 'free'] as $kind) {
            $db->prepare('INSERT INTO customer_groups (store_id, code, name, discount_bps, active) VALUES (?, ?, ?, 500, 1)')->execute([$owner, "a126{$kind}-{$owner}", 'A126G' . strtoupper($kind) . "-{$owner}-NAME"]);
        }
        $db->prepare("INSERT INTO price_lists (store_id, code, name, currency, customer_group_id) VALUES (?, ?, 'PL', 'EUR', ?)")->execute([$owner, "a126list-{$owner}", $idOf('customer_groups', 'code', "a126pl-{$owner}")]);
        $db->prepare("INSERT INTO promotions (store_id, code, name, conditions, actions) VALUES (?, ?, 'Promo', ?, '{}')")
            ->execute([$owner, "a126promotion-{$owner}", json_encode(['customer_group_ids' => [$idOf('customer_groups', 'code', "a126promo-{$owner}")]])]);
        $company = $db->prepare("INSERT INTO companies (store_id, code, legal_name, vat_id, vat_country, vat_validated_at, approval_status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $company->execute([$owner, "a126co-{$owner}", "A126CO-{$owner}-NAME", 'SK1111111111', 'SK', '2026-01-02 03:04:05']);
        $company->execute([$owner, "a126empty-{$owner}", "A126EMPTY-{$owner}-NAME", null, null, null]);
        $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, customer_group_id, company_id, status) VALUES (?, ?, '!test!', ?, ?, ?, 'active')")
            ->execute([$owner, "a126-{$owner}@example.test", "A126CUST-{$owner}-NAME", $idOf('customer_groups', 'code', "a126mem-{$owner}"), $idOf('companies', 'code', "a126co-{$owner}")]);
        $db->prepare("INSERT INTO customers (store_id, email, password_hash, name, status) VALUES (?, ?, '!test!', ?, 'active')")
            ->execute([$owner, "a126-solo-{$owner}@example.test", "A126SOLO-{$owner}-NAME"]);
    }
    exit(0);
}

$stores = Bootstrap::stores();
$stores['stores']['cz'] = array_replace($stores['stores']['default'], ['id'=>'cz', 'url_prefix'=>'/cz', 'domains'=>[], 'catalog_scope'=>'cz', 'provider_scope'=>'cz', 'languages'=>['sk', 'en']]);
$stores['stores']['default']['languages'] = ['sk', 'en'];
StoreContext::activate(StoreContext::resolve($stores, '/', '', $store));
I18n::setLocale('sk');
$foreign = $store === 'default' ? 'cz' : 'default';
$own = $store;
$_SESSION = ['admin_role'=>'manager', 'admin_user'=>'a126', 'csrf_token'=>'a126-token'];
$snapshot = static function() use ($db): array {
    return [
        'customer_groups' => $db->query('SELECT id, store_id, code, name, discount_bps, active FROM customer_groups ORDER BY id')->fetchAll(),
        'companies' => $db->query('SELECT id, store_id, code, legal_name, registration_number, vat_id, vat_country, vat_validated_at, approval_status, credit_limit, payment_terms_days, invoice_purchase, active FROM companies ORDER BY id')->fetchAll(),
        'customers' => $db->query('SELECT id, store_id, email, customer_group_id, company_id FROM customers ORDER BY id')->fetchAll(),
        'audit' => $db->query("SELECT entity, entity_id, action, store_id FROM audit_log WHERE entity IN ('customer_group', 'company', 'customer') ORDER BY id")->fetchAll(),
    ];
};
$db->beginTransaction();
$before = $snapshot();
$extra = [];
ob_start();
register_shutdown_function(static function() use ($db, $snapshot, $before, &$extra): void {
    $output = ob_get_contents();
    if ($extra instanceof Closure) $extra = $extra();
    ob_clean();
    $result = ['output'=>$output, 'before'=>$before, 'after'=>$snapshot(), 'flash'=>$_SESSION['flash'] ?? null, 'extra'=>$extra];
    $db->depth = 1;
    $db->rollBack();
    print json_encode($result, JSON_THROW_ON_ERROR);
    ob_end_flush();
});
$_GET = [];
$g = static fn(string $kind, ?string $owner = null): int => $idOf('customer_groups', 'code', "a126{$kind}-" . ($owner ?? $own));
$co = static fn(string $kind, ?string $owner = null): int => $idOf('companies', 'code', "a126{$kind}-" . ($owner ?? $own));
$member = $idOf('customers', 'email', "a126-{$own}@example.test");
$solo = static fn(?string $owner = null): int => $idOf('customers', 'email', 'a126-solo-' . ($owner ?? $own) . '@example.test');
$group = static fn(array $fields): array => array_replace(['csrf_token'=>'a126-token', 'name'=>'', 'code'=>'', 'discount'=>'', 'active'=>'1'], $fields);
$company = static fn(array $fields): array => array_replace(['csrf_token'=>'a126-token', 'legal_name'=>'', 'code'=>'', 'registration_number'=>'', 'vat_id'=>'', 'approval_status'=>'pending',
    'credit_limit'=>'0', 'payment_terms_days'=>'0', 'invoice_purchase'=>'', 'active'=>'1'], $fields);
$editCompany = static fn(string $kind, array $fields) => [$_GET = ['id'=>$co($kind)], $company(array_replace(['legal_name'=>'A126CO-' . $own . '-NAME', 'code'=>"a126{$kind}-{$own}", 'vat_id'=>'SK1111111111'], $fields))][1];
$assign = static fn(array $fields): array => array_replace(['csrf_token'=>'a126-token', 'customer_group_id'=>'', 'company_id'=>''], $fields);
$invoice = static fn(): array => ['invoice' => [Customers::canInvoicePurchase($member, 1000), Customers::canInvoicePurchase($member, 1001)]];
match ($action) {
    'groups_page' => [$_GET = ['id'=>$g('free')], pageCustomerGroups()],
    'companies_page' => [$_GET = ['id'=>$co('co')], pageCompanies()],
    'customer_page' => [$_GET = ['id'=>(string)$member], pageCustomerDetail()],
    'group_create' => [$_POST = $group(['name'=>'Veľkoobchod Žltý', 'discount'=>'7,5']), handlePost('customer_groups', 'save')],
    'group_edit' => [$_GET = ['id'=>$g('free')], $_POST = array_diff_key($group(['name'=>'Renamed', 'code'=>"a126free-{$own}", 'discount'=>'']), ['active'=>1]), handlePost('customer_groups', 'save')],
    'group_delete_free' => [$_POST = ['csrf_token'=>'a126-token', 'customer_group_id'=>(string)$g('free')], handlePost('customer_groups', 'delete')],
    'group_delete_members' => [$_POST = ['csrf_token'=>'a126-token', 'customer_group_id'=>(string)$g('mem')], handlePost('customer_groups', 'delete')],
    'group_delete_pricelist' => [$_POST = ['csrf_token'=>'a126-token', 'customer_group_id'=>(string)$g('pl')], handlePost('customer_groups', 'delete')],
    'group_delete_promo' => [$_POST = ['csrf_token'=>'a126-token', 'customer_group_id'=>(string)$g('promo')], handlePost('customer_groups', 'delete')],
    'group_delete_foreign' => [$_POST = ['csrf_token'=>'a126-token', 'customer_group_id'=>(string)$g('free', $foreign)], handlePost('customer_groups', 'delete')],
    'group_edit_foreign' => [$_GET = ['id'=>$g('free', $foreign)], $_POST = $group(['name'=>'Stolen']), handlePost('customer_groups', 'save')],
    'group_dup_code' => [$_POST = $group(['name'=>'X', 'code'=>"a126free-{$own}"]), handlePost('customer_groups', 'save')],
    'group_bad_discount' => [$_POST = $group(['name'=>'X', 'discount'=>'101']), handlePost('customer_groups', 'save')],
    'group_array' => [$_POST = $group(['name'=>['X']]), handlePost('customer_groups', 'save')],
    'group_no_csrf' => [$_POST = array_diff_key($group(['name'=>'X']), ['csrf_token'=>1]), handlePost('customer_groups', 'save')],
    'company_create' => [$_POST = $company(['legal_name'=>'Žltá Firma s.r.o.', 'registration_number'=>'12345678', 'vat_id'=>'sk 2020 123456', 'approval_status'=>'approved',
        'credit_limit'=>'50000', 'payment_terms_days'=>'14', 'invoice_purchase'=>'1']), handlePost('companies', 'save')],
    'company_keep_vat' => [$_POST = $editCompany('co', ['registration_number'=>'87654321']), handlePost('companies', 'save')],
    'company_change_vat' => [$_POST = $editCompany('co', ['vat_id'=>'SK2222222222']), handlePost('companies', 'save')],
    'company_approve_invoice' => [$extra = static fn(): array => $invoice(), $_POST = $editCompany('co', ['approval_status'=>'approved', 'credit_limit'=>'1000', 'payment_terms_days'=>'30', 'invoice_purchase'=>'1']),
        handlePost('companies', 'save')],
    'company_invoice_pending' => [$extra = static fn(): array => $invoice(), $_POST = $editCompany('co', ['credit_limit'=>'1000', 'payment_terms_days'=>'30', 'invoice_purchase'=>'1']),
        handlePost('companies', 'save')],
    'company_delete_empty' => [$_POST = ['csrf_token'=>'a126-token', 'company_id'=>(string)$co('empty')], handlePost('companies', 'delete')],
    'company_delete_members' => [$_POST = ['csrf_token'=>'a126-token', 'company_id'=>(string)$co('co')], handlePost('companies', 'delete')],
    'company_delete_foreign' => [$_POST = ['csrf_token'=>'a126-token', 'company_id'=>(string)$co('empty', $foreign)], handlePost('companies', 'delete')],
    'company_edit_foreign' => [$_GET = ['id'=>$co('empty', $foreign)], $_POST = $company(['legal_name'=>'Stolen']), handlePost('companies', 'save')],
    'company_dup_code' => [$_POST = $company(['legal_name'=>'X', 'code'=>"a126empty-{$own}"]), handlePost('companies', 'save')],
    'company_invoice_no_terms' => [$_POST = $company(['legal_name'=>'X', 'invoice_purchase'=>'1']), handlePost('companies', 'save')],
    'company_bad_vat' => [$_POST = $company(['legal_name'=>'X', 'vat_id'=>'2020123456']), handlePost('companies', 'save')],
    'company_bad_approval' => [$_POST = $company(['legal_name'=>'X', 'approval_status'=>'vip']), handlePost('companies', 'save')],
    'company_negative_credit' => [$_POST = $company(['legal_name'=>'X', 'credit_limit'=>'-5']), handlePost('companies', 'save')],
    'assign_customer' => [$extra = static fn(): array => ['profile' => array_intersect_key(Customers::commerceProfile($solo()) ?? [], ['discount_bps'=>1, 'company_code'=>1])],
        $_GET = ['id'=>(string)$solo()], $_POST = $assign(['customer_group_id'=>(string)$g('free'), 'company_id'=>(string)$co('empty')]), handlePost('customer', 'b2b')],
    'unassign_customer' => [$_GET = ['id'=>(string)$member], $_POST = $assign([]), handlePost('customer', 'b2b')],
    'assign_foreign_group' => [$_GET = ['id'=>(string)$solo()], $_POST = $assign(['customer_group_id'=>(string)$g('free', $foreign)]), handlePost('customer', 'b2b')],
    'assign_foreign_company' => [$_GET = ['id'=>(string)$solo()], $_POST = $assign(['company_id'=>(string)$co('empty', $foreign)]), handlePost('customer', 'b2b')],
    'assign_foreign_customer' => [$_GET = ['id'=>(string)$solo($foreign)], $_POST = $assign(['customer_group_id'=>(string)$g('free')]), handlePost('customer', 'b2b')],
    'assign_viewer' => [$_SESSION['admin_role'] = 'viewer', $_GET = ['id'=>(string)$solo()], $_POST = $assign(['customer_group_id'=>(string)$g('free')]), handlePost('customer', 'b2b')],
    'assign_no_csrf' => [$_GET = ['id'=>(string)$solo()], $_POST = array_diff_key($assign(['customer_group_id'=>(string)$g('free')]), ['csrf_token'=>1]), handlePost('customer', 'b2b')],
};
PHP;
file_put_contents($a126Fixture, $a126Source);
$a126Run = static function(array $args) use ($a126Fixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $a126Fixture], $args), [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
// Refused actions: expected flash fragment; groups, companies, customer assignments and audit log must stay byte-identical.
$a126Refused = [
    'group_delete_members' => 'zákazníci', 'group_delete_pricelist' => 'cenníky a126list-', 'group_delete_promo' => 'promo akcie a126promotion-', 'group_delete_foreign' => 'neexistuje',
    'group_edit_foreign' => 'neexistuje', 'group_dup_code' => 'už v tomto obchode existuje', 'group_bad_discount' => 'Zľava skupiny', 'group_array' => 'Neplatný formulár', 'group_no_csrf' => 'CSRF',
    'company_delete_members' => 'priradených zákazníkov', 'company_delete_foreign' => 'neexistuje', 'company_edit_foreign' => 'neexistuje', 'company_dup_code' => 'už v tomto obchode existuje',
    'company_invoice_no_terms' => 'splatnosť aspoň 1 deň', 'company_bad_vat' => 'IČ DPH', 'company_bad_approval' => 'Stav schválenia', 'company_negative_credit' => 'Kreditný limit',
    'assign_foreign_group' => 'nepatrí do tohto obchodu', 'assign_foreign_company' => 'nepatrí do tohto obchodu', 'assign_foreign_customer' => 'Zákazník neexistuje', 'assign_no_csrf' => 'CSRF',
];
$a126Actions = array_merge(['groups_page', 'companies_page', 'customer_page', 'group_create', 'group_edit', 'group_delete_free', 'company_create', 'company_keep_vat', 'company_change_vat',
    'company_approve_invoice', 'company_invoice_pending', 'company_delete_empty', 'assign_customer', 'unassign_customer', 'assign_viewer'], array_keys($a126Refused));
$a126Drivers = ['sqlite' => $a126Root . '/b2b.sqlite'];
if (getenv('TEST_MYSQL_DSN')) $a126Drivers['mysql'] = 'bbe_a126_b2b_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_test';
else print "  [SKIP] Audit A12.6 native B2B admin (set TEST_MYSQL_DSN)\n";
try {
    foreach ($a126Drivers as $a126Driver => $a126Target) {
        [$a126Exit, , $a126Stderr] = $a126Run([$a126Driver, 'setup', 'default', 'none', dirname(TEST_CLI_DIR), $a126Target]);
        assert_equals([0, ''], [$a126Exit, $a126Stderr], "A12.6 {$a126Driver}: migrated fixture with groups, companies, a price list, a promotion and customers per store");
        foreach (['default', 'cz'] as $a126Store) {
            $a126Foreign = $a126Store === 'default' ? 'cz' : 'default';
            foreach ($a126Actions as $a126Action) {
                $a126Label = "A12.6 {$a126Driver}/{$a126Store}/{$a126Action}";
                [$a126Exit, $a126Stdout, $a126Stderr] = $a126Run([$a126Driver, 'run', $a126Store, $a126Action, dirname(TEST_CLI_DIR), $a126Target]);
                if ($a126Action === 'assign_viewer') {
                    // Security::adminRequire answers 403 and stops before any write.
                    assert_true(!str_contains((string)$a126Stdout, '"type":"ok"') && $a126Stderr === '', "{$a126Label}: viewer cannot change the assignment");
                    $a126Result = json_decode((string)$a126Stdout, true);
                    if (is_array($a126Result)) assert_equals($a126Result['before'], $a126Result['after'], "{$a126Label}: nothing changed");
                    continue;
                }
                assert_equals([0, ''], [$a126Exit, $a126Stderr], "{$a126Label}: actual handler exits without errors");
                $a126Result = json_decode((string)$a126Stdout, true) ?: ['output'=>'', 'before'=>[], 'after'=>[], 'flash'=>null, 'extra'=>[]];
                $a126Output = (string)$a126Result['output'];
                $a126Flash = $a126Result['flash'] ?? [];
                $a126Own = static fn(array $rows, string $store): array => array_values(array_filter($rows, static fn(array $r): bool => $r['store_id'] === $store));
                $a126Groups = array_column($a126Own($a126Result['after']['customer_groups'] ?? [], $a126Store), null, 'code');
                $a126Companies = array_column($a126Own($a126Result['after']['companies'] ?? [], $a126Store), null, 'code');
                $a126Customers = array_column($a126Own($a126Result['after']['customers'] ?? [], $a126Store), null, 'email');
                $a126IdOf = static fn(string $table, string $code): ?int => isset(array_column($a126Result['after'][$table] ?? [], 'id', 'code')[$code]) ? (int)array_column($a126Result['after'][$table], 'id', 'code')[$code] : null;
                $a126NewAudit = array_slice($a126Result['after']['audit'] ?? [], count($a126Result['before']['audit'] ?? []));
                assert_true(!preg_match('/A126[A-Z]+-' . $a126Foreign . '-NAME/', $a126Output), "{$a126Label}: no foreign store data rendered");
                foreach (['customer_groups', 'companies', 'customers'] as $a126Table) {
                    assert_equals($a126Own($a126Result['before'][$a126Table] ?? [], $a126Foreign), $a126Own($a126Result['after'][$a126Table] ?? [], $a126Foreign), "{$a126Label}: foreign store {$a126Table} unchanged");
                }
                if (isset($a126Refused[$a126Action])) {
                    assert_equals(['err', true], [$a126Flash['type'] ?? null, str_contains((string)($a126Flash['msg'] ?? ''), $a126Refused[$a126Action])],
                        "{$a126Label}: refused with a specific error (" . ($a126Flash['msg'] ?? '') . ')');
                    assert_equals($a126Result['before'], $a126Result['after'], "{$a126Label}: groups, companies, assignments and audit log unchanged");
                    continue;
                }
                if (!str_ends_with($a126Action, '_page')) {
                    assert_equals('ok', $a126Flash['type'] ?? null, "{$a126Label}: save succeeds (" . ($a126Flash['msg'] ?? '') . ')');
                    assert_equals([$a126Store], array_values(array_unique(array_column($a126NewAudit, 'store_id'))), "{$a126Label}: change audited in the active store");
                }
                $a126Co = $a126Companies["a126co-{$a126Store}"] ?? [];
                match ($a126Action) {
                    'groups_page' => assert_true(str_contains($a126Output, "value=\"A126GFREE-{$a126Store}-NAME\"") && str_contains($a126Output, 'value="5"') && str_contains($a126Output, 'name="csrf_token"'),
                        "{$a126Label}: own groups and the edited group's 5 % discount rendered"),
                    'companies_page' => assert_true(str_contains($a126Output, "value=\"A126CO-{$a126Store}-NAME\"") && str_contains($a126Output, 'overené 2026-01-02') && str_contains($a126Output, 'bez limitu'),
                        "{$a126Label}: own companies, VAT validation date and unlimited credit rendered"),
                    'customer_page' => assert_true(str_contains($a126Output, 'action=b2b&id=') && preg_match('#<option value="\d+" selected>A126GMEM-' . $a126Store . '-NAME#', $a126Output) === 1
                        && preg_match('#<option value="\d+" selected>A126CO-' . $a126Store . '-NAME#', $a126Output) === 1, "{$a126Label}: B2B card preselects the customer's own group and company"),
                    'group_create' => assert_equals([750, 1], [(int)($a126Groups['velkoobchod-zlty']['discount_bps'] ?? -1), (int)($a126Groups['velkoobchod-zlty']['active'] ?? 0)], "{$a126Label}: Unicode name → code, 7,5 % → 750 bps"),
                    'group_edit' => assert_equals(['Renamed', 0, 0], [$a126Groups["a126free-{$a126Store}"]['name'] ?? null, (int)($a126Groups["a126free-{$a126Store}"]['discount_bps'] ?? -1), (int)($a126Groups["a126free-{$a126Store}"]['active'] ?? 1)],
                        "{$a126Label}: rename, empty discount = 0, deactivate"),
                    'group_delete_free' => assert_equals([false, true], [isset($a126Groups["a126free-{$a126Store}"]), isset($a126Groups["a126mem-{$a126Store}"])], "{$a126Label}: only the unused group deleted"),
                    'company_create' => assert_equals(['12345678', 'SK2020123456', 'SK', null, 'approved', 50000, 14, 1],
                        array_values(array_intersect_key($a126Companies['zlta-firma-s-r-o'] ?? [], array_flip(['registration_number', 'vat_id', 'vat_country', 'vat_validated_at', 'approval_status', 'credit_limit', 'payment_terms_days', 'invoice_purchase']))),
                        "{$a126Label}: VAT ID normalized with its country, not marked validated; approval, credit, terms, invoice stored"),
                    'company_keep_vat' => assert_equals(['87654321', 'SK1111111111', '2026-01-02 03:04:05'], [$a126Co['registration_number'] ?? null, $a126Co['vat_id'] ?? null, substr((string)($a126Co['vat_validated_at'] ?? ''), 0, 19)],
                        "{$a126Label}: unchanged VAT ID keeps its validation"),
                    'company_change_vat' => assert_equals(['SK2222222222', null], [$a126Co['vat_id'] ?? null, array_key_exists('vat_validated_at', $a126Co) ? $a126Co['vat_validated_at'] : 'missing'], "{$a126Label}: new VAT ID clears the validation timestamp"),
                    'company_approve_invoice' => assert_equals(['approved', ['invoice'=>[true, false]]], [$a126Co['approval_status'] ?? null, $a126Result['extra']],
                        "{$a126Label}: approved company with terms offers invoice purchase up to the credit limit"),
                    'company_invoice_pending' => assert_equals([true, ['invoice'=>[false, false]], true], [(int)($a126Co['invoice_purchase'] ?? 0) === 1, $a126Result['extra'], str_contains((string)($a126Flash['msg'] ?? ''), 'až po schválení')],
                        "{$a126Label}: invoice purchase stays unavailable until the company is approved, and the admin is told so"),
                    'company_delete_empty' => assert_equals([false, true], [isset($a126Companies["a126empty-{$a126Store}"]), isset($a126Companies["a126co-{$a126Store}"])], "{$a126Label}: only the company without members deleted"),
                    'assign_customer' => assert_equals([[$a126IdOf('customer_groups', "a126free-{$a126Store}"), $a126IdOf('companies', "a126empty-{$a126Store}")], ['profile'=>['discount_bps'=>500, 'company_code'=>"a126empty-{$a126Store}"]]],
                        [[(int)($a126Customers["a126-solo-{$a126Store}@example.test"]['customer_group_id'] ?? 0), (int)($a126Customers["a126-solo-{$a126Store}@example.test"]['company_id'] ?? 0)], $a126Result['extra']],
                        "{$a126Label}: customer joins the own group and company; the commerce profile (pricing, invoice) sees them"),
                    'unassign_customer' => assert_equals(['customer_group_id'=>null, 'company_id'=>null], array_intersect_key($a126Customers["a126-{$a126Store}@example.test"] ?? [], ['customer_group_id'=>1, 'company_id'=>1]),
                        "{$a126Label}: empty selection removes group and company"),
                };
            }
        }
    }
} finally {
    if (isset($a126Drivers['mysql'])) $a126Run(['mysql', 'teardown', 'default', 'none', dirname(TEST_CLI_DIR), $a126Drivers['mysql']]);
    $a126Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($a126Root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($a126Files as $a126File) $a126File->isDir() ? rmdir($a126File->getPathname()) : unlink($a126File->getPathname());
    rmdir($a126Root);
}
