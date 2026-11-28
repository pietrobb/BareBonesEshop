<?php
/**
 * R58 (review round 23, medium S4): the shipping.json delivery methods (Standard, Express) on Admin → Shipping, switched
 * on or off and priced there; the settings live in the data directory (DataOverrides::shippingMethods()), never in
 * shipping.json. Loaded by Operations.php.
 */

/**
 * R57 (review round 22, UX S5): the shipping.json delivery methods (Standard, Express) with their zones, countries and prices.
 * R58 (review round 23, medium S4 and lows): switched on or off and priced here (DataOverrides::shippingMethods()), so
 * shipping.json is never edited (an edit stopped every update); not offered in a store charging in another currency,
 * which says so instead of listing EUR prices; zones by name; no "In-store pickup", which checkout never offers from it.
 */
function shippingFileMethods(): void {
    $rows = Shipping::overview(I18n::locale(), true);
    if ($rows === []) return;
    $fileCurrency = Shipping::currency();
    $storeCurrency = strtoupper(StoreContext::current()->currency());
    $methods = [];
    foreach ($rows as $row) $methods[$row['key']][] = $row;
    $settings = DataOverrides::shippingMethods()['methods'] ?? [];
    $zoneName = static function (string $zone): string {
        $label = I18n::t('admin.shipping.file_zone.' . $zone);
        return $label === 'admin.shipping.file_zone.' . $zone ? $zone : $label;
    };
    $money = static fn(?int $cents): string => Money::adminValue($cents, $fileCurrency);
    ?>
    <div class="card" data-shipping-file-methods>
        <h3><?= esc(I18n::t('admin.shipping.file_methods')) ?></h3>
        <?php shippingFileNotices(); ?>
        <?php if ($fileCurrency !== $storeCurrency): ?>
        <p class="muted" data-shipping-file-other-currency><?= esc(I18n::format('admin.shipping.file_other_currency', ['currency' => $fileCurrency, 'store_currency' => $storeCurrency])) ?></p>
    </div>
        <?php return; endif; ?>
        <p class="muted"><?= esc(I18n::format('admin.shipping.file_help', ['currency' => $fileCurrency])) ?></p>
        <?php foreach ($methods as $key => $zones): $first = $zones[0]; ?>
        <form method="post" action="admin.php?p=shipping&amp;action=file_method_save" data-shipping-file-method="<?= esc($key) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <input type="hidden" name="method" value="<?= esc($key) ?>">
            <div class="table-scroll"><table>
                <thead><tr><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.shipping.zone')) ?></th><th><?= esc(I18n::t('admin.shipping.countries_in_zones')) ?></th><th><?= esc(I18n::t('admin.th.price') . ' (' . $fileCurrency . ')') ?></th><th><?= esc(I18n::t('admin.shipping.per_kg') . ' (' . $fileCurrency . ')') ?></th></tr></thead>
                <tbody>
                <?php foreach ($zones as $fm): $zoneLabel = $zoneName($fm['zone']) . ' – ' . $first['name']; ?>
                <tr><td title="<?= esc($fm['key']) ?>"><?= esc($fm['name']) ?></td><td title="<?= esc($fm['zone']) ?>"><?= esc($zoneName($fm['zone'])) ?></td><td><?= esc($fm['countries'] === ['*'] ? I18n::t('admin.shipping.all_countries') : implode(', ', $fm['countries'])) ?></td>
                    <td><input aria-label="<?= esc(I18n::t('admin.th.price') . ': ' . $zoneLabel) ?>" inputmode="decimal" name="rates[<?= esc($fm['zone']) ?>][base]" value="<?= esc($money($fm['base'])) ?>" placeholder="<?= esc(I18n::t('admin.shipping.file_not_offered')) ?>" style="width:7rem"></td>
                    <td><input aria-label="<?= esc(I18n::t('admin.shipping.per_kg') . ': ' . $zoneLabel) ?>" inputmode="decimal" name="rates[<?= esc($fm['zone']) ?>][per_kg]" value="<?= esc($fm['base'] === null ? '' : $money($fm['per_kg'])) ?>" style="width:6rem"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p><label><input type="checkbox" name="active" value="1" <?= $first['active'] ? 'checked' : '' ?> data-shipping-file-active> <?= esc(I18n::t('admin.shipping.file_offered')) ?></label>
                <label style="margin-left:1rem"><?= esc(I18n::t('admin.shipping.free_above') . ' (' . $fileCurrency . ')') ?> <input inputmode="decimal" name="free_above" value="<?= esc($money($first['free_above'])) ?>" style="width:7rem"></label>
                <label style="margin-left:1rem"><?= esc(I18n::t('admin.shipping.days')) ?> <input name="delivery_days" value="<?= esc($first['delivery_days'] ?? '') ?>" maxlength="20" style="width:5rem"></label>
                <button class="btn btn-sm btn-primary" style="margin-left:1rem"><?= esc(I18n::t('admin.btn.save')) ?></button>
                <?php if (isset($settings[$key])): ?><button class="btn btn-sm" name="reset" value="1" style="margin-left:.5rem"><?= esc(I18n::t('admin.shipping.file_reset')) ?></button><?php endif; ?></p>
        </form>
        <?php endforeach; ?>
    </div>
    <?php
}

/**
 * R62 (review round 27, finding 8): what the doctor's shipping.file_edited and shipping.methods_file "Fix" lead to, shown
 * in every store (was: a HUF/CZK/PLN store showed only that Standard and Express are priced in EUR, no way out).
 */
function shippingFileNotices(): void {
    if (Shipping::fileEdited()) {
        $methods = implode(', ', array_map(static fn(string $k): string => Shipping::methodName($k, I18n::locale()) ?? $k, Shipping::CHECKOUT_METHODS));
        $notice = I18n::format('admin.shipping.file_edited', ['methods' => $methods]);
        echo '<p class="flash flash-warn" role="alert" data-shipping-file-edited>', esc($notice), "</p>\n";
    }
    if (!empty(DataOverrides::shippingMethods()['invalid'])) {
        $notice = I18n::format('admin.shipping.methods_file_invalid', ['file' => DataOverrides::SHIPPING_METHODS]);
        echo '<p class="flash flash-warn" role="alert" data-shipping-methods-file-invalid>', esc($notice), "</p>\n";
    }
}

/**
 * R58 (review round 23, medium S4): save one shipping.json method's settings from Admin → Shipping into the data
 * directory (DataOverrides::saveShippingMethods()), so shipping.json itself is never edited.
 */
function handleShippingFileMethodSave(): void {
    Security::adminRequire('shipping.manage');
    $key = Security::str($_POST['method'] ?? '');
    $all = DataOverrides::shippingMethods()['methods'] ?? [];
    $old = $all[$key] ?? null;
    $result = shippingFileMethodSettings($key, $_POST, $all);
    if ($result['error'] === null && !DataOverrides::saveShippingMethods($result['settings'])) $result['error'] = I18n::t('admin.shipping.file_unwritable');
    if ($result['error'] !== null) { setFlash('err', $result['error']); header('Location: admin.php?p=shipping'); exit; }
    Shipping::forget();
    AuditLog::log('shipping_file_method', 0, ['method' => $key, 'settings' => $old], ['method' => $key, 'settings' => $result['settings'][$key] ?? null], 'Admin shipping.json method settings');
    setFlash('ok', I18n::t('admin.shipping.file_saved'));
    header('Location: admin.php?p=shipping');
    exit;
}

/**
 * The settings of every shipping.json method ($all, DataOverrides::shippingMethods()) with $key's taken from the posted
 * form: the whole method (R60: was only what differed from shipping.json, so a release changing its prices changed the
 * merchant's, and a restored shipping.json offered 3.99/7.99 again); "reset" drops the method's settings. Amounts are typed in shipping.json's currency; an empty price
 * means the method does not serve that zone. R59: only the zones the form shows (a posted rates[local] was saved too).
 * @return array{settings: array<string, mixed>, error: ?string}
 */
function shippingFileMethodSettings(string $key, array $post, array $all): array {
    $file = Shipping::fileConfig();
    $method = in_array($key, Shipping::CHECKOUT_METHODS, true) && is_array($file['methods'][$key] ?? null) ? $file['methods'][$key] : null;
    if ($method === null) return ['settings' => $all, 'error' => I18n::t('admin.common.record_missing')];
    $zones = Shipping::editableZones($file);
    // R60 (review round 25, medium 3): the whole method is saved, as it stands after this form (Shipping::methodSettings()).
    $own = Shipping::methodSettings((array)(Shipping::withSettings($file, ['methods' => $all])['methods'][$key] ?? $method), $zones);
    if (!empty($post['reset'])) {
        // R60 (review round 25, low): the shipped prices, free shipping and delivery days; the method stays on or off as it
        // was (was: the settings were dropped, which switched a method the merchant had turned off back on).
        $all[$key] = ['active' => $own['active']] + Shipping::methodSettings($method, $zones);
        return ['settings' => $all, 'error' => null];
    }
    $currency = Shipping::currency();
    $invalid = false;
    $amount = static function (mixed $raw, ?int $empty) use ($currency, &$invalid): ?int {
        $cents = Money::adminInput($raw, $currency, $empty);
        if ($cents === null && !($empty === null && is_scalar($raw) && trim((string)$raw) === '')) $invalid = true;
        return $cents;
    };
    $own['active'] = !empty($post['active']);
    $freeAbove = $amount($post['free_above'] ?? '', null);
    // R59 (review round 24, lows): 0 was saved and did nothing; bad delivery days said "amount format".
    if ($freeAbove === 0) return ['settings' => $all, 'error' => I18n::t('admin.shipping.free_above_zero')];
    $own['free_above'] = $freeAbove;
    $days = trim(Security::str($post['delivery_days'] ?? ''));
    if (!Shipping::deliveryDaysAccepted($days, $own['delivery_days'])) return ['settings' => $all, 'error' => I18n::t('admin.shipping.days_format')];
    $own['delivery_days'] = $days;
    $posted = is_array($post['rates'] ?? null) ? $post['rates'] : [];
    foreach ($zones as $zone) {
        if (!is_array($posted[$zone] ?? null)) continue;
        $base = $amount($posted[$zone]['base'] ?? '', null);
        $own['rates'][$zone] = $base === null ? null : ['base' => $base, 'per_kg' => (int)$amount($posted[$zone]['per_kg'] ?? '', 0)];
    }
    if ($invalid) return ['settings' => $all, 'error' => I18n::format('admin.msg.amount_format', ['example' => Money::decimal(1290, $currency) . ' ' . $currency])];
    $all[$key] = $own;
    return ['settings' => $all, 'error' => null];
}
