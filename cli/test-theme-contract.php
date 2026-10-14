<?php
require_once __DIR__ . '/cli-only.php';
require_once __DIR__ . '/theme-check.php';

// Runs independently and from the canonical suite. All altered templates are disposable copies.
$themeContractExit = (static function(): int {
    $temp = sys_get_temp_dir() . '/bbe-theme-contract-' . bin2hex(random_bytes(8));
    $themePath = $temp . '/themes/probe/blocks/storefront';
    mkdir($themePath, 0700, true);
    mkdir($temp . '/blocks/stores/default/storefront', 0700, true);
    $manifest = ['name' => 'Contract probe', 'version' => '1.0.0'];
    file_put_contents($temp . '/themes/probe/theme.json', json_encode($manifest));
    $failures = 0;
    $assert = static function(bool $ok, string $message) use (&$failures): void {
        if (!$ok) $failures++;
        if (function_exists('assert_true')) assert_true($ok, 'Theme contract: ' . $message);
        else echo ($ok ? '[PASS] ' : '[FAIL] ') . $message . "\n";
    };
    $run = static fn(array $extra = []): array => runThemeCompatibility(array_merge([
        '--theme=probe', '--themes-dir=' . $temp . '/themes', '--blocks-dir=' . $temp . '/blocks',
    ], $extra));
    try {
        $baseline = runThemeCompatibility(['--theme=default']);
        $assert($baseline['status'] === 'GO' && $baseline['checks'] >= 100, 'real presenter + default theme pass all six scenarios');
        $legacy = (string)file_get_contents(__DIR__ . '/tests/fixtures/theme-contract/legacy-aeon.html');
        file_put_contents($themePath . '/checkout.html', $legacy);
        $result = $run();
        $diagnostics = implode("\n", $result['failures']);
        $assert($result['status'] === 'NO-GO', 'unmodified legacy Aeon fails before deployment');
        foreach (['[countries]', '[country.selected]', '[values.name]', '[values.email]', '[address.street]'] as $code) {
            $assert(str_contains($diagnostics, $code), 'legacy Aeon identifies ' . $code);
        }
        $fixed = str_replace(['form_values.', '{{#unless digital_only}}', '{{/unless}}<label for="checkout-country">',
            '{{#each countries}}<option value="{{code}}"{{#if selected}} selected{{/if}}>{{label}}</option>'],
            ['values.', '{{#if address_required}}', '{{/if}}<label for="checkout-country">',
            '{{#each country_options}}<option value="{{code}}"{{#if selected}} selected{{/if}}>{{name}}</option>'], $legacy);
        file_put_contents($themePath . '/checkout.html', $fixed);
        $assert($run()['status'] === 'GO', 'Aeon field/condition repair passes without rewriting design');
        $default = (string)file_get_contents(dirname(__DIR__) . '/themes/default/blocks/storefront/checkout.html');
        $mutations = [
            'empty country choices' => ['{{#each country_options}}', '{{#each missing_countries}}', '[countries]'],
            'renamed values contract' => ['values.name', 'missing_values.name', '[values.name]'],
            'required digital address' => ['{{#if address_required}} required{{/if}}', ' required', '[address.street]'],
            'missing CSRF' => ['name="csrf_token"', 'name="missing_csrf"', '[csrf]'],
            'missing payment choice' => ['name="payment"', 'name="missing_payment"', '[payment]'],
            'missing consents' => ['{{#each consents}}', '{{#each missing_consents}}', '[consent.contract_terms]'],
            'hidden checkout error' => ['{{checkout_error}}', '', '[error]'],
            'disabled order submit' => ['class="btn-checkout" type="submit"', 'class="btn-checkout" type="submit" disabled', '[submit]'],
            'unescaped form value' => ['{{values.name}}', '{{{values.name}}}', '[escaping]'],
        ];
        foreach ($mutations as $name => [$from, $to, $code]) {
            $assert(str_contains($default, $from), 'mutation target exists: ' . $name);
            file_put_contents($themePath . '/checkout.html', str_replace($from, $to, $default));
            $result = $run();
            $assert($result['status'] === 'NO-GO' && str_contains(implode("\n", $result['failures']), $code), 'rejects ' . $name);
        }
        file_put_contents($themePath . '/checkout.html', $default);
        file_put_contents($temp . '/blocks/stores/default/storefront/checkout.html', $legacy);
        $assert($run(['--store=default'])['status'] === 'NO-GO', 'store override takes precedence and cannot hide behind a good theme');
        $assert($run()['status'] === 'GO', 'a different store does not use the broken override');
        $assert(runThemeCompatibility(['--theme=does-not-exist'])['status'] === 'NO-GO', 'missing theme fails closed');
        $assert(runThemeCompatibility(['--store=does-not-exist'])['status'] === 'NO-GO', 'missing store fails closed');
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($temp);
    }
    return $failures === 0 ? 0 : 1;
})();
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) exit($themeContractExit);
