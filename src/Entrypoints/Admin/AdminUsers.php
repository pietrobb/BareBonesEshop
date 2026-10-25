<?php
/** Web administration of database admin identities (accounts, passwords, TOTP, sessions, store grants). */

function pageAdminUsers(): void {
    echo '<h1>' . esc(I18n::t('admin.title.admin_users')) . '</h1>';
    if (!AdminIdentity::available()) {
        echo '<div class="card"><p class="muted">' . esc(I18n::t('admin.users.unavailable')) . '</p></div>';
        return;
    }
    // Enrollment material is shown exactly once, then dropped from the session.
    $enrollment = $_SESSION['admin_users_enrollment'] ?? null;
    unset($_SESSION['admin_users_enrollment']);
    if (is_array($enrollment)) {
        echo '<div class="card"><h2>' . esc((string)$enrollment['username']) . ' — TOTP</h2>'
            . '<p class="flash flash-err">' . esc(I18n::t('admin.users.enrollment_once')) . '</p>'
            . '<p><code>' . esc((string)$enrollment['secret']) . '</code></p>'
            . '<p class="muted"><code>' . esc((string)$enrollment['uri']) . '</code></p>'
            . '<p><code>' . esc(implode('  ', array_map('strval', (array)$enrollment['recovery_codes']))) . '</code></p></div>';
    }
    $storeId = StoreContext::current()->id();
    $csrf = Security::csrfToken();
    $roles = array_values(array_filter(AdminIdentity::roles(), [Security::class, 'adminCanAssumeRole']));
    if (AdminIdentity::accountCount() === 0) {
        echo '<div class="card"><p class="muted">' . esc(I18n::t('admin.users.legacy_notice')) . '</p></div>';
    }
    ?>
    <div class="card table-scroll">
        <table>
            <tr><th><?= esc(I18n::t('admin.users.username')) ?></th><th><?= esc(I18n::t('admin.users.role')) ?></th><th><?= esc(I18n::t('admin.users.status')) ?></th>
                <th>MFA</th><th><?= esc(I18n::t('admin.users.last_login')) ?></th><th><?= esc(I18n::t('admin.users.store_permissions')) ?> (<?= esc($storeId) ?>)</th><th></th></tr>
            <?php foreach (AdminIdentity::accounts() as $a): $id = (int)$a['id'];
                $currentPassword = adminUsersIsSelf($a) ? '<input type="password" name="current_password" required autocomplete="current-password" placeholder="' . esc(I18n::t('admin.users.current_password')) . '" aria-label="' . esc(I18n::t('admin.users.current_password')) . '">' : ''; ?>
            <tr>
                <td><?= esc((string)$a['username']) ?></td>
                <td><?= esc((string)$a['role']) ?></td>
                <td><span class="badge badge-<?= $a['status'] === 'active' ? 'active' : 'draft' ?>"><?= esc((string)$a['status']) ?></span></td>
                <td><?= !empty($a['mfa_required']) ? '✓' : '—' ?></td>
                <td class="muted"><?= esc(substr((string)($a['last_login_at'] ?? ''), 0, 16)) ?></td>
                <td>
                    <form method="post" action="admin.php?p=admin_users&amp;action=grant" style="display:flex;gap:.25rem">
                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="account_id" value="<?= $id ?>">
                        <input name="permissions" value="<?= esc(implode(',', adminUsersStoreGrant($id, $storeId))) ?>" aria-label="<?= esc(I18n::t('admin.users.store_permissions')) ?>">
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.users.save')) ?></button>
                    </form>
                </td>
                <td style="display:flex;flex-wrap:wrap;gap:.25rem">
                    <form method="post" action="admin.php?p=admin_users&amp;action=status">
                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="account_id" value="<?= $id ?>">
                        <input type="hidden" name="status" value="<?= $a['status'] === 'active' ? 'disabled' : 'active' ?>">
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t($a['status'] === 'active' ? 'admin.users.disable' : 'admin.users.enable')) ?></button>
                    </form>
                    <form method="post" action="admin.php?p=admin_users&amp;action=mfa" data-confirm="<?= esc(I18n::t('admin.users.mfa_reset')) ?>?">
                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="account_id" value="<?= $id ?>"><?= $currentPassword ?>
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.users.mfa_reset')) ?></button>
                    </form>
                    <form method="post" action="admin.php?p=admin_users&amp;action=revoke_sessions">
                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="account_id" value="<?= $id ?>">
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.users.revoke_sessions')) ?></button>
                    </form>
                    <form method="post" action="admin.php?p=admin_users&amp;action=password" style="display:flex;gap:.25rem">
                        <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>"><input type="hidden" name="account_id" value="<?= $id ?>"><?= $currentPassword ?>
                        <input type="password" name="password" minlength="12" autocomplete="new-password" required aria-label="<?= esc(I18n::t('admin.users.password')) ?>">
                        <button class="btn btn-sm" type="submit"><?= esc(I18n::t('admin.users.reset_password')) ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div class="card">
        <h2><?= esc(I18n::t('admin.users.create')) ?></h2>
        <form method="post" action="admin.php?p=admin_users&amp;action=create" class="sales-toolbar">
            <input type="hidden" name="csrf_token" value="<?= esc($csrf) ?>">
            <div class="form-row"><label for="au-username"><?= esc(I18n::t('admin.users.username')) ?></label><input id="au-username" name="username" required pattern="[a-z0-9][a-z0-9_.\-]{2,63}" autocomplete="off"></div>
            <div class="form-row"><label for="au-role"><?= esc(I18n::t('admin.users.role')) ?></label><select id="au-role" name="role">
                <?php foreach ($roles as $role): ?><option value="<?= esc($role) ?>"<?= $role === 'viewer' ? ' selected' : '' ?>><?= esc($role) ?></option><?php endforeach; ?>
            </select></div>
            <div class="form-row"><label for="au-password"><?= esc(I18n::t('admin.users.password')) ?></label><input id="au-password" type="password" name="password" minlength="12" required autocomplete="new-password"></div>
            <div class="form-row"><label><input type="checkbox" name="mfa" value="1" checked> MFA (TOTP)</label></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.users.create')) ?></button>
        </form>
    </div>
    <?php
}

function adminUsersStoreGrant(int $accountId, string $storeId): array {
    $stmt = Db::get()->prepare('SELECT permissions FROM admin_store_permissions WHERE account_id = :account AND store_id = :store');
    $stmt->execute([':account' => $accountId, ':store' => $storeId]);
    $decoded = json_decode((string)$stmt->fetchColumn(), true);
    return is_array($decoded) ? array_map('strval', $decoded) : [];
}

/**
 * Resolves the target account and enforces owner protection; returns null after setting a flash.
 * $allowSelf: an admin may act on its own account (password, MFA reset, revoking its sessions)
 * even when its store grants were narrowed below its role; grant/role/status changes never pass it.
 */
function adminUsersTarget(bool $allowSelf = false): ?array {
    $target = AdminIdentity::accountById(Security::int($_POST['account_id'] ?? 0));
    if (!$target) {
        setFlash('err', I18n::t('admin.users.failed'));
        return null;
    }
    // A legacy (config / admin-roles.json) session never acts on DB accounts: it would reset an owner's password or MFA without MFA.
    if (empty($_SESSION['admin_identity_id'])) {
        setFlash('err', I18n::t('admin.users.forbidden_owner'));
        return null;
    }
    // Only owners may touch owner accounts (no privilege escalation through a delegated admins.manage).
    if ($target['role'] === 'owner' && Security::adminRole() !== 'owner') {
        setFlash('err', I18n::t('admin.users.forbidden_owner'));
        return null;
    }
    // A delegated admins.manage may only act on accounts it fully outranks: resetting a
    // peer's password or MFA would hand over that peer's extra permissions.
    if (!($allowSelf && adminUsersIsSelf($target)) && !adminUsersCanManage($target)) {
        setFlash('err', I18n::t('admin.users.forbidden_role'));
        return null;
    }
    return $target;
}

/**
 * True when every permission the target holds — its role, in every store it is granted — is
 * also held by the current admin in that store. Owners manage everyone (owner rules aside).
 */
function adminUsersCanManage(array $target): bool {
    if (Security::adminRole() === 'owner') return true;
    $role = (string)$target['role'];
    if (!Security::adminCanAssumeRole($role)) return false;
    if (empty($_SESSION['admin_identity_id'])) return true;
    $actorGrants = is_array($_SESSION['admin_store_permissions'] ?? null) ? $_SESSION['admin_store_permissions'] : [];
    $stmt = Db::get()->prepare('SELECT store_id, permissions FROM admin_store_permissions WHERE account_id = :account');
    $stmt->execute([':account' => (int)$target['id']]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $storeId = (string)$row['store_id'];
        $grants = json_decode((string)$row['permissions'], true);
        foreach (is_array($grants) ? array_map('strval', $grants) : [] as $grant) {
            foreach (Security::adminRolePermissions($role) as $permission) {
                // Effective permission is the narrower of an overlapping role permission and store grant.
                $effective = AdminIdentity::storeAllows([$storeId => [$grant]], $storeId, $permission) ? $permission
                    : (AdminIdentity::storeAllows([$storeId => [$permission]], $storeId, $grant) ? $grant : null);
                if ($effective !== null && !AdminIdentity::storeAllows($actorGrants, $storeId, $effective)) return false;
            }
        }
    }
    return true;
}

function adminUsersIsSelf(array $target): bool {
    return !empty($_SESSION['admin_identity_id']) && (int)$_SESSION['admin_identity_id'] === (int)$target['id'];
}

/** Changing one's own password or TOTP needs the current password: a hijacked session must not take the account over. */
function adminUsersSelfReauthenticated(array $target): bool {
    if (!adminUsersIsSelf($target)) return true;
    if (password_verify((string)($_POST['current_password'] ?? ''), (string)($target['password_hash'] ?? ''))) return true;
    setFlash('err', I18n::t('admin.users.current_password_invalid'));
    return false;
}

/** The legacy session that created the first DB account ends here; the TOTP material is shown on this page only. */
function adminUsersLegacyHandoff(): never {
    $enrollment = $_SESSION['admin_users_enrollment'] ?? null;
    unset($_SESSION['admin_users_enrollment']);
    Security::endLegacyAdminSession();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="' . esc(I18n::locale()) . '"><head><meta charset="utf-8"><title>' . esc(I18n::t('admin.title.admin_users')) . '</title></head><body>'
        . '<h1>' . esc(I18n::t('admin.users.saved')) . '</h1><p>' . esc(I18n::t('admin.users.legacy_handoff')) . '</p>';
    if (is_array($enrollment)) {
        echo '<h2>' . esc((string)$enrollment['username']) . ' — TOTP</h2><p><strong>' . esc(I18n::t('admin.users.enrollment_once')) . '</strong></p>'
            . '<p><code>' . esc((string)$enrollment['secret']) . '</code></p><p><code>' . esc((string)$enrollment['uri']) . '</code></p>'
            . '<p><code>' . esc(implode('  ', array_map('strval', (array)$enrollment['recovery_codes']))) . '</code></p>';
    }
    echo '<p><a href="admin.php">' . esc(I18n::t('admin.login.submit')) . '</a></p></body></html>';
    exit;
}

function adminUsersDone(): never {
    header('Location: admin.php?p=admin_users');
    exit;
}

function adminUsersEnroll(array $target): void {
    $enrollment = AdminIdentity::enrollTotp((int)$target['id']);
    $_SESSION['admin_users_enrollment'] = ['username' => $target['username']] + $enrollment;
}

function handleAdminUserCreate(): void {
    Security::adminRequire('admins.manage');
    $role = Security::clean($_POST['role'] ?? 'viewer');
    $mfa = !empty($_POST['mfa']);
    if (!Security::adminCanAssumeRole($role)) {
        setFlash('err', I18n::t($role === 'owner' ? 'admin.users.forbidden_owner' : 'admin.users.forbidden_role'));
        adminUsersDone();
    }
    if ($mfa && !AdminIdentity::mfaKeyConfigured()) {
        setFlash('err', I18n::t('admin.users.mfa_key_missing'));
        adminUsersDone();
    }
    try {
        $id = AdminIdentity::create((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''), $role, $mfa);
        AdminIdentity::grantStore($id, StoreContext::current()->id(), ['*']);
        if ($mfa) adminUsersEnroll(AdminIdentity::accountById($id) ?? []);
        setFlash('ok', I18n::t('admin.users.saved'));
    } catch (InvalidArgumentException | PDOException $e) {
        setFlash('err', I18n::t('admin.users.failed') . ($e instanceof InvalidArgumentException ? ': ' . $e->getMessage() : ''));
    }
    if (empty($_SESSION['admin_identity_id']) && AdminIdentity::accountCount() > 0) adminUsersLegacyHandoff();
    adminUsersDone();
}

function handleAdminUserStatus(): void {
    Security::adminRequire('admins.manage');
    $target = adminUsersTarget();
    $status = Security::clean($_POST['status'] ?? '');
    if ($target && $status !== 'active') {
        $activeOwners = array_filter(AdminIdentity::accounts(), static fn(array $a): bool => $a['role'] === 'owner' && $a['status'] === 'active');
        if (adminUsersIsSelf($target) || ($target['role'] === 'owner' && count($activeOwners) <= 1)) {
            setFlash('err', I18n::t('admin.users.self_or_last_owner'));
            $target = null;
        }
    }
    if ($target) {
        try {
            AdminIdentity::setStatus((int)$target['id'], $status);
            setFlash('ok', I18n::t('admin.users.saved'));
        } catch (InvalidArgumentException $e) {
            setFlash('err', I18n::t('admin.users.failed'));
        }
    }
    adminUsersDone();
}

function handleAdminUserPassword(): void {
    Security::adminRequire('admins.manage');
    $target = adminUsersTarget(true);
    if ($target && adminUsersSelfReauthenticated($target)) {
        try {
            AdminIdentity::setPassword((int)$target['id'], (string)($_POST['password'] ?? ''));
            setFlash('ok', I18n::t('admin.users.saved'));
        } catch (InvalidArgumentException $e) {
            setFlash('err', I18n::t('admin.users.failed') . ': ' . $e->getMessage());
        }
    }
    adminUsersDone();
}

function handleAdminUserMfa(): void {
    Security::adminRequire('admins.manage');
    $target = adminUsersTarget(true);
    if ($target && !adminUsersSelfReauthenticated($target)) $target = null;
    if ($target && !AdminIdentity::mfaKeyConfigured()) {
        setFlash('err', I18n::t('admin.users.mfa_key_missing'));
        $target = null;
    }
    if ($target) {
        adminUsersEnroll($target);
        // A reset seed must not leave someone else's existing sessions alive.
        if (!adminUsersIsSelf($target)) AdminIdentity::revokeSessions((int)$target['id']);
        setFlash('ok', I18n::t('admin.users.saved'));
    }
    adminUsersDone();
}

function handleAdminUserRevokeSessions(): void {
    Security::adminRequire('admins.manage');
    $target = adminUsersTarget(true);
    if ($target) {
        AdminIdentity::revokeSessions((int)$target['id']);
        setFlash('ok', I18n::t('admin.users.saved'));
    }
    adminUsersDone();
}

function handleAdminUserGrant(): void {
    Security::adminRequire('admins.manage');
    $target = adminUsersTarget();
    // Store grants narrow the role: a delegated admin widening its own would undo the owner's restriction.
    if ($target && adminUsersIsSelf($target) && Security::adminRole() !== 'owner') {
        setFlash('err', I18n::t('admin.users.forbidden_role'));
        $target = null;
    }
    if ($target) {
        $permissions = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['permissions'] ?? '')))));
        try {
            // Grants are edited for the active store only: an admin cannot hand out access to stores outside its own context.
            AdminIdentity::grantStore((int)$target['id'], StoreContext::current()->id(), $permissions);
            setFlash('ok', I18n::t('admin.users.saved'));
        } catch (InvalidArgumentException $e) {
            setFlash('err', I18n::t('admin.users.failed') . ': ' . $e->getMessage());
        }
    }
    adminUsersDone();
}
