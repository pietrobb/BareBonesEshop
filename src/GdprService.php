<?php
/**
 * BareBones Shop — GDPR compliance service
 *
 * Orchestrates GDPR-relevant operations:
 *   - exportCustomer()       — Subject Access Request (SAR) JSON dump
 *   - anonymizeCustomer()    — Right-to-erasure Tier A (soft anonymize, retain legal records)
 *   - applyRetention()       — Policy-driven cleanup (delete / scrub_pii / hash_email)
 *   - logEvent()             — Append-only audit trail (gdpr_events)
 *   - logCookieConsent()     — Persist banner/preferences choice
 *   - hashPii()              — Deterministic salted SHA-256 for emails/IPs
 *
 * Legal basis: GDPR Art.15 (access), Art.17 (erasure), Art.21 (objection),
 * Art.5(1)(e) (storage limitation), Art.7 (consent records).
 *
 * Retention exception: orders.customer_* and invoices.customer_snapshot are
 * preserved for 10 years per SK Act 431/2002 §35 (accounting law). Anonymization
 * of those fields happens via cli/gdpr-gc.php after the 10-year window.
 */
class GdprService {
    private const CUSTOMER_ORDERS = "SELECT o.id FROM orders o
        WHERE o.store_id = :order_store AND (o.customer_id = :customer_id
            OR ((:verified = '1' OR :guest = '1') AND o.customer_id IS NULL AND LOWER(o.customer_email) = LOWER(:owner_email)))";
    // Carts are keyed by e-mail only: unverified accounts own just the carts that became their orders.
    private const CUSTOMER_CARTS = "LOWER(customer_email) = LOWER(:cart_email) AND (store_id = :cart_store OR store_id IS NULL)
              AND (:cart_verified = '1' OR order_id IN (" . self::CUSTOMER_ORDERS . "))";

    /**
     * PII registry: every table holding a customer's personal data, what erasure does with it and
     * which exportCustomer() section carries it. Both anonymizeCustomer() and exportCustomer() refuse
     * to finish when a registered table has no handler, and the schema walk in
     * AuditOpsGdprErpRegression fails for a table with personal-data columns that is neither here
     * nor in PII_EXEMPT_TABLES.
     *   erase:  anonymize | delete | scrub | retain (legal hold, 'reason' says why)
     *   export: section key of exportCustomer(), or null ('reason' says why)
     */
    public const PII_REGISTRY = [
        'customers'                    => ['columns' => ['email', 'name', 'phone', 'vat_id'], 'erase' => 'anonymize', 'export' => 'customer'],
        'customer_addresses'           => ['columns' => ['name', 'company', 'vat_id', 'street', 'city', 'zip', 'phone'], 'erase' => 'delete', 'export' => 'addresses'],
        'customer_email_verifications' => ['columns' => ['customer_id', 'email'], 'erase' => 'delete', 'export' => null,
            'reason' => 'single-use token hashes; the e-mail itself is exported with the customer'],
        'password_reset_tokens'        => ['columns' => ['customer_id', 'token'], 'erase' => 'delete', 'export' => null,
            'reason' => 'credential secrets are never exported'],
        'orders'                       => ['columns' => ['customer_id', 'customer_name', 'customer_email', 'customer_phone', 'shipping_addr', 'ip_address', 'notes'], 'erase' => 'retain', 'export' => 'orders',
            'reason' => '10-year accounting retention (SK Act 431/2002 §35), scrubbed by cli/gdpr-gc.php afterwards'],
        'order_items'                  => ['columns' => ['name'], 'erase' => 'retain', 'export' => 'orders', 'reason' => 'accounting record of the retained order'],
        'invoices'                     => ['columns' => ['customer_snapshot'], 'erase' => 'retain', 'export' => 'orders', 'reason' => 'accounting record of the retained order'],
        'order_consents'               => ['columns' => ['ip_hash', 'user_agent'], 'erase' => 'retain', 'export' => 'orders',
            'reason' => 'proof of the consent given at checkout (Art.7(1)), kept with the retained order'],
        'coupon_uses'                  => ['columns' => ['customer_email'], 'erase' => 'scrub', 'export' => 'coupon_uses'],
        'email_log'                    => ['columns' => ['recipient', 'subject', 'payload'], 'erase' => 'scrub', 'export' => 'email_log'],
        'audit_log'                    => ['columns' => ['old_value', 'new_value'], 'erase' => 'scrub', 'export' => null,
            'reason' => 'staff accountability trail; the data it snapshots is exported from its source tables'],
        'cookie_consent'               => ['columns' => ['customer_id', 'session_id', 'ip_hash', 'user_agent'], 'erase' => 'scrub', 'export' => 'consent_history'],
        'product_reviews'              => ['columns' => ['customer_id', 'author_name', 'title', 'body'], 'erase' => 'delete', 'export' => 'reviews'],
        'wishlist_items'               => ['columns' => ['owner_key', 'customer_id'], 'erase' => 'delete', 'export' => 'wishlist'],
        'abandoned_carts'              => ['columns' => ['customer_email', 'cart_data'], 'erase' => 'delete', 'export' => 'abandoned_carts'],
        'rma_requests'                 => ['columns' => ['customer_id', 'reason', 'admin_note'], 'erase' => 'scrub', 'export' => 'rma_requests'],
        'rma_items'                    => ['columns' => ['name'], 'erase' => 'retain', 'export' => 'rma_requests', 'reason' => 'product lines of the retained RMA (credit-note trail)'],
        'return_receipts'              => ['columns' => ['note'], 'erase' => 'scrub', 'export' => 'rma_requests'],
        'event_outbox'                 => ['columns' => ['payload'], 'erase' => 'scrub', 'export' => 'events'],
        'queue_jobs'                   => ['columns' => ['payload'], 'erase' => 'scrub', 'export' => 'queued_jobs'],
    ];

    /**
     * Payload keys that hold an e-mail address, at any depth: JobQueue::push() mail jobs use 'to'
     * (OrderWorkflow, Orders, AbandonedCarts, ApiHandlers), Outbox::record() customer events 'email',
     * checkout and merchant mail data 'customer_email', mail records 'recipient'. Order status events
     * also use 'to' for the new status, which never equals an e-mail address.
     */
    private const PAYLOAD_EMAIL_KEYS = ['to', 'email', 'customer_email', 'recipient'];

    private const NOT_CUSTOMER_DATA = 'catalog, configuration or bookkeeping names, not a customer\'s personal data';

    /** Tables with personal-data-like columns that deliberately stay out of erasure and export. */
    public const PII_EXEMPT_TABLES = [
        'admin_accounts' => 'staff identities, not shop customers',
        'admin_devices' => 'staff devices, not shop customers',
        'admin_sessions' => 'staff sessions, not shop customers',
        'companies' => 'B2B legal entity shared by several accounts; it is not a natural person',
        'email_suppressions' => 'kept on erasure so the address is never mailed again (opt-out must survive)',
        'gdpr_events' => 'salted hashes only; the Art.5(2) accountability record of the erasure itself',
        'merchant_notifications' => 'recipient is the merchant\'s own address',
        'vat_id_cache' => 'public VIES register data keyed by VAT id, purged by TTL retention',
        'vat_id_checks' => 'VIES evidence of reverse-charge sales, retained with the accounting documents',
        'credit_note_items' => 'product lines of a retained accounting document',
        'api_clients' => self::NOT_CUSTOMER_DATA, 'attribute_definitions' => self::NOT_CUSTOMER_DATA,
        'brands' => self::NOT_CUSTOMER_DATA, 'categories' => self::NOT_CUSTOMER_DATA, 'customer_groups' => self::NOT_CUSTOMER_DATA,
        'digital_assets' => self::NOT_CUSTOMER_DATA, 'feeds' => self::NOT_CUSTOMER_DATA, 'payment_methods' => self::NOT_CUSTOMER_DATA,
        'price_lists' => self::NOT_CUSTOMER_DATA, 'product_attrs' => self::NOT_CUSTOMER_DATA, 'product_specifications' => self::NOT_CUSTOMER_DATA,
        'products' => self::NOT_CUSTOMER_DATA, 'products_fts' => self::NOT_CUSTOMER_DATA, 'promotions' => self::NOT_CUSTOMER_DATA,
        'schema_migrations' => self::NOT_CUSTOMER_DATA, 'shipping_methods' => self::NOT_CUSTOMER_DATA, 'shipping_zones' => self::NOT_CUSTOMER_DATA,
        'sqlite_sequence' => self::NOT_CUSTOMER_DATA, 'suppliers' => self::NOT_CUSTOMER_DATA, 'variants' => self::NOT_CUSTOMER_DATA,
        'warehouses' => self::NOT_CUSTOMER_DATA,
    ];

    /** @var ?string Cached salt (loaded once per request) */
    private static ?string $cachedSalt = null;

    /**
     * Hash PII (email or IP) with persistent salt for de-identified audit trails.
     * Returns hex SHA-256. Same input always produces same output (preserves dedup).
     *
     * Salt source priority:
     *   1. config['gdpr_hash_salt'] if non-empty
     *   2. configured gdpr_salt_file outside the application root
     *   3. Autogenerated 32-byte hex, persisted to that private file
     *
     * Hard-fails if the salt file is unsafe, symlinked, empty, or unwritable.
     */
    public static function hashPii(string $value): string {
        if ($value === '') return '';
        $salt = self::salt();
        return hash('sha256', $salt . '|' . $value);
    }

    /** Returns the persistent GDPR hash salt (autogenerates on first call). */
    public static function salt(): string {
        if (self::$cachedSalt !== null) return self::$cachedSalt;

        $cfg = self::config();
        $configured = (string)($cfg['gdpr_hash_salt'] ?? '');
        if ($configured !== '') {
            self::$cachedSalt = $configured;
            return $configured;
        }

        $saltFile = (string)($cfg['gdpr_salt_file'] ?? dirname(__DIR__, 2) . '/bareboneseshop-private/secrets/gdpr_salt');
        $normalizedSalt = rtrim(str_replace('\\', '/', $saltFile), '/');
        $appRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..') ?: dirname(__DIR__)), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalizedSalt = strtolower($normalizedSalt);
            $appRoot = strtolower($appRoot);
        }
        $absolute = str_starts_with($normalizedSalt, '/') || preg_match('/^[a-z]:\//i', $normalizedSalt) === 1;
        if (!$absolute || $normalizedSalt === $appRoot || str_starts_with($normalizedSalt, $appRoot . '/')) {
            throw new RuntimeException('gdpr_salt_file must be an absolute path outside the application root');
        }
        $parent = dirname($saltFile);
        $existingParent = $parent;
        while (!file_exists($existingParent) && !is_link($existingParent)) {
            $next = dirname($existingParent);
            if ($next === $existingParent) break;
            $existingParent = $next;
        }
        $canonicalParent = realpath($existingParent);
        if ($canonicalParent === false) {
            throw new RuntimeException('GDPR salt parent path cannot be resolved');
        }
        $canonicalParent = rtrim(str_replace('\\', '/', $canonicalParent), '/');
        $compareRoot = $appRoot;
        if (DIRECTORY_SEPARATOR === '\\') $canonicalParent = strtolower($canonicalParent);
        if ($canonicalParent === $compareRoot || str_starts_with($canonicalParent, $compareRoot . '/')) {
            throw new RuntimeException('gdpr_salt_file canonical parent must be outside the application root');
        }

        if (is_link($saltFile)) {
            throw new RuntimeException('GDPR salt file must not be a symlink');
        }
        if (file_exists($saltFile)) {
            $before = lstat($saltFile);
            $handle = @fopen($saltFile, 'rb');
            if ($before === false || $handle === false) {
                if (is_resource($handle)) fclose($handle);
                throw new RuntimeException('GDPR salt file cannot be opened safely');
            }
            try {
                $opened = fstat($handle);
                $after = lstat($saltFile);
                $sameFile = $opened !== false && $after !== false
                    && (int)$opened['ino'] !== 0
                    && ($before['mode'] & 0170000) === 0100000
                    && ($after['mode'] & 0170000) === 0100000
                    && $before['dev'] === $opened['dev'] && $before['ino'] === $opened['ino']
                    && $after['dev'] === $opened['dev'] && $after['ino'] === $opened['ino'];
                $stored = $sameFile ? trim((string)stream_get_contents($handle, 128)) : '';
            } finally {
                fclose($handle);
            }
            if (!$sameFile || !preg_match('/^[0-9a-f]{64}$/', $stored)) {
                throw new RuntimeException(
                    'GDPR salt file is unsafe, empty or corrupt. Restore it from backup; regeneration breaks existing hash matches.'
                );
            }
            self::$cachedSalt = $stored;
            return $stored;
        }

        $new = bin2hex(random_bytes(32));
        $dir = dirname($saltFile);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create GDPR secret directory: {$dir}");
        }
        @chmod($dir, 0700);
        $handle = @fopen($saltFile, 'x');
        if ($handle === false) {
            throw new RuntimeException("Cannot exclusively create GDPR salt file: {$saltFile}");
        }
        try {
            if (fwrite($handle, $new) !== strlen($new) || !fflush($handle)) {
                throw new RuntimeException("Cannot write GDPR salt to {$saltFile}");
            }
        } finally {
            fclose($handle);
        }
        @chmod($saltFile, 0600);
        self::$cachedSalt = $new;
        return $new;
    }

    /**
     * Append a GDPR audit event. Append-only — no UPDATE, no DELETE.
     * @param array $params keys: action, customer_id?, email?, ip?, actor?, payload?, note?
     */
    public static function logEvent(array $params): int {
        $action = (string)($params['action'] ?? '');
        if ($action === '') throw new InvalidArgumentException('logEvent: action required');

        $allowed = ['export', 'erase', 'consent_change', 'retention_apply', 'admin_anonymize', 'purge'];
        if (!in_array($action, $allowed, true)) {
            throw new InvalidArgumentException("logEvent: action must be one of: " . implode(', ', $allowed));
        }

        $email = (string)($params['email'] ?? '');
        $ip    = (string)($params['ip']    ?? '');

        $emailHash = $email !== '' ? self::hashPii(strtolower($email)) : null;
        $ipHash    = $ip    !== '' ? self::hashPii($ip)                : null;

        $payload = $params['payload'] ?? null;
        $payloadHash = null;
        if ($payload !== null) {
            $serialized = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);
            $payloadHash = hash('sha256', (string)$serialized);
        }

        $db = Db::get();
        $stmt = $db->prepare("
            INSERT INTO gdpr_events (action, customer_id, email_hash, ip_hash, actor, payload_hash, note, store_id)
            VALUES (:action, :customer_id, :email_hash, :ip_hash, :actor, :payload_hash, :note, :store)
        ");
        $stmt->execute([
            ':action'       => $action,
            ':customer_id'  => $params['customer_id'] ?? null,
            ':email_hash'   => $emailHash,
            ':ip_hash'      => $ipHash,
            ':actor'        => $params['actor'] ?? null,
            ':payload_hash' => $payloadHash,
            ':note'         => $params['note'] ?? null,
            ':store'        => StoreContext::current()->id(),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Persist cookie consent choice. Each call = new row (consent history kept).
     * @param array $params keys: session_id?, customer_id?, ip?, user_agent?,
     *                           categories (array of bool), method, consent_version?
     */
    public static function logCookieConsent(array $params): int {
        $cats = $params['categories'] ?? [];
        $normalized = [
            'necessary' => true, // always true
            'analytics' => (bool)($cats['analytics'] ?? false),
            'marketing' => (bool)($cats['marketing'] ?? false),
        ];

        $method = (string)($params['method'] ?? 'banner_accept_all');
        $allowed = ['banner_accept_all', 'banner_reject_all', 'preferences', 'withdrawal'];
        if (!in_array($method, $allowed, true)) {
            throw new InvalidArgumentException("logCookieConsent: method must be one of: " . implode(', ', $allowed));
        }

        $ip = (string)($params['ip'] ?? '');
        $ipHash = $ip !== '' ? self::hashPii($ip) : null;

        $db = Db::get();
        $stmt = $db->prepare("
            INSERT INTO cookie_consent (session_id, customer_id, ip_hash, user_agent, categories_json, method, consent_version)
            VALUES (:session_id, :customer_id, :ip_hash, :user_agent, :cats, :method, :ver)
        ");
        $stmt->execute([
            ':session_id'  => $params['session_id'] ?? null,
            ':customer_id' => $params['customer_id'] ?? null,
            ':ip_hash'     => $ipHash,
            ':user_agent'  => $params['user_agent'] ?? null,
            ':cats'        => json_encode($normalized, JSON_UNESCAPED_UNICODE),
            ':method'      => $method,
            ':ver'         => (int)($params['consent_version'] ?? 1),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Subject Access Request — export ALL data for a customer as a structured array.
     * Returns: ['customer'=>{}, 'addresses'=>[], 'orders'=>[{order, items, invoices, payments, consents}],
     *           'email_log'=>[], 'consent_history'=>[], 'coupon_uses'=>[], 'reviews'=>[], 'wishlist'=>[],
     *           'abandoned_carts'=>[], 'rma_requests'=>[{rma, items, receipts}], 'events'=>[], 'queued_jobs'=>[],
     *           'meta'=>{exported_at, customer_id}] — one section per PII_REGISTRY 'export' key.
     * Payment data redacted (no `raw_response` field). 'queued_jobs' lists the queue_jobs table and
     * the file backend's job files; jobs held by the Redis backend are not listed (see anonymizeCustomer()).
     */
    public static function exportCustomer(int $customerId): array {
        $db = Db::get();

        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND store_id = ?");
        $stmt->execute([$customerId, StoreContext::current()->id()]);
        $customer = $stmt->fetch();
        if (!$customer) throw new RuntimeException("Customer #{$customerId} not found");

        // Redact password_hash
        unset($customer['password_hash']);

        $stmt = $db->prepare("SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY id");
        $stmt->execute([$customerId]);
        $addresses = $stmt->fetchAll();

        // Email ownership may link guest orders, never another registered customer's order.
        return self::exportSubject($db, $customer, $customerId, $addresses, self::ownershipParams($customer));
    }

    /**
     * Access request of a guest (orders without an account): everything the store holds for the
     * address outside registered accounts — the guest orders, their mail, carts, consents, events and
     * jobs. The merchant verifies the requester's identity before calling this; the 'customer' section
     * is null and 'addresses' empty because a guest has no account.
     */
    public static function exportGuest(string $email): array {
        $db = Db::get();
        $ownership = self::guestOwnership($db, $email);
        $export = self::exportSubject($db, null, 0, [], $ownership);
        $export['meta']['guest_email'] = $ownership[':owner_email'];
        return $export;
    }

    /** Guest orders (no account) of the current store placed with exactly this address; 0 for a non-address. */
    public static function guestOrderCount(string $email): int {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 0;
        $stmt = Db::get()->prepare("SELECT COUNT(*) FROM orders WHERE store_id = ? AND customer_id IS NULL AND LOWER(customer_email) = LOWER(?)");
        $stmt->execute([StoreContext::current()->id(), $email]);
        return (int)$stmt->fetchColumn();
    }

    private static function exportSubject(PDO $db, ?array $customer, int $customerId, array $addresses, array $ownership): array {
        $email = (string)$ownership[':owner_email'];
        $stmt = $db->prepare("SELECT * FROM orders WHERE id IN (" . self::CUSTOMER_ORDERS . ") ORDER BY id ASC");
        $stmt->execute($ownership);
        $orders = [];
        $hasOrderConsents = self::hasTable($db, 'order_consents');
        foreach ($stmt->fetchAll() as $o) {
            $orderId = (int)$o['id'];
            $itemsStmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
            $itemsStmt->execute([$orderId]);
            $invStmt = $db->prepare("
                SELECT id, number, issued_at, due_date, total_net, total_vat, total_gross, currency, status, type
                FROM invoices WHERE order_id = ? AND store_id = ? ORDER BY id
            ");
            $invStmt->execute([$orderId, StoreContext::current()->id()]);
            // Redact payment_transactions raw_response
            $payStmt = $db->prepare("
                SELECT id, provider, provider_ref, status, amount, currency, idempotency_key, created_at, updated_at
                FROM payment_transactions WHERE order_id = ? AND store_id = ? ORDER BY id
            ");
            $payStmt->execute([$orderId, StoreContext::current()->id()]);
            $consents = [];
            if ($hasOrderConsents) {
                $consentStmt = $db->prepare("SELECT * FROM order_consents WHERE order_id = ? ORDER BY id");
                $consentStmt->execute([$orderId]);
                $consents = $consentStmt->fetchAll();
            }
            $orders[] = [
                'order'    => $o,
                'items'    => $itemsStmt->fetchAll(),
                'invoices' => $invStmt->fetchAll(),
                'payments' => $payStmt->fetchAll(),
                'consents' => $consents,
            ];
        }

        // Unverified accounts may see only mail linked to their own orders.
        $stmt = $db->prepare("
            SELECT id, order_id, recipient, template, locale, subject, adapter, status, sent_at, created_at
            FROM email_log WHERE store_id = :log_store AND LOWER(recipient) = LOWER(:recipient_email)
              AND (order_id IN (" . self::CUSTOMER_ORDERS . ") OR (order_id IS NULL AND :unlinked_verified = '1')) ORDER BY id ASC
        ");
        $stmt->execute($ownership + [':log_store' => StoreContext::current()->id(), ':recipient_email' => $email, ':unlinked_verified' => $ownership[':verified']]);
        $emailLog = $stmt->fetchAll();

        $store = StoreContext::current()->id();
        $orderIds = self::customerOrderIds($db, $ownership);
        $sessionKeys = self::customerSessionKeys($db, $customerId, $orderIds);

        // Cookie consent history: the account's own records and those of its browser sessions.
        $consent = [];
        if (self::hasTable($db, 'cookie_consent')) {
            $stmt = $db->prepare("SELECT * FROM cookie_consent WHERE customer_id = ?" . self::inList(' OR session_id', $sessionKeys) . " ORDER BY id ASC");
            $stmt->execute(array_merge([$customerId], $sessionKeys));
            $consent = $stmt->fetchAll();
        }

        $stmt = $db->prepare("SELECT id, store_id, order_id, coupon_code, customer_email, discount, created_at FROM coupon_uses
            WHERE store_id = :coupon_store AND LOWER(customer_email) = LOWER(:coupon_email) AND order_id IN (" . self::CUSTOMER_ORDERS . ") ORDER BY id ASC");
        $stmt->execute($ownership + [':coupon_store' => $store, ':coupon_email' => $email]);
        $couponUses = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT * FROM product_reviews WHERE customer_id = ? ORDER BY id ASC");
        $stmt->execute([$customerId]);
        $reviews = $stmt->fetchAll();

        $owners = array_merge(['c:' . $customerId], array_map(static fn(string $key): string => 's:' . $key, $sessionKeys));
        $stmt = $db->prepare("SELECT * FROM wishlist_items WHERE customer_id = ?" . self::inList(' OR owner_key', $owners) . " ORDER BY id ASC");
        $stmt->execute(array_merge([$customerId], $owners));
        $wishlist = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT id, session_id, customer_email, cart_data, cart_value, item_count, currency, locale, order_id,
                created_at, updated_at, reminded_at, converted_at, reminder_status
            FROM abandoned_carts WHERE " . self::CUSTOMER_CARTS . " ORDER BY id ASC");
        $stmt->execute($ownership + [':cart_email' => $email, ':cart_store' => $store, ':cart_verified' => $ownership[':verified']]);
        $carts = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT * FROM rma_requests
            WHERE store_id = :rma_store AND (customer_id = :rma_customer OR order_id IN (" . self::CUSTOMER_ORDERS . ")) ORDER BY id ASC");
        $stmt->execute($ownership + [':rma_store' => $store, ':rma_customer' => $customerId]);
        $rmas = [];
        $hasReceipts = self::hasTable($db, 'return_receipts');
        foreach ($stmt->fetchAll() as $rma) {
            $itemsStmt = $db->prepare("SELECT * FROM rma_items WHERE rma_id = ? ORDER BY id");
            $itemsStmt->execute([(int)$rma['id']]);
            $receipts = [];
            if ($hasReceipts) {
                $receiptStmt = $db->prepare("SELECT id, rma_item_id, quantity, disposition, note, created_at FROM return_receipts WHERE rma_id = ? AND store_id = ? ORDER BY id");
                $receiptStmt->execute([(int)$rma['id'], $store]);
                $receipts = $receiptStmt->fetchAll();
            }
            $rmas[] = ['rma' => $rma, 'items' => $itemsStmt->fetchAll(), 'receipts' => $receipts];
        }

        $payloads = self::customerPayloadRows($db, $customerId, $email, $ownership, $orderIds);

        $export = [
            'meta' => [
                'exported_at' => gmdate('c'),
                'customer_id' => $customerId,
                'export_version' => 2,
            ],
            'customer' => $customer,
            'addresses' => $addresses,
            'orders'    => $orders,
            'email_log' => $emailLog,
            'consent_history' => $consent,
            'coupon_uses' => $couponUses,
            'reviews' => $reviews,
            'wishlist' => $wishlist,
            'abandoned_carts' => $carts,
            'rma_requests' => $rmas,
            'events' => array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['id', 'event_type', 'aggregate_type', 'aggregate_id', 'payload', 'status', 'occurred_at'])), $payloads['event_outbox']),
            'queued_jobs' => array_merge(
                array_map(static fn(array $row): array => array_intersect_key($row, array_flip(['id', 'type', 'state', 'payload', 'created_at', 'completed_at'])), $payloads['queue_jobs']),
                array_map(static fn(array $file): array => [
                    'id' => (string)($file['job']['id'] ?? ''), 'type' => (string)($file['job']['type'] ?? ''), 'state' => $file['state'],
                    'payload' => json_encode($file['job']['payload'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => $file['job']['created_at'] ?? null, 'completed_at' => null,
                ], self::customerFileJobs($email, $ownership, $orderIds)),
            ),
        ];
        self::assertRegistryCovered('export', array_keys($export));
        return $export;
    }

    /**
     * Right-to-erasure Tier A (soft anonymize). Performs:
     *   - customers: email→deleted-{id}@anonymized.local, name/phone empty, password_hash invalidated,
     *                vat_id NULL, status='anonymized'
     *   - customer_addresses: hard DELETE
     *   - email_log: scrub PII (recipient→deleted-{id}@anonymized.local, subject→[erased], payload→[erased])
     *   - audit_log: scrub payload_json with placeholder
     *   - coupon_uses: hash email
     *   - everything else in PII_REGISTRY: see eraseCustomerContent()
     *   - orders.customer_*, invoices.customer_snapshot: KEPT (10y legal retention)
     * Queued jobs are scrubbed in the queue_jobs table (database backend) and in the job files of the
     * file backend (jobs_dir, see JobQueue::rewriteFileJobs()). The Redis backend is NOT covered: the
     * QueueBackend contract cannot enumerate jobs, so jobs held in Redis (including the completed ones
     * it keeps) keep the e-mail until the operator purges them, and the export does not list them.
     *
     * Logs gdpr_events row with action='erase'.
     */
    public static function anonymizeCustomer(int $customerId, string $actor = 'self'): array {
        $db = Db::get();
        $stmt = $db->prepare("SELECT * FROM customers WHERE id = ? AND store_id = ?");
        $stmt->execute([$customerId, StoreContext::current()->id()]);
        $customer = $stmt->fetch();
        if (!$customer) throw new RuntimeException("Customer #{$customerId} not found");
        if (($customer['status'] ?? '') === 'anonymized') {
            return ['status' => 'already_anonymized', 'customer_id' => $customerId];
        }

        $origEmail = (string)$customer['email'];
        $ownership = self::ownershipParams($customer);
        $placeholder = "deleted-{$customerId}@anonymized.local";
        $erasureMarker = '[erased per GDPR Art.17 at ' . gmdate('Y-m-d') . ']';

        $db->beginTransaction();
        try {
            // 1) customers row → anonymize. Use '' (empty) for NOT NULL columns.
            $stmt = $db->prepare("
                UPDATE customers
                SET email = :email,
                    name = '', phone = '',
                    password_hash = '!disabled!',
                    vat_id = NULL, vat_country = NULL, vat_validated_at = NULL,
                    status = 'anonymized',
                    updated_at = " . Db::now() . "
                WHERE id = :id
            ");
            $stmt->execute([':email' => $placeholder, ':id' => $customerId]);
            $rowsCust = $stmt->rowCount();

            // 2) addresses → hard DELETE (per design — no legal need)
            $stmt = $db->prepare("DELETE FROM customer_addresses WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $rowsAddr = $stmt->rowCount();

            $rows = ['customers' => $rowsCust, 'customer_addresses' => $rowsAddr]
                + self::eraseSubject($db, $customerId, $origEmail, $ownership, $placeholder, $erasureMarker);
            // A registered table without an erasure step must not report a completed erasure.
            self::assertRegistryCovered('erase', array_keys($rows));
            self::recordErasure($db, $customerId, $customerId, $ownership);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // 6) Audit event is best-effort after commit; it must not turn completed erasure into a reported failure.
        self::logErasure($customerId, $origEmail, $actor, $rows);

        return [
            'status' => 'anonymized',
            'customer_id' => $customerId,
            'rows' => $rows,
        ];
    }

    /**
     * Right-to-erasure of a guest: the same erasure as anonymizeCustomer() for everything tied to the
     * address outside registered accounts. Guest orders stay (accounting retention) like an account's.
     * The merchant verifies the requester's identity before calling this.
     */
    public static function anonymizeGuest(string $email, string $actor): array {
        $db = Db::get();
        $ownership = self::guestOwnership($db, $email);
        $email = (string)$ownership[':owner_email'];
        $placeholder = 'deleted-guest@anonymized.local';
        $erasureMarker = '[erased per GDPR Art.17 at ' . gmdate('Y-m-d') . ']';
        $db->beginTransaction();
        try {
            $rows = ['customers' => 0, 'customer_addresses' => 0]
                + self::eraseSubject($db, 0, $email, $ownership, $placeholder, $erasureMarker);
            self::assertRegistryCovered('erase', array_keys($rows));
            self::recordErasure($db, null, 'guest', $ownership);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        self::logErasure(null, $email, $actor, $rows);
        return ['status' => 'anonymized', 'customer_id' => null, 'rows' => $rows];
    }

    /**
     * Webhook subscribers and extensions received the person's data in earlier events (customer.created,
     * order.created …); customer.erased tells them to erase their copies. It names the account and the
     * retained orders, never the e-mail. Recorded in the erasing transaction, after the customer's
     * earlier events were rewritten.
     */
    private static function recordErasure(PDO $db, ?int $customerId, int|string $aggregateId, array $ownership): void {
        Outbox::record(DomainEvents::CUSTOMER_ERASED, 'customer', $aggregateId, [
            'customer_id' => $customerId,
            'guest' => $customerId === null,
            'order_ids' => array_map('intval', self::customerOrderIds($db, $ownership)),
            'erased_at' => gmdate('c'),
        ]);
    }

    private static function logErasure(?int $customerId, string $email, string $actor, array $rows): void {
        try {
            self::logEvent([
                'action'      => 'erase',
                'customer_id' => $customerId,
                'email'       => $email,
                'actor'       => $actor,
                'payload'     => $rows,
            ]);
        } catch (\Throwable $e) {
            $errorId = bin2hex(random_bytes(6));
            error_log("GDPR erase audit failed [{$errorId}] for " . ($customerId !== null ? "customer #{$customerId}" : 'a guest') . ': ' . $e->getMessage());
        }
    }

    /**
     * Ownership of a guest: the store's orders without an account placed with the address. Throws when
     * the store holds no guest order and no cart for it, so an unknown address reports "not found".
     */
    private static function guestOwnership(PDO $db, string $email): array {
        $email = Str::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid e-mail');
        // ':guest' owns the guest orders. Rows linked to no order (mail log, audit trail, carts, jobs) are only the
        // guest's ('verified') while no account of this store uses the address: otherwise they may be the account's.
        $account = $db->prepare("SELECT COUNT(*) FROM customers WHERE store_id = ? AND LOWER(email) = LOWER(?)");
        $account->execute([StoreContext::current()->id(), $email]);
        $ownership = [':order_store' => StoreContext::current()->id(), ':customer_id' => 0, ':verified' => (int)$account->fetchColumn() > 0 ? 0 : 1,
            ':guest' => 1, ':owner_email' => $email];
        $carts = $db->prepare("SELECT COUNT(*) FROM abandoned_carts WHERE LOWER(customer_email) = LOWER(?) AND (store_id = ? OR store_id IS NULL)");
        $carts->execute([$email, StoreContext::current()->id()]);
        if (self::customerOrderIds($db, $ownership) === [] && (int)$carts->fetchColumn() === 0) {
            throw new RuntimeException('No guest data for this e-mail');
        }
        return $ownership;
    }

    /** Erasure shared by accounts and guests: mail log, audit trail, coupon uses and eraseCustomerContent(). */
    private static function eraseSubject(PDO $db, int $customerId, string $origEmail, array $ownership, string $placeholder, string $erasureMarker): array {
        // 3) email_log → scrub PII fields, keep template+adapter+status for stats
        $stmt = $db->prepare("
            UPDATE email_log
            SET recipient = :placeholder,
                subject = :marker,
                payload = :payload_marker
            WHERE store_id = :log_store AND LOWER(recipient) = LOWER(:recipient_email)
              AND (order_id IN (" . self::CUSTOMER_ORDERS . ") OR (order_id IS NULL AND :unlinked_verified = '1'))
        ");
        $stmt->execute($ownership + [
            ':placeholder' => $placeholder,
            ':marker'      => $erasureMarker,
            ':payload_marker' => $erasureMarker,
            ':log_store' => StoreContext::current()->id(),
            ':recipient_email' => $origEmail,
            ':unlinked_verified' => $ownership[':verified'],
        ]);
        $rowsEmail = $stmt->rowCount();

        // 4) audit_log → scrub old_value + new_value (typically JSON snapshots)
        //    when they contain the email substring. Keep entity/action/actor/timestamp.
        $hasAudit = self::tableColumns($db, 'audit_log') !== [];
        $rowsAudit = 0;
        if ($hasAudit) {
            $stmt = $db->prepare("
                UPDATE audit_log
                SET old_value = :old_marker, new_value = :new_marker
                WHERE store_id = :store AND (INSTR(old_value, :old_email) > 0 OR INSTR(new_value, :new_email) > 0)
                  AND (:verified = '1' OR (entity = 'customer' AND entity_id = :customer_id))
            ");
            $stmt->execute([
                ':old_marker' => $erasureMarker, ':new_marker' => $erasureMarker,
                ':store' => StoreContext::current()->id(),
                ':old_email' => $origEmail, ':new_email' => $origEmail,
                ':verified' => $ownership[':verified'], ':customer_id' => $customerId,
            ]);
            $rowsAudit = $stmt->rowCount();
        }

        // 5) coupon_uses → hash email column (preserves dedup logic).
        //    Column name varies: 'customer_email' (current schema) or 'email' (legacy).
        $couponColumns = self::tableColumns($db, 'coupon_uses');
        $hasCoupon = $couponColumns !== [];
        $rowsCoupon = 0;
        if ($hasCoupon) {
            $emailCol = null;
            foreach ($couponColumns as $col) {
                $n = strtolower($col);
                if ($n === 'customer_email' || $n === 'email') { $emailCol = $col; break; }
            }
            if ($emailCol !== null) {
                $hashed = self::hashPii(strtolower($origEmail));
                $sql = "UPDATE coupon_uses SET {$emailCol} = :hashed WHERE store_id = :coupon_store AND LOWER({$emailCol}) = LOWER(:coupon_email) AND order_id IN (" . self::CUSTOMER_ORDERS . ")";
                $stmt = $db->prepare($sql);
                $stmt->execute($ownership + [':hashed' => $hashed, ':coupon_store' => StoreContext::current()->id(), ':coupon_email' => $origEmail]);
                $rowsCoupon = $stmt->rowCount();
            }
        }

        return [
            'email_log'          => $rowsEmail,
            'audit_log'          => $rowsAudit,
            'coupon_uses'        => $rowsCoupon,
        ] + self::eraseCustomerContent($db, $customerId, $origEmail, $ownership, $placeholder, $erasureMarker);
    }

    /** @param 'erase'|'export' $kind */
    private static function assertRegistryCovered(string $kind, array $handled): void {
        $missing = [];
        foreach (self::PII_REGISTRY as $table => $entry) {
            $key = $kind === 'erase' ? ($entry['erase'] === 'retain' ? null : $table) : $entry['export'];
            if ($key !== null && !in_array($key, $handled, true)) $missing[] = $table;
        }
        if ($missing !== []) throw new LogicException("GDPR {$kind} has no handler for registered PII table(s): " . implode(', ', $missing));
    }

    /**
     * Personal data outside the customer/order records: reviews, wishlists (account and linked
     * browser sessions) and abandoned carts are deleted; verification and password-reset tokens are
     * deleted (the customer row is updated, so no FK cascade fires); cookie consents keep only the
     * choice; RMA reasons, admin notes and return receipt notes are scrubbed (the RMA stays for the
     * credit-note trail); outbox events and queued jobs (table and file backend) lose the e-mail in
     * their e-mail fields (see scrubPayloadEmail()) and customer events are rewritten.
     */
    private static function eraseCustomerContent(PDO $db, int $customerId, string $email, array $ownership, string $placeholder, string $marker): array {
        $store = StoreContext::current()->id();
        $rows = [];
        $orderIds = self::customerOrderIds($db, $ownership);
        // Read before carts and consents are erased: they are what links the browser sessions.
        $sessionKeys = self::customerSessionKeys($db, $customerId, $orderIds);

        $stmt = $db->prepare("DELETE FROM product_reviews WHERE customer_id = :id");
        $stmt->execute([':id' => $customerId]);
        $rows['product_reviews'] = $stmt->rowCount();

        $owners = array_merge(['c:' . $customerId], array_map(static fn(string $key): string => 's:' . $key, $sessionKeys));
        $stmt = $db->prepare("DELETE FROM wishlist_items WHERE customer_id = ?" . self::inList(' OR owner_key', $owners));
        $stmt->execute(array_merge([$customerId], $owners));
        $rows['wishlist_items'] = $stmt->rowCount();

        $stmt = $db->prepare("DELETE FROM abandoned_carts WHERE " . self::CUSTOMER_CARTS);
        $stmt->execute($ownership + [':cart_email' => $email, ':cart_store' => $store, ':cart_verified' => $ownership[':verified']]);
        $rows['abandoned_carts'] = $stmt->rowCount();

        foreach (['password_reset_tokens', 'customer_email_verifications'] as $tokenTable) {
            $rows[$tokenTable] = 0;
            if (!self::hasTable($db, $tokenTable)) continue;
            $stmt = $db->prepare("DELETE FROM {$tokenTable} WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $rows[$tokenTable] = $stmt->rowCount();
        }

        // The consent choice itself stays as Art.7(1) evidence; nothing links it to the person any more.
        $rows['cookie_consent'] = 0;
        if (self::hasTable($db, 'cookie_consent')) {
            $stmt = $db->prepare("UPDATE cookie_consent SET customer_id = NULL, session_id = NULL, ip_hash = NULL, user_agent = NULL
                WHERE customer_id = ?" . self::inList(' OR session_id', $sessionKeys));
            $stmt->execute(array_merge([$customerId], $sessionKeys));
            $rows['cookie_consent'] = $stmt->rowCount();
        }

        // Staff notes are free text that often quotes the customer: redacted like the reason, never kept.
        $rmaScope = "store_id = :rma_store AND (customer_id = :rma_customer OR order_id IN (" . self::CUSTOMER_ORDERS . "))";
        $stmt = $db->prepare("UPDATE rma_requests SET reason = :marker,
                admin_note = CASE WHEN admin_note IS NULL OR admin_note = '' THEN admin_note ELSE :note_marker END,
                updated_at = " . Db::now() . "
            WHERE {$rmaScope}");
        $stmt->execute($ownership + [':marker' => $marker, ':note_marker' => $marker, ':rma_store' => $store, ':rma_customer' => $customerId]);
        $rows['rma_requests'] = $stmt->rowCount();
        $rows['return_receipts'] = 0;
        if (self::hasTable($db, 'return_receipts')) {
            $stmt = $db->prepare("UPDATE return_receipts SET note = :marker
                WHERE store_id = :receipt_store AND note IS NOT NULL AND note <> '' AND rma_id IN (SELECT id FROM rma_requests WHERE {$rmaScope})");
            $stmt->execute($ownership + [':marker' => $marker, ':receipt_store' => $store, ':rma_store' => $store, ':rma_customer' => $customerId]);
            $rows['return_receipts'] = $stmt->rowCount();
        }

        $payloads = self::customerPayloadRows($db, $customerId, $email, $ownership, $orderIds);
        $rows['event_outbox'] = 0;
        $erasedEvent = json_encode(['customer_id' => $customerId, 'erased' => true]);
        $update = $db->prepare("UPDATE event_outbox SET payload = ? WHERE id = ?");
        foreach ($payloads['event_outbox'] as $event) {
            $payload = $event['aggregate_type'] === 'customer' ? $erasedEvent : self::scrubPayloadJson((string)$event['payload'], $email, $placeholder);
            $update->execute([$payload, $event['id']]);
            $rows['event_outbox'] += $update->rowCount();
        }
        $rows['queue_jobs'] = 0;
        $update = $db->prepare("UPDATE queue_jobs SET payload = ? WHERE id = ?");
        foreach ($payloads['queue_jobs'] as $job) {
            $update->execute([self::scrubPayloadJson((string)$job['payload'], $email, $placeholder), $job['id']]);
            $rows['queue_jobs'] += $update->rowCount();
        }
        // Last: file writes are not transactional; a failure rolls the database back and a rerun redoes them.
        $rows['queue_jobs'] += self::eraseFileJobs($email, $ownership, $orderIds, $placeholder);
        return $rows;
    }

    /** @return list<string> ids of the orders the customer owns, as strings (see customerPayloadRows). */
    private static function customerOrderIds(PDO $db, array $ownership): array {
        $stmt = $db->prepare(self::CUSTOMER_ORDERS);
        $stmt->execute($ownership);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Browser session keys (Security::sessionKey()) provably used by the customer: consents logged
     * while signed in as the customer and the checkout sessions of the customer's orders. An
     * anonymous wishlist is keyed 's:<session key>', so these find it after the session is gone.
     * Abandoned carts are no source: anyone can type the customer's e-mail into a checkout in their
     * own browser, so a cart's session may be a third party's (and a cart that became an order is
     * re-keyed 'order:<id>', its checkout session is already covered).
     *
     * @return list<string>
     */
    private static function customerSessionKeys(PDO $db, int $customerId, array $orderIds): array {
        $keys = [];
        if (self::hasTable($db, 'cookie_consent')) {
            $stmt = $db->prepare("SELECT session_id FROM cookie_consent WHERE customer_id = ? AND session_id IS NOT NULL");
            $stmt->execute([$customerId]);
            $keys = array_merge($keys, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($orderIds !== [] && self::hasTable($db, 'checkout_sessions')) {
            $stmt = $db->prepare("SELECT browser_session_hash FROM checkout_sessions WHERE browser_session_hash IS NOT NULL" . self::inList(' AND order_id', $orderIds));
            $stmt->execute($orderIds);
            $keys = array_merge($keys, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        // Converted carts are re-keyed 'order:<id>'; only real session keys can own a wishlist.
        return array_values(array_unique(array_filter(array_map('strval', $keys), static fn(string $key): bool => preg_match('/^[a-f0-9]{64}$/D', $key) === 1)));
    }

    /**
     * Outbox events and queued jobs carrying the customer's data: the customer's own events, events
     * of the customer's orders whose payload names the e-mail, and jobs naming the e-mail (for an
     * unverified account only jobs of its own orders). "Names" means an e-mail field of the decoded
     * payload holds exactly that address, case-insensitively (see PAYLOAD_EMAIL_KEYS): a substring
     * match would take another customer's 'joann@x.sk' for 'ann@x.sk'. The SQL INSTR is a prefilter.
     *
     * @return array{event_outbox: list<array<string, mixed>>, queue_jobs: list<array<string, mixed>>}
     */
    private static function customerPayloadRows(PDO $db, int $customerId, string $email, array $ownership, array $orderIds): array {
        $store = StoreContext::current()->id();
        $stmt = $db->prepare("SELECT * FROM event_outbox WHERE store_id = ? AND aggregate_type = 'customer' AND aggregate_id = ? ORDER BY id");
        $stmt->execute([$store, (string)$customerId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $jobs = [];
        if ($email === '') return ['event_outbox' => $events, 'queue_jobs' => $jobs];
        // Order ids are bound as strings: a CAST(... AS CHAR) subquery mixes collations with aggregate_id on MySQL.
        if ($orderIds !== []) {
            $stmt = $db->prepare("SELECT * FROM event_outbox WHERE store_id = ? AND aggregate_type = 'order'" . self::inList(' AND aggregate_id', $orderIds) . " ORDER BY id");
            $stmt->execute(array_merge([$store], $orderIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
                if (self::payloadNamesEmail(json_decode((string)$event['payload'], true), $email)) $events[] = $event;
            }
        }
        if (!self::hasTable($db, 'queue_jobs')) return ['event_outbox' => $events, 'queue_jobs' => $jobs];
        $stmt = $db->prepare("SELECT * FROM queue_jobs WHERE store_id = ? AND INSTR(LOWER(payload), ?) > 0 ORDER BY created_at, id");
        $stmt->execute([$store, strtolower($email)]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
            if (self::jobBelongsToCustomer(json_decode((string)$job['payload'], true), $email, $ownership, $orderIds)) $jobs[] = $job;
        }
        return ['event_outbox' => $events, 'queue_jobs' => $jobs];
    }

    /**
     * File-backend jobs (JobQueue::fileJobs()) of the current store that belong to the customer.
     *
     * @return list<array{state: string, job: array}>
     */
    private static function customerFileJobs(string $email, array $ownership, array $orderIds): array {
        if ($email === '') return [];
        $store = StoreContext::current()->id();
        return array_values(array_filter(JobQueue::fileJobs(), static fn(array $file): bool =>
            self::fileJobInStore($file['job'], $store) && self::jobBelongsToCustomer($file['job']['payload'] ?? null, $email, $ownership, $orderIds)));
    }

    /** Rewrites the e-mail fields of the customer's file-backend jobs; returns the number of files rewritten. */
    private static function eraseFileJobs(string $email, array $ownership, array $orderIds, string $placeholder): int {
        if ($email === '') return 0;
        $store = StoreContext::current()->id();
        return JobQueue::rewriteFileJobs(static function (array $job) use ($email, $ownership, $orderIds, $placeholder, $store): ?array {
            if (!self::fileJobInStore($job, $store) || !self::jobBelongsToCustomer($job['payload'] ?? null, $email, $ownership, $orderIds)) return null;
            $job['payload'] = self::scrubPayloadEmail($job['payload'], $email, $placeholder);
            return $job;
        });
    }

    private static function fileJobInStore(array $job, string $store): bool {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        return (string)($payload['_store_id'] ?? 'default') === $store;
    }

    /** The payload names the e-mail; an unverified account owns only the jobs of its own orders. */
    private static function jobBelongsToCustomer(mixed $payload, string $email, array $ownership, array $orderIds): bool {
        if (!is_array($payload) || !self::payloadNamesEmail($payload, $email)) return false;
        $orderId = isset($payload['order_id']) ? (string)(int)$payload['order_id'] : '';
        return (string)$ownership[':verified'] === '1' || in_array($orderId, $orderIds, true);
    }

    private static function payloadNamesEmail(mixed $payload, string $email): bool {
        return $email !== '' && is_array($payload) && self::scrubPayloadEmail($payload, $email, '') !== $payload;
    }

    /**
     * $payload with every e-mail field (PAYLOAD_EMAIL_KEYS, at any depth) that holds exactly $email,
     * case-insensitively, replaced by $placeholder. Every other value is kept as it was.
     */
    private static function scrubPayloadEmail(mixed $payload, string $email, string $placeholder): mixed {
        if (!is_array($payload)) return $payload;
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::scrubPayloadEmail($value, $email, $placeholder);
            } elseif (is_string($value) && is_string($key) && in_array(strtolower($key), self::PAYLOAD_EMAIL_KEYS, true)
                && strcasecmp(trim($value), trim($email)) === 0) {
                $payload[$key] = $placeholder;
            }
        }
        return $payload;
    }

    /** A JSON payload column rewritten by scrubPayloadEmail(); an undecodable payload is returned unchanged. */
    private static function scrubPayloadJson(string $json, string $email, string $placeholder): string {
        $payload = json_decode($json, true);
        if (!is_array($payload)) return $json;
        return json_encode(self::scrubPayloadEmail($payload, $email, $placeholder), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** " <column> IN (?, ...)" for a non-empty list, '' otherwise; $prefix carries the connective and column. */
    private static function inList(string $prefix, array $values): string {
        return $values === [] ? '' : $prefix . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
    }

    /**
     * Apply ONE retention policy. Returns ['affected'=>int, 'mode'=>string, 'details'=>array].
     * Modes: 'dry-run' (count only), 'apply' (perform).
     *
     * Supported actions:
     *   - delete:           DELETE FROM table WHERE field <= cutoff
     *   - delete_expired_ttl: DELETE WHERE field + ttl_seconds <= now (vat_id_cache style)
     *   - scrub_pii:        UPDATE SET <scrub_fields> = '[erased ...]'
     *   - hash_email:       UPDATE SET email = sha256(salt|email) WHERE email NOT LIKE 'sha256:%'
     */
    public static function applyRetention(string $policyKey, array $policy, string $mode = 'dry-run'): array {
        if (!in_array($mode, ['dry-run', 'apply'], true)) {
            throw new InvalidArgumentException("applyRetention: mode must be 'dry-run' or 'apply'");
        }

        $db = Db::get();
        $action = (string)($policy['action'] ?? '');
        $table  = (string)($policy['table']  ?? '');
        if ($table === '') throw new InvalidArgumentException("policy.table required for {$policyKey}");

        // Validate table name (anti-injection — whitelist alphanumeric + underscore)
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("policy.table invalid: {$table}");
        }

        $start = microtime(true);

        switch ($action) {
            case 'delete':
                return self::retentionDelete($db, $policyKey, $policy, $mode, $start);
            case 'delete_expired_ttl':
                return self::retentionDeleteExpiredTtl($db, $policyKey, $policy, $mode, $start);
            case 'scrub_pii':
                return self::retentionScrubPii($db, $policyKey, $policy, $mode, $start);
            case 'hash_email':
                return self::retentionHashEmail($db, $policyKey, $policy, $mode, $start);
            default:
                throw new InvalidArgumentException("policy.action unknown: {$action}");
        }
    }

    private static function retentionDelete(PDO $db, string $key, array $p, string $mode, float $start): array {
        $table = $p['table'];
        $field = (string)($p['field'] ?? 'created_at');
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            throw new InvalidArgumentException("policy.field invalid: {$field}");
        }
        $cutoff = self::computeCutoff($p);
        $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE {$field} <= :cutoff");
        $countStmt->execute([':cutoff' => $cutoff]);
        $affected = (int)$countStmt->fetch()['c'];

        if ($mode === 'apply' && $affected > 0) {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE {$field} <= :cutoff");
            $stmt->execute([':cutoff' => $cutoff]);
            $affected = $stmt->rowCount();
        }

        return self::recordRun($db, $key, $mode, $affected, $start, ['cutoff' => $cutoff]);
    }

    private static function retentionDeleteExpiredTtl(PDO $db, string $key, array $p, string $mode, float $start): array {
        $table = $p['table'];
        $field = (string)($p['field'] ?? 'checked_at');
        $ttlField = (string)($p['ttl_field'] ?? 'ttl_seconds');
        // Driver-aware: row expires when field + ttl_seconds is in the past.
        $sql = Db::dateAddSeconds($field, $ttlField) . " <= " . Db::now();
        $countStmt = $db->query("SELECT COUNT(*) AS c FROM {$table} WHERE {$sql}");
        $affected = (int)$countStmt->fetch()['c'];

        if ($mode === 'apply' && $affected > 0) {
            $stmt = $db->prepare("DELETE FROM {$table} WHERE {$sql}");
            $stmt->execute();
            $affected = $stmt->rowCount();
        }

        return self::recordRun($db, $key, $mode, $affected, $start, ['ttl_field' => $ttlField]);
    }

    private static function retentionScrubPii(PDO $db, string $key, array $p, string $mode, float $start): array {
        $table  = $p['table'];
        $field  = (string)($p['field'] ?? 'created_at');
        $scrub  = $p['scrub_fields'] ?? [];
        if (!is_array($scrub) || empty($scrub)) {
            throw new InvalidArgumentException("policy.scrub_fields required for scrub_pii");
        }
        foreach ($scrub as $f) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$f)) {
                throw new InvalidArgumentException("policy.scrub_fields invalid: {$f}");
            }
        }
        // Checked in dry-run too: a wrong column would otherwise only fail on --apply once old rows exist.
        $missing = array_diff($scrub, self::tableColumns($db, $table));
        if ($missing) {
            throw new InvalidArgumentException("policy.scrub_fields not in {$table}: " . implode(', ', $missing));
        }
        $cutoff = self::computeCutoff($p);
        $markerPrefix = '[erased per GDPR retention at ';
        $marker = $markerPrefix . gmdate('Y-m-d') . ']';

        // Only rows created before the cutoff that still hold PII in at least one field: a re-run
        // neither rewrites rows scrubbed earlier (and their marker date) nor counts them again.
        // Distinct placeholders: native MySQL prepares reject a repeated named parameter.
        $params = [':cutoff' => $cutoff];
        $pending = [];
        $sets = [];
        foreach (array_values($scrub) as $i => $f) {
            $pending[] = "({$f} IS NOT NULL AND {$f} NOT LIKE :erased{$i})";
            $params[":erased{$i}"] = $markerPrefix . '%';
            $sets[] = "{$f} = :marker{$i}";
        }
        $where = "{$field} <= :cutoff AND (" . implode(' OR ', $pending) . ')';
        $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM {$table} WHERE {$where}");
        $countStmt->execute($params);
        $affected = (int)$countStmt->fetch()['c'];

        if ($mode === 'apply' && $affected > 0) {
            foreach (array_keys($sets) as $i) $params[":marker{$i}"] = $marker;
            $stmt = $db->prepare("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$where}");
            $stmt->execute($params);
            $affected = $stmt->rowCount();
        }

        return self::recordRun($db, $key, $mode, $affected, $start, [
            'cutoff' => $cutoff, 'fields' => $scrub
        ]);
    }

    private static function retentionHashEmail(PDO $db, string $key, array $p, string $mode, float $start): array {
        $table = $p['table'];
        $field = (string)($p['field'] ?? 'created_at');
        // Resolve email column: explicit policy.scrub_fields[0] OR auto-detect (email | customer_email)
        $emailCol = null;
        $scrub = $p['scrub_fields'] ?? null;
        if (is_array($scrub) && !empty($scrub)) {
            $emailCol = (string)$scrub[0];
        } else {
            foreach (self::tableColumns($db, $table) as $column) {
                $n = strtolower($column);
                if ($n === 'email' || $n === 'customer_email') { $emailCol = $column; break; }
            }
        }
        if ($emailCol === null || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $emailCol)) {
            throw new InvalidArgumentException("hash_email: cannot resolve email column for {$table}");
        }

        $cutoff = self::computeCutoff($p);
        // count rows with email NOT already hashed AND older than cutoff
        $countStmt = $db->prepare("
            SELECT COUNT(*) AS c FROM {$table}
            WHERE {$field} <= :cutoff
              AND {$emailCol} IS NOT NULL
              AND {$emailCol} != ''
              AND {$emailCol} NOT LIKE 'sha256:%'
              AND length({$emailCol}) != 64
        ");
        $countStmt->execute([':cutoff' => $cutoff]);
        $affected = (int)$countStmt->fetch()['c'];

        if ($mode === 'apply' && $affected > 0) {
            // Fetch + hash + update per row (salt is in PHP, not SQL)
            $rows = $db->prepare("
                SELECT id, {$emailCol} AS email FROM {$table}
                WHERE {$field} <= :cutoff
                  AND {$emailCol} IS NOT NULL
                  AND {$emailCol} != ''
                  AND {$emailCol} NOT LIKE 'sha256:%'
                  AND length({$emailCol}) != 64
            ");
            $rows->execute([':cutoff' => $cutoff]);
            $upd = $db->prepare("UPDATE {$table} SET {$emailCol} = ? WHERE id = ?");
            $count = 0;
            foreach ($rows->fetchAll() as $r) {
                $upd->execute([self::hashPii(strtolower((string)$r['email'])), (int)$r['id']]);
                $count++;
            }
            $affected = $count;
        }

        return self::recordRun($db, $key, $mode, $affected, $start, ['cutoff' => $cutoff, 'column' => $emailCol]);
    }

    private static function computeCutoff(array $p): string {
        if (isset($p['ttl_days']))  return gmdate('Y-m-d H:i:s', time() - 86400 * (int)$p['ttl_days']);
        if (isset($p['ttl_hours'])) return gmdate('Y-m-d H:i:s', time() - 3600  * (int)$p['ttl_hours']);
        throw new InvalidArgumentException("policy must define ttl_days or ttl_hours");
    }

    private static function recordRun(PDO $db, string $key, string $mode, int $affected, float $start, array $details): array {
        $duration = (int)round((microtime(true) - $start) * 1000);
        if ($mode === 'apply') {
            $stmt = $db->prepare("
                INSERT INTO data_retention_runs (mode, policy_key, affected_rows, details, duration_ms)
                VALUES (:mode, :key, :rows, :details, :dur)
            ");
            $stmt->execute([
                ':mode' => $mode, ':key' => $key,
                ':rows' => $affected,
                ':details' => json_encode($details, JSON_UNESCAPED_UNICODE),
                ':dur' => $duration,
            ]);
        }
        return [
            'policy'   => $key,
            'mode'     => $mode,
            'affected' => $affected,
            'duration_ms' => $duration,
            'details'  => $details,
        ];
    }

    private static function ownershipParams(array $customer): array {
        return [
            ':order_store' => StoreContext::current()->id(),
            ':customer_id' => (int)$customer['id'],
            ':verified' => !empty($customer['email_verified_at']) ? 1 : 0,
            ':guest' => 0,
            ':owner_email' => (string)$customer['email'],
        ];
    }

    /** Tables added by later migrations may be missing on a partially migrated schema: there is nothing to erase there. */
    private static function hasTable(PDO $db, string $table): bool {
        return Db::tableExists($table, $db);
    }

    private static function tableColumns(PDO $db, string $table): array {
        return Db::tableColumns($table, $db);
    }

    /** Returns config array. Stored separately so tests can stub. */
    private static function config(): array {
        return require __DIR__ . '/../config.php';
    }
}
