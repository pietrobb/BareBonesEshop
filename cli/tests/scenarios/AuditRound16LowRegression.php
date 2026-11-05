<?php

// Review round 16, low: admin prices in the currency, the hero without an image, the delivery note's codes, a long
// update's maintenance window, and the two surviving mutants SEC12 and MAIL2. (Feed variant link with ?lang: in
// AuditRound15ReportsRegression; doctor/preflight exit codes: in AuditRound5DeployRegression.)
echo "\n--- Audit round 16: low findings ---\n";

require_once dirname(TEST_CLI_DIR) . '/src/Entrypoints/Admin/Catalog.php';

// 1. The product form asked for the price in cents ("1290" for 12.90 €). It shows and takes the store's currency.
assert_equals(['12.90', '15.90'], array_values(array_intersect_key(adminFormValues('admin-product', ['price' => 1290, 'compare_price' => 1590, 'currency' => 'eur']),
    ['price' => 1, 'compare_price' => 1])), 'R16 low: the product form shows 12.90, not 1290 cents');
assert_equals('1290', adminFormValues('admin-product', ['price' => 129000, 'currency' => 'huf'])['price'], 'R16 low: a currency without decimals (HUF) shows whole forints');
assert_equals(1290, Money::parseInput(adminFormValues('admin-product', ['price' => 1290, 'currency' => 'eur'])['price'], 'EUR'), 'R16 low: the shown price saves back unchanged');
$r16lForm = json_decode((string)file_get_contents(dirname(TEST_CLI_DIR) . '/forms/admin-product.json'), true);
$r16lFields = array_column(array_merge(...array_map(static fn(array $s): array => $s['fields'] ?? [], $r16lForm['sections'] ?? [$r16lForm])), null, 'name');
assert_true(!str_contains((string)($r16lFields['price']['label'] ?? 'centy'), 'cent') && (float)($r16lFields['price']['step'] ?? 1) === 0.01,
    'R16 low: the price field is not labelled in cents and accepts hundredths (' . json_encode($r16lFields['price'] ?? null) . ')');

// 2. A hero without an image was an empty grey 16:5 block with its heading at the bottom. It is now a text hero.
$r16lSection = new ReflectionMethod(Template::class, 'renderSection');
$r16lSection->setAccessible(true);
$r16lHero = static fn(array $content): string => (string)$r16lSection->invoke(null, ['type' => 'hero', 'id' => 'r16l-hero', 'settings' => ['variant' => 'default'],
    'content' => $content, 'data' => []], ['t' => I18n::all()]);
$r16lText = $r16lHero(['heading' => 'R16 hero']);
$r16lImage = $r16lHero(['heading' => 'R16 hero', 'media' => ['image_url' => '/uploads/r16-hero.jpg', 'alt' => 'R16']]);
assert_true(str_contains($r16lText, 'hero--text') && !str_contains($r16lText, '<img') && str_contains($r16lText, 'R16 hero'),
    'R16 low: a hero without an image renders as a text hero, not an empty image box');
assert_true(!str_contains($r16lImage, 'hero--text') && str_contains($r16lImage, '<img src="/uploads/r16-hero.jpg"'), 'R16 low: a hero with an image keeps the image layout');
assert_true(str_contains((string)file_get_contents(dirname(TEST_CLI_DIR) . '/themes/default/assets/storefront.css'), '.hero--text{aspect-ratio:auto'),
    'R16 low: the text hero drops the 16:5 box');

// 3. The delivery note printed "SK" and the method's code ("packeta_pickup"). It names the country and the method.
assert_true(in_array('Slovensko', DeliveryNote::recipientLines(['address' => ['country' => 'sk'], 'order' => ['customer_name' => 'Jana R16']]), true),
    'R16 low: the delivery note names the country (Slovensko), not SK');
$r16lMethod = new ReflectionMethod(DeliveryNote::class, 'shippingMethod');
$r16lMethod->setAccessible(true);
assert_equals(['R16 box delivery', 'R16 courier', ''], [
    $r16lMethod->invoke(null, ['order' => ['shipping_method' => 'r16_courier'], 'rate' => ['label' => 'R16 box delivery']]),
    $r16lMethod->invoke(null, ['order' => ['shipping_method' => 'r16_courier'], 'rate' => []]),
    $r16lMethod->invoke(null, ['order' => [], 'rate' => []]),
], 'R16 low: the delivery note shows the method as chosen at checkout, else a readable form of its code, not the raw code');

// 4. The maintenance marker stopped blocking after an hour even while the updater still ran (a long migration).
//    The window now lasts while the process that wrote the marker runs on this host, at most a day.
if (is_dir('/proc/self') || function_exists('posix_kill')) {
    $r16lRoot = test_fixture_path('r16-maintenance');
    mkdir($r16lRoot);
    try {
        MaintenanceMode::enter($r16lRoot, 'r16 test');
        $r16lMarker = MaintenanceMode::path($r16lRoot);
        touch($r16lMarker, time() - MaintenanceMode::STALE_AFTER - 600);
        $r16lLong = MaintenanceMode::active($r16lRoot);
        touch($r16lMarker, time() - MaintenanceMode::MAX_AGE - 60);
        $r16lDay = MaintenanceMode::active($r16lRoot);
        file_put_contents($r16lMarker, json_encode(['since' => gmdate('c'), 'reason' => 'v2.1.1 marker']));
        touch($r16lMarker, time() - MaintenanceMode::STALE_AFTER - 600);
        $r16lOld = MaintenanceMode::active($r16lRoot);
        file_put_contents($r16lMarker, json_encode(['since' => gmdate('c'), 'reason' => 'r16', 'pid' => getmypid(), 'host' => 'r16-other-host.invalid']));
        touch($r16lMarker, time() - MaintenanceMode::STALE_AFTER - 600);
        $r16lHost = MaintenanceMode::active($r16lRoot);
        assert_equals([true, false, false, false], [$r16lLong, $r16lDay, $r16lOld, $r16lHost],
            'R16 low: an update still running after an hour keeps the shop in maintenance; after a day, without a PID, or from another host it does not');
    } finally {
        @unlink(MaintenanceMode::path($r16lRoot));
        @rmdir($r16lRoot);
    }
} else {
    print "  [SKIP] R16 low maintenance PID check (no /proc and no posix extension)\n";
}

// 5. Mutant SEC12: rich text dropped <style> (and <script>) without a test noticing that keeping it would print the
//    CSS as text. The content of every removed element is gone, not unwrapped into the page.
$r16lRich = Security::sanitizeRichHtml('<p>R16 kept</p><style>.r16css{color:red}</style><script>r16js()</script><iframe>r16frame</iframe>'
    . '<object>r16object</object><form><textarea>r16area</textarea><select><option>r16option</option></select><button>r16button</button></form>'
    . '<svg><text>r16svg</text></svg><math><mi>r16math</mi></math>');
assert_equals('<p>R16 kept</p>', $r16lRich, 'R16 SEC12: <style>, <script> and the other removed elements leave no text behind (CSS not shown as text)');

// 6. Mutant MAIL2: the plain-text part of a mail drops <style>, <script> and <title> also outside <head> (an editor
//    puts <style> in the body); the existing test had them only inside <head>, which goes as a whole.
assert_equals("Hi R16\n\nBye", MailService::textFromHtml('<title>R16 mail title</title><style>p{color:red}</style><p>Hi R16</p>'
    . '<script>track()</script><STYLE type="text/css">.x{}</STYLE ><p>Bye</p>'), 'R16 MAIL2: the plain-text part has no CSS, script or title text from the body');
