<?php
/**
 * Admin sign-in page, rendered by Security::renderAdminLogin() (templates/ is web-denied).
 * Expects $view: locale, nonce (CSP style nonce), csrf, return_to, error (translated message or null).
 * Field names (admin_login, csrf_token, return_to, admin_username, admin_password, admin_mfa) are the login contract.
 */
if (!isset($view) || !is_array($view)) {
    http_response_code(404);
    exit;
}
$t = static fn(string $key): string => esc(I18n::t($key));
?><!doctype html><html lang="<?= esc((string)$view['locale']) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $t('admin.login.title') ?></title><style nonce="<?= esc((string)$view['nonce']) ?>">body{font:16px system-ui;background:#f1f5f9;margin:0;display:grid;place-items:center;min-height:100vh}form{background:#fff;padding:2rem;border-radius:.75rem;box-shadow:0 8px 30px #0002;width:min(24rem,calc(100% - 2rem))}label{display:block;margin-top:1rem;font-weight:600}input{box-sizing:border-box;width:100%;padding:.7rem;margin-top:.3rem}button{width:100%;margin-top:1.25rem;padding:.8rem;background:#0f172a;color:#fff;border:0;border-radius:.35rem}.error{color:#b91c1c}</style></head><body><form method="post" action="admin.php" autocomplete="on"><h1><?= $t('admin.login.title') ?></h1><?php if ($view['error'] !== null): ?><p class="error"><?= esc((string)$view['error']) ?></p><?php endif; ?><input type="hidden" name="admin_login" value="1"><input type="hidden" name="csrf_token" value="<?= esc((string)$view['csrf']) ?>"><input type="hidden" name="return_to" value="<?= esc((string)$view['return_to']) ?>"><label><?= $t('admin.login.username') ?><input name="admin_username" required autocomplete="username"></label><label><?= $t('admin.login.password') ?><input type="password" name="admin_password" required autocomplete="current-password"></label><label><?= $t('admin.login.mfa') ?><input name="admin_mfa" inputmode="numeric" autocomplete="one-time-code"></label><button type="submit"><?= $t('admin.login.submit') ?></button></form></body></html>
