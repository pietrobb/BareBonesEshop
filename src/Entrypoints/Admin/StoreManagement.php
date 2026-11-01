<?php
/** Admin "Stores and seller", owner part: add or remove a storefront and edit the legal texts (StoreSettings, DataOverrides). */

function adminStoreManagementCards(array $stores, bool $writable): void {
    if (Security::adminRole() !== 'owner') return;
    $page = Security::str($_GET['legal'] ?? '');
    $lang = Security::str($_GET['lang'] ?? '');
    $legalStore = Security::str($_GET['store'] ?? '');
    $disabled = $writable ? '' : ' disabled';
    ?>
    <div class="card" id="store-add" data-store-add>
        <h3><?= esc(I18n::t('admin.stores.add')) ?></h3>
        <p class="muted"><?= esc(I18n::t('admin.stores.add_hint')) ?></p>
        <form method="post" action="admin.php?p=stores&amp;action=add">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(12rem,1fr));gap:.25rem 1rem">
                <?php foreach (['id' => 'cz2', 'name' => '', 'url_prefix' => '/xx', 'default_locale' => 'en', 'currency' => 'eur'] as $field => $placeholder): ?>
                <div class="form-row"><label for="store-add-<?= $field ?>"><?= esc(I18n::t('admin.stores.new.' . $field)) ?></label>
                    <input id="store-add-<?= $field ?>" name="<?= $field ?>" placeholder="<?= esc($placeholder) ?>" required maxlength="200"></div>
                <?php endforeach; ?>
            </div>
            <p><button class="btn btn-primary" type="submit"<?= $disabled ?>><?= esc(I18n::t('admin.stores.add')) ?></button></p>
        </form>
        <?php foreach ($stores as $storeId => $store): if (!is_array($store)) continue; ?>
        <form method="post" action="admin.php?p=stores&amp;action=remove&amp;store=<?= esc(rawurlencode((string)$storeId)) ?>" style="display:inline-block;margin:.25rem .5rem 0 0" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?= esc(I18n::format('admin.stores.remove_confirm', ['store' => (string)$storeId])) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <button class="btn btn-sm btn-danger" type="submit"<?= $disabled ?>><?= esc(I18n::format('admin.stores.remove', ['store' => (string)$storeId])) ?></button>
        </form>
        <?php endforeach; ?>
    </div>
    <div class="card" id="store-legal" data-store-legal>
        <h3><?= esc(I18n::t('admin.stores.legal_texts')) ?></h3>
        <p class="muted"><?= esc(I18n::t('admin.stores.legal_hint')) ?></p>
        <?php foreach ($stores as $storeId => $store): if (!is_array($store)) continue; $storeId = (string)$storeId; ?>
        <p data-store-legal-links="<?= esc($storeId) ?>"><strong><?= esc($storeId) ?>:</strong>
            <?php foreach (ConsumerNotice::LEGAL_PAGES as $legalPage): foreach (array_map('strval', (array)($store['languages'] ?? [])) as $locale): ?>
            <a class="btn btn-sm" href="admin.php?p=stores&amp;store=<?= esc(rawurlencode($storeId)) ?>&amp;legal=<?= esc($legalPage) ?>&amp;lang=<?= esc($locale) ?>#store-legal"><?= esc($legalPage . ' (' . $locale . ')') ?><?= DataOverrides::legalPath($legalPage, $locale, $storeId) !== null ? ' *' : '' ?></a>
            <?php endforeach; endforeach; ?></p>
        <?php endforeach; ?>
        <?php if (isset($stores[$legalStore]) && DataOverrides::validLegal($page, $lang, $legalStore)):
            $shipped = dirname(__DIR__, 3) . "/templates/legal/{$lang}/{$page}.html";
            $html = (string)@file_get_contents(DataOverrides::legalPath($page, $lang, $legalStore) ?? (is_file($shipped) ? $shipped : dirname(__DIR__, 3) . "/templates/legal/en/{$page}.html")); ?>
        <form method="post" action="admin.php?p=stores&amp;action=legal_save&amp;store=<?= esc(rawurlencode($legalStore)) ?>&amp;legal=<?= esc($page) ?>&amp;lang=<?= esc($lang) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <label for="store-legal-html"><?= esc($legalStore . ': ' . $page . ' (' . $lang . ')') ?></label>
            <textarea id="store-legal-html" name="html" rows="18" style="width:100%;font-family:monospace"><?= esc($html) ?></textarea>
            <p><button class="btn btn-primary" type="submit"<?= DataOverrides::dir() === null ? ' disabled' : '' ?>><?= esc(I18n::t('admin.btn.save')) ?></button>
                <button class="btn" type="submit" name="reset" value="1"><?= esc(I18n::t('admin.stores.legal_reset')) ?></button></p>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

function handleStoreAdd(): void {
    if (Security::adminRole() !== 'owner') return;
    $result = StoreSettings::addStore($_POST);
    setFlash($result['ok'] ? 'ok' : 'err', $result['ok'] ? I18n::format('admin.stores.added', ['store' => strtolower(trim(Security::str($_POST['id'] ?? '')))]) : implode(' ', $result['errors']));
    header('Location: admin.php?p=stores');
    exit;
}

function handleStoreRemove(): void {
    if (Security::adminRole() !== 'owner') return;
    $storeId = Security::str($_GET['store'] ?? '');
    $result = StoreSettings::removeStore($storeId, Db::get());
    setFlash($result['ok'] ? 'ok' : 'err', $result['ok'] ? I18n::format('admin.stores.removed', ['store' => $storeId]) : implode(' ', $result['errors']));
    header('Location: admin.php?p=stores');
    exit;
}

/** The owner's own legal text (raw HTML with the {{placeholders}} of the shipped template); reset restores the shipped one. */
function handleStoreLegalSave(): void {
    if (Security::adminRole() !== 'owner') return;
    $page = Security::str($_GET['legal'] ?? '');
    $lang = Security::str($_GET['lang'] ?? '');
    $storeId = Security::str($_GET['store'] ?? '');
    $html = !empty($_POST['reset']) || !is_string($_POST['html'] ?? null) ? '' : str_replace("\r\n", "\n", $_POST['html']);
    $ok = isset(StoreSettings::load()['stores'][$storeId]) && DataOverrides::saveLegal($page, $lang, $html, $storeId);
    if ($ok) AuditLog::log('legal_text', 0, null, ['store' => $storeId, 'page' => $page, 'locale' => $lang, 'sha256' => $html === '' ? null : hash('sha256', $html)]);
    setFlash($ok ? 'ok' : 'err', I18n::t($ok ? 'admin.stores.legal_saved' : 'admin.stores.err.write'));
    header('Location: admin.php?p=stores&store=' . rawurlencode($storeId) . '&legal=' . rawurlencode($page) . '&lang=' . rawurlencode($lang) . '#store-legal');
    exit;
}
