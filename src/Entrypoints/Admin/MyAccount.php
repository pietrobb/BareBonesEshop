<?php
/**
 * R14 UX4: every signed-in admin account changes its own password and TOTP here, without admins.manage. Both changes
 * need the current password (AdminIdentity::reauthenticate, throttled per session); other accounts are never touched.
 */

function pageMyAccount(): void {
    echo '<h1>' . esc(I18n::t('admin.title.my_account')) . '</h1>';
    $account = myAccountCurrent();
    if ($account === null) {
        echo '<div class="card"><p class="muted">' . esc(I18n::t('admin.my_account.legacy')) . '</p></div>';
        return;
    }
    // New TOTP material is shown exactly once, then dropped from the session.
    $enrollment = $_SESSION['admin_my_account_enrollment'] ?? null;
    unset($_SESSION['admin_my_account_enrollment']);
    if (is_array($enrollment)) {
        echo '<div class="card" data-my-account-enrollment><h2>TOTP</h2><p class="flash flash-err">' . esc(I18n::t('admin.users.enrollment_once')) . '</p>'
            . '<p><code>' . esc((string)$enrollment['secret']) . '</code></p><p class="muted"><code>' . esc((string)$enrollment['uri']) . '</code></p>'
            . '<p><code>' . esc(implode('  ', array_map('strval', (array)$enrollment['recovery_codes']))) . '</code></p></div>';
    }
    $csrf = esc(Security::csrfToken());
    $current = '<div class="form-row"><label>' . esc(I18n::t('admin.users.current_password'))
        . '<input type="password" name="current_password" required autocomplete="current-password"></label></div>';
    ?>
    <div class="card"><p><?= esc(I18n::format('admin.my_account.signed_in_as', ['user' => (string)$account['username'], 'role' => (string)$account['role']])) ?></p></div>
    <div class="card">
        <h2><?= esc(I18n::t('admin.my_account.password_heading')) ?></h2>
        <p class="muted"><?= esc(I18n::t('admin.my_account.password_help')) ?></p>
        <form method="post" action="admin.php?p=my_account&amp;action=password">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><?= $current ?>
            <div class="form-row"><label><?= esc(I18n::t('admin.my_account.new_password')) ?><input type="password" name="password" minlength="12" required autocomplete="new-password"></label></div>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.my_account.password_submit')) ?></button>
        </form>
    </div>
    <div class="card">
        <h2><?= esc(I18n::t('admin.my_account.mfa_heading')) ?></h2>
        <p class="muted"><?= esc(I18n::t('admin.my_account.mfa_help')) ?></p>
        <form method="post" action="admin.php?p=my_account&amp;action=mfa" data-confirm="<?= esc(I18n::t('admin.my_account.mfa_submit')) ?>?">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><?= $current ?>
            <button class="btn" type="submit"><?= esc(I18n::t('admin.my_account.mfa_submit')) ?></button>
        </form>
    </div>
    <?php
}

/** The signed-in database account, or null for a config / admin-roles.json login (its password lives in the configuration). */
function myAccountCurrent(): ?array {
    $id = (int)($_SESSION['admin_identity_id'] ?? 0);
    return $id > 0 && AdminIdentity::available() ? AdminIdentity::accountById($id) : null;
}

/** The current account after its current password was confirmed; null (with a flash) otherwise. */
function myAccountReauthenticated(): ?array {
    $account = myAccountCurrent();
    if ($account === null) {
        setFlash('err', I18n::t('admin.my_account.legacy'));
        return null;
    }
    $result = AdminIdentity::reauthenticate((int)$account['id'], (string)($_POST['current_password'] ?? ''), (string)($_SESSION['admin_session_id'] ?? ''));
    if ($result === 'ok') return $account;
    setFlash('err', I18n::t($result === 'locked' ? 'admin.users.reauth_locked' : 'admin.users.current_password_invalid'));
    return null;
}

function myAccountDone(): never {
    header('Location: admin.php?p=my_account');
    exit;
}

/** A new password ends every session of the account, this one too: the admin signs in again with it. */
function handleMyAccountPassword(): void {
    $account = myAccountReauthenticated();
    if ($account !== null) {
        try {
            AdminIdentity::setPassword((int)$account['id'], (string)($_POST['password'] ?? ''));
            setFlash('ok', I18n::t('admin.my_account.password_changed'));
        } catch (InvalidArgumentException $e) {
            setFlash('err', I18n::t('admin.users.failed') . ': ' . $e->getMessage());
        }
    }
    myAccountDone();
}

/** A new TOTP secret and recovery codes replace the old ones; the material is shown once on the next page. */
function handleMyAccountMfa(): void {
    $account = myAccountReauthenticated();
    if ($account !== null && !AdminIdentity::mfaKeyConfigured()) {
        setFlash('err', I18n::t('admin.users.mfa_key_missing'));
        $account = null;
    }
    if ($account !== null) {
        $_SESSION['admin_my_account_enrollment'] = AdminIdentity::enrollTotp((int)$account['id']);
        setFlash('ok', I18n::t('admin.my_account.mfa_changed'));
    }
    myAccountDone();
}
