<?php

// Review round 26 (R61), lows 4 and 5 (goal #653).
//  low 4: account-page.html copies made before 2.1.3 read `invoice` (now `invoices`); theme-check said nothing.
//  low 5: only Bootstrap::stores() refused a stores.json over 2 MB; the locale, the admin, orders and mail read all of it.
echo "\n--- Audit round 61: stores.json size limit for every reader; account-page.html copies before 2.1.3 ---\n";
$r61tRepo = dirname(TEST_CLI_DIR);
$r61tRoot = test_fixture_path('r61-theme-limits');
mkdir($r61tRoot, 0700, true);

try {
    $r61tChild = $r61tRoot . '/child.php';
    file_put_contents($r61tChild, <<<'PHP'
<?php
[, $root, $dir] = $argv;
require $root . '/src/autoload.php';
DataOverrides::setDirForTesting($dir);
$json = json_encode(['default_store' => 'default', 'default_locale' => 'sk', 'stores' => ['default' => ['name' => 'R61T', 'default_locale' => 'sk']]]);
file_put_contents($dir . '/stores.json', $json . str_repeat(' ', DataOverrides::STORES_MAX_BYTES + 1 - strlen($json)));
$i18n = new ReflectionMethod(I18n::class, 'stores');
$mail = new ReflectionMethod(MailService::class, 'defaultLocale');
echo 'r61t ' . json_encode([
    'bootstrap' => Bootstrap::stores(),
    'settings' => StoreSettings::load(),
    'i18n' => $i18n->invoke(null),
    'mail' => $mail->invoke(null),
    'tooLarge' => DataOverrides::storesTooLarge($dir . '/stores.json'),
]) . "\n";
PHP);
    $r61tOut = [];
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $r61tChild, $r61tRepo, $r61tRoot])) . ' 2>&1', $r61tOut);
    $r61tLine = array_values(array_filter($r61tOut, static fn(string $line): bool => str_starts_with($line, 'r61t ')))[0] ?? '';
    assert_equals(['bootstrap' => [], 'settings' => [], 'i18n' => [], 'mail' => 'en', 'tooLarge' => true],
        $r61tLine !== '' ? json_decode(substr($r61tLine, 5), true) : implode(' | ', $r61tOut),
        'R61 stores.json: over 2 MB, the locale, the admin settings and mail read no stores, as Bootstrap does (was: all six stores)');
} catch (Throwable $e) {
    assert_true(false, 'R61 stores.json limit (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    @unlink($r61tRoot . '/stores.json');
    @unlink($r61tRoot . '/child.php');
}

try {
    // A store's copy of account-page.html from before 2.1.3: it reads `invoice`, so it lists no credit notes.
    $r61tBlocks = $r61tRoot . '/blocks';
    mkdir($r61tBlocks . '/stores/default/storefront', 0700, true);
    file_put_contents($r61tBlocks . '/stores/default/storefront/account-page.html',
        '<td>{{#if invoice}}<a href="{{invoice.url}}">{{invoice.number}}</a>{{else}}—{{/if}}</td>');
    $r61tCheck = [];
    exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, TEST_CLI_DIR . '/theme-check.php', '--blocks-dir=' . $r61tBlocks, '--json'])) . ' 2>&1', $r61tCheck);
    $r61tReport = json_decode(implode("\n", $r61tCheck), true);
    $r61tWarned = array_values(array_filter((array)($r61tReport['warnings'] ?? []), static fn(string $w): bool => str_contains($w, 'account-page.html') && str_contains($w, 'credit notes')));
    assert_true(count($r61tWarned) === 1, 'R61 theme-check: a copy of account-page.html that reads `invoice` is reported (was: GO without a word)');
    $r61tShipped = array_values(array_filter((array)($r61tReport['warnings'] ?? []), static fn(string $w): bool => str_contains($w, 'themes/default/blocks/storefront/account-page.html:')));
    assert_equals([], $r61tShipped, 'R61 theme-check: the shipped account-page.html is not reported');
} catch (Throwable $e) {
    assert_true(false, 'R61 theme-check (' . get_class($e) . ': ' . $e->getMessage() . ')');
} finally {
    @unlink($r61tBlocks . '/stores/default/storefront/account-page.html');
    @rmdir($r61tBlocks . '/stores/default/storefront');
    @rmdir($r61tBlocks . '/stores/default');
    @rmdir($r61tBlocks . '/stores');
    @rmdir($r61tBlocks);
    @rmdir($r61tRoot);
}
