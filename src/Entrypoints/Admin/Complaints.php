<?php
/** R8: admin complaints (reklamácie) — separate from returns; the decision is written and e-mailed. See Complaints. */

function pageComplaints(): void {
    $filter = Security::clean($_GET['status'] ?? '');
    $filter = in_array($filter, Complaints::STATUSES, true) ? $filter : null;
    $rows = Complaints::all($filter);
    $next = Complaints::NEXT;
    ?>
    <h1><?= esc(I18n::t('admin.complaints.heading')) ?> <span class="muted">(<?= count($rows) ?>)</span></h1>
    <div class="card">
        <p class="muted"><?= esc(I18n::format('admin.complaints.hint', ['days' => (string)Complaints::DECISION_DAYS, 'months' => (string)ConsumerNotice::LIABILITY_MONTHS])) ?></p>
        <p>
            <a class="btn btn-sm<?= $filter === null ? ' btn-primary' : '' ?>" href="admin.php?p=complaints"><?= esc(I18n::t('admin.common.all')) ?></a>
            <?php foreach (Complaints::STATUSES as $s): ?>
            <a class="btn btn-sm<?= $filter === $s ? ' btn-primary' : '' ?>" href="admin.php?p=complaints&status=<?= esc($s) ?>"><?= esc(I18n::t('complaint.status.' . $s)) ?></a>
            <?php endforeach; ?>
        </p>
        <?php if (!$rows): ?>
        <p class="muted"><?= esc(I18n::t('admin.complaints.none')) ?></p>
        <?php else: ?>
        <table>
            <tr><th><?= esc(I18n::t('admin.complaints.number')) ?></th><th><?= esc(I18n::t('admin.order.label')) ?></th><th><?= esc(I18n::t('admin.th.customer')) ?></th><th><?= esc(I18n::t('complaint.item')) ?></th><th><?= esc(I18n::t('complaint.defect')) ?></th><th><?= esc(I18n::t('complaint.remedy')) ?></th><th><?= esc(I18n::t('admin.th.status')) ?></th><th><?= esc(I18n::t('admin.common.action')) ?></th></tr>
            <?php foreach ($rows as $c): ?>
            <tr>
                <td><?= esc($c['complaint_number']) ?><br><span class="muted"><?= esc(substr((string)$c['received_at'], 0, 16)) ?></span></td>
                <td><a href="admin.php?p=order&id=<?= (int)$c['order_id'] ?>"><?= esc($c['order_number']) ?></a></td>
                <td><?= esc($c['customer_name']) ?><br><span class="muted"><?= esc($c['customer_email']) ?></span></td>
                <td><?= esc($c['item_name']) ?></td>
                <td><?= nl2br(esc((string)$c['defect'])) ?></td>
                <td><?= esc(I18n::t('complaint.remedy.' . $c['remedy'])) ?></td>
                <td><span class="badge badge-<?= esc($c['status']) ?>"><?= esc(I18n::t('complaint.status.' . $c['status'])) ?></span>
                    <?php if (isset($next[$c['status']])): ?><br><span class="muted"><?= esc(I18n::format('admin.complaints.decide_by', ['date' => Complaints::decideBy($c)])) ?></span><?php endif; ?>
                    <?php if ((string)($c['decision_note'] ?? '') !== ''): ?><br><span class="muted"><?= esc((string)$c['decision_note']) ?></span><?php endif; ?></td>
                <td>
                    <?php if (isset($next[$c['status']])): ?>
                    <form method="post" action="admin.php?p=complaints&action=status">
                        <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
                        <input type="hidden" name="complaint_id" value="<?= (int)$c['id'] ?>">
                        <select name="status" aria-label="<?= esc(I18n::t('admin.th.status')) ?>">
                            <?php foreach ($next[$c['status']] as $to): ?><option value="<?= esc($to) ?>"><?= esc(I18n::t('complaint.status.' . $to)) ?></option><?php endforeach; ?>
                        </select>
                        <textarea name="note" rows="2" maxlength="4000" aria-label="<?= esc(I18n::t('admin.complaints.note')) ?>" placeholder="<?= esc(I18n::t('admin.complaints.note')) ?>"></textarea>
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.complaints.save')) ?></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

function handleComplaintStatus(): void {
    Security::adminRequire('rma.manage');
    $error = Complaints::setStatus(Security::int($_POST['complaint_id'] ?? 0), Security::clean($_POST['status'] ?? ''), Security::str($_POST['note'] ?? ''));
    if ($error === null) setFlash('ok', I18n::t('admin.complaints.saved'));
    else setFlash('err', I18n::t('admin.complaints.error.' . $error));
    header('Location: admin.php?p=complaints');
    exit;
}
