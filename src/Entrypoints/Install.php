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

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$page = static function (string $title, string $body): void { echo WebInstaller::page($title, $body); };

// Serialises two submits racing for the same fresh upload; config.php is checked again under the lock.
$installLock = fopen(__FILE__, 'r');
if (WebInstaller::installed($installRoot)) {
    http_response_code(404);
    $page('Already installed', '<p>This shop is installed. <a href="./">Open the shop</a> or <a href="admin.php">sign in to the administration</a>.</p>');
    return;
}

// Only someone who can read the shop's files may install it: whoever opened the fresh upload first used to become owner.
if (WebInstaller::installKey($installRoot) === null) {
    http_response_code(500);
    $page('Cannot install yet', '<p>PHP cannot write to the shop folder (' . $h(WebInstaller::KEY_FILE) . ' could not be created). '
        . 'Give the web server user write access to the folder you uploaded the shop to and reload this page.</p>');
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
        $errors['form'] = 'The form expired. Check the values and send it again.';
        $values = array_merge($values, array_diff_key($_POST, ['admin_password' => 1, 'admin_password_repeat' => 1, 'token' => 1, 'install_key' => 1]));
    } elseif (!WebInstaller::keyMatches($installRoot, (string)($_POST['install_key'] ?? ''))) {
        $errors['install_key'] = 'This is not the key in ' . WebInstaller::KEY_FILE . '.';
        $values = array_merge($values, array_diff_key($_POST, ['admin_password' => 1, 'admin_password_repeat' => 1, 'token' => 1, 'install_key' => 1]));
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
    $page('Your shop is ready', WebInstaller::readyHtml($result, $installRoot));
    return;
}

$field = static function (string $name, string $label, string $type = 'text', string $hint = '', string $autocomplete = 'off') use ($h, &$values, &$errors): string {
    $value = in_array($type, ['password'], true) ? '' : (string)($values[$name] ?? '');
    $id = 'f-' . $name;
    $error = isset($errors[$name]) ? '<p class="error" id="' . $id . '-error">' . $h($errors[$name]) . '</p>' : '';
    return '<label for="' . $id . '">' . $h($label) . '</label>' . ($hint !== '' ? '<p class="hint" id="' . $id . '-hint">' . $h($hint) . '</p>' : '')
        . '<input type="' . $type . '" id="' . $id . '" name="' . $name . '" value="' . $h($value) . '" autocomplete="' . $autocomplete . '"'
        . ($hint !== '' || $error !== '' ? ' aria-describedby="' . trim(($hint !== '' ? $id . '-hint ' : '') . ($error !== '' ? $id . '-error' : '')) . '"' : '')
        . ($error !== '' ? ' aria-invalid="true"' : '') . '>' . $error;
};
$checkbox = static fn(string $name, string $label): string => '<label class="check"><input type="checkbox" name="' . $name . '" value="1"' . (!empty($values[$name]) ? ' checked' : '') . '> ' . $h($label) . '</label>';
$groupError = static fn(string $key): string => isset($errors[$key]) ? '<p class="error">' . $h($errors[$key]) . '</p>' : '';

$page('Install ' . ($values['shop_name'] !== '' ? $values['shop_name'] : 'your shop'), $groupError('protection') . $groupError('form')
    . '<p>Fill in this one form and the shop is ready.</p>'
    . '<form method="post" action="install.php" novalidate><input type="hidden" name="token" value="' . $h($_SESSION['install_token']) . '">'
    . ($exposed === null ? '<p>' . $checkbox('protection_checked', 'I opened ' . WebInstaller::PROBE_FILE . ' in the browser and it answered "Forbidden" or "Not Found".') . '</p>' : '')
    . '<fieldset><legend>Installation key</legend>' . $field('install_key', 'Installation key', 'text',
        'Proves that you own this server: open the file ' . WebInstaller::KEY_FILE . ' in the shop folder with your FTP client or the hosting file manager and copy the key from it (XXXXX-XXXXX-XXXXX-XXXXX).') . '</fieldset>'
    . '<fieldset><legend>Shop</legend>' . $field('shop_name', 'Shop name') . $field('app_url', 'Shop address', 'url', 'Used in e-mails and payment links.')
    . $field('timezone', 'Time zone', 'text', 'For invoice dates and deadlines, e.g. Europe/Bratislava.')
    . $field('private_parent', 'Private data folder', 'text', 'The shop creates bareboneseshop-private/ here for the database, eBook files, invoices and logs. It must not be reachable from the web.') . '</fieldset>'
    . '<fieldset><legend>Seller (shown on invoices and the imprint)</legend>' . $field('company', 'Company or your name', 'text', '', 'organization') . $field('street', 'Street', 'text', '', 'street-address')
    . $field('city', 'City', 'text', '', 'address-level2') . $field('zip', 'Postcode', 'text', '', 'postal-code') . $field('country', 'Country code', 'text', 'Two letters, e.g. SK, CZ, GB.', 'country')
    . $field('email', 'E-mail', 'email', 'Shown to buyers; new orders are reported here.', 'email') . $field('vat_id', 'VAT ID (optional)', 'text', 'Leave empty if you are not VAT registered.')
    . $field('ico', 'Company number (IČO, optional)', 'text', 'Printed on invoices, e.g. 12345678.') . $field('dic', 'Tax number (DIČ, optional)', 'text', 'Printed on invoices, e.g. 2020123456.') . '</fieldset>'
    . '<fieldset><legend>Payments</legend>' . $groupError('payments') . $checkbox('pay_cod', 'Cash on delivery') . $checkbox('pay_bank', 'Bank transfer')
    . $field('iban', 'IBAN for bank transfers', 'text', 'Buyers get it with a QR payment code.')
    . $field('bic', 'BIC / SWIFT (optional)', 'text', 'Shown with the IBAN for payments from abroad, e.g. TATRSKBX.')
    . '<p>Card payments with Stripe (optional, test mode): enter all three values or leave them empty.</p>' . $groupError('stripe')
    . $field('stripe_secret_key', 'Stripe secret key (sk_test_…)') . $field('stripe_publishable_key', 'Stripe publishable key (pk_test_…)') . $field('stripe_webhook_secret', 'Stripe webhook signing secret (whsec_…)') . '</fieldset>'
    . '<fieldset><legend>Administrator</legend>' . $field('admin_user', 'User name', 'text', '', 'username') . $field('admin_password', 'Password', 'password', 'At least 12 characters.', 'new-password')
    . $field('admin_password_repeat', 'Password again', 'password', '', 'new-password') . '</fieldset>'
    . '<button type="submit">Install the shop</button></form>');
