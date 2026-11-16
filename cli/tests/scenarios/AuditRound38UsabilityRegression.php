<?php

// Review round 12 (R38, usability):
// 4. `php cli/setup.php doctor` printed the fix ("-> Set operations.worker_cron …") under checks that passed.
// 5. Missing translations: the admin checklist had no admin.doctor.operations_cron title in any language, sk.php and
//    cs.php showed "Low stock" and "Stock" in English. (The payment status label of the manual payment change is
//    checked through its handler in AuditRound38RefundUnknownRegression, the order-done text through index.php in
//    AuditCheckoutBankTransferRegression, on SQLite and MySQL.)
// 6. The sample catalog had attribute texts in English and Slovak only.
// 7. The web installer (English only) is checked over HTTP in BookshopInstallerRegression (SQLite only, as it installs).
// 8. README and runbook disagreed on the account running cron, backups and restores.
// No database driver decides these: the doctor runs against the test checkout's own configuration.
echo "\n--- R38 usability: doctor advice only for problems; checklist and stock labels translated ---\n";

$r38yApp = dirname(TEST_CLI_DIR);
$r38yRun = static function (array $args) use ($r38yApp): array {
    $process = proc_open(array_merge([PHP_BINARY, $r38yApp . '/cli/setup.php'], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $r38yApp);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'out' => $out, 'err' => $err];
};

// 4. The same checks as JSON and as text: the JSON keeps every action, the text shows them only under WARN/FAIL.
$r38yJson = $r38yRun(['doctor', '--json']);
$r38yChecks = json_decode($r38yJson['out'], true)['checks'] ?? null;
assert_true(is_array($r38yChecks) && $r38yChecks !== [], 'R38 doctor: setup doctor --json runs (' . trim(substr($r38yJson['out'] . $r38yJson['err'], 0, 300)) . ')');
$r38yPassWithAction = array_values(array_filter((array)$r38yChecks, static fn(array $c): bool => ($c['status'] ?? '') === 'pass' && ($c['action'] ?? '') !== ''));
assert_true($r38yPassWithAction !== [], 'R38 doctor: some passing checks carry a fix in their data (precondition)');
$r38yText = $r38yRun(['doctor']);
preg_match_all('~^\[PASS\] [^\n]*\n  -> ~m', $r38yText['out'], $r38yAdvised);
assert_true(str_contains($r38yText['out'], '[PASS] ') && $r38yAdvised[0] === [],
    'R38 doctor: no "->" advice under a passing check (' . count($r38yAdvised[0]) . ' passing checks advised; was: every one with an action)');
$r38yProblems = array_values(array_filter((array)$r38yChecks, static fn(array $c): bool => ($c['status'] ?? '') !== 'pass' && ($c['action'] ?? '') !== ''));
if ($r38yProblems !== []) {
    assert_true(str_contains($r38yText['out'], '  -> ' . $r38yProblems[0]['action']), 'R38 doctor: a warning or failure still shows its fix');
}

// 5. Translations, read as the admin renders them.
$r38yLocale = I18n::locale();
try {
    foreach (['en', 'sk', 'cs', 'de', 'pl', 'hu', 'es'] as $r38yLang) {
        I18n::setLocale($r38yLang);
        $r38yTitle = SetupChecklist::title('operations.cron');
        assert_true($r38yTitle !== null && $r38yTitle !== 'admin.doctor.operations_cron', "R38 i18n {$r38yLang}: the checklist names the worker cron check (was: no title)");
    }
    foreach (['sk', 'cs'] as $r38yLang) {
        I18n::setLocale($r38yLang);
        assert_true(!in_array(I18n::t('admin.stat.low_stock'), ['Low stock', 'admin.stat.low_stock'], true) && !in_array(I18n::t('admin.th.stock'), ['Stock', 'admin.th.stock'], true),
            "R38 i18n {$r38yLang}: stock labels are translated (was: \"Low stock\", \"Stock\")");
    }
    // 6. The sample catalog's attribute labels as the product page renders them (I18n::attrLabel()), and every label,
    //    type name and translatable option of catalog.json in Czech and German.
    foreach (['cs' => ['brand' => 'Značka', 'material' => 'Materiál', 'storage' => 'Úložiště'], 'de' => ['brand' => 'Marke', 'care_instructions' => 'Pflegehinweise', 'storage' => 'Speicher']] as $r38yLang => $r38yExpected) {
        I18n::setLocale($r38yLang);
        $r38yGot = [];
        foreach (array_keys($r38yExpected) as $r38yAttr) $r38yGot[$r38yAttr] = I18n::attrLabel($r38yAttr);
        assert_equals($r38yExpected, $r38yGot, "R38 i18n {$r38yLang}: sample catalog attributes are labelled in the store's language (was: Brand, Material …)");
    }
    $r38yCatalog = json_decode((string)file_get_contents($r38yApp . '/catalog.json'), true);
    $r38yMissing = [];
    $r38yCheck = static function (string $where, mixed $texts) use (&$r38yMissing): void {
        if (!is_array($texts)) return;
        foreach (['cs', 'de'] as $lang) if (trim((string)($texts[$lang] ?? '')) === '') $r38yMissing[] = "{$where}:{$lang}";
    };
    foreach ((array)($r38yCatalog['product_types'] ?? []) as $r38yType => $r38yDef) {
        $r38yCheck("{$r38yType}.name", $r38yDef['name'] ?? null);
        foreach ((array)($r38yDef['attributes'] ?? []) as $r38yAttr => $r38yAttrDef) {
            $r38yCheck("{$r38yType}.{$r38yAttr}.label", $r38yAttrDef['label'] ?? null);
            if (!empty($r38yAttrDef['options_translatable'])) foreach ((array)$r38yAttrDef['options'] as $r38yOption => $r38yTexts) $r38yCheck("{$r38yType}.{$r38yAttr}.{$r38yOption}", $r38yTexts);
        }
    }
    foreach ((array)($r38yCatalog['global_attributes'] ?? []) as $r38yAttr => $r38yAttrDef) $r38yCheck("global.{$r38yAttr}.label", $r38yAttrDef['label'] ?? null);
    assert_equals([], $r38yMissing, 'R38 i18n: catalog.json has Czech and German texts for every type, attribute and option');
} finally {
    I18n::setLocale($r38yLocale);
}

// 8. One account story in README and runbook: the web server user owns the private runtime directory and runs cron,
//    backups, monitoring and restores from its crontab; the owner of the application root deploys (was: "backup
//    operator", backups and monitor without an account).
$r38yReadme = (string)file_get_contents($r38yApp . '/README.md');
$r38yRunbook = (string)file_get_contents($r38yApp . '/docs/OPERATIONS_RUNBOOK.md');
assert_equals([true, true, true, false, true, true, true],
    [str_contains($r38yReadme, "crontab -u www-data -e"), str_contains($r38yReadme, '**The web server user** (PHP-FPM, e.g. `www-data`) owns the private runtime directory'),
     str_contains($r38yReadme, 'chown -R www-data: /var/www/bareboneseshop-private'), str_contains($r38yRunbook, 'backup operator'),
     str_contains($r38yRunbook, 'Schedule `php cli/backup.php --gc` at least daily in the application user\'s crontab'),
     str_contains($r38yRunbook, 'cli/monitor.php` from the application root every five minutes, in the application user\'s crontab'),
     str_contains($r38yRunbook, '**Run `cli/restore.php` as the application user**')],
    'R38 docs: README and runbook name the same account for cron, backups, monitoring and restores, and the owner of the private directory');
