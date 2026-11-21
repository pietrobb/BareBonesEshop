<?php
declare(strict_types=1);

/**
 * install.php: the first-visit web installer (WebInstaller). Answers 404 once config.php exists, so it can stay on the
 * server. One form; the result page shows the owner's TOTP QR code and recovery codes exactly once.
 */
$installRoot = dirname(__DIR__, 2);
$installHttps = strtolower((string)($_SERVER['HTTPS'] ?? '')) === 'on';
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('Vary: Accept-Language');

// R38 UX: the installer speaks the language picked from its links, else the browser's (was: English only).
I18n::setLocale(WebInstaller::locale(is_string($_GET['lang'] ?? null) ? $_GET['lang'] : null, (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));
$t = static fn(string $key, array $vars = []): string => I18n::format($key, $vars);
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (string $title, string $body): void { echo WebInstaller::page($title, WebInstaller::languageLinks() . $body); };

// Serialises two submits racing for the same fresh upload; config.php is checked again under the lock.
$installLock = fopen(__FILE__, 'r');
if (WebInstaller::installed($installRoot)) {
    http_response_code(404);
    $page($t('install.title.installed'), '<p>' . $h($t('install.installed')) . '</p>' . WebInstaller::homeLinks());
    return;
}

// Only someone who can read the shop's files may install it: whoever opened the fresh upload first used to become owner.
if (WebInstaller::installKey($installRoot) === null) {
    http_response_code(500);
    $page($t('install.title.cannot'), '<p>' . $h($t('install.cannot_write', ['file' => WebInstaller::KEY_FILE])) . '</p>');
    return;
}
session_name('bbe_install');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $installHttps]);
session_start();
$_SESSION['install_token'] ??= bin2hex(random_bytes(32));
$values = WebInstaller::defaults($installRoot, $_SERVER);
$errors = [];
$result = null;
$exposed = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string)$_SESSION['install_token'], (string)($_POST['token'] ?? ''))) {
        $errors['form'] = $t('install.err.expired');
        $values = array_merge($values, array_diff_key($_POST, ['admin_password' => 1, 'admin_password_repeat' => 1, 'smtp_pass' => 1, 'token' => 1, 'install_key' => 1]));
    } elseif (!WebInstaller::keyMatches($installRoot, (string)($_POST['install_key'] ?? ''))) {
        $errors['install_key'] = $t('install.err.key', ['file' => WebInstaller::KEY_FILE]);
        $values = array_merge($values, array_diff_key($_POST, ['admin_password' => 1, 'admin_password_repeat' => 1, 'smtp_pass' => 1, 'token' => 1, 'install_key' => 1]));
    } else {
        [$values, $validationErrors] = WebInstaller::validate($_POST, $installRoot);
        $errors += $validationErrors;
        $values['install_key'] = (string)($_POST['install_key'] ?? '');
        // The self-check connects to this server, so only the key holder can start it (nobody else probes its ports).
        // php -S with router.php is the development server that applies the .htaccess rules itself (one request at a
        // time, so it cannot answer its own probe). R14: php -S without that router serves every file as it is.
        if ($errors === []) $exposed = WebInstaller::devRouterActive($installRoot, PHP_SAPI, get_included_files()) ? [] : WebInstaller::exposedPaths($installRoot, $_SERVER);
        $protectionError = WebInstaller::protectionError($exposed, !empty($_POST['protection_checked']));
        if ($protectionError !== null) $errors['protection'] = $protectionError;
        if ($errors === []) {
            [$result, $installError] = WebInstaller::installLocked($values, $installRoot, $installLock);
            if ($installError !== null) $errors['form'] = $installError;
        }
    }
}

if ($result !== null) {
    session_destroy();
    echo WebInstaller::page($t('install.title.ready'), WebInstaller::readyHtml($result, $installRoot));
    return;
}

$field = static function (string $name, string $type = 'text', bool $hint = false, string $autocomplete = 'off', array $vars = []) use ($h, $t, &$values, &$errors): string {
    $label = $t('install.label.' . $name);
    $hintText = $hint ? $t('install.hint.' . $name, $vars) : '';
    $value = in_array($type, ['password'], true) ? '' : (string)($values[$name] ?? '');
    $id = 'f-' . $name;
    $error = isset($errors[$name]) ? '<p class="error" id="' . $id . '-error">' . $h($errors[$name]) . '</p>' : '';
    return '<label for="' . $id . '">' . $h($label) . '</label>' . ($hintText !== '' ? '<p class="hint" id="' . $id . '-hint">' . $h($hintText) . '</p>' : '')
        . '<input type="' . $type . '" id="' . $id . '" name="' . $name . '" value="' . $h($value) . '" autocomplete="' . $autocomplete . '"'
        . ($hintText !== '' || $error !== '' ? ' aria-describedby="' . trim(($hintText !== '' ? $id . '-hint ' : '') . ($error !== '' ? $id . '-error' : '')) . '"' : '')
        . ($error !== '' ? ' aria-invalid="true"' : '') . '>' . $error;
};
$select = static fn(string $name, array $options): string => WebInstaller::selectField($name, $options, (string)($values[$name] ?? ''), $errors[$name] ?? null);
$checkbox = static fn(string $name, string $label): string => '<label class="check"><input type="checkbox" name="' . $name . '" value="1"' . (!empty($values[$name]) ? ' checked' : '') . '> ' . $h($label) . '</label>';
$groupError = static fn(string $key): string => isset($errors[$key]) ? '<p class="error">' . $h($errors[$key]) . '</p>' : '';
$legend = static fn(string $key): string => '<fieldset><legend>' . $h($t('install.legend.' . $key)) . '</legend>';

$page($values['shop_name'] !== '' ? $t('install.title.install', ['shop' => $values['shop_name']]) : $t('install.title.install_default'), $groupError('protection') . $groupError('form')
    . '<p>' . $h($t('install.intro')) . '</p>'
    . '<form method="post" action="install.php?lang=' . $h(I18n::locale()) . '" novalidate><input type="hidden" name="token" value="' . $h($_SESSION['install_token']) . '">'
    . ($exposed === null ? '<p>' . $checkbox('protection_checked', $t('install.protection_checked', ['file' => WebInstaller::PROBE_FILE])) . '</p>' : '')
    . $legend('key') . $field('install_key', 'text', true, 'off', ['file' => WebInstaller::KEY_FILE]) . '</fieldset>'
    . $legend('shop') . $field('shop_name') . $field('app_url', 'url', true) . $field('timezone', 'text', true) . $select('shop_locale', WebInstaller::LOCALES) . $select('currency', WebInstaller::CURRENCIES) . $field('private_parent', 'text', true) . '</fieldset>'
    . $legend('seller') . $field('company', 'text', false, 'organization') . $field('street', 'text', false, 'street-address')
    . $field('city', 'text', false, 'address-level2') . $field('zip', 'text', false, 'postal-code') . $field('country', 'text', true, 'country')
    . $field('email', 'email', true, 'email') . $field('vat_id', 'text', true) . $field('ico', 'text', true) . $field('dic', 'text', true) . '</fieldset>'
    . $legend('payments') . $groupError('payments') . $checkbox('pay_cod', $t('install.label.pay_cod')) . $checkbox('pay_bank', $t('install.label.pay_bank'))
    . $field('iban', 'text', true) . $field('bic', 'text', true)
    . '<p>' . $h($t('install.stripe_intro')) . '</p>' . $groupError('stripe')
    . $field('stripe_secret_key') . $field('stripe_publishable_key') . $field('stripe_webhook_secret') . '</fieldset>'
    . $legend('mail') . '<p>' . $h($t('install.mail_intro')) . '</p>'
    . $field('smtp_host', 'text', true) . $field('smtp_port', 'text', true) . $field('smtp_encryption', 'text', true)
    . $field('smtp_user') . $field('smtp_pass', 'password', true, 'new-password') . $field('mail_from', 'email', true, 'email') . '</fieldset>'
    . $legend('admin') . $field('admin_user', 'text', false, 'username') . $field('admin_password', 'password', true, 'new-password')
    . $field('admin_password_repeat', 'password', false, 'new-password') . '</fieldset>'
    . '<button type="submit">' . $h($t('install.submit')) . '</button></form>');
