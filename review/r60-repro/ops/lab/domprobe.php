<?php
// php domprobe.php <shopRoot> <resolverSleepSeconds>: StoreSettings::saveDomains with a resolver that hangs like an unreachable NS
$root = $argv[1]; chdir($root); require $root . '/src/autoload.php';
$cfgFile = $root . '/config.php'; $c = require $cfgFile; $c['app_url'] = 'https://shop.example.test'; file_put_contents($cfgFile, "<?php\nreturn " . var_export($c, true) . ";\n");
Bootstrap::initStoreContext('/', '', 'default');
$sleep = (int)$argv[2];
StoreSettings::setResolverForTesting(static function (string $h) use ($sleep): array { sleep($sleep); return []; });
$domains = ['shop-de.example.com', 'shop-cz.example.com', 'shop-pl.example.com'];
for ($i = 1; $i <= 3; $i++) {
    $t = microtime(true);
    $r = StoreSettings::saveDomains('de', implode("\n", $domains));
    printf("save %d: %.1f s ok=%s untested=%s warning=%s\n", $i, microtime(true) - $t, json_encode($r['ok']), json_encode(StoreSettings::load()['stores']['de']['domains_untested'] ?? null), substr($r['warning'] ?? '', 0, 120));
}
