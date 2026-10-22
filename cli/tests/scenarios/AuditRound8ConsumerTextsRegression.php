<?php

// R8 EU consumer law (texts and languages). Texts here are a technical baseline and must be reviewed by a lawyer.
// 1) Every store names the consumer ADR body of its seller's country on every page, and publishes an accessibility
//    statement (European Accessibility Act) in every shipped locale.
// 4) A guest changes or withdraws the cookie choice from the footer, without an account and without JavaScript.
echo "\n--- Audit round 8: EU consumer law texts (ADR, accessibility, cookie settings) ---\n";

$r8tRoot = test_fixture_path('r8-consumer-texts');
mkdir($r8tRoot);
mkdir($r8tRoot . '/sessions');
$r8tFixture = $r8tRoot . '/request.php';
file_put_contents($r8tFixture, <<<'PHP'
<?php
[, $phase, $root, $target, $dir, $extra] = $argv;
require $root . '/src/autoload.php';
$db = new PDO('sqlite:' . $target);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Db::init($db, 'sqlite');
if ($phase === 'setup') {
    (new Migrator($db, $root . '/migrations', $dir . '/migrate.lock'))->apply();
    exit(0);
}
$config = is_file($root . '/config.php') ? require $root . '/config.php' : require $root . '/config.example.php';
(new ReflectionProperty(Security::class, 'cfg'))->setValue(null, ['app_url' => 'https://shop.example'] + $config);
Security::setRateDbForTesting(new PDO('sqlite:' . $dir . '/rate.sqlite'));
ini_set('session.use_cookies', '0');
session_save_path($dir . '/sessions');
session_start();
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '127.0.8.22';
$_SERVER['HTTP_HOST'] = 'shop.example';
$input = json_decode($extra, true) ?: [];
$_GET = $input['get'] ?? [];
$_COOKIE = $input['cookie'] ?? [];
$_SERVER['REQUEST_URI'] = $input['uri'] ?? '/index.php?' . http_build_query($_GET);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
http_response_code(200);
ob_start();
register_shutdown_function(static function(): void {
    $out = '';
    while (ob_get_level() > 0) $out = ob_get_clean() . $out;
    echo "\nR8TRESULT" . json_encode(['code' => http_response_code(), 'output' => $out], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
});
require $root . '/index.php';
PHP);
$r8tRun = static function(array $args) use ($r8tFixture): array {
    $process = proc_open(array_merge([PHP_BINARY, $r8tFixture], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $raw = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $at = strrpos($raw, 'R8TRESULT');
    $result = $at === false ? null : json_decode(strtok(substr($raw, $at + 9), "\n"), true);
    return [$exit, is_array($result) ? $result : null, $at === false ? substr($raw, 0, 800) : substr($raw, 0, min($at, 800))];
};
$r8tApp = dirname(TEST_CLI_DIR);
$r8tDb = $r8tRoot . '/shop.sqlite';
$r8tPage = static function(array $get, array $cookie = [], ?string $uri = null) use ($r8tRun, $r8tApp, $r8tDb, $r8tRoot): array {
    [, $result, $noise] = $r8tRun(['page', $r8tApp, $r8tDb, $r8tRoot, json_encode(['get' => $get, 'cookie' => $cookie, 'uri' => $uri])]);
    return [(int)($result['code'] ?? 0), (string)($result['output'] ?? $noise)];
};

[$r8tExit, , $r8tNoise] = $r8tRun(['setup', $r8tApp, $r8tDb, $r8tRoot, '{}']);
assert_equals([0, ''], [$r8tExit, trim($r8tNoise)], 'R8 texts: migrated fixture');

// 1) ADR body of each store's seller country (stores.json); a store without a known body names none.
$r8tStores = json_decode((string)file_get_contents($r8tApp . '/stores.json'), true)['stores'];
$r8tAdr = [];
foreach ($r8tStores as $r8tId => $r8tStore) $r8tAdr[$r8tId] = ConsumerNotice::adr((array)($r8tStore['legal'] ?? []))['name'];
assert_equals(['default' => 'Slovenská obchodná inšpekcia', 'cz' => 'Česká obchodní inspekce', 'pl' => 'Inspekcja Handlowa', 'hu' => 'Budapesti Békéltető Testület', 'de' => 'Universalschlichtungsstelle des Bundes', 'us' => ''],
    $r8tAdr, 'R8 ADR: each store names the consumer ADR body of its seller country');

[$r8tCode, $r8tHome] = $r8tPage([], ['cc_v1' => '{"necessary":true,"analytics":false,"marketing":false}']);
assert_true($r8tCode === 200 && preg_match('~class="footer-adr">[^<]*<a href="https://www\.soi\.sk"[^>]*>Slovenská obchodná inšpekcia</a>~u', $r8tHome) === 1,
    'R8 ADR: every storefront page names the ADR body with its link in the footer (' . $r8tCode . ')');
assert_true(str_contains($r8tHome, '?page=accessibility') && str_contains($r8tHome, 'cookie_settings=1'),
    'R8 footer: links the accessibility statement and the cookie settings');
assert_true(!str_contains($r8tHome, 'id="cc-banner"'), 'R8 cookies: a visitor who chose is not shown the banner on a normal page');

[$r8tCode, $r8tA11y] = $r8tPage(['page' => 'accessibility']);
assert_true($r8tCode === 200 && str_contains($r8tA11y, '<h1>Vyhlásenie o prístupnosti</h1>') && str_contains($r8tA11y, 'WCAG 2.1') && str_contains($r8tA11y, 'Woodle s.r.o.')
    && str_contains($r8tA11y, 'info@example.sk, +421 2 1234 5678') && !str_contains($r8tA11y, '{{'),
    'R8 accessibility: the statement page renders in the store locale with the seller and the feedback contact (' . $r8tCode . ')');
foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu', 'es'] as $r8tLocale) {
    $r8tHtml = ConsumerNotice::renderLegal('accessibility', $r8tLocale, ['name' => 'R8T s.r.o.', 'email' => 'a11y@example.test', 'accessibility_authority' => 'R8T Authority']);
    assert_true(str_contains($r8tHtml, '<html') === false && preg_match_all('~<h2>~', $r8tHtml) === 5 && str_contains($r8tHtml, 'a11y@example.test')
        && str_contains($r8tHtml, ': R8T Authority') && str_contains($r8tHtml, '2019/882') && !str_contains($r8tHtml, '{{')
        && is_file($r8tApp . '/templates/legal/' . $r8tLocale . '/accessibility.html'),
        "R8 accessibility ({$r8tLocale}): own statement with standard, status, service, feedback and enforcement");
}

// 4) The guest reopens the banner from the footer: current choice ticked, "save" offered without JS, returns home.
[$r8tCode, $r8tSettings] = $r8tPage(['cookie_settings' => '1'], ['cc_v1' => '{"necessary":true,"analytics":true,"marketing":false}'], '/index.php?cookie_settings=1');
assert_true($r8tCode === 200 && preg_match('~<form id="cc-banner" class="show-prefs"~', $r8tSettings) === 1
    && preg_match('~id="cc-ana"[^>]*\bchecked~', $r8tSettings) === 1 && preg_match('~id="cc-mkt"[^>]*\bchecked~', $r8tSettings) === 0
    && preg_match('~id="cc-save"[^>]*value="preferences"\s*>~', $r8tSettings) === 1
    && preg_match('~name="return_to" value="[^"?]*/"~', $r8tSettings) === 1,
    'R8 cookies: the settings link reopens the banner for a guest with the current choice and a no-JS save that returns home');
foreach (['sk', 'cs', 'en', 'de', 'pl', 'hu'] as $r8tLocale) {
    $r8tLabel = (string)((require $r8tApp . '/lang/' . $r8tLocale . '.php')['cookie_settings'] ?? '');
    $r8tPolicy = (string)file_get_contents($r8tApp . '/templates/legal/' . $r8tLocale . '/cookies.html');
    assert_true($r8tLabel !== '' && str_contains($r8tPolicy, $r8tLabel) && !str_contains($r8tPolicy, 'zákazníckom účte, ak je dostupné') && !str_contains($r8tPolicy, 'customer account where available'),
        "R8 cookies ({$r8tLocale}): the cookie policy points to the footer settings link, not only to an account");
}
