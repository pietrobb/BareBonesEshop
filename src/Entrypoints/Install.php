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
$page = static function (string $title, string $body) use ($h): void {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . $h($title) . '</title><style>'
        . 'body{font:16px/1.5 system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;color:#1f1d1a;background:#faf7f2}'
        . 'h1{font-family:Georgia,serif}fieldset{border:1px solid #cfc6b8;border-radius:6px;margin:0 0 1.2rem;padding:.8rem 1rem}'
        . 'label{display:block;margin:.5rem 0 .1rem;font-weight:600}input[type=text],input[type=url],input[type=email],input[type=password]{width:100%;box-sizing:border-box;padding:.45rem;border:1px solid #8a8074;border-radius:4px;font:inherit}'
        . '.check{font-weight:400}.hint{color:#5c554c;font-size:.9rem;margin:.1rem 0}.error{color:#a3150b;font-weight:600}'
        . 'button{background:#7a2e1f;color:#fff;border:0;border-radius:4px;padding:.7rem 1.4rem;font:inherit;cursor:pointer}'
        . 'table{border-collapse:collapse}td{padding:.2rem .6rem;border-bottom:1px solid #e3dccf;vertical-align:top}code{word-break:break-all}'
        . '</style></head><body><main><h1>' . $h($title) . '</h1>' . $body . '</main></body></html>';
};

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
// php -S is the development server: router.php applies the .htaccess rules itself (and serves one request at a time).
$exposed = PHP_SAPI === 'cli-server' ? [] : WebInstaller::exposedPaths($installRoot, $_SERVER);

session_name('bbe_install');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $installHttps]);
session_start();
$_SESSION['install_token'] ??= bin2hex(random_bytes(32));
$values = WebInstaller::defaults($installRoot, $_SERVER);
$errors = [];
$result = null;
if ($exposed) {
    $errors['protection'] = 'The web server serves files that must stay private (' . implode(', ', $exposed) . '), so the .htaccess rules are not active. '
        . 'Turn on mod_rewrite and AllowOverride All for this folder (ask your hosting), or use deploy/nginx.conf on nginx, then reload this page.';
}
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
        if ($errors === []) {
            if (is_resource($installLock)) flock($installLock, LOCK_EX);
            try {
                if (is_file($installRoot . '/config.php')) throw new RuntimeException('The shop has just been installed by another request.');
                $result = WebInstaller::install($values, $installRoot);
                @unlink($installRoot . '/' . WebInstaller::KEY_FILE);
            } catch (Throwable $e) {
                // The details (paths, driver messages) go to the PHP error log, not to the page.
                $reference = bin2hex(random_bytes(4));
                error_log("BareBonesEshop install.php failure {$reference}: " . $e);
                $errors['form'] = "Installation failed (reference {$reference}). Nothing was installed; the details are in the PHP error log of your hosting.";
            }
        }
    }
}

if ($result !== null) {
    session_destroy();
    $admin = $result['admin'];
    $doctor = $result['doctor'];
    $rows = '';
    foreach ($doctor['checks'] as $check) {
        if ($check['status'] === 'pass') continue;
        $rows .= '<tr><td>' . $h(strtoupper($check['status'])) . '</td><td>' . $h($check['message']) . ($check['action'] !== '' ? '<p class="hint">' . $h($check['action']) . '</p>' : '') . '</td></tr>';
    }
    $page('Your shop is ready', '<p>' . ($result['starter'] !== null ? $h($result['starter']) . ' is installed with its demo catalog.' : 'The shop is installed.') . '</p>'
        . '<h2>Sign-in for the administration</h2><p>User name: <strong>' . $h($admin['username']) . '</strong>. Signing in needs your password and a code from an authenticator app (Google Authenticator, Aegis, 1Password …).</p>'
        . '<p>Scan this code with the app now. It is shown only once.</p>' . QrCode::svg((string)($admin['uri'] ?? ''), 'TOTP for ' . (string)$admin['username'])
        . '<p class="hint">Or enter the key by hand: <code>' . $h($admin['secret'] ?? '') . '</code></p>'
        . '<p><strong>Recovery codes</strong> (each works once if you lose the phone; store them safely, they are not shown again):</p><p><code>' . $h(implode('  ', (array)($admin['recovery_codes'] ?? []))) . '</code></p>'
        . '<h2>Health check</h2><p>' . $h($doctor['counts']['pass']) . ' checks passed, ' . $h($doctor['counts']['warn']) . ' warnings, ' . $h($doctor['counts']['fail']) . ' failures.</p>'
        . ($rows !== '' ? '<table>' . $rows . '</table>' : '')
        . ($result['leftover'] !== null ? '<p class="error">Delete the folder ' . $h(substr(str_replace('\\', '/', $result['leftover']), strlen(str_replace('\\', '/', $installRoot)) + 1))
            . ' with your FTP client: it still holds the starter database and the eBook files, which now live in the private data folder.</p>' : '')
        . '<p><a href="./">Open the shop</a> · <a href="admin.php">Sign in to the administration</a></p>');
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
    . ($exposed === null ? '<p class="hint">The installer could not check from here whether the web server keeps the application folders private. '
        . 'After installing, make sure that opening /src/autoload.php in the browser answers "Forbidden".</p>' : '')
    . '<form method="post" action="install.php" novalidate><input type="hidden" name="token" value="' . $h($_SESSION['install_token']) . '">'
    . '<fieldset><legend>Installation key</legend>' . $field('install_key', 'Installation key', 'text',
        'Proves that you own this server: open the file ' . WebInstaller::KEY_FILE . ' in the shop folder with your FTP client or the hosting file manager and copy the key from it (XXXXX-XXXXX-XXXXX-XXXXX).') . '</fieldset>'
    . '<fieldset><legend>Shop</legend>' . $field('shop_name', 'Shop name') . $field('app_url', 'Shop address', 'url', 'Used in e-mails and payment links.')
    . $field('timezone', 'Time zone', 'text', 'For invoice dates and deadlines, e.g. Europe/Bratislava.')
    . $field('private_parent', 'Private data folder', 'text', 'The shop creates bareboneseshop-private/ here for the database, eBook files, invoices and logs. It must not be reachable from the web.') . '</fieldset>'
    . '<fieldset><legend>Seller (shown on invoices and the imprint)</legend>' . $field('company', 'Company or your name', 'text', '', 'organization') . $field('street', 'Street', 'text', '', 'street-address')
    . $field('city', 'City', 'text', '', 'address-level2') . $field('zip', 'Postcode', 'text', '', 'postal-code') . $field('country', 'Country code', 'text', 'Two letters, e.g. SK, CZ, GB.', 'country')
    . $field('email', 'E-mail', 'email', 'Shown to buyers; new orders are reported here.', 'email') . $field('vat_id', 'VAT ID (optional)', 'text', 'Leave empty if you are not VAT registered.') . '</fieldset>'
    . '<fieldset><legend>Payments</legend>' . $groupError('payments') . $checkbox('pay_cod', 'Cash on delivery') . $checkbox('pay_bank', 'Bank transfer')
    . $field('iban', 'IBAN for bank transfers', 'text', 'Buyers get it with a QR payment code.')
    . '<p>Card payments with Stripe (optional, test mode): enter all three values or leave them empty.</p>' . $groupError('stripe')
    . $field('stripe_secret_key', 'Stripe secret key (sk_test_…)') . $field('stripe_publishable_key', 'Stripe publishable key (pk_test_…)') . $field('stripe_webhook_secret', 'Stripe webhook signing secret (whsec_…)') . '</fieldset>'
    . '<fieldset><legend>Administrator</legend>' . $field('admin_user', 'User name', 'text', '', 'username') . $field('admin_password', 'Password', 'password', 'At least 12 characters.', 'new-password')
    . $field('admin_password_repeat', 'Password again', 'password', '', 'new-password') . '</fieldset>'
    . '<button type="submit">Install the shop</button></form>');
