<?php
/**
 * BareBones Shop — Customer Account Portal
 *
 * Handles registration, login, password reset, dashboard, orders and
 * addresses. Pages: login, register, forgot, reset, dashboard (default),
 * orders, addresses, profile, change-password, logout.
 */
require_once __DIR__ . '/src/autoload.php';

Security::sessionStart();
Security::headers();

// Locale: URL ?lang= overrides session, session overrides auto-detect.
$storesCfg = Bootstrap::stores();
$supported = [];
foreach ($storesCfg['stores'] ?? [] as $s) {
    foreach ($s['languages'] ?? [] as $lc) $supported[$lc] = true;
}
$supported = array_keys($supported) ?: [$storesCfg['default_locale'] ?? 'en'];
if (!empty($_GET['lang']) && in_array($_GET['lang'], $supported, true)) {
    $_SESSION['customer_locale'] = $_GET['lang'];
}
$locale = $_SESSION['customer_locale']
    ?? I18n::detectLocale($_SERVER['REQUEST_URI'] ?? '/')
    ?? ($storesCfg['default_locale'] ?? 'en');
I18n::setLocale($locale);

$t = fn(string $k) => I18n::t($k);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$p = Security::clean($_GET['p'] ?? '');

// ============================================================
// POST handlers
// ============================================================
$flash = null;
$flashType = null;
$fieldErrors = [];
$fieldValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::csrfValidate($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Forbidden (CSRF)');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $fieldValues = $_POST;
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $name = Security::clean($_POST['name'] ?? '');
        $rlKey = 'account_register_' . md5($_SERVER['REMOTE_ADDR'] ?? 'cli');
        if (!Security::rateLimit($rlKey, 10)) {
            $flash = $t('account.rate_limited'); $flashType = 'error';
        } else {
            [$ok, $id, $err] = Customers::register($email, $password, $name, ['locale' => $locale]);
            if ($ok) {
                // Send welcome email (fire-and-forget)
                MailService::send('account_welcome', $email, $locale, [
                    'customer_name' => $name ?: $email,
                    'shop_name'     => 'BareBones Shop',
                ], null);
                // Auto-login
                Customers::login($email, $password);
                header('Location: account.php');
                exit;
            }
            $fieldErrors[$err] = true;
            $flash = $t('account.err_' . $err) ?: $err;
            $flashType = 'error';
        }
        $p = 'register';
    }

    elseif ($action === 'login') {
        $fieldValues = $_POST;
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $rlKey = 'account_login_' . md5(($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . $email);
        if (!Security::rateLimit($rlKey, 10)) {
            $flash = $t('account.rate_limited'); $flashType = 'error';
            $p = 'login';
        } else {
            [$ok, $c, $err] = Customers::login($email, $password);
            if ($ok) {
                $next = Security::safeLocalPath($_POST['next'] ?? null, 'account.php');
                header('Location: ' . $next);
                exit;
            }
            $flash = $t('account.err_' . $err) ?: $err;
            $flashType = 'error';
            $p = 'login';
        }
    }

    elseif ($action === 'logout') {
        Customers::logout();
        header('Location: account.php?p=login');
        exit;
    }

    elseif ($action === 'forgot') {
        $fieldValues = $_POST;
        $email = strtolower(trim($_POST['email'] ?? ''));
        $rlKey = 'account_forgot_' . md5($_SERVER['REMOTE_ADDR'] ?? 'cli');
        if (!Security::rateLimit($rlKey, 5)) {
            $flash = $t('account.rate_limited'); $flashType = 'error';
        } else {
            $token = Customers::startPasswordReset($email);
            if ($token) {
                $resetUrl = (!empty($_SERVER['HTTPS']) ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                    . '/account.php?p=reset&token=' . urlencode($token);
                MailService::send('account_password_reset', $email, $locale, [
                    'customer_name' => $email,
                    'reset_url'     => $resetUrl,
                    'expires_in'    => '1',
                ], null);
            }
            // Generic success (avoid email enumeration)
            $flash = $t('account.forgot_sent');
            $flashType = 'ok';
        }
        $p = 'forgot';
    }

    elseif ($action === 'reset') {
        $token = Security::clean($_POST['token'] ?? '');
        $newPassword = $_POST['password'] ?? '';
        [$ok, $err] = Customers::completePasswordReset($token, $newPassword);
        if ($ok) {
            $flash = $t('account.reset_success');
            $flashType = 'ok';
            $p = 'login';
        } else {
            $flash = $t('account.err_' . $err) ?: $err;
            $flashType = 'error';
            $p = 'reset';
            $fieldValues = ['token' => $token];
        }
    }

    elseif ($action === 'change_password' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        [$ok, $err] = Customers::changePassword($cid, $_POST['current'] ?? '', $_POST['new'] ?? '');
        $flash = $ok ? $t('account.password_changed') : ($t('account.err_' . $err) ?: $err);
        $flashType = $ok ? 'ok' : 'error';
        $p = 'change-password';
    }

    elseif ($action === 'update_profile' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        Customers::update($cid, [
            'name'   => Security::clean($_POST['name'] ?? ''),
            'phone'  => Security::clean($_POST['phone'] ?? ''),
            'locale' => in_array($_POST['locale'] ?? '', $supported, true)
                        ? $_POST['locale'] : $locale,
        ]);
        $flash = $t('account.profile_saved');
        $flashType = 'ok';
        $p = 'profile';
    }

    elseif ($action === 'address_add' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        Customers::addAddress($cid, [
            'type'       => $_POST['type'] ?? 'shipping',
            'is_default' => !empty($_POST['is_default']),
            'name'       => $_POST['name'] ?? '',
            'company'    => $_POST['company'] ?? '',
            'vat_id'     => $_POST['vat_id'] ?? '',
            'street'     => $_POST['street'] ?? '',
            'city'       => $_POST['city'] ?? '',
            'zip'        => $_POST['zip'] ?? '',
            'country'    => $_POST['country'] ?? '',
            'phone'      => $_POST['phone'] ?? '',
        ]);
        header('Location: account.php?p=addresses&ok=1');
        exit;
    }

    elseif ($action === 'address_update' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        $aid = Security::int($_POST['id'] ?? 0);
        Customers::updateAddress($cid, $aid, $_POST);
        header('Location: account.php?p=addresses&ok=1');
        exit;
    }

    elseif ($action === 'address_delete' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        $aid = Security::int($_POST['id'] ?? 0);
        Customers::deleteAddress($cid, $aid);
        header('Location: account.php?p=addresses&ok=1');
        exit;
    }

    elseif ($action === 'data_export' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        $rlCustomer = Security::rateLimit('gdpr_export_c_' . $cid, 1, 86400);
        $rlIp       = Security::rateLimit('gdpr_export_ip_' . md5($_SERVER['REMOTE_ADDR'] ?? 'cli'), 5, 86400);
        if (!$rlCustomer || !$rlIp) {
            $flash = $t('account.gdpr_export_rate_limit');
            $flashType = 'error';
            $p = 'privacy';
        } else {
            try {
                $data = GdprService::exportCustomer($cid);
                GdprService::logEvent([
                    'action' => 'export', 'customer_id' => $cid,
                    'email' => (string)($data['customer']['email'] ?? ''),
                    'actor' => 'self', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'note' => 'account.php data_export',
                    'payload' => ['orders' => count($data['orders']), 'addresses' => count($data['addresses'])],
                ]);
                $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="my-data-' . $cid . '-' . gmdate('Ymd') . '.json"');
                echo $json;
                exit;
            } catch (Throwable $ex) {
                $flash = $t('account.gdpr_export_failed');
                $flashType = 'error';
                $p = 'privacy';
            }
        }
    }

    elseif ($action === 'erase_account' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        $confirm = (string)($_POST['confirm'] ?? '');
        if (strtoupper(trim($confirm)) !== 'DELETE') {
            $flash = $t('account.gdpr_erase_confirm_required');
            $flashType = 'error';
            $p = 'privacy';
        } else {
            try {
                GdprService::anonymizeCustomer($cid, 'self');
                Customers::logout();
                Security::sessionStart();
                $_SESSION['gdpr_erased'] = 1;
                header('Location: account.php?p=login&erased=1');
                exit;
            } catch (Throwable $ex) {
                $flash = $t('account.gdpr_erase_failed') . ': ' . $ex->getMessage();
                $flashType = 'error';
                $p = 'privacy';
            }
        }
    }

    elseif ($action === 'rma_request' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $rawItems = $_POST['items'] ?? [];
        $items = [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $oiId => $qty) {
                $q = (int)$qty;
                if ($q > 0) $items[(int)$oiId] = $q;
            }
        }
        [$ok, $rmaId, $err] = Rma::create($orderId, $cid, $reason, $items);
        header('Location: account.php?p=returns&msg=' . urlencode($ok ? 'ok' : (string)$err));
        exit;
    }

    elseif ($action === 'cookie_consent') {
        $cats = [
            'necessary' => true,
            'analytics' => !empty($_POST['analytics']),
            'marketing' => !empty($_POST['marketing']),
        ];
        $method = (string)($_POST['method'] ?? 'preferences');
        $cid = Customers::isLoggedIn() ? (int)$_SESSION['customer_id'] : null;
        $consentId = GdprService::logCookieConsent([
            'session_id'  => session_id() ?: null,
            'customer_id' => $cid,
            'categories'  => $cats,
            'method'      => $method,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $cookieValue = json_encode($cats);
        setcookie('cookie_consent', $cookieValue, [
            'expires'  => time() + 31536000, // 12 months
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        header('Location: ' . Security::safeLocalPath($_POST['return_to'] ?? null, 'account.php?p=privacy'));
        exit;
    }
}

// ============================================================
// Public signed-token invoice download (no login required)
// ============================================================
if ($p === 'invoice') {
    $token = (string)($_GET['token'] ?? '');
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
        http_response_code(400);
        exit('Invalid token.');
    }
    $stmt = Db::get()->prepare("SELECT * FROM invoices WHERE download_token = :tok LIMIT 1");
    $stmt->execute([':tok' => $token]);
    $inv = $stmt->fetch();
    if (!$inv || ($inv['status'] ?? '') === 'cancelled') {
        http_response_code(404);
        exit('Invoice not found.');
    }
    $abs = InvoiceService::pdfAbsolutePath($inv);
    if (!is_file($abs)) {
        http_response_code(404);
        exit('PDF missing.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $inv['number'] . '.pdf"');
    header('Content-Length: ' . filesize($abs));
    header('Cache-Control: private, max-age=300');
    readfile($abs);
    exit;
}

// ============================================================
// Auth gate for private pages
// ============================================================
$publicPages = ['login', 'register', 'forgot', 'reset'];
$loggedIn = Customers::isLoggedIn();
if (!$loggedIn && !in_array($p, $publicPages, true)) {
    $next = $_SERVER['REQUEST_URI'] ?? 'account.php';
    header('Location: account.php?p=login&next=' . urlencode($next));
    exit;
}
if ($loggedIn && in_array($p, ['login', 'register', 'forgot'], true)) {
    header('Location: account.php');
    exit;
}
if ($p === '') $p = 'dashboard';

$customer = $loggedIn ? Customers::current() : null;
$csrf = Security::csrfToken();

// ============================================================
// Render
// ============================================================
ob_start();

echo '<!DOCTYPE html><html lang="' . $e($locale) . '"><head>';
echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<title>' . $e($t('account.title')) . ' — BareBones Shop</title>';
echo '<style>
*{box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Helvetica,Arial,sans-serif;background:#f5f6f8;color:#222;margin:0}
.wrap{max-width:960px;margin:0 auto;padding:24px}
header{background:#1a202c;color:#fff;padding:16px 24px;display:flex;justify-content:space-between;align-items:center}
header a{color:#fff;text-decoration:none;margin-right:16px}
header .brand{font-weight:600}
nav{background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px}
nav a{display:inline-block;padding:8px 12px;color:#2d3748;text-decoration:none;border-radius:4px}
nav a.active{background:#4a5568;color:#fff}
nav a:hover:not(.active){background:#edf2f7}
.card{background:#fff;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.06);padding:24px;margin-bottom:16px}
h1{margin:0 0 16px}h2{margin:0 0 12px}
.grid{display:grid;gap:16px}
.grid-2{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
label{display:block;margin-bottom:4px;font-size:14px;color:#4a5568;font-weight:500}
input,select,textarea{width:100%;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;font-size:14px}
input:focus,textarea:focus{outline:none;border-color:#4299e1}
.form-row{margin-bottom:12px}
.btn{background:#4299e1;color:#fff;border:0;padding:10px 16px;border-radius:4px;cursor:pointer;font-size:14px;text-decoration:none;display:inline-block}
.btn:hover{background:#3182ce}
.btn-secondary{background:#a0aec0}.btn-secondary:hover{background:#718096}
.btn-danger{background:#e53e3e}.btn-danger:hover{background:#c53030}
.btn-sm{padding:6px 10px;font-size:13px}
.flash{padding:12px 16px;border-radius:4px;margin-bottom:16px}
.flash-ok{background:#c6f6d5;color:#22543d}
.flash-error{background:#fed7d7;color:#742a2a}
table{width:100%;border-collapse:collapse;margin-top:12px}
th,td{text-align:left;padding:10px;border-bottom:1px solid #e2e8f0;font-size:14px}
th{background:#edf2f7;color:#4a5568;font-size:12px;text-transform:uppercase}
.muted{color:#718096;font-size:13px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:500}
.badge-pending{background:#fefcbf;color:#744210}
.badge-paid{background:#c6f6d5;color:#22543d}
.badge-shipped{background:#bee3f8;color:#2a4365}
.badge-delivered{background:#b2f5ea;color:#234e52}
.badge-cancelled{background:#fed7d7;color:#742a2a}
.addr-card{border:1px solid #e2e8f0;border-radius:4px;padding:12px;position:relative}
.addr-default{border-color:#4299e1}
.addr-actions{margin-top:8px}
a.link{color:#4299e1;text-decoration:none}a.link:hover{text-decoration:underline}
</style></head><body>';

echo '<header><div class="brand"><a href="/">BareBones Shop</a></div><div>';
if ($loggedIn) {
    echo '<span style="margin-right:16px">' . $e($customer['email']) . '</span>';
    echo '<form method="post" action="account.php" style="display:inline">';
    echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
    echo '<input type="hidden" name="action" value="logout">';
    echo '<button class="btn btn-sm btn-secondary">' . $e($t('account.logout')) . '</button>';
    echo '</form>';
} else {
    echo '<a href="account.php?p=login">' . $e($t('account.login')) . '</a>';
    echo '<a href="account.php?p=register" class="btn btn-sm">' . $e($t('account.register')) . '</a>';
}
echo '</div></header>';

if ($loggedIn) {
    $mk = function(string $slug, string $label) use ($p, $e) {
        $cls = $p === $slug ? 'active' : '';
        return "<a class=\"{$cls}\" href=\"account.php?p=" . $e($slug) . "\">" . $e($label) . "</a>";
    };
    echo '<nav>';
    echo $mk('dashboard', $t('account.dashboard'));
    echo $mk('orders',    $t('account.orders'));
    echo $mk('returns',   $t('account.returns'));
    echo $mk('addresses', $t('account.addresses'));
    echo $mk('profile',   $t('account.profile'));
    echo $mk('change-password', $t('account.change_password'));
    echo $mk('privacy',   $t('account.privacy'));
    echo '</nav>';
}

echo '<div class="wrap">';

if ($flash) {
    $cls = $flashType === 'ok' ? 'flash-ok' : 'flash-error';
    echo '<div class="flash ' . $cls . '">' . $e($flash) . '</div>';
}

switch ($p) {
    case 'login':
        $next = $e($_GET['next'] ?? $fieldValues['next'] ?? 'account.php');
        echo '<div class="card" style="max-width:420px;margin:0 auto">';
        echo '<h1>' . $e($t('account.login')) . '</h1>';
        echo '<form method="post" action="account.php">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
        echo '<input type="hidden" name="action" value="login">';
        echo '<input type="hidden" name="next" value="' . $next . '">';
        echo '<div class="form-row"><label>' . $e($t('account.email')) . '</label><input type="email" name="email" required value="' . $e($fieldValues['email'] ?? '') . '"></div>';
        echo '<div class="form-row"><label>' . $e($t('account.password')) . '</label><input type="password" name="password" required></div>';
        echo '<button class="btn">' . $e($t('account.login')) . '</button>';
        echo '</form>';
        echo '<p class="muted" style="margin-top:16px"><a class="link" href="account.php?p=forgot">' . $e($t('account.forgot_link')) . '</a></p>';
        echo '<p class="muted"><a class="link" href="account.php?p=register">' . $e($t('account.no_account')) . '</a></p>';
        echo '</div>';
        break;

    case 'register':
        echo '<div class="card" style="max-width:420px;margin:0 auto">';
        echo '<h1>' . $e($t('account.register')) . '</h1>';
        echo '<form method="post" action="account.php">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
        echo '<input type="hidden" name="action" value="register">';
        echo '<div class="form-row"><label>' . $e($t('account.name')) . '</label><input type="text" name="name" value="' . $e($fieldValues['name'] ?? '') . '"></div>';
        echo '<div class="form-row"><label>' . $e($t('account.email')) . '</label><input type="email" name="email" required value="' . $e($fieldValues['email'] ?? '') . '"></div>';
        echo '<div class="form-row"><label>' . $e($t('account.password')) . ' <span class="muted">(min 8)</span></label><input type="password" name="password" required minlength="8"></div>';
        echo '<button class="btn">' . $e($t('account.register')) . '</button>';
        echo '</form>';
        echo '<p class="muted" style="margin-top:16px"><a class="link" href="account.php?p=login">' . $e($t('account.have_account')) . '</a></p>';
        echo '</div>';
        break;

    case 'forgot':
        echo '<div class="card" style="max-width:420px;margin:0 auto">';
        echo '<h1>' . $e($t('account.forgot_title')) . '</h1>';
        echo '<p class="muted">' . $e($t('account.forgot_help')) . '</p>';
        echo '<form method="post" action="account.php">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
        echo '<input type="hidden" name="action" value="forgot">';
        echo '<div class="form-row"><label>' . $e($t('account.email')) . '</label><input type="email" name="email" required value="' . $e($fieldValues['email'] ?? '') . '"></div>';
        echo '<button class="btn">' . $e($t('account.forgot_send')) . '</button>';
        echo '</form>';
        echo '</div>';
        break;

    case 'reset':
        $tok = Security::clean($_GET['token'] ?? $fieldValues['token'] ?? '');
        echo '<div class="card" style="max-width:420px;margin:0 auto">';
        echo '<h1>' . $e($t('account.reset_title')) . '</h1>';
        if (!$tok) {
            echo '<p>' . $e($t('account.err_invalid_or_expired_token')) . '</p>';
        } else {
            echo '<form method="post" action="account.php">';
            echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
            echo '<input type="hidden" name="action" value="reset">';
            echo '<input type="hidden" name="token" value="' . $e($tok) . '">';
            echo '<div class="form-row"><label>' . $e($t('account.new_password')) . '</label><input type="password" name="password" required minlength="8"></div>';
            echo '<button class="btn">' . $e($t('account.reset_submit')) . '</button>';
            echo '</form>';
        }
        echo '</div>';
        break;

    case 'dashboard':
        $cid = (int)$customer['id'];
        $recent = Customers::orders($cid, 5);
        $count = Customers::orderCount($cid);
        $addr = Customers::defaultAddress($cid, 'shipping');
        echo '<h1>' . $e($t('account.welcome') . ', ' . ($customer['name'] ?: $customer['email'])) . '</h1>';
        echo '<div class="grid grid-2">';
        echo '<div class="card"><h2>' . $e($t('account.orders')) . '</h2>';
        echo '<p>' . $e(sprintf('%s: %d', $t('account.orders_total'), $count)) . '</p>';
        echo '<a class="btn btn-sm" href="account.php?p=orders">' . $e($t('account.orders_view')) . '</a></div>';
        echo '<div class="card"><h2>' . $e($t('account.addresses')) . '</h2>';
        if ($addr) {
            echo '<p class="muted">' . $e("{$addr['name']}, {$addr['street']}, {$addr['city']} {$addr['zip']}, {$addr['country']}") . '</p>';
        } else {
            echo '<p class="muted">' . $e($t('account.no_addresses')) . '</p>';
        }
        echo '<a class="btn btn-sm" href="account.php?p=addresses">' . $e($t('account.addresses_manage')) . '</a></div>';
        echo '</div>';
        break;

    case 'orders':
        $cid = (int)$customer['id'];
        $orders = Customers::orders($cid, 100);
        echo '<div class="card"><h1>' . $e($t('account.orders')) . '</h1>';
        if (!$orders) {
            echo '<p class="muted">' . $e($t('account.no_orders')) . '</p>';
        } else {
            // Pre-fetch invoices for these orders
            $orderIds = array_map(static fn($r) => (int)$r['id'], $orders);
            $invByOrder = [];
            if ($orderIds) {
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                $st = Db::get()->prepare("SELECT order_id, number, download_token FROM invoices WHERE order_id IN ({$ph}) AND status != 'cancelled' ORDER BY id DESC");
                $st->execute($orderIds);
                foreach ($st->fetchAll() as $r) {
                    $invByOrder[(int)$r['order_id']] = $r;
                }
            }
            echo '<table><tr><th>' . $e($t('account.order_number')) . '</th><th>' . $e($t('account.status')) . '</th><th>' . $e($t('account.payment')) . '</th><th>' . $e($t('account.payment_history')) . '</th><th>' . $e($t('account.total')) . '</th><th>' . $e($t('account.date')) . '</th><th>' . $e($t('account.invoice')) . '</th></tr>';
            foreach ($orders as $o) {
                $paymentSnapshot = json_decode((string)($o['payment_method_snapshot'] ?? '{}'), true) ?: [];
                $paymentLabel = (string)($paymentSnapshot['label'] ?? $paymentSnapshot['name'] ?? $o['payment_method'] ?? '');
                $paymentTxs = PaymentService::transactionsForOrder((int)$o['id']);
                echo '<tr>';
                echo '<td>' . $e($o['order_number']) . '</td>';
                echo '<td><span class="badge badge-' . $e($o['status']) . '">' . $e($o['status']) . '</span></td>';
                echo '<td><span class="badge badge-' . $e($o['payment_status'] ?? 'pending') . '">' . $e($o['payment_status'] ?? 'pending') . '</span><br><span class="muted">' . $e($paymentLabel) . '</span></td>';
                if ($paymentTxs) {
                    echo '<td><details><summary>' . count($paymentTxs) . '×</summary><ul class="muted">';
                    foreach ($paymentTxs as $tx) {
                        $paymentRef = $tx['provider_ref'] ? ' · ' . (string)$tx['provider_ref'] : '';
                        echo '<li>' . $e(substr((string)$tx['created_at'], 0, 16) . ' — ' . (string)$tx['status'] . $paymentRef) . '</li>';
                    }
                    echo '</ul></details></td>';
                } else {
                    echo '<td class="muted">—</td>';
                }
                echo '<td>' . $e(I18n::price((int)$o['total'], $o['currency'])) . '</td>';
                echo '<td class="muted">' . $e(substr((string)$o['created_at'], 0, 16)) . '</td>';
                $inv = $invByOrder[(int)$o['id']] ?? null;
                if ($inv) {
                    echo '<td><a class="btn btn-sm" href="account.php?p=invoice&token=' . $e($inv['download_token']) . '" target="_blank">' . $e($inv['number']) . '</a></td>';
                } else {
                    echo '<td class="muted">—</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';
        break;

    case 'returns':
        $cid = (int)$customer['id'];
        $msg = (string)($_GET['msg'] ?? '');
        if ($msg === 'ok') {
            echo '<div class="flash flash-ok">' . $e($t('account.return_submitted')) . '</div>';
        } elseif ($msg !== '') {
            echo '<div class="flash flash-error">' . $e($t('account.return_error') . ': ' . $msg) . '</div>';
        }

        // Existing returns
        $rmas = Rma::forCustomer($cid);
        echo '<div class="card"><h1>' . $e($t('account.returns')) . '</h1>';
        if (!$rmas) {
            echo '<p class="muted">' . $e($t('account.no_returns')) . '</p>';
        } else {
            echo '<table><tr><th>RMA</th><th>' . $e($t('account.order_number')) . '</th><th>' . $e($t('account.status')) . '</th><th>' . $e($t('account.date')) . '</th></tr>';
            foreach ($rmas as $r) {
                $ord = Customers::order($cid, (int)$r['order_id']);
                echo '<tr>';
                echo '<td>' . $e($r['rma_number']) . '</td>';
                echo '<td>' . $e($ord['order_number'] ?? ('#' . $r['order_id'])) . '</td>';
                echo '<td><span class="badge badge-' . $e($r['status']) . '">' . $e($r['status']) . '</span></td>';
                echo '<td class="muted">' . $e(substr((string)$r['created_at'], 0, 16)) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        // Delivered orders eligible for a new return (no open RMA)
        $delivered = array_filter(
            Customers::orders($cid, 100),
            static fn($o) => ($o['status'] ?? '') === 'delivered' && !Rma::openForOrder((int)$o['id'])
        );
        echo '<div class="card"><h2>' . $e($t('account.request_return')) . '</h2>';
        if (!$delivered) {
            echo '<p class="muted">' . $e($t('account.no_returnable_orders')) . '</p>';
        }
        foreach ($delivered as $o) {
            $items = Orders::items((int)$o['id']);
            echo '<form method="post" action="account.php" class="rma-form">';
            echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
            echo '<input type="hidden" name="action" value="rma_request">';
            echo '<input type="hidden" name="order_id" value="' . (int)$o['id'] . '">';
            echo '<h3>' . $e($o['order_number']) . ' <span class="muted">(' . $e(substr((string)$o['created_at'], 0, 10)) . ')</span></h3>';
            foreach ($items as $it) {
                $oid = (int)$it['id'];
                echo '<label class="rma-item">';
                echo '<input type="number" name="items[' . $oid . ']" value="0" min="0" max="' . (int)$it['quantity'] . '" style="width:64px"> ';
                echo $e($it['name']) . ' <span class="muted">(' . (int)$it['quantity'] . '×)</span>';
                echo '</label>';
            }
            echo '<div class="form-row"><label>' . $e($t('account.return_reason')) . '</label><textarea name="reason" rows="2" required></textarea></div>';
            echo '<button class="btn">' . $e($t('account.submit_return')) . '</button>';
            echo '</form><hr>';
        }
        echo '</div>';
        break;

    case 'addresses':
        $cid = (int)$customer['id'];
        $list = Customers::addresses($cid);
        echo '<div class="card"><h1>' . $e($t('account.addresses')) . '</h1>';
        if (!empty($_GET['ok'])) echo '<div class="flash flash-ok">' . $e($t('account.saved')) . '</div>';
        if (!$list) echo '<p class="muted">' . $e($t('account.no_addresses')) . '</p>';
        echo '<div class="grid grid-2">';
        foreach ($list as $a) {
            $cls = $a['is_default'] ? 'addr-card addr-default' : 'addr-card';
            echo '<div class="' . $cls . '">';
            if ($a['is_default']) echo '<span class="badge badge-paid">' . $e($t('account.default')) . '</span> ';
            echo '<strong>' . $e($a['type']) . '</strong><br>';
            echo $e($a['name']) . '<br>';
            if ($a['company']) echo $e($a['company']) . '<br>';
            echo $e($a['street']) . '<br>';
            echo $e($a['zip'] . ' ' . $a['city']) . ', ' . $e($a['country']) . '<br>';
            if ($a['phone']) echo '<span class="muted">' . $e($a['phone']) . '</span>';
            echo '<div class="addr-actions">';
            echo '<form method="post" action="account.php" style="display:inline" onsubmit="return confirm(\'' . $e($t('account.confirm_delete')) . '\')">';
            echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
            echo '<input type="hidden" name="action" value="address_delete">';
            echo '<input type="hidden" name="id" value="' . (int)$a['id'] . '">';
            echo '<button class="btn btn-sm btn-danger">' . $e($t('account.delete')) . '</button>';
            echo '</form>';
            echo '</div></div>';
        }
        echo '</div>';
        echo '<h2 style="margin-top:24px">' . $e($t('account.address_add')) . '</h2>';
        echo '<form method="post" action="account.php" class="grid grid-2">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
        echo '<input type="hidden" name="action" value="address_add">';
        foreach ([
            ['name', 'account.addr_name'],
            ['company', 'account.addr_company'],
            ['vat_id', 'account.addr_vat'],
            ['street', 'account.addr_street'],
            ['city', 'account.addr_city'],
            ['zip', 'account.addr_zip'],
            ['country', 'account.addr_country'],
            ['phone', 'account.addr_phone'],
        ] as [$f, $lbl]) {
            echo '<div class="form-row"><label>' . $e($t($lbl)) . '</label><input type="text" name="' . $e($f) . '"></div>';
        }
        echo '<div class="form-row"><label>' . $e($t('account.addr_type')) . '</label><select name="type"><option value="shipping">shipping</option><option value="billing">billing</option></select></div>';
        echo '<div class="form-row"><label><input type="checkbox" name="is_default" value="1"> ' . $e($t('account.addr_default')) . '</label></div>';
        echo '<div style="grid-column:1/-1"><button class="btn">' . $e($t('account.save')) . '</button></div>';
        echo '</form>';
        echo '</div>';
        break;

    case 'profile':
        echo '<div class="card"><h1>' . $e($t('account.profile')) . '</h1>';
        echo '<form method="post" action="account.php">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
        echo '<input type="hidden" name="action" value="update_profile">';
        echo '<div class="form-row"><label>' . $e($t('account.email')) . '</label><input type="email" value="' . $e($customer['email']) . '" disabled></div>';
        echo '<div class="form-row"><label>' . $e($t('account.name')) . '</label><input type="text" name="name" value="' . $e($customer['name']) . '"></div>';
        echo '<div class="form-row"><label>' . $e($t('account.phone')) . '</label><input type="text" name="phone" value="' . $e($customer['phone']) . '"></div>';
        echo '<div class="form-row"><label>' . $e($t('account.locale')) . '</label><select name="locale">';
        foreach ($supported as $lc) {
            $sel = $lc === $customer['locale'] ? ' selected' : '';
            echo '<option value="' . $e($lc) . '"' . $sel . '>' . $e($lc) . '</option>';
        }
        echo '</select></div>';
        echo '<button class="btn">' . $e($t('account.save')) . '</button>';
        echo '</form></div>';
        break;

    case 'privacy':
        echo '<div class="card"><h1>' . $e($t('account.privacy')) . '</h1>';
        echo '<p style="color:#555">' . $e($t('account.privacy_intro')) . '</p>';

        // Subject Access Request
        echo '<h2 style="margin-top:24px">' . $e($t('account.gdpr_export_title')) . '</h2>';
        echo '<p style="color:#555">' . $e($t('account.gdpr_export_desc')) . '</p>';
        echo '<form method="post" action="account.php" style="margin:0">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
        echo '<input type="hidden" name="action" value="data_export">';
        echo '<button class="btn">' . $e($t('account.gdpr_export_btn')) . '</button>';
        echo '</form>';

        // Cookie consent
        $consentCookie = isset($_COOKIE['cookie_consent']) ? json_decode((string)$_COOKIE['cookie_consent'], true) : [];
        $aOn = !empty($consentCookie['analytics']);
        $mOn = !empty($consentCookie['marketing']);
        echo '<h2 style="margin-top:24px">' . $e($t('account.gdpr_cookies_title')) . '</h2>';
        echo '<form method="post" action="account.php" style="margin:0">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
        echo '<input type="hidden" name="action" value="cookie_consent">';
        echo '<input type="hidden" name="method" value="preferences">';
        echo '<input type="hidden" name="return_to" value="account.php?p=privacy&saved=1">';
        echo '<div class="form-row"><label><input type="checkbox" checked disabled> ' . $e($t('account.gdpr_cookies_necessary')) . '</label></div>';
        echo '<div class="form-row"><label><input type="checkbox" name="analytics" value="1"' . ($aOn ? ' checked' : '') . '> ' . $e($t('account.gdpr_cookies_analytics')) . '</label></div>';
        echo '<div class="form-row"><label><input type="checkbox" name="marketing" value="1"' . ($mOn ? ' checked' : '') . '> ' . $e($t('account.gdpr_cookies_marketing')) . '</label></div>';
        echo '<button class="btn">' . $e($t('account.save')) . '</button>';
        echo '</form>';

        // Right to erasure
        echo '<h2 style="margin-top:24px;color:#b91c1c">' . $e($t('account.gdpr_erase_title')) . '</h2>';
        echo '<p style="color:#555">' . $e($t('account.gdpr_erase_desc')) . '</p>';
        echo '<p style="color:#777;font-size:0.9em">' . $e($t('account.gdpr_erase_legal_note')) . '</p>';
        echo '<form method="post" action="account.php" style="margin:0">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
        echo '<input type="hidden" name="action" value="erase_account">';
        echo '<div class="form-row"><label>' . $e($t('account.gdpr_erase_confirm_label')) . '</label>';
        echo '<input type="text" name="confirm" placeholder="DELETE" required pattern="DELETE"></div>';
        echo '<button class="btn" style="background:#b91c1c">' . $e($t('account.gdpr_erase_btn')) . '</button>';
        echo '</form>';

        echo '</div>';
        break;

    case 'change-password':
        echo '<div class="card" style="max-width:420px"><h1>' . $e($t('account.change_password')) . '</h1>';
        echo '<form method="post" action="account.php">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '">';
        echo '<input type="hidden" name="action" value="change_password">';
        echo '<div class="form-row"><label>' . $e($t('account.current_password')) . '</label><input type="password" name="current" required></div>';
        echo '<div class="form-row"><label>' . $e($t('account.new_password')) . '</label><input type="password" name="new" required minlength="8"></div>';
        echo '<button class="btn">' . $e($t('account.save')) . '</button>';
        echo '</form></div>';
        break;

    default:
        echo '<div class="card"><p>' . $e($t('account.page_not_found')) . '</p></div>';
}

echo '</div></body></html>';

echo ob_get_clean();
