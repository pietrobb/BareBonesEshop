<?php

// Review round 8 — upgrade from released versions: a v2.0.0 shop customised templates/emails/ files in place; the
// updater refuses those modified core files, and the current release keeps customisations as store overrides.
// cli/email-templates.php import moves them (only parts that differ from the untouched v2.0.0 files), with the same
// validation as the admin editor — an old order confirmation without the consumer information is refused.
echo "\n--- Review round 8: upgrade — customised v2.0.0 e-mail templates become store overrides ---\n";

$r8uPreviousStore = StoreContext::current();
$r8uPreviousLocale = I18n::locale();
$r8uDir = sys_get_temp_dir() . '/bbe-r8u-' . bin2hex(random_bytes(4));
$r8uWrite = static function(string $file, string $content): void {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
    file_put_contents($file, $content);
};
$r8uTemplates = ['order_shipped', 'order_paid', 'order_confirmation'];
$r8uClear = static function() use ($r8uTemplates): void {
    Db::get()->prepare('DELETE FROM email_template_overrides WHERE template IN (?, ?, ?)')->execute($r8uTemplates);
};
try {
    I18n::setLocale('en');
    StoreContext::activate(StoreContext::resolve(Bootstrap::stores(), '/', '', 'default'));
    $r8uClear();

    $r8uOld = '<p>Hello {{customer_name}}, order {{order_number}}: {{order_total}}</p>';
    $r8uErrors = EmailTemplates::validate('order_confirmation', 'Order {{order_number}}', $r8uOld, '');
    assert_true(implode(' ', $r8uErrors) !== '' && str_contains(implode(' ', $r8uErrors), 'withdrawal_url') && str_contains(implode(' ', $r8uErrors), 'terms_url'),
        'R8 upgrade: an order-confirmation override without the consumer information is refused (' . implode(' ', $r8uErrors) . ')');
    assert_equals([], EmailTemplates::validate('order_shipped', 'Shipped {{order_number}}', '<p>{{order_number}}</p>', ''), 'R8 upgrade: other templates have no required variables');

    // v2.0.0 base: the shop's untouched files. custom: the shop's copy taken before the update.
    $r8uShipped = (string)EmailTemplates::fileSource('order_shipped', 'sk')['html'];
    $r8uPaid = str_replace("\r\n", "\n", (string)EmailTemplates::fileSource('order_paid', 'sk')['html']); // LF like a release export
    foreach (['base', 'custom'] as $r8uSide) {
        $r8uWrite("{$r8uDir}/{$r8uSide}/sk/order_shipped.html", $r8uSide === 'custom' ? $r8uShipped . "\n<p>R8U tweak {{order_number}}</p>" : $r8uShipped);
        $r8uWrite("{$r8uDir}/{$r8uSide}/sk/order_shipped.subject.txt", "Odoslané {{order_number}}\n");
        $r8uWrite("{$r8uDir}/{$r8uSide}/sk/order_paid.html", $r8uPaid);
        $r8uWrite("{$r8uDir}/{$r8uSide}/sk/order_confirmation.html", $r8uSide === 'custom' ? str_replace('Hello', 'R8U Ahoj', $r8uOld) : $r8uOld);
        $r8uWrite("{$r8uDir}/{$r8uSide}/de/order_shipped.html", $r8uSide === 'custom' ? '<p>R8U de</p>' : '<p>de</p>');
        $r8uWrite("{$r8uDir}/{$r8uSide}/en/core_drift_alert.html", $r8uSide === 'custom' ? '<p>R8U all fine</p>' : '<p>alert</p>');
    }
    // A Windows checkout of the custom copy (CRLF) of an unchanged file is not a customisation.
    $r8uWrite("{$r8uDir}/custom/sk/order_paid.html", str_replace("\n", "\r\n", $r8uPaid));

    $r8uStatus = static fn(array $rows): array => array_combine(
        array_map(static fn(array $r): string => $r['template'] . '/' . $r['locale'], $rows), array_column($rows, 'status'));
    $r8uDry = EmailTemplates::importCustomised("{$r8uDir}/custom", "{$r8uDir}/base", true);
    assert_equals(['core_drift_alert/en' => 'skipped', 'order_confirmation/sk' => 'refused', 'order_shipped/de' => 'skipped', 'order_shipped/sk' => 'would_import'],
        $r8uStatus($r8uDry), 'R8 upgrade: dry run lists only the changed templates (unchanged and CRLF-only copies are not customisations)');
    assert_true((int)Db::get()->query("SELECT COUNT(*) FROM email_template_overrides WHERE template IN ('order_shipped', 'order_confirmation')")->fetchColumn() === 0,
        'R8 upgrade: dry run writes no override');

    $r8uRows = EmailTemplates::importCustomised("{$r8uDir}/custom", "{$r8uDir}/base", false);
    $r8uByKey = array_combine(array_map(static fn(array $r): string => $r['template'] . '/' . $r['locale'], $r8uRows), $r8uRows);
    $r8uOverride = EmailTemplates::override('order_shipped', 'sk');
    assert_true(($r8uByKey['order_shipped/sk']['status'] ?? '') === 'imported' && $r8uOverride !== null
        && str_contains($r8uOverride['html'], 'R8U tweak {{order_number}}') && $r8uOverride['subject'] === trim((string)EmailTemplates::fileSource('order_shipped', 'sk')['subject']),
        'R8 upgrade: a customised part becomes the override; the unchanged subject takes the current default');
    assert_true(($r8uByKey['order_confirmation/sk']['status'] ?? '') === 'refused' && str_contains($r8uByKey['order_confirmation/sk']['message'], 'withdrawal_url')
        && EmailTemplates::override('order_confirmation', 'sk') === null,
        'R8 upgrade: the v2.0.0 order confirmation without consumer information is refused and not stored');
    assert_true(EmailTemplates::override('order_paid', 'sk') === null && EmailTemplates::source('core_drift_alert', 'en')['html'] !== '<p>R8U all fine</p>',
        'R8 upgrade: an unchanged template and a locked alert are not imported');
    assert_true(str_contains(MailService::render('order_shipped', 'sk', ['order_number' => 'R8U-1', 'customer_name' => 'Ján'])['html'], 'R8U tweak R8U-1'),
        'R8 upgrade: the imported override is what MailService sends');
    $r8uAgain = EmailTemplates::importCustomised("{$r8uDir}/custom", "{$r8uDir}/base", false);
    assert_equals('skipped', $r8uStatus($r8uAgain)['order_shipped/sk'] ?? null, 'R8 upgrade: a second import does not overwrite an existing override');

    $r8uCli = static function(array $args): array {
        $process = proc_open(array_merge([PHP_BINARY, dirname(TEST_CLI_DIR) . '/cli/email-templates.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    };
    [$r8uExit, $r8uOut] = $r8uCli(['import', '--custom=' . dirname(TEST_CLI_DIR) . '/templates/emails', '--base=' . $r8uDir . '/base', '--dry-run']);
    assert_true($r8uExit === 2 && str_contains($r8uOut, 'copy taken before the update'), 'R8 upgrade CLI: the installed templates/emails is refused as --custom: ' . trim($r8uOut));
    [$r8uExit, $r8uOut] = $r8uCli(['import', '--custom=' . $r8uDir . '/custom']);
    assert_true($r8uExit === 2 && str_contains($r8uOut, 'Usage:'), 'R8 upgrade CLI: --base is required');
} finally {
    $r8uClear();
    StoreContext::activate($r8uPreviousStore);
    I18n::setLocale($r8uPreviousLocale);
    if (is_dir($r8uDir)) {
        $r8uTree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r8uDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($r8uTree as $r8uPath) $r8uPath->isDir() ? rmdir($r8uPath->getPathname()) : unlink($r8uPath->getPathname());
        rmdir($r8uDir);
    }
}
