<?php
/** Customer and GDPR administration. */

function pageCustomers(): void {
    $q = trim((string)($_GET['q'] ?? ''));
    $customers = Customers::adminList($q, 100);
    // Pre-fetch order counts for the listed customers.
    $counts = [];
    $ids = array_map(static fn($c) => (int)$c['id'], $customers);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = Db::get()->prepare("SELECT customer_id, COUNT(*) AS cnt FROM orders WHERE store_id = ? AND customer_id IN ({$ph}) GROUP BY customer_id");
        $st->execute([StoreContext::current()->id(), ...$ids]);
        foreach ($st->fetchAll() as $r) { $counts[(int)$r['customer_id']] = (int)$r['cnt']; }
    }
    ?>
    <h1><?= esc(I18n::t('admin.title.customers')) ?> <span class="muted">(<?= count($customers) ?>)</span></h1>
    <div class="card">
        <form method="get" action="admin.php" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
            <input type="hidden" name="p" value="customers">
            <input type="text" name="q" value="<?= esc($q) ?>" placeholder="<?= esc(I18n::t('admin.customers.search_placeholder')) ?>" style="min-width:280px">
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.search')) ?></button>
            <?php if ($q !== ''): ?><a class="btn btn-sm" href="admin.php?p=customers"><?= esc(I18n::t('admin.customers.clear_filter')) ?></a><?php endif; ?>
        </form>
        <?php if (!$customers): ?>
        <p class="muted"><?= esc(I18n::t('admin.customers.none')) ?></p>
        <?php else: ?>
        <table>
            <tr><th>ID</th><th><?= esc(I18n::t('admin.common.email')) ?></th><th><?= esc(I18n::t('admin.customers.name')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.title.orders')) ?></th><th><?= esc(I18n::t('admin.customers.registered')) ?></th><th></th></tr>
            <?php foreach ($customers as $c): ?>
            <tr>
                <td><?= (int)$c['id'] ?></td>
                <td><?= esc($c['email']) ?></td>
                <td><?= esc($c['name'] ?? '') ?></td>
                <td><span class="badge badge-<?= ($c['status'] ?? '')==='active'?'active':'draft' ?>"><?= esc($c['status'] ?? '') ?></span></td>
                <td><?= (int)($counts[(int)$c['id']] ?? 0) ?></td>
                <td class="muted"><?= esc(substr((string)$c['created_at'], 0, 16)) ?></td>
                <td><a class="btn btn-sm" href="admin.php?p=customer&id=<?= (int)$c['id'] ?>"><?= esc(I18n::t('admin.sales.detail')) ?></a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
function pageCustomerDetail(): void {
    $id = Security::int($_GET['id'] ?? 0);
    $c = $id > 0 ? Customers::byId($id) : null;
    if (!$c) {
        echo '<h1>' . esc(I18n::t('admin.title.customer')) . '</h1><div class="card"><p class="muted">' . esc(I18n::t('admin.customers.not_found'))
            . ' <a href="admin.php?p=customers">' . esc(I18n::t('admin.common.back_to_list')) . '</a></p></div>';
        return;
    }
    $status   = (string)($c['status'] ?? '');
    $addresses = Customers::addresses($id);
    $orders    = Customers::orders($id, 100);
    $rmas      = Rma::forCustomer($id);
    $anon = $status === 'anonymized';
    ?>
    <h1><?= esc(I18n::t('admin.title.customer')) ?> #<?= (int)$c['id'] ?> <span class="muted"><?= esc($c['email']) ?></span></h1>
    <p style="margin-bottom:1rem"><a class="btn btn-sm" href="admin.php?p=customers">← <?= esc(I18n::t('admin.customers.list')) ?></a></p>

    <div class="card">
        <h3><?= esc(I18n::t('admin.customers.profile')) ?></h3>
        <table>
            <tr><th><?= esc(I18n::t('admin.customers.name')) ?></th><td><?= esc($c['name'] ?? '') ?></td></tr>
            <tr><th><?= esc(I18n::t('admin.common.email')) ?></th><td><?= esc($c['email']) ?></td></tr>
            <tr><th><?= esc(I18n::t('admin.customers.phone')) ?></th><td><?= esc($c['phone'] ?? '') ?></td></tr>
            <tr><th><?= esc(I18n::t('admin.th.status')) ?></th><td><span class="badge badge-<?= $status==='active'?'active':'draft' ?>"><?= esc($status) ?></span></td></tr>
            <tr><th><?= esc(I18n::t('admin.customers.registered')) ?></th><td class="muted"><?= esc(substr((string)($c['created_at'] ?? ''), 0, 16)) ?></td></tr>
            <tr><th><?= esc(I18n::t('admin.customers.last_login')) ?></th><td class="muted"><?= esc(substr((string)($c['last_login_at'] ?? ''), 0, 16)) ?: '—' ?></td></tr>
        </table>
        <?php if (!$anon): ?>
        <p style="margin-top:1rem">
            <?php if (Security::adminCan('customers.edit')): ?>
            <?php $toggleTo = $status === 'active' ? 'blocked' : 'active'; ?>
            <form method="post" action="admin.php?p=customer&id=<?= (int)$c['id'] ?>&action=status" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="status" value="<?= esc($toggleTo) ?>">
                <button class="btn btn-sm<?= $toggleTo==='blocked'?' btn-danger':' btn-primary' ?>" type="submit">
                    <?= esc(I18n::t($toggleTo === 'blocked' ? 'admin.customers.block' : 'admin.customers.unblock')) ?>
                </button>
            </form>
            <?php endif; ?>
            <?php if (Security::adminCan('gdpr.view')): ?>
            <form method="post" action="admin.php?p=gdpr&action=export" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.gdpr.export_hint')) ?></button>
            </form>
            <?php endif; ?>
            <a class="btn btn-sm" href="admin.php?p=gdpr&email=<?= urlencode((string)$c['email']) ?>"><?= esc(I18n::t('admin.customers.gdpr_erase')) ?></a>
        </p>
        <?php else: ?>
        <p class="muted" style="margin-top:1rem"><?= esc(I18n::t('admin.customers.anonymized')) ?></p>
        <?php endif; ?>
    </div>
    <?php b2bCustomerCard((int)$c['id']); ?>
    <div class="card">
        <h3><?= esc(I18n::t('admin.customers.addresses')) ?> (<?= count($addresses) ?>)</h3>
        <?php if (!$addresses): ?>
        <p class="muted"><?= esc(I18n::t('admin.customers.no_addresses')) ?></p>
        <?php else: ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.common.type')) ?></th><th><?= esc(I18n::t('admin.customers.name')) ?></th><th><?= esc(I18n::t('admin.customers.address')) ?></th><th><?= esc(I18n::t('admin.customers.city')) ?></th><th><?= esc(I18n::t('admin.common.country')) ?></th></tr>
            <?php foreach ($addresses as $a): ?>
            <tr>
                <td><?= esc($a['type'] ?? '') ?><?= !empty($a['is_default']) ? ' <span class="muted">' . esc(I18n::t('admin.customers.default')) . '</span>' : '' ?></td>
                <td><?= esc($a['name'] ?? '') ?></td>
                <td><?= esc($a['street'] ?? '') ?></td>
                <td><?= esc(trim(($a['zip'] ?? '') . ' ' . ($a['city'] ?? ''))) ?></td>
                <td><?= esc($a['country'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?= esc(I18n::t('admin.title.orders')) ?> (<?= count($orders) ?>)</h3>
        <?php if (!$orders): ?>
        <p class="muted"><?= esc(I18n::t('admin.customers.no_orders')) ?></p>
        <?php else: ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.common.number')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.amount')) ?></th><th><?= esc(I18n::t('admin.th.date')) ?></th><th></th></tr>
            <?php foreach ($orders as $o): ?>
            <tr>
                <td><?= esc($o['order_number']) ?></td>
                <td><span class="badge badge-<?= esc($o['status']) ?>"><?= esc($o['status']) ?></span></td>
                <td><?= esc(I18n::price((int)$o['total'], $o['currency'])) ?></td>
                <td class="muted"><?= esc(substr((string)$o['created_at'], 0, 16)) ?></td>
                <td><a class="btn btn-sm" href="admin.php?p=order&id=<?= (int)$o['id'] ?>"><?= esc(I18n::t('admin.sales.detail')) ?></a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3><?= esc(I18n::t('admin.customers.returns')) ?> (<?= count($rmas) ?>)</h3>
        <?php if (!$rmas): ?>
        <p class="muted"><?= esc(I18n::t('admin.rma.none')) ?></p>
        <?php else: ?>
        <table>
            <tr><th>RMA</th><th><?= esc(I18n::t('admin.order.label')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.date')) ?></th><th></th></tr>
            <?php foreach ($rmas as $r): ?>
            <tr>
                <td><?= esc($r['rma_number']) ?></td>
                <td>#<?= (int)$r['order_id'] ?></td>
                <td><span class="badge badge-<?= esc($r['status']) ?>"><?= esc($r['status']) ?></span></td>
                <td class="muted"><?= esc(substr((string)$r['created_at'], 0, 16)) ?></td>
                <td><a class="btn btn-sm" href="admin.php?p=rma&status=<?= esc($r['status']) ?>"><?= esc(I18n::t('admin.customers.rma_queue')) ?></a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

// ============================================================
// Content & Marketing
// ============================================================
function pageGdpr(): void {
    Security::adminRequire('gdpr.view');
    $db = Db::get();
    $email = strtolower(trim((string)($_GET['email'] ?? '')));
    $customers = [];
    if ($email !== '') {
        $stmt = $db->prepare("SELECT id, email, name, status, created_at FROM customers WHERE store_id = :store AND LOWER(email) LIKE LOWER(:email) ESCAPE '!' ORDER BY id DESC LIMIT 25");
        $stmt->execute([':store' => StoreContext::current()->id(), ':email' => '%' . Db::likeLiteral($email) . '%']);
        $customers = $stmt->fetchAll();
    }
    $events = $db->prepare("SELECT * FROM gdpr_events WHERE store_id = :store ORDER BY created_at DESC, id DESC LIMIT 50");
    $events->execute([':store' => StoreContext::current()->id()]);
    $events = $events->fetchAll();
    ?>
    <h1><?= esc(I18n::t('admin.title.gdpr')) ?></h1>
    <div class="flash" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa">
        <?= esc(I18n::t('admin.gdpr.legal_disclaimer')) ?>
    </div>
    <div class="card">
        <h3><?= esc(I18n::t('admin.gdpr.search_title')) ?></h3>
        <form method="get" action="admin.php" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
            <input type="hidden" name="p" value="gdpr">
            <input type="email" name="email" value="<?= esc($email) ?>" placeholder="<?= esc(I18n::t('admin.gdpr.email_placeholder')) ?>" style="min-width:280px">
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.search')) ?></button>
        </form>
        <?php if ($email !== ''): ?>
        <table>
            <tr><th>ID</th><th><?= esc(I18n::t('admin.th.email')) ?></th><th><?= esc(I18n::t('admin.th.name')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.actions')) ?></th></tr>
            <?php foreach ($customers as $c): ?>
            <tr>
                <td><?= (int)$c['id'] ?></td>
                <td><?= esc($c['email']) ?></td>
                <td><?= esc($c['name'] ?? '') ?></td>
                <td><span class="badge badge-<?= ($c['status'] ?? '') === 'active' ? 'active' : 'draft' ?>"><?= esc($c['status'] ?? '') ?></span></td>
                <td>
                    <form method="post" action="admin.php?p=gdpr&action=export" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.gdpr.export_hint')) ?></button>
                    </form>
                    <?php if (Security::adminCan('gdpr.erase') && ($c['status'] ?? '') !== 'anonymized'): ?>
                    <form method="post" action="admin.php?p=gdpr&action=anonymize&email=<?= urlencode($email) ?>" style="display:inline" data-confirm="<?= esc(I18n::t('admin.gdpr.anonymize_confirm')) ?>">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                        <button class="btn btn-danger btn-sm" type="submit"><?= esc(I18n::t('admin.gdpr.anonymize_btn')) ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$customers): ?><tr><td colspan="5" class="muted"><?= esc(I18n::t('admin.gdpr.no_results')) ?></td></tr><?php endif; ?>
        </table>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3><?= esc(I18n::t('admin.gdpr.events_title')) ?></h3>
        <table>
            <tr><th>ID</th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.th.customer')) ?></th><th><?= esc(I18n::t('admin.gdpr.actor')) ?></th><th><?= esc(I18n::t('admin.gdpr.note')) ?></th><th><?= esc(I18n::t('admin.th.date')) ?></th></tr>
            <?php foreach ($events as $e): ?>
            <tr>
                <td><?= (int)$e['id'] ?></td>
                <td><?= esc($e['action']) ?></td>
                <td><?= $e['customer_id'] !== null ? (int)$e['customer_id'] : '' ?></td>
                <td><?= esc($e['actor'] ?? '') ?></td>
                <td class="muted" style="max-width:320px;overflow:hidden;text-overflow:ellipsis"><?= esc($e['note'] ?? '') ?></td>
                <td class="muted"><?= esc(substr($e['created_at'], 0, 16)) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php
}
/** GDPR access-request export as an admin POST action (CSRF, gdpr.view, active store) — not a link to the CLI script. */
function handleGdprExport(): void {
    Security::adminRequire('gdpr.view');
    $customerId = Security::int($_POST['customer_id'] ?? 0);
    try {
        $data = GdprService::exportCustomer($customerId);
    } catch (RuntimeException $e) {
        // Unknown id or a customer of another store: nothing is disclosed.
        setFlash('err', I18n::t('admin.gdpr.invalid_customer'));
        header('Location: admin.php?p=gdpr');
        exit;
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    GdprService::logEvent([
        'action'      => 'export',
        'customer_id' => $customerId,
        'email'       => (string)($data['customer']['email'] ?? ''),
        'actor'       => Security::adminUser(),
        'note'        => 'Admin GDPR export of customer #' . $customerId,
        'payload'     => ['orders' => count($data['orders']), 'addresses' => count($data['addresses'])],
    ]);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="gdpr-export-customer-' . $customerId . '.json"');
    header('Cache-Control: no-store, private');
    echo $json;
    exit;
}
function handleCustomerStatus(): void {
    Security::adminRequire('customers.edit');
    $id = Security::int($_POST['customer_id'] ?? 0);
    $status = Security::clean($_POST['status'] ?? '');
    if ($id > 0 && Customers::setStatus($id, $status)) {
        setFlash('ok', I18n::format('admin.customers.status_changed', ['id' => $id, 'status' => $status]));
    } else {
        setFlash('err', I18n::t('admin.customers.status_failed'));
    }
    header('Location: admin.php?p=customer&id=' . $id);
    exit;
}
function handleGdprAnonymize(): void {
    Security::adminRequire('gdpr.erase');
    $customerId = Security::int($_POST['customer_id'] ?? 0);
    if ($customerId <= 0) {
        setFlash('err', I18n::t('admin.gdpr.invalid_customer'));
        header('Location: admin.php?p=gdpr');
        exit;
    }
    try {
        $result = GdprService::anonymizeCustomer($customerId, Security::adminUser());
        GdprService::logEvent([
            'action' => 'admin_anonymize',
            'customer_id' => $customerId,
            'actor' => Security::adminUser(),
            'payload' => $result,
            'note' => 'Admin anonymized customer #' . $customerId,
        ]);
        setFlash('ok', I18n::t('admin.gdpr.anonymized_ok'));
    } catch (Throwable $e) {
        setFlash('err', I18n::t('admin.gdpr.anonymized_failed') . ': ' . $e->getMessage());
    }
    $email = trim((string)($_GET['email'] ?? ''));
    header('Location: admin.php?p=gdpr' . ($email !== '' ? '&email=' . urlencode($email) : ''));
    exit;
}
