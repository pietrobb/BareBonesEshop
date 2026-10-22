<?php
/**
 * BareBones Shop — Customer Account Portal (served by the thin /account.php entrypoint)
 *
 * Handles registration, login, password reset, dashboard, orders and
 * addresses. Pages: login, register, forgot, reset, dashboard (default),
 * orders, addresses, profile, change-password, logout.
 */
require_once dirname(__DIR__, 3) . '/src/autoload.php';

Security::sessionStart();
Security::headers();

$locale = Bootstrap::initLocale();
// A profile can only pick a language the current store actually serves.
$supported = array_values(array_unique(array_map('strval', (array)(StoreContext::current()->config()['languages'] ?? [])))) ?: [$locale];

$t = fn(string $k) => I18n::t($k);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$p = Security::clean($_GET['p'] ?? '');

// ============================================================
// Public invoice link (no login, no session: the token is the bearer)
// ============================================================
if ($p === 'invoice') {
    // The e-mailed link carries its token in the fragment, which never reaches a server log or a Referer: this page
    // posts it. A token in the query (links mailed before round 8) still opens the PDF until it expires.
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    $token = Security::str($_POST['token'] ?? $_GET['token'] ?? '');
    if ($token !== '') {
        $inv = InvoiceService::byToken($token);
        if ($inv && ($inv['status'] ?? '') !== 'cancelled') InvoiceService::sendPdf($inv);
        http_response_code(404);
    }
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="' . $h(I18n::locale()) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $h($t('account.invoice_link_title')) . '</title></head><body>'
        . ($token !== '' ? '<p role="alert">' . $h($t('account.invoice_link_invalid')) . '</p>' : '')
        . '<form method="post" action="' . $h(Template::storeUrl('/account.php?p=invoice')) . '" id="invoice-link" hidden>'
        . '<input type="hidden" name="token" value=""><button type="submit">' . $h($t('account.invoice_link_open')) . '</button></form>'
        . '<noscript><p>' . $h($t('account.invoice_link_noscript')) . '</p></noscript>'
        . '<script nonce="' . $h(Security::cspNonce()) . '">(function(){var f=document.getElementById("invoice-link"),t=location.hash.slice(1);'
        . 'if(!/^[0-9a-f]{64}$/i.test(t))return;'
        . 'history.replaceState(null,"",location.pathname+location.search);f.token.value=t;f.submit();})();</script>'
        . '</body></html>';
    exit;
}

// ============================================================
// POST handlers
// ============================================================
$flash = null;
$flashType = null;
$fieldErrors = [];
$fieldValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::csrfValidate()) {
        http_response_code(403);
        exit('Forbidden (CSRF)');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $fieldValues = $_POST;
        $email = Str::normalizeEmail(Security::str($_POST['email'] ?? ''));
        $password = Security::str($_POST['password'] ?? '');
        $name = Security::clean($_POST['name'] ?? '');
        $rlKey = 'account_register_' . md5($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $looksHuman = Security::formTrapValidate(
            'account_register',
            (string)($_POST['register_token'] ?? ''),
            (string)($_POST['company_website'] ?? '')
        );
        if (!Security::rateLimit($rlKey, 4)) {
            $flash = $t('account.rate_limited'); $flashType = 'error';
        } elseif (!$looksHuman) {
            // A too-fast or expired form is not a rate limit; a neutral retry keeps real users moving.
            $flash = $t('account.form_retry'); $flashType = 'error';
        } else {
            [$ok, $id, $err] = Customers::register($email, $password, $name, ['locale' => $locale]);
            if ($ok) {
                // Send welcome email (fire-and-forget)
                MailService::send('account_welcome', $email, $locale, [
                    'customer_name' => $name ?: $email,
                    'shop_name'     => (string)Template::branding()['name'],
                ], null);
                Customers::requestEmailVerification((int)$id, $locale, Security::config());
                // Auto-login
                Customers::login($email, $password);
                header('Location: ' . Template::storeUrl('/account.php'));
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
        $email = Str::normalizeEmail(Security::str($_POST['email'] ?? ''));
        $password = Security::str($_POST['password'] ?? '');
        $emailKey = hash('sha256', $email);
        $ipAllowed = Security::rateLimit('account_login_ip', 30, 900);
        // Per account AND client IP: foreign failures must not lock the owner out (no lockout DoS).
        $accountAllowed = Security::rateLimit('account_login_account_' . $emailKey, 10, 900);
        // Deliberately no per-account ceiling across all IPs: a handful of addresses could then
        // lock any customer out of their account. The trade-off is that a botnet rotating
        // addresses is only slowed per IP; password hashing cost and the per-IP limits bound it.
        if ($ipAllowed && $accountAllowed) {
            [$ok, $c, $err] = Customers::login($email, $password);
            if ($ok) {
                $next = Security::safeLocalPath($_POST['next'] ?? null, Template::storeUrl('/account.php'));
                header('Location: ' . $next);
                exit;
            }
        }
        $flash = $t('account.err_invalid_credentials') ?: 'invalid_credentials';
        $flashType = 'error';
        $p = 'login';
    }

    elseif ($action === 'logout') {
        Customers::logout();
        header('Location: ' . Template::storeUrl('/account.php?p=login'));
        exit;
    }

    elseif ($action === 'forgot') {
        $fieldValues = $_POST;
        $email = Str::normalizeEmail(Security::str($_POST['email'] ?? ''));
        // Per client IP here; per recipient (store-scoped, sent mails only) in Customers::forgotPassword().
        if (Security::rateLimit('account_forgot_ip', 5, 60)) {
            Customers::forgotPassword($email, $locale, Security::config());
        }
        // Always generic, including throttled and unknown recipients.
        $flash = $t('account.forgot_sent');
        $flashType = 'ok';
        $p = 'forgot';
    }

    elseif ($action === 'reset') {
        $token = Security::clean($_POST['token'] ?? '');
        $newPassword = Security::str($_POST['password'] ?? '');
        $ipAllowed = Security::rateLimit('account_reset_ip', 10, 3600);
        $tokenAllowed = Security::rateLimit('account_reset_token_' . hash('sha256', $token), 5, 3600, false);
        [$ok, $err] = ($ipAllowed && $tokenAllowed)
            ? Customers::completePasswordReset($token, $newPassword)
            : [false, 'invalid_or_expired_token'];
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

    elseif ($action === 'resend_verification' && Customers::isLoggedIn()) {
        $outcome = Customers::requestEmailVerification((int)$_SESSION['customer_id'], $locale, Security::config());
        $flash = $t(match ($outcome) {
            'sent' => 'account.verify_email_sent',
            'verified' => 'account.verify_email_done',
            'throttled' => 'account.verify_email_throttled',
            default => 'account.verify_email_failed',
        });
        $flashType = in_array($outcome, ['sent', 'verified'], true) ? 'ok' : 'error';
        $p = 'dashboard';
    }

    elseif ($action === 'change_password' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        $allowed = Security::rateLimit('account_change_password_' . $cid, 5, 900, false);
        [$ok, $err] = $allowed
            ? Customers::changePassword($cid, Security::str($_POST['current'] ?? ''), Security::str($_POST['new'] ?? ''))
            : [false, 'invalid_current_password'];
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
        $address = ['type' => Security::str($_POST['type'] ?? 'shipping'), 'is_default' => !empty($_POST['is_default'])];
        foreach (['name', 'company', 'vat_id', 'street', 'city', 'zip', 'country', 'phone'] as $field) {
            $address[$field] = trim(Security::str($_POST[$field] ?? ''));
        }
        $address['country'] = strtoupper($address['country']);
        $fieldErrors = Customers::addressErrors($address);
        if (!$fieldErrors) {
            Customers::addAddress($cid, $address);
            header('Location: ' . Template::storeUrl('/account.php?p=addresses&ok=1'));
            exit;
        }
        $fieldValues = $address;
        $flash = $t('account.err_address_invalid');
        $flashType = 'error';
        $p = 'addresses';
    }

    elseif ($action === 'address_update' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        $aid = Security::int($_POST['id'] ?? 0);
        Customers::updateAddress($cid, $aid, $_POST);
        header('Location: ' . Template::storeUrl('/account.php?p=addresses&ok=1'));
        exit;
    }

    elseif ($action === 'address_delete' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        $aid = Security::int($_POST['id'] ?? 0);
        Customers::deleteAddress($cid, $aid);
        header('Location: ' . Template::storeUrl('/account.php?p=addresses&ok=1'));
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
        } elseif (!Security::rateLimit('account_change_password_' . $cid, 5, 900, false)
            || !Customers::passwordMatches($cid, Security::str($_POST['password'] ?? ''))) {
            // A hijacked or unattended session alone must not be able to erase the account irreversibly.
            $flash = $t('account.err_invalid_current_password');
            $flashType = 'error';
            $p = 'privacy';
        } else {
            try {
                GdprService::anonymizeCustomer($cid, 'self');
                Customers::logout();
                Security::sessionStart();
                $_SESSION['gdpr_erased'] = 1;
                header('Location: ' . Template::storeUrl('/account.php?p=login&erased=1'));
                exit;
            } catch (Throwable $ex) {
                $errorId = bin2hex(random_bytes(6));
                error_log("GDPR erase failed [{$errorId}] for customer #{$cid}: " . $ex->getMessage());
                $flash = $t('account.gdpr_erase_failed') . " ({$errorId})";
                $flashType = 'error';
                $p = 'privacy';
            }
        }
    }

    elseif ($action === 'reorder' && Customers::isLoggedIn()) {
        $cid = (int)$_SESSION['customer_id'];
        $orderId = Security::int($_POST['order_id'] ?? 0);
        if (!Customers::order($cid, $orderId)) {
            $flash = $t('account.order_not_found');
            $flashType = 'error';
            $p = 'orders';
        } else {
            $result = Cart::reorder(Customers::orderItems($cid, $orderId));
            $message = $result['added'] ? sprintf($t('account.reorder_added'), count($result['added'])) : $t('account.reorder_none');
            if ($result['skipped']) $message .= ' ' . $t('account.reorder_skipped') . ': ' . implode(', ', $result['skipped']);
            $_SESSION['account_flash'] = ['message' => $message, 'type' => $result['added'] ? 'ok' : 'error'];
            header('Location: ' . Template::storeUrl('/account.php?p=order&id=' . $orderId));
            exit;
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
        header('Location: ' . Template::storeUrl('/account.php?p=returns&msg=' . urlencode($ok ? 'ok' : (string)$err)));
        exit;
    }

    elseif ($action === 'download' && Customers::isLoggedIn()) {
        // The e-mailed token is stored only as a hash, so the account issues a fresh link for its own entitlement.
        $url = DigitalDelivery::reissueForCustomer((int)$_SESSION['customer_id'], Security::int($_POST['id'] ?? 0));
        if ($url !== null) {
            header('Location: ' . $url, true, 303);
            exit;
        }
        $flash = $t('account.download_unavailable');
        $flashType = 'error';
        $p = 'downloads';
    }

    elseif ($action === 'cookie_consent') {
        $cats = [
            'necessary' => true,
            'analytics' => !empty($_POST['analytics']),
            'marketing' => !empty($_POST['marketing']),
        ];
        $method = (string)($_POST['method'] ?? 'preferences');
        if (!in_array($method, ['banner_accept_all', 'banner_reject_all', 'preferences'], true)) $method = 'preferences';
        // The banner's accept/reject buttons (no-JS form submit) carry no checkboxes: the button decides.
        if ($method !== 'preferences') $cats['analytics'] = $cats['marketing'] = $method === 'banner_accept_all';
        $cid = Customers::isLoggedIn() ? (int)$_SESSION['customer_id'] : null;
        // Same consent log as the banner's cookie_consent API action.
        GdprService::logCookieConsent([
            'session_id'  => Security::sessionKey() ?: null,
            'customer_id' => $cid,
            'categories'  => $cats,
            'method'      => $method,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        // The banner (storefront.js) and MarketingConsent read cc_v1: same name, value and path as the banner writes.
        $cookieOptions = [
            'expires'  => time() + 31536000, // 12 months
            'path'     => Template::baseUrl() ?: '/',
            'secure'   => Security::requestIsHttps(),
            'httponly' => false,
            'samesite' => 'Lax',
        ];
        setcookie('cc_v1', (string)json_encode($cats), $cookieOptions);
        // Older releases stored the account choice under cookie_consent, which MarketingConsent still honours.
        if (isset($_COOKIE['cookie_consent'])) {
            setcookie('cookie_consent', '', ['expires' => 1] + $cookieOptions);
            setcookie('cookie_consent', '', ['expires' => 1, 'path' => '/'] + $cookieOptions);
        }
        // The banner returns to the page it covered, which shows no account flash.
        if (($_POST['source'] ?? '') !== 'banner') $_SESSION['account_flash'] = ['message' => $t('account.saved'), 'type' => 'ok'];
        header('Location: ' . Security::safeLocalPath($_POST['return_to'] ?? null, Template::storeUrl('/account.php?p=privacy')));
        exit;
    }
}

// ============================================================
// E-mail verification link (works signed in or out; token is single-use)
// ============================================================
$loggedIn = Customers::isLoggedIn();
if ($p === 'verify_email') {
    $token = Security::str($_GET['token'] ?? '');
    $verified = Security::rateLimit('account_verify_email_ip', 20, 3600) && Customers::verifyEmail($token) !== null;
    $flash = $t($verified ? 'account.verify_email_done' : 'account.verify_email_invalid');
    $flashType = $verified ? 'ok' : 'error';
    $p = $loggedIn ? 'dashboard' : 'login';
}

// ============================================================
// Auth gate for private pages
// ============================================================
$publicPages = ['login', 'register', 'forgot', 'reset'];
if (!$loggedIn && !in_array($p, $publicPages, true)) {
    $next = $_SERVER['REQUEST_URI'] ?? 'account.php';
    header('Location: ' . Template::storeUrl('/account.php?p=login&next=' . urlencode($next)));
    exit;
}
if ($loggedIn && in_array($p, ['login', 'register', 'forgot'], true)) {
    header('Location: ' . Template::storeUrl('/account.php'));
    exit;
}
if ($p === '') $p = 'dashboard';

$customer = $loggedIn ? Customers::current() : null;
$csrf = Security::csrfToken();
if ($flash === null && isset($_SESSION['account_flash'])) {
    ['message' => $flash, 'type' => $flashType] = $_SESSION['account_flash'];
    unset($_SESSION['account_flash']);
}
// The signed-in customer's own invoice needs no e-mailed link.
if ($customer && $p === 'invoice_pdf') {
    $inv = InvoiceService::ownedBy((int)$customer['id'], Security::int($_GET['id'] ?? 0));
    if ($inv === null) {
        http_response_code(404);
        exit('Invoice not found.');
    }
    InvoiceService::sendPdf($inv);
}
// Only the customer's own order in this store; any other id is indistinguishable from a missing one.
$order = $customer && $p === 'order' ? Customers::order((int)$customer['id'], Security::int($_GET['id'] ?? 0)) : null;
if (($p === 'order' && $order === null) || !in_array($p, AccountViewModel::PAGES, true)) {
    http_response_code(404);
    $p = 'unknown';
}

// ============================================================
// Render through the active theme and shared storefront shell
// ============================================================
$viewData = AccountViewModel::build($p, $customer, $supported, $fieldValues, $flash, $flashType, $order, $fieldErrors);
$content = Template::page('account', $viewData);
$title = Template::pageTitle($t('account.title'));
$cartCount = StorefrontCartAction::count();
$catName = static fn(string $name): string => Bootstrap::categoryName($name, $locale);
$lang = $locale;
$page = 'account';
require __DIR__ . '/Layout.php';
