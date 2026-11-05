<?php

echo "\n--- R17 updater: check/apply refuse what doctor reports as FAIL, a MariaDB dump client against MySQL is named, api.php removal is flagged ---\n";

$r17upBase = test_fixture_path('r17-updater');
try {
    foreach (['base', 'target', 'installed'] as $tree) mkdir("{$r17upBase}/{$tree}/src", 0750, true);
    file_put_contents($r17upBase . '/base/src/Core.php', "before\n");
    file_put_contents($r17upBase . '/target/src/Core.php', "after\n");
    file_put_contents($r17upBase . '/installed/src/Core.php', "before\n");
    UpdatePackageBuilder::build($r17upBase . '/base', $r17upBase . '/target', $r17upBase . '/package', '4.1.0', '4.0.0');
    $r17upPackage = new UpdatePackage($r17upBase . '/package', null, true);
    $r17upState = $r17upBase . '/state';
    $r17upInstalled = $r17upBase . '/installed';
    (new Updater($r17upInstalled, $r17upState))->baseline('4.0.0');
    $r17upInside = ['jobs_dir' => $r17upInstalled . '/jobs', 'update_storage_dir' => $r17upState];
    $r17upOutside = ['jobs_dir' => $r17upBase . '/private/jobs', 'update_storage_dir' => $r17upState];

    // jobs_dir in the web root: doctor says FAIL, and so do check and apply (they used to apply it).
    $r17upDoctor = array_column((new Updater($r17upInstalled, $r17upState, null, null, null, null, $r17upInside))->doctor(null, null, false)['checks'], 'level', 'name');
    $r17upCheck = (new Updater($r17upInstalled, $r17upState, null, null, null, null, $r17upInside))->check($r17upPackage);
    $r17upBlocker = implode(' | ', $r17upCheck['blockers']);
    assert_equals(['fail', false, true], [$r17upDoctor['config'] ?? null, $r17upCheck['can_apply'], str_contains($r17upBlocker, 'Doctor FAIL (config)') && str_contains($r17upBlocker, 'jobs_dir')],
        "R17 updater: jobs_dir in the web root is a doctor FAIL and a check blocker ({$r17upBlocker})");
    $r17upApplied = null;
    try {
        (new Updater($r17upInstalled, $r17upState, null, null, null, null, $r17upInside))->apply($r17upPackage);
        $r17upApplied = 'applied';
    } catch (Throwable $e) {
        $r17upApplied = get_class($e);
    }
    assert_equals(['before', true], [trim((string)file_get_contents($r17upInstalled . '/src/Core.php')), $r17upApplied !== 'applied'],
        "R17 updater: apply refuses it too and changes no file ({$r17upApplied})");
    assert_true((new Updater($r17upInstalled, $r17upState, null, null, null, null, $r17upOutside))->check($r17upPackage)['can_apply'],
        'R17 updater: with jobs_dir outside the web root the same package can be applied');

    // The CLI passes config.php: `update.php check` exits 1 with the doctor's reason.
    file_put_contents($r17upInstalled . '/config.php', '<?php return ' . var_export($r17upInside, true) . ";\n");
    $r17upCli = static function (string $command) use ($r17upInstalled, $r17upBase): array {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(TEST_CLI_DIR . '/update.php') . " {$command} " . escapeshellarg('--package=' . $r17upBase . '/package')
            . ' --allow-unsigned ' . escapeshellarg('--installed=' . $r17upInstalled) . ' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    };
    [$r17upCode, $r17upOut] = $r17upCli('check');
    assert_true($r17upCode === 1 && str_contains($r17upOut, 'Doctor FAIL (config)'), "R17 updater: php cli/update.php check exits 1 on the doctor FAIL ({$r17upCode}: " . str_replace("\n", ' / ', substr($r17upOut, 0, 600)) . ')');
    [$r17upCode, $r17upOut] = $r17upCli('apply');
    assert_true($r17upCode === 1 && trim((string)file_get_contents($r17upInstalled . '/src/Core.php')) === 'before', "R17 updater: php cli/update.php apply exits 1 and changes nothing ({$r17upCode})");

    // A shop from before 2.1.0 still has api.php: doctor and check say the payment webhooks move.
    file_put_contents($r17upInstalled . '/api.php', "<?php\n");
    $r17upLegacy = new Updater($r17upInstalled, $r17upState, null, null, null, null, $r17upOutside);
    $r17upLevels = array_column($r17upLegacy->doctor(null, null, false)['checks'], 'level', 'name');
    $r17upWarnings = implode(' | ', $r17upLegacy->check($r17upPackage)['warnings']);
    assert_true(($r17upLevels['payment_webhooks'] ?? null) === 'warn' && str_contains($r17upWarnings, '/api/v1/webhooks/payment/'),
        "R17 updater: an installed api.php is flagged by doctor and check with the new webhook URL ({$r17upWarnings})");
    unlink($r17upInstalled . '/api.php');
    assert_true(!isset(array_column($r17upLegacy->doctor(null, null, false)['checks'], 'level', 'name')['payment_webhooks']), 'R17 updater: no api.php, no webhook warning');
} catch (Throwable $e) {
    assert_true(false, 'R17 updater: the scenario raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
}

// mariadb-dump against a MySQL server writes generated columns MySQL refuses on restore (ERROR 3105).
$r17upMaria = 'mariadb-dump from 11.4.3-MariaDB, client 10.19 for debian-linux-gnu (x86_64)';
$r17upMysql = 'mysqldump  Ver 8.4.3 for Linux on x86_64 (MySQL Community Server - GPL)';
$r17upProblem = MysqlUpdateDatabaseBackup::clientProblem($r17upMaria, '8.4.3', ['categories.parent_id_uniq', 'invoices.invoice_order_id']);
assert_true($r17upProblem !== null && str_contains($r17upProblem, 'ERROR 3105') && str_contains($r17upProblem, 'categories.parent_id_uniq') && str_contains($r17upProblem, 'update_mysql_dump_binary'),
    'R17 updater: a MariaDB dump client against MySQL is refused with the reason and the setting to change (' . $r17upProblem . ')');
assert_equals([null, null, null], [MysqlUpdateDatabaseBackup::clientProblem($r17upMaria, '11.4.3-MariaDB-ubu2404', ['categories.parent_id_uniq']),
    MysqlUpdateDatabaseBackup::clientProblem($r17upMysql, '8.4.3', ['categories.parent_id_uniq']), MysqlUpdateDatabaseBackup::clientProblem($r17upMaria, '8.4.3', [])],
    'R17 updater: MariaDB to MariaDB, MySQL to MySQL and a schema without generated columns are fine');
$r17upRunbook = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/docs/AI_UPDATE_RUNBOOK.md');
assert_true(str_contains($r17upRunbook, 'ERROR 3105') && str_contains($r17upRunbook, '/api/v1/webhooks/payment/'), 'R17 updater: the runbook covers the dump client and the api.php webhook move');
