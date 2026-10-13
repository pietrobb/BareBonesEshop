<?php
/**
 * BareBones Shop — Customer Accounts
 *
 * Handles registration, login, logout, password reset, profile and address
 * management. Uses PHP sessions (Security::sessionStart()) and bcrypt password
 * hashing (cost 12). Guest checkout remains supported; orders.customer_id is
 * nullable.
 */
class Customers {

    private const BCRYPT_COST = 12;
    private const RESET_TOKEN_TTL_SECONDS = 3600; // 1 hour
    private const EMAIL_VERIFY_TTL_SECONDS = 86400; // 24 hours
    private const SESSION_IDLE_TTL_SECONDS = 1800;
    private const MIN_PASSWORD_LEN = 8;
    private static ?string $dummyPasswordHash = null;

    // ============================================================
    // Registration
    // ============================================================

    /**
     * Register a new customer. Returns [success, id|null, error|null].
     * Throws nothing; all errors returned as strings for form display.
     */
    public static function register(string $email, string $password, string $name = '', array $extras = []): array {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, null, 'invalid_email'];
        }
        if (strlen($password) < self::MIN_PASSWORD_LEN) {
            return [false, null, 'weak_password'];
        }
        $db = Db::get();
        $stmt = $db->prepare("SELECT id FROM customers WHERE email = ? AND store_id = ?");
        $stmt->execute([$email, StoreContext::current()->id()]);
        if ($stmt->fetchColumn()) {
            return [false, null, 'email_taken'];
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        $locale = $extras['locale'] ?? I18n::locale();
        $phone = trim($extras['phone'] ?? '');
        $ins = $db->prepare(
            "INSERT INTO customers (email, password_hash, name, phone, locale, status, store_id)
             VALUES (?, ?, ?, ?, ?, 'active', ?)"
        );
        $db->beginTransaction();
        try {
            $ins->execute([$email, $hash, trim($name), $phone, $locale, StoreContext::current()->id()]);
            $id = (int)$db->lastInsertId();
            Outbox::record(DomainEvents::CUSTOMER_CREATED, 'customer', $id, [
                'customer_id' => $id,
                'email' => $email,
                'name' => trim($name),
                'locale' => (string)$locale,
                'status' => 'active',
            ]);
            $db->commit();
            return [true, $id, null];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e instanceof PDOException && in_array($e->getCode(), ['23000', '23505'], true)) {
                return [false, null, 'email_taken'];
            }
            return [false, null, 'internal_error'];
        }
    }

    // ============================================================
    // Login / Logout
    // ============================================================

    /**
     * Attempt to log in. Returns [success, customer|null, error|null].
     * On success, populates $_SESSION['customer_id'] and regenerates the session id.
     */
    public static function login(string $email, string $password): array {
        $email = strtolower(trim($email));
        $db = Db::get();
        $stmt = $db->prepare("SELECT * FROM customers WHERE email = ? AND store_id = ?");
        $stmt->execute([$email, StoreContext::current()->id()]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (self::$dummyPasswordHash === null) {
            self::$dummyPasswordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        }
        $passwordValid = password_verify($password, (string)($c['password_hash'] ?? self::$dummyPasswordHash));
        if (!$c || ($c['status'] ?? 'active') !== 'active' || !$passwordValid) {
            return [false, null, 'invalid_credentials'];
        }
        // Rehash if PHP bumped defaults
        if (password_needs_rehash($c['password_hash'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            $new = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
            $db->prepare("UPDATE customers SET password_hash = ? WHERE id = ? AND store_id = ?")
               ->execute([$new, $c['id'], StoreContext::current()->id()]);
        }
        // Session setup
        Security::sessionStart();
        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
        // A pre-login CSRF token (possibly planted together with the session) must not survive authentication.
        Security::rotateCsrfToken();
        $_SESSION['customer_id'] = (int)$c['id'];
        $_SESSION['customer_email'] = $c['email'];
        $_SESSION['customer_auth_hash'] = hash('sha256', (string)$c['password_hash']);
        $_SESSION['customer_last_seen'] = time();
        $db->prepare("UPDATE customers SET last_login_at = " .
            (Db::isSqlite() ? "datetime('now')" : "NOW()") . " WHERE id = ? AND store_id = ?")
           ->execute([$c['id'], StoreContext::current()->id()]);
        unset($c['password_hash']);
        return [true, $c, null];
    }

    public static function logout(): void {
        Security::sessionStart();
        unset($_SESSION['customer_id'], $_SESSION['customer_email'], $_SESSION['customer_auth_hash'], $_SESSION['customer_last_seen']);
        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
        Security::rotateCsrfToken();
    }

    // ============================================================
    // Current customer
    // ============================================================

    public static function current(): ?array {
        Security::sessionStart();
        $id = (int)($_SESSION['customer_id'] ?? 0);
        $lastSeen = (int)($_SESSION['customer_last_seen'] ?? 0);
        $sessionHash = (string)($_SESSION['customer_auth_hash'] ?? '');
        if ($id <= 0 || $lastSeen < time() - self::SESSION_IDLE_TTL_SECONDS || $sessionHash === '') {
            if ($id > 0) self::logout();
            return null;
        }
        $stmt = Db::get()->prepare("SELECT status, password_hash FROM customers WHERE id = ? AND store_id = ?");
        $stmt->execute([$id, StoreContext::current()->id()]);
        $auth = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$auth || ($auth['status'] ?? '') !== 'active'
            || !hash_equals(hash('sha256', (string)$auth['password_hash']), $sessionHash)) {
            self::logout();
            return null;
        }
        $_SESSION['customer_last_seen'] = time();
        return self::byId($id);
    }

    public static function isLoggedIn(): bool {
        return self::current() !== null;
    }

    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare(
            "SELECT id, email, name, phone, locale, status, email_verified_at,
                    last_login_at, created_at, updated_at
             FROM customers WHERE id = ? AND store_id = ?"
        );
        $stmt->execute([$id, StoreContext::current()->id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function byEmail(string $email): ?array {
        $stmt = Db::get()->prepare(
            "SELECT id, email, name, phone, locale, status, email_verified_at,
                    last_login_at, created_at, updated_at
             FROM customers WHERE email = ? AND store_id = ?"
        );
        $stmt->execute([strtolower(trim($email)), StoreContext::current()->id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ============================================================
    // Update profile
    // ============================================================

    public static function update(int $id, array $data): bool {
        $allowed = ['name', 'phone', 'locale', 'vat_id', 'vat_country', 'vat_validated_at'];
        $set = [];
        $params = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $set[] = "{$k} = ?";
                $params[] = $data[$k];
            }
        }
        if (!$set) return false;
        $set[] = "updated_at = " . (Db::isSqlite() ? "datetime('now')" : "NOW()");
        $params[] = $id;
        $params[] = StoreContext::current()->id();
        $sql = "UPDATE customers SET " . implode(', ', $set) . " WHERE id = ? AND store_id = ?";
        $db = Db::get();
        $db->beginTransaction();
        try {
            $updated = $db->prepare($sql)->execute($params);
            if ($updated) {
                Outbox::record(DomainEvents::CUSTOMER_UPDATED, 'customer', $id, [
                    'customer_id' => $id,
                    'changed_fields' => array_keys(array_intersect_key($data, array_flip($allowed))),
                ]);
            }
            $db->commit();
            return $updated;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function changePassword(int $id, string $currentPassword, string $newPassword): array {
        if (strlen($newPassword) < self::MIN_PASSWORD_LEN) {
            return [false, 'weak_password'];
        }
        $stmt = Db::get()->prepare("SELECT password_hash FROM customers WHERE id = ? AND store_id = ?");
        $stmt->execute([$id, StoreContext::current()->id()]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($currentPassword, $hash)) {
            return [false, 'invalid_current_password'];
        }
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        Db::get()->prepare("UPDATE customers SET password_hash = ? WHERE id = ? AND store_id = ?")
                 ->execute([$newHash, $id, StoreContext::current()->id()]);
        Security::sessionStart();
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
        $_SESSION['customer_auth_hash'] = hash('sha256', $newHash);
        $_SESSION['customer_last_seen'] = time();
        return [true, null];
    }

    // ============================================================
    // Password reset
    // ============================================================

    /**
     * Start password reset. Generates a one-time token (1h expiry).
     * Returns the token string (caller must send via MailService).
     * Returns null if email does not match an active account — caller should
     * still display a generic success message to avoid enumeration.
     */
    public static function startPasswordReset(string $email): ?string {
        $email = strtolower(trim($email));
        $db = Db::get();
        $stmt = $db->prepare("SELECT id FROM customers WHERE email = ? AND store_id = ? AND status = 'active'");
        $stmt->execute([$email, StoreContext::current()->id()]);
        $cid = (int)$stmt->fetchColumn();
        if ($cid <= 0) return null;
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::RESET_TOKEN_TTL_SECONDS);
        // Store only the SHA-256 hash; the plaintext token lives solely in the
        // emailed reset URL. A DB leak then cannot be used to hijack resets.
        $tokenHash = hash('sha256', $token);
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM password_reset_tokens WHERE customer_id = ? AND used_at IS NULL")
               ->execute([$cid]);
            $db->prepare(
                "INSERT INTO password_reset_tokens (customer_id, token, expires_at)
                 VALUES (?, ?, ?)"
            )->execute([$cid, $tokenHash, $expiresAt]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return null;
        }
        return $token;
    }

    /**
     * Forgot-password request. Callers show one generic result whatever happens;
     * the outcome (sent|unknown|unavailable|failed) is only for logs and tests.
     */
    public static function requestPasswordReset(string $email, string $locale, array $cfg): string {
        try {
            $baseUrl = Security::appUrl($cfg);
        } catch (RuntimeException $e) {
            // Never fall back to the Host header (reset-link poisoning): fail closed, but visibly.
            Logger::error('password_reset.app_url_invalid', ['message' => $e->getMessage()]);
            return 'unavailable';
        }
        $token = self::startPasswordReset($email);
        if ($token === null) return 'unknown';
        // Keep the store prefix: tokens are store-scoped, so the link must reopen the same store.
        $resetUrl = $baseUrl . Template::seoPath(Template::storeUrl('/account.php')) . '?p=reset&token=' . rawurlencode($token);
        $mail = MailService::send('account_password_reset', $email, $locale, [
            'customer_name' => $email,
            'reset_url'     => $resetUrl,
            'expires_in'    => '1',
        ]);
        if (($mail['status'] ?? '') !== 'sent') {
            Logger::error('password_reset.mail_not_sent', ['status' => $mail['status'] ?? '', 'error' => $mail['error'] ?? '']);
            return 'failed';
        }
        return 'sent';
    }

    /** Issues a verification token for an unverified active customer; earlier unused tokens are dropped. */
    public static function startEmailVerification(int $customerId): ?string {
        $db = Db::get();
        $stmt = $db->prepare("SELECT email, email_verified_at FROM customers WHERE id = ? AND store_id = ? AND status = 'active'");
        $stmt->execute([$customerId, StoreContext::current()->id()]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c || !empty($c['email_verified_at'])) return null;
        $token = bin2hex(random_bytes(32));
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM customer_email_verifications WHERE customer_id = ? AND used_at IS NULL')->execute([$customerId]);
            $db->prepare('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)')
               ->execute([$customerId, (string)$c['email'], hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + self::EMAIL_VERIFY_TTL_SECONDS)]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            Logger::error('email_verification.start_failed', ['customer_id' => $customerId, 'message' => $e->getMessage()]);
            return null;
        }
        return $token;
    }

    /**
     * Sends (or re-sends) the verification e-mail. Outcome: sent|verified|unknown|throttled|unavailable|failed.
     * Re-sends are throttled per customer so the address cannot be mail-bombed through the account.
     */
    public static function requestEmailVerification(int $customerId, string $locale, array $cfg): string {
        $customer = self::byId($customerId);
        if (!$customer || ($customer['status'] ?? '') !== 'active') return 'unknown';
        if (!empty($customer['email_verified_at'])) return 'verified';
        if (!Security::rateLimit('account_email_verify_' . $customerId, 3, 3600, false)) return 'throttled';
        try {
            $baseUrl = Security::appUrl($cfg);
        } catch (RuntimeException $e) {
            Logger::error('email_verification.app_url_invalid', ['message' => $e->getMessage()]);
            return 'unavailable';
        }
        $token = self::startEmailVerification($customerId);
        if ($token === null) return 'failed';
        $url = $baseUrl . Template::seoPath(Template::storeUrl('/account.php')) . '?p=verify_email&token=' . rawurlencode($token);
        $mail = MailService::send('account_email_verification', (string)$customer['email'], $locale, [
            'customer_name' => (string)(($customer['name'] ?? '') ?: $customer['email']),
            'verify_url'    => $url,
            'expires_in'    => (string)intdiv(self::EMAIL_VERIFY_TTL_SECONDS, 3600),
        ]);
        if (($mail['status'] ?? '') !== 'sent') {
            Logger::error('email_verification.mail_not_sent', ['status' => $mail['status'] ?? '', 'error' => $mail['error'] ?? '']);
            return 'failed';
        }
        return 'sent';
    }

    /** Consumes a verification token once; it only verifies the address it was issued for. Returns the customer ID. */
    public static function verifyEmail(string $token): ?int {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $db = Db::get();
        $hash = hash('sha256', $token);
        $now = gmdate('Y-m-d H:i:s');
        $db->beginTransaction();
        try {
            $find = $db->prepare("SELECT v.customer_id FROM customer_email_verifications v
                JOIN customers c ON c.id = v.customer_id
                WHERE v.token_hash = ? AND v.used_at IS NULL AND v.expires_at > ? AND c.store_id = ? AND c.email = v.email AND c.status = 'active'");
            $find->execute([$hash, $now, StoreContext::current()->id()]);
            $cid = (int)$find->fetchColumn();
            $consume = $db->prepare('UPDATE customer_email_verifications SET used_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?');
            if ($cid <= 0 || !$consume->execute([$now, $hash, $now]) || $consume->rowCount() !== 1) {
                $db->rollBack();
                return null;
            }
            $db->prepare('UPDATE customers SET email_verified_at = ? WHERE id = ? AND store_id = ? AND email_verified_at IS NULL')
               ->execute([$now, $cid, StoreContext::current()->id()]);
            $db->commit();
            return $cid;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            Logger::error('email_verification.verify_failed', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public static function verifyResetToken(string $token): ?int {
        $stmt = Db::get()->prepare(
            "SELECT prt.customer_id, prt.expires_at, prt.used_at
             FROM password_reset_tokens prt
             JOIN customers c ON c.id = prt.customer_id
             WHERE prt.token = ? AND c.store_id = ?"
        );
        $stmt->execute([hash('sha256', $token), StoreContext::current()->id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!empty($row['used_at'])) return null;
        if (strtotime($row['expires_at'] . ' UTC') <= time()) return null;
        return (int)$row['customer_id'];
    }

    public static function completePasswordReset(string $token, string $newPassword): array {
        if (strlen($newPassword) < self::MIN_PASSWORD_LEN) {
            return [false, 'weak_password'];
        }
        $db = Db::get();
        $tokenHash = hash('sha256', $token);
        $now = gmdate('Y-m-d H:i:s');
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        $db->beginTransaction();
        try {
            $find = $db->prepare(
                "SELECT prt.customer_id FROM password_reset_tokens prt
                 JOIN customers c ON c.id = prt.customer_id
                 WHERE prt.token = ? AND c.store_id = ? AND prt.used_at IS NULL AND prt.expires_at > ?"
            );
            $find->execute([$tokenHash, StoreContext::current()->id(), $now]);
            $cid = (int)$find->fetchColumn();
            if ($cid <= 0) {
                $db->rollBack();
                return [false, 'invalid_or_expired_token'];
            }
            $consume = $db->prepare(
                "UPDATE password_reset_tokens SET used_at = " .
                (Db::isSqlite() ? "datetime('now')" : "NOW()") .
                " WHERE token = ? AND used_at IS NULL AND expires_at > ?"
            );
            $consume->execute([$tokenHash, $now]);
            if ($consume->rowCount() !== 1) {
                $db->rollBack();
                return [false, 'invalid_or_expired_token'];
            }
            $db->prepare("UPDATE customers SET password_hash = ? WHERE id = ? AND store_id = ?")
               ->execute([$hash, $cid, StoreContext::current()->id()]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return [false, 'internal_error'];
        }
        return [true, null];
    }

    // ============================================================
    // Addresses
    // ============================================================

    public static function addresses(int $customerId, ?string $type = null): array {
        $sql = "SELECT * FROM customer_addresses WHERE customer_id = ?";
        $params = [$customerId];
        if ($type !== null) {
            $sql .= " AND type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY is_default DESC, id DESC";
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function defaultAddress(int $customerId, string $type = 'shipping'): ?array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM customer_addresses
             WHERE customer_id = ? AND type = ?
             ORDER BY is_default DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$customerId, $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function addAddress(int $customerId, array $data): int {
        $type = in_array(($data['type'] ?? 'shipping'), ['shipping', 'billing'], true)
            ? $data['type'] : 'shipping';
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $db = Db::get();
        if ($isDefault) {
            $db->prepare("UPDATE customer_addresses SET is_default = 0
                           WHERE customer_id = ? AND type = ?")
               ->execute([$customerId, $type]);
        }
        $ins = $db->prepare(
            "INSERT INTO customer_addresses
             (customer_id, type, is_default, name, company, vat_id, street, city, zip, country, phone)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([
            $customerId, $type, $isDefault,
            trim(Security::str($data['name'] ?? '')),
            trim(Security::str($data['company'] ?? '')),
            trim(Security::str($data['vat_id'] ?? '')),
            trim(Security::str($data['street'] ?? '')),
            trim(Security::str($data['city'] ?? '')),
            trim(Security::str($data['zip'] ?? '')),
            strtoupper(trim(Security::str($data['country'] ?? ''))),
            trim(Security::str($data['phone'] ?? '')),
        ]);
        return (int)$db->lastInsertId();
    }

    public static function updateAddress(int $customerId, int $addressId, array $data): bool {
        // Ownership check
        $stmt = Db::get()->prepare(
            "SELECT type FROM customer_addresses WHERE id = ? AND customer_id = ?"
        );
        $stmt->execute([$addressId, $customerId]);
        $existingType = $stmt->fetchColumn();
        if ($existingType === false) return false;
        $type = in_array(($data['type'] ?? $existingType), ['shipping', 'billing'], true)
            ? $data['type'] : $existingType;
        $isDefault = array_key_exists('is_default', $data) ? (!empty($data['is_default']) ? 1 : 0) : null;
        $db = Db::get();
        if ($isDefault === 1) {
            $db->prepare("UPDATE customer_addresses SET is_default = 0
                           WHERE customer_id = ? AND type = ? AND id <> ?")
               ->execute([$customerId, $type, $addressId]);
        }
        $fields = [];
        $params = [];
        foreach (['name','company','vat_id','street','city','zip','country','phone'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "{$k} = ?";
                $params[] = ($k === 'country')
                    ? strtoupper(trim(Security::str($data[$k])))
                    : trim(Security::str($data[$k]));
            }
        }
        $fields[] = "type = ?"; $params[] = $type;
        if ($isDefault !== null) { $fields[] = "is_default = ?"; $params[] = $isDefault; }
        $fields[] = "updated_at = " . (Db::isSqlite() ? "datetime('now')" : "NOW()");
        $params[] = $addressId;
        $params[] = $customerId;
        $sql = "UPDATE customer_addresses SET " . implode(', ', $fields)
             . " WHERE id = ? AND customer_id = ?";
        return $db->prepare($sql)->execute($params);
    }

    public static function deleteAddress(int $customerId, int $addressId): bool {
        $stmt = Db::get()->prepare(
            "DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?"
        );
        return $stmt->execute([$addressId, $customerId]) && $stmt->rowCount() > 0;
    }

    // ============================================================
    // Order history
    // ============================================================

    public static function orders(int $customerId, int $limit = 50, int $offset = 0): array {
        $stmt = Db::get()->prepare(
            "SELECT id, order_number, status, payment_status, total, currency,
                    tracking_number, carrier, created_at
             FROM orders
             WHERE customer_id = ? AND store_id = ?
             ORDER BY id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$customerId, StoreContext::current()->id(), $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function orderCount(int $customerId): int {
        $stmt = Db::get()->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND store_id = ?");
        $stmt->execute([$customerId, StoreContext::current()->id()]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Fetch a single order scoped to this customer. Returns null if order
     * belongs to another customer (or is guest-owned).
     */
    public static function order(int $customerId, int $orderId): ?array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM orders WHERE id = ? AND customer_id = ? AND store_id = ?"
        );
        $stmt->execute([$orderId, $customerId, StoreContext::current()->id()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ============================================================
    // B2B commercial profile
    // ============================================================

    public static function commerceProfile(?int $customerId): ?array {
        if ($customerId === null || $customerId <= 0) return null;
        try {
            $stmt = Db::get()->prepare("SELECT
                    c.id AS customer_id, c.customer_group_id, c.company_id,
                    cg.code AS customer_group_code, cg.discount_bps,
                    co.code AS company_code, co.legal_name, co.vat_id, co.vat_country,
                    co.vat_validated_at, co.approval_status, co.credit_limit,
                    co.payment_terms_days, co.invoice_purchase, co.active AS company_active
                FROM customers c
                LEFT JOIN customer_groups cg ON cg.id = c.customer_group_id AND cg.store_id = c.store_id AND cg.active = 1
                LEFT JOIN companies co ON co.id = c.company_id AND co.store_id = c.store_id
                WHERE c.id = :id AND c.store_id = :store");
            $stmt->execute([':id' => $customerId, ':store' => StoreContext::current()->id()]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    public static function canInvoicePurchase(int $customerId, int $amount): bool {
        if ($amount < 0) return false;
        $profile = self::commerceProfile($customerId);
        if (!$profile
            || empty($profile['company_active'])
            || ($profile['approval_status'] ?? '') !== 'approved'
            || empty($profile['invoice_purchase'])
            || (int)($profile['payment_terms_days'] ?? 0) <= 0) {
            return false;
        }

        $creditLimit = max(0, (int)($profile['credit_limit'] ?? 0));
        if ($creditLimit === 0) return true;
        $stmt = Db::get()->prepare("SELECT COALESCE(SUM(o.total), 0)
            FROM orders o
            JOIN customers c ON c.id = o.customer_id AND c.store_id = o.store_id
            WHERE o.store_id = :store AND c.company_id = :company
              AND o.status <> 'cancelled'
              AND COALESCE(o.payment_status, 'pending') NOT IN ('paid', 'refunded', 'cancelled', 'failed')");
        $stmt->execute([
            ':store' => StoreContext::current()->id(),
            ':company' => (int)$profile['company_id'],
        ]);
        return (int)$stmt->fetchColumn() + $amount <= $creditLimit;
    }

    // ============================================================
    // Admin customer management
    // ============================================================

    /** Account statuses an admin may toggle between (terminal 'anonymized' excluded). */
    public const ADMIN_STATUSES = ['active', 'blocked'];

    /**
     * List customers for the admin UI, optionally filtered by a free-text query
     * matching email, name, or numeric id. Newest first.
     */
    public static function adminList(string $query = '', int $limit = 50): array {
        $query = trim($query);
        $sql = "SELECT id, email, name, status, created_at, last_login_at FROM customers WHERE store_id = ?";
        $params = [StoreContext::current()->id()];
        if ($query !== '') {
            $sql .= " AND (LOWER(email) LIKE LOWER(?) ESCAPE '!' OR LOWER(name) LIKE LOWER(?) ESCAPE '!'";
            $like = '%' . Db::likeLiteral($query) . '%';
            $params[] = $like;
            $params[] = $like;
            if (ctype_digit($query)) {
                $sql .= " OR id = ?";
                $params[] = (int)$query;
            }
            $sql .= ')';
        }
        $sql .= " ORDER BY id DESC LIMIT ?";
        $params[] = $limit;
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Set an account's status. Only 'active' / 'blocked' are permitted, and an
     * already-anonymized (GDPR-erased) account can never be reactivated here.
     * Returns true when a row was updated.
     */
    public static function setStatus(int $id, string $status): bool {
        if ($id <= 0 || !in_array($status, self::ADMIN_STATUSES, true)) {
            return false;
        }
        $current = self::byId($id);
        if ($current === null || ($current['status'] ?? '') === 'anonymized') {
            return false;
        }
        $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
        $stmt = Db::get()->prepare("UPDATE customers SET status = ?, updated_at = {$now} WHERE id = ? AND store_id = ?");
        $stmt->execute([$status, $id, StoreContext::current()->id()]);
        return $stmt->rowCount() > 0;
    }
}
