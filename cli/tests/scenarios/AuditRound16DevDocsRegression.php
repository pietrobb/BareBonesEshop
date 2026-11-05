<?php

// Review round 16, medium (developer docs): a theme's own texts, unregistered homepage sections, and theme-check
// reading stores.example.json instead of the installed shop's stores.json.
echo "\n--- Audit round 16: theme texts, unregistered sections, theme-check stores ---\n";
require_once TEST_CLI_DIR . '/theme-check.php';

$r16dApp = dirname(TEST_CLI_DIR);
$r16dTemp = sys_get_temp_dir() . '/bbe-r16-devdocs-' . bin2hex(random_bytes(6));
$r16dWrite = static function (string $path, string $content): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $content);
};
$r16dCopy = static function (string $from, string $to) use (&$r16dCopy): void {
    if (is_dir($from)) {
        if (!is_dir($to)) mkdir($to, 0700, true);
        foreach (scandir($from) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $r16dCopy($from . '/' . $entry, $to . '/' . $entry);
    } else {
        copy($from, $to);
    }
};
$r16dState = [];
foreach (['theme', 'store', 'themesDir', 'blocksDir'] as $r16dName) $r16dState[$r16dName] = (new ReflectionProperty(Template::class, $r16dName))->getValue();
$r16dLocale = I18n::locale();
try {
    // A child of the real default theme with its own texts and a homepage section whose type is not registered.
    $r16dThemes = $r16dTemp . '/themes';
    $r16dCopy($r16dApp . '/themes/default', $r16dThemes . '/default');
    $r16dWrite($r16dThemes . '/acme/theme.json', json_encode(['name' => 'Acme', 'version' => '1.0.0', 'parent' => 'default',
        'sections' => ['hero' => 'storefront/section-hero']]));
    $r16dWrite($r16dThemes . '/acme/lang/en.json', json_encode(['acme.tagline' => 'Hand-made since 1990', 'cart' => 'HIJACKED']));
    $r16dWrite($r16dThemes . '/acme/lang/sk.json', json_encode(['acme.tagline' => 'Ručná výroba od roku 1990', 'checkout' => 'HIJACKED']));
    $r16dWrite($r16dThemes . '/acme-kid/theme.json', json_encode(['name' => 'Acme kid', 'version' => '1.0.0', 'parent' => 'acme']));
    $r16dWrite($r16dThemes . '/acme-kid/lang/en.json', json_encode(['acme-kid.note' => 'Kid note', 'acme.tagline' => 'not mine']));
    $r16dWrite($r16dThemes . '/acme/layouts.json', json_encode(['pages' => ['home' => ['sections' => [
        ['id' => 'top', 'type' => 'hero'], ['id' => 'authors', 'type' => 'author-list']]]]]));

    // 1. Texts: a theme had nowhere to put its own texts except the core's lang/ files, which an update replaces.
    Template::setThemesDir($r16dThemes);
    Template::setTheme('acme');
    I18n::setLocale('sk');
    $r16dSkCart = (require $r16dApp . '/lang/sk.php')['cart'];
    $r16dSkCheckout = (require $r16dApp . '/lang/sk.php')['checkout'];
    assert_true(I18n::t('acme.tagline') === 'Ručná výroba od roku 1990' && (I18n::all()['acme.tagline'] ?? '') === 'Ručná výroba od roku 1990',
        'R16 docs: a theme ships its own texts in themes/<name>/lang/<locale>.json, read by I18n::t() and the templates\' t');
    assert_true(Template::process('<p>{{t.acme.tagline}}</p>', ['t' => I18n::all()]) === '<p>Ručná výroba od roku 1990</p>',
        'R16 docs: a block shows a theme text with {{t.<theme>.<key>}}');
    assert_true(I18n::t('cart') === $r16dSkCart && I18n::all()['checkout'] === $r16dSkCheckout && I18n::t('checkout') !== 'HIJACKED',
        'R16 docs: keys outside the theme\'s own name (core texts like cart, checkout) are ignored, never overridden');
    I18n::setLocale('de');
    assert_true(I18n::t('acme.tagline') === 'Hand-made since 1990', 'R16 docs: a language without its own theme file uses the theme\'s en.json');
    Template::setTheme('acme-kid');
    I18n::setLocale('en');
    assert_true(I18n::t('acme.tagline') === 'Hand-made since 1990' && I18n::t('acme-kid.note') === 'Kid note',
        'R16 docs: a child theme gets its parent\'s texts plus its own, and cannot redefine the parent\'s keys');
    Template::setTheme(null);
    assert_true(I18n::t('acme.tagline') === 'acme.tagline', 'R16 docs: without an active storefront theme (CLI, worker) no theme texts apply');
    $r16dWrite($r16dThemes . '/acme/lang/en.json', '{"acme.tagline": ');
    Template::setTheme('acme');
    $r16dBroken = '';
    try { I18n::t('acme.tagline'); } catch (Throwable $e) { $r16dBroken = $e->getMessage(); }
    assert_true($r16dBroken !== '', 'R16 docs: a theme text file that is not valid JSON is an error, not silently empty texts');
    $r16dWrite($r16dThemes . '/acme/lang/en.json', json_encode(['acme.tagline' => 'Hand-made since 1990']));
    Template::setTheme('acme');

    // 2. Unregistered sections: a layouts.json section whose type theme.json does not map rendered nothing, silently.
    $r16dMissing = Template::unregisteredSections();
    assert_true(in_array('home: author-list', $r16dMissing, true) && !in_array('home: hero', $r16dMissing, true),
        'R16 docs: Template::unregisteredSections() names the page and type of a section with no block (' . json_encode($r16dMissing) . ')');
    $r16dRender = (new ReflectionMethod(Template::class, 'renderSection'));
    assert_true($r16dRender->invoke(null, ['id' => 'authors', 'type' => 'author-list'], []) === '<!-- unregistered section: author-list -->',
        'R16 docs: an unregistered section leaves a marker in the HTML source instead of nothing');
} finally {
    I18n::setLocale($r16dLocale);
    Template::setThemesDir((string)$r16dState['themesDir']);
    Template::setBlocksDir((string)$r16dState['blocksDir']);
    Template::setTheme($r16dState['theme']);
    Template::setStore($r16dState['store']);
}

// The theme check reports those sections as warnings without failing the checkout contract.
$r16dCheck = runThemeCompatibility(['--theme=acme', '--themes-dir=' . $r16dThemes, '--blocks-dir=' . $r16dApp . '/blocks']);
assert_true($r16dCheck['status'] === 'GO' && str_contains(implode("\n", $r16dCheck['warnings'] ?? []), 'home: author-list'),
    'R16 docs: theme-check lists an unregistered section as a warning and stays GO (' . json_encode([$r16dCheck['status'], $r16dCheck['warnings'] ?? null, array_slice($r16dCheck['failures'], 0, 2)]) . ')');
$r16dCli = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(TEST_CLI_DIR . '/theme-check.php') . ' --theme=acme --themes-dir=' . escapeshellarg($r16dThemes)
    . ' --blocks-dir=' . escapeshellarg($r16dApp . '/blocks') . ' 2>&1', $r16dCli, $r16dCliExit);
assert_true($r16dCliExit === 0 && (bool)preg_grep('/^\[WARN\] .*home: author-list/', $r16dCli) && (bool)preg_grep('/^Stores: /', $r16dCli),
    'R16 docs: the theme-check output shows [WARN] lines and the stores file it read (' . implode(' | ', array_slice($r16dCli, 0, 4)) . ')');

// 3. theme-check after `setup.php install` read stores.example.json: the installed stores.json lives in the data
//    directory (private_runtime_dir), which the probe never looked at. A copy of the application with its own
//    config.php and data directory: the probe must check the installed store and its theme, not the example's.
$r16dShop = $r16dTemp . '/shop';
foreach (['src', 'adapters', 'migrations', 'themes', 'blocks', 'lang', 'catalog.json', 'layouts.json', 'pricing.json', 'shipping.json',
    'stores.example.json', 'cli/cli-only.php', 'cli/theme-check.php', 'cli/tests/ThemeContractProbe.php'] as $r16dPath) {
    if (!is_dir(dirname($r16dShop . '/' . $r16dPath))) mkdir(dirname($r16dShop . '/' . $r16dPath), 0700, true);
    $r16dCopy($r16dApp . '/' . $r16dPath, $r16dShop . '/' . $r16dPath);
}
$r16dWrite($r16dShop . '/config.php', '<?php return ' . var_export(['private_runtime_dir' => $r16dTemp . '/private'] + (require $r16dApp . '/config.example.php'), true) . ';');
$r16dStores = json_decode((string)file_get_contents($r16dApp . '/stores.example.json'), true);
$r16dStores['stores'] = ['r16-installed' => ['name' => 'R16 installed', 'theme' => 'bookshop'] + (array)reset($r16dStores['stores'])];
$r16dWrite($r16dTemp . '/private/stores.json', json_encode($r16dStores));
$r16dOut = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($r16dShop . '/cli/theme-check.php') . ' --json 2>&1', $r16dOut, $r16dExit);
$r16dReport = json_decode(implode("\n", $r16dOut), true);
$r16dTargets = (array)($r16dReport['targets'] ?? []);
assert_true(in_array('bookshop / store r16-installed', $r16dTargets, true) && str_ends_with((string)($r16dReport['stores_file'] ?? ''), '/private/stores.json'),
    'R16 docs: theme-check on an installed shop checks the stores.json in the data directory, not the example (' . json_encode([$r16dExit, $r16dReport['stores_file'] ?? null, $r16dTargets]) . ')');
unlink($r16dTemp . '/private/stores.json');
$r16dOut = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($r16dShop . '/cli/theme-check.php') . ' --json 2>&1', $r16dOut);
$r16dReport = json_decode(implode("\n", $r16dOut), true);
assert_true(str_ends_with((string)($r16dReport['stores_file'] ?? ''), '/stores.example.json') && !in_array('bookshop / store r16-installed', (array)($r16dReport['targets'] ?? []), true),
    'R16 docs: without an installed stores.json the check falls back to the example, as before');

$r16dRemove = static function (string $path) use (&$r16dRemove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $r16dRemove($path . '/' . $entry);
        rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        unlink($path);
    }
};
$r16dRemove($r16dTemp);
