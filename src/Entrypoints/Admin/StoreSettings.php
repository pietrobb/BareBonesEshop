<?php
/** Admin "Stores and seller": storefront name, seller identity and VAT switches of each store (StoreSettings). */

/** Form values of a failed save, keyed by store id, so the merchant does not retype them. */
$GLOBALS['adminStoreDraft'] = [];

/** An owner edits every store; another role with stores.manage only the store it is working in. */
function adminStoreSettingsAllowed(string $storeId): bool {
    return Security::adminRole() === 'owner' || $storeId === StoreContext::current()->id();
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
        $problems = $seller === null ? Setup::legalIdentityProblems($legal) : [];
    ?>
    <div class="card" id="store-<?= esc($storeId) ?>" data-store-settings="<?= esc($storeId) ?>">
        <h3><?= esc((string)($store['name'] ?? $storeId)) ?> <span class="muted">(<?= esc($storeId) ?>)</span>
            <?php if ($seller === null): ?>
            <span class="badge badge-<?= $problems ? 'draft' : 'active' ?>" data-store-status="<?= $problems ? 'example' : 'ready' ?>"><?= esc($problems ? I18n::format('admin.stores.example_data', ['problems' => implode('; ', $problems)]) : I18n::t('admin.stores.ready')) ?></span>
            <?php endif; ?></h3>
        <p class="muted"><?= esc(I18n::format('admin.stores.fixed', [
            'currency' => strtoupper((string)($store['currency'] ?? '')),
            'prefix' => (string)($store['url_prefix'] ?? '') !== '' ? (string)$store['url_prefix'] : '/',
            'invoice_prefix' => (string)($legal['invoice_prefix'] ?? '') !== '' ? (string)$legal['invoice_prefix'] : '—',
        ])) ?></p>
        <form method="post" action="admin.php?p=stores&amp;action=save&amp;store=<?= esc(rawurlencode($storeId)) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="store-<?= esc($storeId) ?>-store_name"><?= esc(I18n::t('admin.stores.field.store_name')) ?></label>
                <input id="store-<?= esc($storeId) ?>-store_name" name="store_name" value="<?= esc((string)($draft['store_name'] ?? ($store['name'] ?? ''))) ?>" required maxlength="200"></div>
            <?php if ($seller !== null): ?>
            <p data-store-shared-seller><?= esc(I18n::format('admin.stores.shared_seller', ['seller' => $seller])) ?></p>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:.25rem 1rem">
                <?php foreach (StoreSettings::LEGAL_TEXT as $field): ?>
                <div class="form-row"><label for="store-<?= esc($storeId . '-' . $field) ?>"><?= esc(I18n::t('admin.stores.field.' . $field)) ?></label>
                    <input id="store-<?= esc($storeId . '-' . $field) ?>" name="<?= esc($field) ?>" value="<?= esc((string)$value($field)) ?>" maxlength="200"<?= $field === 'email' ? ' type="email"' : '' ?>></div>
                <?php endforeach; ?>
                <div class="form-row"><label for="store-<?= esc($storeId) ?>-payment_terms_days"><?= esc(I18n::t('admin.stores.field.payment_terms_days')) ?></label>
                    <input id="store-<?= esc($storeId) ?>-payment_terms_days" name="payment_terms_days" type="number" min="0" max="365" value="<?= esc((string)$value('payment_terms_days')) ?>"></div>
            </div>
            <label style="display:block"><input type="checkbox" name="vat_registered" value="1"<?= ($draft !== null ? ($draft['vat_registered'] ?? '') === '1' : !empty($legal['vat_registered'])) ? ' checked' : '' ?>> <?= esc(I18n::t('admin.stores.field.vat_registered')) ?></label>
            <?php endif; ?>
            <label style="display:block"><input type="checkbox" name="oss_enabled" value="1"<?= ($draft !== null ? ($draft['oss_enabled'] ?? '') === '1' : !empty($store['oss_enabled'])) ? ' checked' : '' ?>> <?= esc(I18n::t('admin.stores.field.oss_enabled')) ?></label>
            <?php if ($storeId !== $defaultId): ?>
            <input type="hidden" name="public" value="0"><label style="display:block"><input type="checkbox" name="public" value="1" data-store-public<?= ($draft !== null ? ($draft['public'] ?? '') === '1' : StoreContext::isPublicConfig($store)) ? ' checked' : '' ?>> <?= esc(I18n::t('admin.stores.field.public')) ?></label>
            <?php endif; ?>
            <p style="margin-top:.5rem"><button class="btn btn-primary" type="submit"<?= $writable ? '' : ' disabled' ?>><?= esc(I18n::t('admin.btn.save')) ?></button></p>
        </form>
    </div>
    <?php endforeach;
    adminStoreManagementCards(is_array($stores) ? $stores : [], $writable);
}

function handleStoreSettingsSave(): void {
    $storeId = Security::str($_GET['store'] ?? '');
    if (!adminStoreSettingsAllowed($storeId)) {
        setFlash('err', I18n::t('admin.stores.err.unknown'));
        return;
    }
    $result = StoreSettings::save($storeId, $_POST);
    if (!$result['ok']) {
        setFlash('err', implode(' ', $result['errors']));
        $GLOBALS['adminStoreDraft'][$storeId] = array_map(static fn($v): string => is_scalar($v) ? (string)$v : '', $_POST);
        return;
    }
    setFlash('ok', I18n::format('admin.stores.saved', ['store' => $storeId]));
    header('Location: admin.php?p=stores#store-' . rawurlencode($storeId));
    exit;
}
