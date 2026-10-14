<?php
/** Admin store scope: the admin works in one granted store at a time and the switcher offers only granted stores. */

/**
 * Activate the request's store: ?store= / POST store must be granted (403 otherwise, never silently swapped),
 * else the remembered, host or first granted store.
 * @return list<string> store ids granted to the admin
 */
function adminActivateStore(array $storesCfg): array {
    $granted = AdminStoreScope::allowed($storesCfg, $_SESSION);
    $requested = $_POST['store'] ?? $_GET['store'] ?? null;
    $storeId = AdminStoreScope::select(
        $granted,
        $requested === null ? null : (is_string($requested) ? $requested : ''),
        is_string($_SESSION['admin_store'] ?? null) ? $_SESSION['admin_store'] : null,
        StoreContext::resolve($storesCfg)->id()
    );
    if ($storeId === null) {
        http_response_code(403);
        exit('Forbidden: store not available to this admin');
    }
    Bootstrap::initStoreContext(null, null, $storeId);
    return $granted;
}

/** POST admin_switch_store: remember the already validated and activated store, then return to an admin page. */
function handleStoreSwitch(array $pagePermissions): never {
    if (!Security::csrfValidate()) {
        http_response_code(403);
        exit('Forbidden');
    }
    $_SESSION['admin_store'] = StoreContext::current()->id();
    header('Location: ' . adminStoreSwitchTarget($_POST['return'] ?? '', $pagePermissions));
    exit;
}

/** Where the switcher returns: a known admin page, never a caller-supplied URL. */
function adminStoreSwitchTarget(mixed $return, array $pagePermissions): string {
    $return = Security::str($return);
    return 'admin.php?p=' . (isset($pagePermissions[$return]) ? $return : 'dashboard');
}

/** Store switcher for the admin navigation; nothing when the admin has a single store. Detail pages return to their list. */
function renderStoreSwitcher(array $granted, array $storesCfg, string $page): void {
    if (count($granted) < 2) return;
    $current = StoreContext::current()->id();
    $return = ['order' => 'orders', 'product' => 'products', 'customer' => 'customers', 'page_edit' => 'pages',
        'attribute' => 'attributes', 'warehouse' => 'warehouses', 'feed' => 'feeds'][$page] ?? $page;
    ?>
    <form method="post" action="admin.php" class="store-switcher">
        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
        <input type="hidden" name="admin_switch_store" value="1">
        <input type="hidden" name="return" value="<?= esc($return) ?>">
        <label for="admin-store"><?= esc(I18n::t('admin.store_switcher.label')) ?></label>
        <select id="admin-store" name="store" data-store-switcher>
            <?php foreach ($granted as $storeId): ?>
            <option value="<?= esc($storeId) ?>"<?= $storeId === $current ? ' selected' : '' ?>><?= esc((string)($storesCfg['stores'][$storeId]['name'] ?? $storeId)) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.store_switcher.submit')) ?></button>
    </form>
    <?php
}
