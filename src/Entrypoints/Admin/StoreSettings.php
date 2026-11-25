<?php
/** Admin "Stores and seller": storefront name, seller identity and VAT switches of each store (StoreSettings). */

/** Form values of a failed save, keyed by store id, so the merchant does not retype them. */
$GLOBALS['adminStoreDraft'] = [];

/** An owner edits every store; another role with stores.manage only the store it is working in. */
function adminStoreSettingsAllowed(string $storeId): bool {
    return Security::adminRole() === 'owner' || $storeId === StoreContext::current()->id();
}

/** R48 (review round 18, low 7): one seller-identity issue in the admin's language (was the doctor's English). */
function adminStoreIdentityIssue(array $issue): string {
    $field = $issue['field'] === 'address'
        ? I18n::t('admin.stores.field.street') . ', ' . I18n::t('admin.stores.field.city')
        : I18n::t('admin.stores.field.' . $issue['field']);
    if ($issue['value'] === null) return I18n::format('admin.stores.problem.missing', ['field' => $field]);
    return I18n::format($issue['field'] === 'iban' ? 'admin.stores.problem.iban' : 'admin.stores.problem.example', ['field' => $field, 'value' => $issue['value']]);
}

function pageStoreSettings(): void {
    $settings = StoreSettings::load();
    $stores = $settings['stores'] ?? [];
    $defaultId = (string)($settings['default_store'] ?? '');
    $writable = StoreSettings::writable();
    ?>
    <h1><?= esc(I18n::t('admin.nav.stores')) ?></h1>
    <p class="muted"><?= esc(I18n::t('admin.stores.intro')) ?></p>
    <?php if (!$writable): ?><div class="flash flash-err" role="alert" data-stores-readonly><?= esc(I18n::t('admin.stores.not_writable')) ?></div><?php endif; ?>
    <?php foreach (is_array($stores) ? $stores : [] as $storeId => $store):
        $storeId = (string)$storeId;
        if (!is_array($store) || !adminStoreSettingsAllowed($storeId)) continue;
        $legal = is_array($store['legal'] ?? null) ? $store['legal'] : [];
        $draft = $GLOBALS['adminStoreDraft'][$storeId] ?? null;
        $value = static fn(string $field) => $draft !== null ? ($draft[$field] ?? '') : ($legal[$field] ?? '');
        $seller = isset($legal['seller']) ? (string)$legal['seller'] : null;
        $problems = $seller === null ? array_map('adminStoreIdentityIssue', Setup::legalIdentityIssues($legal)) : [];
    ?>
    <div class="card" id="store-<?= esc($storeId) ?>" data-store-settings="<?= esc($storeId) ?>">
        <h3><?= esc((string)($store['name'] ?? $storeId)) ?> <span class="muted">(<?= esc($storeId) ?>)</span>
            <?php if ($seller === null): ?>
            <span class="badge badge-<?= $problems ? 'draft' : 'active' ?>" data-store-status="<?= $problems ? 'example' : 'ready' ?>"><?= esc($problems ? I18n::format('admin.stores.example_data', ['problems' => implode('; ', $problems)]) : I18n::t('admin.stores.ready')) ?></span>
            <?php endif; ?></h3>
        <p class="muted"><?= esc(I18n::format('admin.stores.fixed', [
            'currency' => strtoupper((string)($store['currency'] ?? '')),
            'invoice_prefix' => (string)($legal['invoice_prefix'] ?? '') !== '' ? (string)$legal['invoice_prefix'] : '—',
        ])) ?></p>
        <form method="post" action="admin.php?p=stores&amp;action=save&amp;store=<?= esc(rawurlencode($storeId)) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="store-<?= esc($storeId) ?>-store_name"><?= esc(I18n::t('admin.stores.field.store_name')) ?></label>
                <input id="store-<?= esc($storeId) ?>-store_name" name="store_name" value="<?= esc((string)($draft['store_name'] ?? ($store['name'] ?? ''))) ?>" required maxlength="200"></div>
            <?php $zone = (string)($draft['timezone'] ?? StoreContext::timezoneFor($store)); ?>
            <div class="form-row"><label for="store-<?= esc($storeId) ?>-timezone"><?= esc(I18n::t('admin.stores.field.timezone')) ?></label>
                <select id="store-<?= esc($storeId) ?>-timezone" name="timezone" data-store-timezone aria-describedby="store-<?= esc($storeId) ?>-timezone-hint">
                    <?php foreach (DateTimeZone::listIdentifiers() as $identifier): ?><option<?= $identifier === $zone ? ' selected' : '' ?>><?= esc($identifier) ?></option><?php endforeach; ?>
                </select>
                <small class="muted" id="store-<?= esc($storeId) ?>-timezone-hint"><?= esc(I18n::t('admin.stores.timezone_hint')) ?></small></div>
            <?php /* R58 (review round 23, medium S3): the storefront languages; the default one is always offered */ $storeLocale = (string)($store['default_locale'] ?? 'en'); $storeLanguages = array_map('strval', (array)($draft['languages'] ?? ($store['languages'] ?? []))); ?>
            <fieldset class="form-row" data-store-languages aria-describedby="store-<?= esc($storeId) ?>-languages-hint"><legend><?= esc(I18n::t('admin.stores.field.languages')) ?></legend><input type="hidden" name="languages[]" value="<?= esc($storeLocale) ?>">
                <?php foreach (I18n::shippedLocales() as $lc): ?><label style="display:inline-block;margin-right:.75rem" lang="<?= esc($lc) ?>"><input type="checkbox" name="languages[]" value="<?= esc($lc) ?>"<?= $lc === $storeLocale || in_array($lc, $storeLanguages, true) ? ' checked' : '' ?><?= $lc === $storeLocale ? ' disabled' : '' ?>> <?= esc(I18n::localeName($lc)) ?></label><?php endforeach; ?>
                <small class="muted" id="store-<?= esc($storeId) ?>-languages-hint" style="display:block"><?= esc(I18n::t('admin.stores.languages_hint')) ?></small></fieldset>
            <?php if ($seller !== null): ?>
            <p data-store-shared-seller><?= esc(I18n::format('admin.stores.shared_seller', ['seller' => $seller])) ?></p><?php $ignored = StoreSettings::identityIgnored($settings, $storeId); if ($ignored !== []): /* R59 */ ?><div class="flash flash-err" role="alert" data-store-identity-ignored><?= esc(I18n::format('admin.stores.identity_ignored', ['fields' => implode(', ', $ignored)])) ?></div><?php endif; ?>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:.25rem 1rem">
                <?php foreach (StoreSettings::LEGAL_TEXT as $field): ?>
                <div class="form-row"><label for="store-<?= esc($storeId . '-' . $field) ?>"><?= esc(I18n::t('admin.stores.field.' . $field)) ?></label>
                    <input id="store-<?= esc($storeId . '-' . $field) ?>" name="<?= esc($field) ?>" value="<?= esc((string)$value($field)) ?>" maxlength="200"<?= $field === 'email' ? ' type="email"' : '' ?>></div>
                <?php endforeach; ?>
                <div class="form-row"><label for="store-<?= esc($storeId) ?>-payment_terms_days"><?= esc(I18n::t('admin.stores.field.payment_terms_days')) ?></label>
                    <input id="store-<?= esc($storeId) ?>-payment_terms_days" name="payment_terms_days" type="number" min="0" max="365" value="<?= esc((string)$value('payment_terms_days')) ?>"></div>
            </div>
            <input type="hidden" name="vat_registered" value="0"><label style="display:block"><input type="checkbox" name="vat_registered" value="1"<?= ($draft !== null ? ($draft['vat_registered'] ?? '') === '1' : StoreSettings::vatRegistered($legal)) ? ' checked' : '' ?>> <?= esc(I18n::t('admin.stores.field.vat_registered')) ?></label>
            <?php endif; ?>
            <label style="display:block"><input type="checkbox" name="oss_enabled" value="1"<?= ($draft !== null ? ($draft['oss_enabled'] ?? '') === '1' : !empty($store['oss_enabled'])) ? ' checked' : '' ?>> <?= esc(I18n::t('admin.stores.field.oss_enabled')) ?></label>
            <?php $pricing = (string)($draft['cross_border_pricing'] ?? (Cart::keepsNetAbroad($store) ? 'net' : 'gross')); ?>
            <div class="form-row"><label for="store-<?= esc($storeId) ?>-cross_border_pricing"><?= esc(I18n::t('admin.stores.field.cross_border_pricing')) ?></label>
                <select id="store-<?= esc($storeId) ?>-cross_border_pricing" name="cross_border_pricing" data-store-cross-border-pricing aria-describedby="store-<?= esc($storeId) ?>-cross_border_pricing-hint">
                    <?php foreach (['net', 'gross'] as $option): ?><option value="<?= $option ?>"<?= $option === $pricing ? ' selected' : '' ?>><?= esc(I18n::t('admin.stores.cross_border_pricing.' . $option)) ?></option><?php endforeach; ?>
                </select>
                <small class="muted" id="store-<?= esc($storeId) ?>-cross_border_pricing-hint"><?= esc(I18n::t('admin.stores.cross_border_pricing_hint')) ?></small></div>
            <?php if ($storeId !== $defaultId): ?>
            <input type="hidden" name="public" value="0"><label style="display:block"><input type="checkbox" name="public" value="1" data-store-public<?= ($draft !== null ? ($draft['public'] ?? '') === '1' : StoreContext::isPublicConfig($store)) ? ' checked' : '' ?>> <?= esc(I18n::t('admin.stores.field.public')) ?></label>
            <?php endif; ?>
            <p style="margin-top:.5rem"><button class="btn btn-primary" type="submit"<?= $writable ? '' : ' disabled' ?>><?= esc(I18n::t('admin.btn.save')) ?></button></p>
        </form>
        <?php if (Security::adminRole() === 'owner'): // the host picks the store: only the owner moves it; R55 UX: own form (a sample IBAN blocked the seller form) ?>
        <form method="post" action="admin.php?p=stores&amp;action=domains&amp;store=<?= esc(rawurlencode($storeId)) ?>" data-store-domains-form>
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="store-<?= esc($storeId) ?>-domains"><?= esc(I18n::t('admin.stores.field.domains')) ?></label>
                <textarea id="store-<?= esc($storeId) ?>-domains" name="domains" rows="2" maxlength="2600" data-store-domains aria-describedby="store-<?= esc($storeId) ?>-domains-hint"><?= esc((string)($GLOBALS['adminStoreDomainsDraft'][$storeId]['domains'] ?? implode("\n", array_map('strval', (array)($store['domains'] ?? []))))) ?></textarea>
                <small class="muted" id="store-<?= esc($storeId) ?>-domains-hint"><?= esc(I18n::t('admin.stores.domains_hint')) ?></small></div>
            <div class="form-row"><label for="store-<?= esc($storeId) ?>-url_prefix"><?= esc(I18n::t('admin.stores.field.url_prefix')) ?></label>
                <input id="store-<?= esc($storeId) ?>-url_prefix" name="url_prefix" maxlength="21" pattern="/?[a-z0-9\-]{0,20}" placeholder="/cz" data-store-url-prefix aria-describedby="store-<?= esc($storeId) ?>-url_prefix-hint" value="<?= esc((string)($GLOBALS['adminStoreDomainsDraft'][$storeId]['url_prefix'] ?? ($store['url_prefix'] ?? ''))) ?>">
                <small class="muted" id="store-<?= esc($storeId) ?>-url_prefix-hint"><?= esc(I18n::t('admin.stores.url_prefix_hint')) ?></small></div>
            <p><button class="btn" type="submit"<?= $writable ? '' : ' disabled' ?>><?= esc(I18n::t('admin.btn.save')) ?></button></p>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach;
    adminStoreManagementCards(is_array($stores) ? $stores : [], $writable);
}

/** $domains (R55): the store's domains and URL prefix from their own form; the owner only (the host picks the store). */
function handleStoreSettingsSave(bool $domains = false): void {
    $storeId = Security::str($_GET['store'] ?? '');
    if (!adminStoreSettingsAllowed($storeId) || ($domains && Security::adminRole() !== 'owner')) {
        setFlash('err', I18n::t('admin.stores.err.unknown'));
        return;
    }
    $input = $_POST;
    if (Security::adminRole() !== 'owner') unset($input['domains']);
    $result = $domains ? StoreSettings::saveDomains($storeId, $_POST['domains'] ?? '', $_POST['url_prefix'] ?? null) : StoreSettings::save($storeId, $input);
    if (!$result['ok']) {
        setFlash('err', implode(' ', $result['errors']));
        // R58: the ticked languages (languages[]) stay ticked too.
        $GLOBALS[$domains ? 'adminStoreDomainsDraft' : 'adminStoreDraft'][$storeId] = (is_array($_POST['languages'] ?? null) ? ['languages' => array_map('strval', array_filter($_POST['languages'], 'is_scalar'))] : [])
            + array_map(static fn($v): string => is_scalar($v) ? (string)$v : '', $_POST);
        return;
    }
    setFlash(empty($result['warning']) ? 'ok' : 'warn', trim(I18n::format('admin.stores.saved', ['store' => $storeId]) . ' ' . ($result['warning'] ?? '')));
    header('Location: admin.php?p=stores#store-' . rawurlencode($storeId));
    exit;
}
