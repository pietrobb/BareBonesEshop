<?php
require_once dirname(__DIR__) . '/cli-only.php';
require_once dirname(__DIR__, 2) . '/src/autoload.php';
require_once dirname(__DIR__, 2) . '/src/Entrypoints/Storefront/CheckoutPages.php';

// Separate process: real services/presenter/renderer, but only an in-memory database.
// Never load bootstrap/config.php, create an order, send mail or contact a gateway.
$report = ['status' => 'NO-GO', 'checks' => 0, 'targets' => [], 'failures' => []];
set_error_handler(static function(int $level, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $level)) return false;
    throw new ErrorException($message, 0, $level, $file, $line);
});
try {
    $root = dirname(__DIR__, 2);
    $options = getopt('', ['theme:', 'themes-dir:', 'blocks-dir:', 'stores-file:', 'store:']);
    $themesDir = (string)($options['themes-dir'] ?? $root . '/themes');
    $blocksDir = (string)($options['blocks-dir'] ?? $root . '/blocks');
    if (!is_dir($themesDir) || !is_dir($blocksDir)) throw new RuntimeException('Theme/block directory does not exist');
    $storesFile = (string)($options['stores-file'] ?? $root . '/stores.json');
    $stores = json_decode((string)file_get_contents($storesFile), true, 32, JSON_THROW_ON_ERROR);
    $targets = [];
    if (isset($options['store'])) {
        $store = (string)$options['store'];
        if (!isset($stores['stores'][$store])) throw new RuntimeException('Unknown store: ' . $store);
        $targets[] = [(string)($options['theme'] ?? $stores['stores'][$store]['theme'] ?? 'default'), $store];
    } elseif (isset($options['theme'])) {
        $targets[] = [(string)$options['theme'], null];
    } else {
        foreach (glob($themesDir . '/*/theme.json') ?: [] as $manifest) $targets[] = [basename(dirname($manifest)), null];
        foreach ($stores['stores'] ?? [] as $store => $config) $targets[] = [(string)($config['theme'] ?? 'default'), (string)$store];
    }
    if ($targets === []) throw new RuntimeException('No themes found; refusing an empty qualification');
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    Db::init($db, 'sqlite');
    foreach (glob($root . '/migrations/*.php') ?: [] as $migration) (require $migration)->up($db, true);
    session_start(['use_cookies' => false, 'cache_limiter' => '']);
    UrlAliases::setRules([]);
    I18n::setLocale('sk');
    Shipping::setConfig(['zones' => ['test' => ['countries' => ['SK', 'DE']]], 'methods' => []]);
    ShippingService::setAdapters([new class implements ShippingAdapter { public function getRates(array $params): array { return []; } public function generateLabel(array $order): array { throw new LogicException('Labels forbidden in theme probe'); } public function trackShipment(string $awb): array { throw new LogicException('Tracking forbidden in theme probe'); } public function supportsCOD(): bool { return false; } public function carrierId(): string { return 'theme-probe'; } }]);
    $db->exec("INSERT INTO categories (id, store_id, slug, name, path, depth) VALUES (99001, 'default', 'contract', 'Contract', 'contract', 0)");
    foreach ([99001 => 'simple', 99002 => 'downloadable'] as $id => $type) {
        $db->prepare("INSERT INTO products (id, store_id, category_id, sku, slug, name, product_type, price, currency, stock, status) VALUES (?, 'default', 99001, ?, ?, ?, ?, 599, 'eur', 500, 'active')")
            ->execute([$id, 'CONTRACT-' . $id, 'contract-' . $id, 'Contract product ' . $id, $type]);
    }
    $db->exec("UPDATE payment_methods SET active=0");
    $db->exec("INSERT INTO payment_methods (code, provider, name, service_type, requires_online, active, sort_order) VALUES ('contractpay', 'manual', 'Contract card', 'online', 1, 1, 0)");
    $db->exec("INSERT INTO payment_method_countries (method_code, country) VALUES ('contractpay', 'SK'), ('contractpay', 'DE')");
    ConsentService::setDefinitionsForTesting([
        'contract_terms' => ['version' => '1', 'required' => true, 'text' => 'Contract terms'],
        'contract_marketing' => ['version' => '1', 'required' => false, 'text' => 'Optional marketing'],
    ]);
    Template::setThemesDir($themesDir);
    Template::setBlocksDir($blocksDir);
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $price = static fn(int $amount): string => number_format($amount / 100, 2, '.', '') . ' EUR';
    $t = static fn(string $key): string => I18n::t($key);
    $storeCurrency = 'eur';
    $values = ['name' => 'Theme "><buyer> & Test', 'email' => 'theme@example.invalid', 'phone' => '+421123456789',
        'street' => 'Contract Street 12', 'city' => 'Bratislava', 'zip' => '81101', 'notes' => 'Keep <this> & note'];
    $scenarios = ['physical' => [99001], 'digital' => [99002], 'mixed' => [99001, 99002],
        'physical-redisplay' => [99001], 'digital-redisplay' => [99002], 'rejected-redisplay' => [99001]];
    foreach ($targets as [$theme, $store]) {
        $target = $theme . ($store === null ? '' : ' / store ' . $store);
        $report['targets'][] = $target;
        try {
            // Preserve real block override selection, isolate commerce/provider identity from shop data.
            StoreContext::activate(StoreContext::resolve(['default_currency' => 'eur']));
            Template::setTheme($theme);
            Template::setStore($store);
            Template::manifest();
            foreach ($scenarios as $scenario => $ids) {
                $posted = str_contains($scenario, 'redisplay');
                $digital = $ids === [99002];
                $_SESSION = ['csrf_token' => 'theme-contract-csrf', 'cart' => []];
                foreach ($ids as $id) Cart::add($id, null, 1);
                $_SERVER['REQUEST_METHOD'] = $posted ? 'POST' : 'GET';
                $_POST = $posted ? $values + ['country' => 'DE', 'checkout_refresh' => '1', 'csrf_token' => 'theme-contract-csrf'] : [];
                if ($scenario === 'rejected-redisplay') {
                    unset($_POST['checkout_refresh']);
                    $_POST['checkout'] = '1';
                    $_POST['csrf_token'] = 'invalid'; // Rejection before any order/config/payment path.
                }
                $html = renderCheckout();
                $dom = new DOMDocument();
                $previous = libxml_use_internal_errors(true);
                try { $dom->loadHTML('<?xml encoding="UTF-8">' . $html); }
                finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
                $xp = new DOMXPath($dom);
                $form = "//form[.//input[@name='checkout' and @value='1']]";
                $check = static function(bool $ok, string $code, string $message) use (&$report, $target, $scenario): void {
                    $report['checks']++;
                    if (!$ok) $report['failures'][] = "{$target} / storefront/checkout / {$scenario} [{$code}]: {$message}";
                };
                $count = static fn(string $query): int => (int)$xp->evaluate('count(' . $query . ')');
                $check($count($form) === 1 && strtolower((string)$xp->evaluate('string(' . $form . '/@method)')) === 'post', 'form', 'Expected one POST checkout form with checkout=1.');
                $check($count($form . "//input[@name='csrf_token' and @value='theme-contract-csrf' and not(@disabled)]") === 1, 'csrf', 'CSRF token missing or not rendered from csrf_token.');
                foreach (['name', 'email'] as $field) $check($count($form . "//input[@name='{$field}' and @required and not(@disabled)]") === 1, 'contact.' . $field, "Required usable {$field} input is missing.");
                $expectedCountries = ShippingService::destinationCountries();
                $actualCountries = [];
                foreach ($xp->query($form . "//select[@name='country' and not(@disabled)]/option") as $option) $actualCountries[$option->getAttribute('value')] = trim($option->textContent);
                $check(!array_diff_key($actualCountries, $expectedCountries) && !array_diff_key($expectedCountries, $actualCountries) && !in_array('', $actualCountries, true), 'countries', 'Country choices are missing/wrong. Render country_options with code, name and selected (not countries/label).');
                $country = $posted ? 'DE' : 'SK';
                $check($count($form . "//select[@name='country']/option[@value='{$country}' and @selected]") === 1, 'country.selected', 'Selected country is not preserved.');
                foreach (['street', 'city', 'zip'] as $field) {
                    $required = $count($form . "//input[@name='{$field}' and @required and not(@disabled)]");
                    $check($digital ? $required === 0 : $required === 1, 'address.' . $field, 'Address requirement is wrong: use address_required, not digital_only.');
                }
                if ($posted) foreach ($values as $field => $value) {
                    if ($digital && in_array($field, ['street', 'city', 'zip'], true) && $count($form . "//*[@name='{$field}']") === 0) continue;
                    $query = $field === 'notes' ? $form . "//textarea[@name='notes']" : $form . "//input[@name='{$field}']/@value";
                    $check($xp->evaluate('string(' . $query . ')') === $value, 'values.' . $field, "Entered {$field} is lost; render values.{$field}, not form_values.{$field}.");
                }
                $check($count('//buyer | //this') === 0, 'escaping', 'User values must be escaped, not inserted as HTML.');
                $quote = CheckoutService::deliveryQuote(Cart::enriched()['items'], 599 * count($ids), $country, '');
                $shipping = (string)($quote['selected']['method_code'] ?? '');
                $check($shipping !== '' && $count($form . "//input[@name='shipping_method' and @value='{$shipping}' and @checked and not(@disabled)]") === 1, 'shipping', 'Selected shipping option is missing.');
                $check($count($form . "//input[@name='payment' and @value='contractpay' and @checked and not(@disabled)]") === 1, 'payment', 'Selected payment option is missing.');
                foreach (['contract_terms' => true, 'contract_marketing' => false] as $key => $required) {
                    $control = $form . "//input[@name='consents[{$key}]' and @type='checkbox' and @value='1' and not(@disabled)]";
                    $check($count($control) === 1 && ($count($control . '[@required]') === 1) === $required && $count($control . '[@checked]') === 0,
                        'consent.' . $key, 'Render all consents; required only where required=true, never preselect optional marketing.');
                }
                $check($count($form . "//button[@type='submit' and not(@name) and not(@disabled)] | " . $form . "//input[@type='submit' and not(@disabled)]") > 0, 'submit', 'Usable order submit control is missing.');
                if ($scenario === 'rejected-redisplay') $check(str_contains($html, esc(I18n::t('checkout_csrf_error'))), 'error', 'checkout_error is not displayed after a refused order.');
            }
        } catch (Throwable $e) {
            $report['failures'][] = $target . ': ' . $e->getMessage();
        }
    }
    $report['status'] = $report['failures'] === [] ? 'GO' : 'NO-GO';
} catch (Throwable $e) {
    $report['failures'][] = 'Probe setup: ' . $e->getMessage();
}
if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); restore_error_handler();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
exit($report['status'] === 'GO' ? 0 : 1);
