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
    private const MIN_PASSWORD_LEN = 8;

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
        $stmt = $db->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            return [false, null, 'email_taken'];
        }
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        $locale = $extras['locale'] ?? I18n::locale();
        $phone = trim($extras['phone'] ?? '');
        $ins = $db->prepare(
            "INSERT INTO customers (email, password_hash, name, phone, locale, status)
             VALUES (?, ?, ?, ?, ?, 'active')"
        );
        $ins->execute([$email, $hash, trim($name), $phone, $locale]);
        $id = (int)$db->lastInsertId();
        return [true, $id, null];
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
        $stmt = $db->prepare("SELECT * FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            return [false, null, 'invalid_credentials'];
        }
        if (($c['status'] ?? 'active') !== 'active') {
            return [false, null, 'account_blocked'];
        }
        if (!password_verify($password, $c['password_hash'])) {
            return [false, null, 'invalid_credentials'];
        }
        // Rehash if PHP bumped defaults
        if (password_needs_rehash($c['password_hash'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            $new = password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
            $db->prepare("UPDATE customers SET password_hash = ? WHERE id = ?")
               ->execute([$new, $c['id']]);
        }
        // Session setup
        Security::sessionStart();
        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
        $_SESSION['customer_id'] = (int)$c['id'];
        $_SESSION['customer_email'] = $c['email'];
        $db->prepare("UPDATE customers SET last_login_at = " .
            (Db::isSqlite() ? "datetime('now')" : "NOW()") . " WHERE id = ?")
           ->execute([$c['id']]);
        unset($c['password_hash']);
        return [true, $c, null];
    }

    public static function logout(): void {
        Security::sessionStart();
        unset($_SESSION['customer_id'], $_SESSION['customer_email']);
        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
    }

    // ============================================================
    // Current customer
    // ============================================================

    public static function current(): ?array {
        Security::sessionStart();
        $id = (int)($_SESSION['customer_id'] ?? 0);
        if ($id <= 0) return null;
        return self::byId($id);
    }

    public static function isLoggedIn(): bool {
        Security::sessionStart();
        return !empty($_SESSION['customer_id']);
    }

    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare(
            "SELECT id, email, name, phone, locale, status, email_verified_at,
                    last_login_at, created_at, updated_at
             FROM customers WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function byEmail(string $email): ?array {
        $stmt = Db::get()->prepare(
            "SELECT id, email, name, phone, locale, status, email_verified_at,
                    last_login_at, created_at, updated_at
             FROM customers WHERE email = ?"
        );
        $stmt->execute([strtolower(trim($email))]);
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
        $sql = "UPDATE customers SET " . implode(', ', $set) . " WHERE id = ?";
        return Db::get()->prepare($sql)->execute($params);
    }

    public static function changePassword(int $id, string $currentPassword, string $newPassword): array {
        if (strlen($newPassword) < self::MIN_PASSWORD_LEN) {
            return [false, 'weak_password'];
        }
        $stmt = Db::get()->prepare("SELECT password_hash FROM customers WHERE id = ?");
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($currentPassword, $hash)) {
            return [false, 'invalid_current_password'];
        }
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        Db::get()->prepare("UPDATE customers SET password_hash = ? WHERE id = ?")
                 ->execute([$newHash, $id]);
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
        $stmt = $db->prepare("SELECT id FROM customers WHERE email = ? AND status = 'active'");
        $stmt->execute([$email]);
        $cid = (int)$stmt->fetchColumn();
        if ($cid <= 0) return null;
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::RESET_TOKEN_TTL_SECONDS);
        $db->prepare(
            "INSERT INTO password_reset_tokens (customer_id, token, expires_at)
             VALUES (?, ?, ?)"
        )->execute([$cid, $token, $expiresAt]);
        return $token;
    }

    public static function verifyResetToken(string $token): ?int {
        $stmt = Db::get()->prepare(
            "SELECT customer_id, expires_at, used_at
             FROM password_reset_tokens WHERE token = ?"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!empty($row['used_at'])) return null;
        if (strtotime($row['expires_at']) < time()) return null;
        return (int)$row['customer_id'];
    }

    public static function completePasswordReset(string $token, string $newPassword): array {
        if (strlen($newPassword) < self::MIN_PASSWORD_LEN) {
            return [false, 'weak_password'];
        }
        $cid = self::verifyResetToken($token);
        if (!$cid) {
            return [false, 'invalid_or_expired_token'];
        }
        $db = Db::get();
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE customers SET password_hash = ? WHERE id = ?")
               ->execute([$hash, $cid]);
            $db->prepare(
                "UPDATE password_reset_tokens SET used_at = " .
                (Db::isSqlite() ? "datetime('now')" : "NOW()") . " WHERE token = ?"
            )->execute([$token]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
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
            trim($data['name'] ?? ''),
            trim($data['company'] ?? ''),
            trim($data['vat_id'] ?? ''),
            trim($data['street'] ?? ''),
            trim($data['city'] ?? ''),
            trim($data['zip'] ?? ''),
            strtoupper(trim($data['country'] ?? '')),
            trim($data['phone'] ?? ''),
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
                    ? strtoupper(trim($data[$k]))
                    : trim($data[$k]);
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
             WHERE customer_id = ?
             ORDER BY id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$customerId, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function orderCount(int $customerId): int {
        $stmt = Db::get()->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Fetch a single order scoped to this customer. Returns null if order
     * belongs to another customer (or is guest-owned).
     */
    public static function order(int $customerId, int $orderId): ?array {
        $stmt = Db::get()->prepare(
            "SELECT * FROM orders WHERE id = ? AND customer_id = ?"
        );
        $stmt->execute([$orderId, $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
