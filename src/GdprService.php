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
        'customer_addresses'           => ['columns' => ['name', 'company', 'vat_id', 'ico', 'dic', 'street', 'city', 'zip', 'phone'], 'erase' => 'delete', 'export' => 'addresses'],
        'customer_email_verifications' => ['columns' => ['customer_id', 'email'], 'erase' => 'delete', 'export' => null,
            'reason' => 'single-use token hashes; the e-mail itself is exported with the customer'],
        'password_reset_tokens'        => ['columns' => ['customer_id', 'token'], 'erase' => 'delete', 'export' => null,
            'reason' => 'credential secrets are never exported'],
        'orders'                       => ['columns' => ['customer_id', 'customer_name', 'customer_email', 'customer_phone', 'shipping_addr', 'billing_addr', 'ip_address', 'notes'], 'erase' => 'retain', 'export' => 'orders',
            'reason' => '10-year accounting retention (SK Act 431/2002 §35), scrubbed by cli/gdpr-gc.php afterwards; ip_address, customer_phone and notes are no accounting data: erased with the customer and scrubbed after 180 days (orders_contact)'],
        'order_items'                  => ['columns' => ['name'], 'erase' => 'retain', 'export' => 'orders', 'reason' => 'accounting record of the retained order'],
        'invoices'                     => ['columns' => ['customer_snapshot'], 'erase' => 'retain', 'export' => 'orders', 'reason' => 'accounting record of the retained order'],
        // Proof of the consent given at checkout (Art.7(1)), kept with the retained order; R51 (low): the browser's
        // user-agent proves nothing of it and is erased (was: kept for the 10 years).
        'order_consents'               => ['columns' => ['ip_hash', 'user_agent'], 'erase' => 'scrub', 'export' => 'orders'],
        // R51 (low): the carrier's printable label and its link repeat the name, address and phone; the parcel number,
        // carrier and status stay for tracking and cash-on-delivery reconciliation.
        'shipping_labels'              => ['columns' => ['label_data'], 'erase' => 'scrub', 'export' => null,
            'reason' => 'carrier print data; the name and address on it are exported with the order'],
        'return_labels'                => ['columns' => ['label_url'], 'erase' => 'scrub', 'export' => null,
            'reason' => 'carrier link to the printable return label; the return itself is exported (rma_requests)'],
        'coupon_uses'                  => ['columns' => ['customer_email'], 'erase' => 'scrub', 'export' => 'coupon_uses'],
        'coupon_usage_counters'        => ['columns' => ['subject'], 'erase' => 'delete', 'export' => null,
            'reason' => 'per-customer coupon counters keyed by an unsalted hash of the e-mail; the uses themselves are exported (coupon_uses)'],
        'api_idempotency'              => ['columns' => ['response_body'], 'erase' => 'delete', 'export' => null,
            'reason' => 'replay cache of /api/v1 responses; the orders and customers they returned are exported'],
        'email_log'                    => ['columns' => ['recipient', 'subject', 'payload'], 'erase' => 'scrub', 'export' => 'email_log'],
        'audit_log'                    => ['columns' => ['old_value', 'new_value'], 'erase' => 'scrub', 'export' => null,
            'reason' => 'staff accountability trail; the data it snapshots is exported from its source tables'],
        'cookie_consent'               => ['columns' => ['customer_id', 'session_id', 'ip_hash', 'user_agent'], 'erase' => 'scrub', 'export' => 'consent_history'],
        'product_reviews'              => ['columns' => ['customer_id', 'author_name', 'title', 'body'], 'erase' => 'delete', 'export' => 'reviews'],
        'wishlist_items'               => ['columns' => ['owner_key', 'customer_id'], 'erase' => 'delete', 'export' => 'wishlist'],
        'saved_for_later_items'        => ['columns' => ['customer_id'], 'erase' => 'delete', 'export' => 'saved_for_later'],
        'abandoned_carts'              => ['columns' => ['customer_email', 'cart_data'], 'erase' => 'delete', 'export' => 'abandoned_carts'],
        'back_in_stock_requests'       => ['columns' => ['email'], 'erase' => 'delete', 'export' => 'stock_notifications'],
        'rma_requests'                 => ['columns' => ['customer_id', 'reason', 'admin_note'], 'erase' => 'scrub', 'export' => 'rma_requests'],
        'rma_items'                    => ['columns' => ['name'], 'erase' => 'retain', 'export' => 'rma_requests', 'reason' => 'product lines of the retained RMA (credit-note trail)'],
        'return_receipts'              => ['columns' => ['note'], 'erase' => 'scrub', 'export' => 'rma_requests'],
        // Name, e-mail, items and receipt time prove the withdrawal statement (Directive 2011/83/EU art. 11a) and stay with the
        // retained order; the optional free-text reason is no proof and is erased.
        'withdrawal_requests'          => ['columns' => ['customer_name', 'customer_email', 'reason'], 'erase' => 'scrub', 'export' => 'withdrawals'],
        'complaints'                   => ['columns' => ['customer_name', 'customer_email', 'defect', 'decision_note'], 'erase' => 'retain', 'export' => 'complaints',
            'reason' => 'complaint record and the written decision for the 24-month liability (Directive (EU) 2019/771): kept with the retained order for legal claims (GDPR Art.17(3)(e))'],
        'event_outbox'                 => ['columns' => ['payload'], 'erase' => 'scrub', 'export' => 'events'],
        'queue_jobs'                   => ['columns' => ['payload'], 'erase' => 'scrub', 'export' => 'queued_jobs'],
        // The gateway's answer can name the payer (Stripe customer_details, PayPal payer, receipt_email): erasure removes those
        // fields and keeps ids, amounts and events the refund and duplicate-capture checks read.
        'payment_transactions'         => ['columns' => ['raw_response'], 'erase' => 'scrub', 'export' => 'orders'],
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
        return self::$cachedSalt ??= self::saltOf(self::config());
    }

    /** The salt of config $cfg: configured, read from gdpr_salt_file, else created there. */
    private static function saltOf(array $cfg): string {
        $configured = (string)($cfg['gdpr_hash_salt'] ?? '');
        if ($configured !== '') return $configured;

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
            return $stored;
        }

        // R62 (review round 27, finding 9): a restore onto a new server sets the backup's salt aside; a fresh one made here
        // would never match the pseudonyms of the restored records, and the copy moved in later would orphan every hash
        // made meanwhile. Refused until the copy is moved in (or deleted to start a new salt on purpose).
        $aside = RestoreRecovery::asideCopies(RestoreRecovery::directory($cfg, dirname(__DIR__)), basename($saltFile))[0] ?? null;
        if ($aside !== null) {
            throw new RuntimeException("GDPR salt file {$saltFile} is missing and a restore set the backup's salt aside at {$aside}:"
                . " move it into place (mv {$aside} {$saltFile}) before the shop hashes personal data. A new salt would not match the"
                . ' restored records; delete the copy only to start one on purpose.');
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
        $stmt = self::prepare($db, "
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
        $stmt = self::prepare($db, "
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
     *           'abandoned_carts'=>[], 'rma_requests'=>[{rma, items, receipts}], 'withdrawals'=>[], 'complaints'=>[],
     *           'events'=>[], 'queued_jobs'=>[],
     *           'meta'=>{exported_at, customer_id}] — one section per PII_REGISTRY 'export' key.
     * Payment data redacted (no `raw_response` field). 'queued_jobs' lists the queue_jobs table and
     * the file backend's job files; jobs held by the Redis backend are not listed (see anonymizeCustomer()).
     */
    public static function exportCustomer(int $customerId): array {
        $db = Db::get();

        $stmt = self::prepare($db, "SELECT * FROM customers WHERE id = ? AND store_id = ?");
        $stmt->execute([$customerId, StoreContext::current()->id()]);
        $customer = $stmt->fetch();
        if (!$customer) throw new RuntimeException("Customer #{$customerId} not found");

        // Redact password_hash
        unset($customer['password_hash']);

        $stmt = self::prepare($db, "SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY id");
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
        $stmt = self::prepare(Db::get(), "SELECT COUNT(*) FROM orders WHERE store_id = ? AND customer_id IS NULL AND LOWER(customer_email) = LOWER(?)");
        $stmt->execute([StoreContext::current()->id(), $email]);
        return (int)$stmt->fetchColumn();
    }

    private static function exportSubject(PDO $db, ?array $customer, int $customerId, array $addresses, array $ownership): array {
        $email = (string)$ownership[':owner_email'];
        $stmt = self::prepare($db, "SELECT * FROM orders WHERE id IN (" . self::CUSTOMER_ORDERS . ") ORDER BY id ASC");
        $stmt->execute($ownership);
        $orders = [];
        $hasOrderConsents = self::hasTable($db, 'order_consents');
        foreach ($stmt->fetchAll() as $o) {
            $orderId = (int)$o['id'];
            $itemsStmt = self::prepare($db, "SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
            $itemsStmt->execute([$orderId]);
            $invStmt = self::prepare($db, "
                SELECT id, number, issued_at, due_date, total_net, total_vat, total_gross, currency, status, type
                FROM invoices WHERE order_id = ? AND store_id = ? ORDER BY id
            ");
            $invStmt->execute([$orderId, StoreContext::current()->id()]);
            // Redact payment_transactions raw_response
            $payStmt = self::prepare($db, "
                SELECT id, provider, provider_ref, status, amount, currency, idempotency_key, created_at, updated_at
                FROM payment_transactions WHERE order_id = ? AND store_id = ? ORDER BY id
            ");
            $payStmt->execute([$orderId, StoreContext::current()->id()]);
            $consents = [];
            if ($hasOrderConsents) {
                $consentStmt = self::prepare($db, "SELECT * FROM order_consents WHERE order_id = ? ORDER BY id");
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

        // Unverified accounts may see only mail linked to their own orders. R25: mail the log provider only wrote down
        // reached nobody; the export does not tell the customer it was sent.
        $stmt = self::prepare($db, "
            SELECT id, order_id, recipient, template, locale, subject, adapter,
                CASE WHEN status = 'sent' AND adapter = 'log' THEN 'logged' ELSE status END AS status, sent_at, created_at
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
            $stmt = self::prepare($db, "SELECT * FROM cookie_consent WHERE customer_id = ?" . self::inList(' OR session_id', $sessionKeys) . " ORDER BY id ASC");
            $stmt->execute(array_merge([$customerId], $sessionKeys));
            $consent = $stmt->fetchAll();
        }

        $stmt = self::prepare($db, "SELECT id, store_id, order_id, coupon_code, customer_email, discount, created_at FROM coupon_uses
            WHERE store_id = :coupon_store AND LOWER(customer_email) = LOWER(:coupon_email) AND order_id IN (" . self::CUSTOMER_ORDERS . ") ORDER BY id ASC");
        $stmt->execute($ownership + [':coupon_store' => $store, ':coupon_email' => $email]);
        $couponUses = $stmt->fetchAll();

        $stmt = self::prepare($db, "SELECT * FROM product_reviews WHERE customer_id = ? ORDER BY id ASC");
        $stmt->execute([$customerId]);
        $reviews = $stmt->fetchAll();

        $owners = array_merge(['c:' . $customerId], array_map(static fn(string $key): string => 's:' . $key, $sessionKeys));
        $stmt = self::prepare($db, "SELECT * FROM wishlist_items WHERE customer_id = ?" . self::inList(' OR owner_key', $owners) . " ORDER BY id ASC");
        $stmt->execute(array_merge([$customerId], $owners));
        $wishlist = $stmt->fetchAll();

        // "Buy later" lines of the account (a guest's list never leaves the session).
        $savedForLater = [];
        if (self::hasTable($db, 'saved_for_later_items')) {
            $stmt = self::prepare($db, "SELECT id, store_id, product_id, variant_id, qty, saved_price, saved_currency, created_at FROM saved_for_later_items WHERE customer_id = ? ORDER BY id ASC");
            $stmt->execute([$customerId]);
            $savedForLater = $stmt->fetchAll();
        }

        $stmt = self::prepare($db, "SELECT id, session_id, customer_email, cart_data, cart_value, item_count, currency, locale, order_id,
                created_at, updated_at, reminded_at, converted_at, reminder_status
            FROM abandoned_carts WHERE " . self::CUSTOMER_CARTS . " ORDER BY id ASC");
        $stmt->execute($ownership + [':cart_email' => $email, ':cart_store' => $store, ':cart_verified' => $ownership[':verified']]);
        $carts = $stmt->fetchAll();

        // Anyone can type an address into the form: an unverified account does not own the requests under it.
        $stockNotifications = [];
        if ((string)$ownership[':verified'] === '1' && self::hasTable($db, 'back_in_stock_requests')) {
            $stmt = self::prepare($db, "SELECT id, product_id, email, locale, created_at, confirmed_at FROM back_in_stock_requests WHERE store_id = ? AND LOWER(email) = LOWER(?) ORDER BY id ASC");
            $stmt->execute([$store, $email]);
            $stockNotifications = $stmt->fetchAll();
        }

        $stmt = self::prepare($db, "SELECT * FROM rma_requests
            WHERE store_id = :rma_store AND (customer_id = :rma_customer OR order_id IN (" . self::CUSTOMER_ORDERS . ")) ORDER BY id ASC");
        $stmt->execute($ownership + [':rma_store' => $store, ':rma_customer' => $customerId]);
        $rmas = [];
        $hasReceipts = self::hasTable($db, 'return_receipts');
        foreach ($stmt->fetchAll() as $rma) {
            $itemsStmt = self::prepare($db, "SELECT * FROM rma_items WHERE rma_id = ? ORDER BY id");
            $itemsStmt->execute([(int)$rma['id']]);
            $receipts = [];
            if ($hasReceipts) {
                $receiptStmt = self::prepare($db, "SELECT id, rma_item_id, quantity, disposition, note, created_at FROM return_receipts WHERE rma_id = ? AND store_id = ? ORDER BY id");
                $receiptStmt->execute([(int)$rma['id'], $store]);
                $receipts = $receiptStmt->fetchAll();
            }
            $rmas[] = ['rma' => $rma, 'items' => $itemsStmt->fetchAll(), 'receipts' => $receipts];
        }

        $withdrawals = self::orderRecords($db, 'withdrawal_requests', $orderIds);
        $complaints = self::orderRecords($db, 'complaints', $orderIds);

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
            'saved_for_later' => $savedForLater,
            'abandoned_carts' => $carts,
            'stock_notifications' => $stockNotifications,
            'rma_requests' => $rmas,
            'withdrawals' => $withdrawals,
            'complaints' => $complaints,
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
     *   - coupon_uses: hash email; coupon_usage_counters of the e-mail: DELETE
     *   - api_idempotency responses naming the e-mail or one of the person's order numbers: DELETE
     *   - everything else in PII_REGISTRY: see eraseCustomerContent()
     *   - orders.customer_*, invoices.customer_snapshot: KEPT (10y legal retention), except the phone, IP and note
     *     of the orders and the phone in their invoices' snapshot (the issued PDFs stay as sent)
     * Queued jobs are scrubbed in the queue_jobs table (database backend) and in the job files of the
     * file backend (jobs_dir, see JobQueue::rewriteFileJobs()). The Redis backend is NOT covered: the
     * QueueBackend contract cannot enumerate jobs, so jobs held in Redis (including the completed ones
     * it keeps) keep the e-mail until the operator purges them, and the export does not list them.
     *
     * Logs gdpr_events row with action='erase'.
     */
    public static function anonymizeCustomer(int $customerId, string $actor = 'self'): array {
        $db = Db::get();
        $stmt = self::prepare($db, "SELECT * FROM customers WHERE id = ? AND store_id = ?");
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
            $stmt = self::prepare($db, "
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
            $stmt = self::prepare($db, "DELETE FROM customer_addresses WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $rowsAddr = $stmt->rowCount();

            $rows = ['customers' => $rowsCust, 'customer_addresses' => $rowsAddr]
                + self::eraseSubject($db, $customerId, $origEmail, $ownership, $placeholder, $erasureMarker);
            // A registered table without an erasure step must not report a completed erasure.
            self::assertRegistryCovered('erase', array_keys($rows));
            self::recordErasure($db, $customerId, $customerId, $ownership);
            $retained = count(self::customerOrderIds($db, $ownership));
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
            'retained_orders' => $retained,
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
            $retained = count(self::customerOrderIds($db, $ownership));
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        self::logErasure(null, $email, $actor, $rows);
        return ['status' => 'anonymized', 'customer_id' => null, 'rows' => $rows, 'retained_orders' => $retained];
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
            Logger::phpLog("GDPR erase audit failed [{$errorId}] for " . ($customerId !== null ? "customer #{$customerId}" : 'a guest') . ': ' . $e->getMessage());
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
        $account = self::prepare($db, "SELECT COUNT(*) FROM customers WHERE store_id = ? AND LOWER(email) = LOWER(?)");
        $account->execute([StoreContext::current()->id(), $email]);
        $ownership = [':order_store' => StoreContext::current()->id(), ':customer_id' => 0, ':verified' => (int)$account->fetchColumn() > 0 ? 0 : 1,
            ':guest' => 1, ':owner_email' => $email];
        if (self::customerOrderIds($db, $ownership) === [] && self::guestCartsAndRequests($db, $email) === 0) {
            throw new RuntimeException('No guest data for this e-mail');
        }
        return $ownership;
    }

    /** Abandoned carts and back-in-stock requests of the address in the current store: erasure deletes both. */
    private static function guestCartsAndRequests(PDO $db, string $email): int {
        $carts = self::prepare($db, "SELECT COUNT(*) FROM abandoned_carts WHERE LOWER(customer_email) = LOWER(?) AND (store_id = ? OR store_id IS NULL)");
        $carts->execute([$email, StoreContext::current()->id()]);
        $count = (int)$carts->fetchColumn();
        if (self::hasTable($db, 'back_in_stock_requests')) {
            $stmt = self::prepare($db, "SELECT COUNT(*) FROM back_in_stock_requests WHERE store_id = ? AND LOWER(email) = LOWER(?)");
            $stmt->execute([StoreContext::current()->id(), $email]);
            $count += (int)$stmt->fetchColumn();
        }
        return $count;
    }

    /**
     * The address's last guest erasure in the current store (the 'erase' event without an account, matched by the
     * address hash), or null when it was never erased. 'new_data' counts what an erasure would remove again: guest
     * orders placed after it, and carts or back-in-stock requests (the erasure deleted all of them). The admin shows
     * the erased state instead of offering an erasure that has nothing left to do.
     * @return array{erased_at: string, new_data: int}|null
     */
    public static function guestErasure(string $email): ?array {
        $email = Str::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        $db = Db::get();
        $store = StoreContext::current()->id();
        $stmt = self::prepare($db, "SELECT MAX(created_at) FROM gdpr_events WHERE store_id = ? AND action = 'erase' AND customer_id IS NULL AND email_hash = ?");
        $stmt->execute([$store, self::hashPii(strtolower($email))]);
        $erasedAt = $stmt->fetchColumn();
        if ($erasedAt === false || $erasedAt === null) return null;
        $stmt = self::prepare($db, "SELECT COUNT(*) FROM orders WHERE store_id = ? AND customer_id IS NULL AND LOWER(customer_email) = LOWER(?) AND created_at > ?");
        $stmt->execute([$store, $email, (string)$erasedAt]);
        return ['erased_at' => (string)$erasedAt, 'new_data' => (int)$stmt->fetchColumn() + self::guestCartsAndRequests($db, $email)];
    }

    /** Erasure shared by accounts and guests: mail log, audit trail, coupon uses and eraseCustomerContent(). */
    private static function eraseSubject(PDO $db, int $customerId, string $origEmail, array $ownership, string $placeholder, string $erasureMarker): array {
        // 3) email_log → scrub PII fields, keep template+adapter+status for stats
        $stmt = self::prepare($db, "
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
            $stmt = self::prepare($db, "
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
                $stmt = self::prepare($db, $sql);
                $stmt->execute($ownership + [':hashed' => $hashed, ':coupon_store' => StoreContext::current()->id(), ':coupon_email' => $origEmail]);
                $rowsCoupon = $stmt->rowCount();
            }
        }

        // R50: the per-customer coupon counters are keyed by an unsalted SHA-256 of the e-mail (anyone with the address
        // could find them). Deleted: the uses above are hashed with the salt, so they are not counted for that address
        // again; its uses in other stores still are (the counter is seeded from them).
        $rowsCounters = 0;
        if (self::tableColumns($db, 'coupon_usage_counters') !== []) {
            $rowsCounters = Pricing::forgetCustomerCounters($db, $origEmail, StoreContext::current()->id());
        }
        // R50: an /api/v1 replay cache entry holds the full response (an order with the customer's name, e-mail, phone
        // and address); the ones naming this e-mail go (a retried request is then executed again, idempotently).
        $rowsIdempotency = 0;
        if (self::tableColumns($db, 'api_idempotency') !== []) {
            // R51 (low): found by a plain (non-locking) read and deleted by id; a DELETE with this WHERE locked every row
            // of the table it scanned on MySQL until the erasure committed, and API requests waited on them.
            // R53 (review round 53, low): also the responses naming one of the person's orders by its number: a response
            // stored before the order's e-mail was corrected (or the account's changed) names the old address only.
            $needles = [strtolower($origEmail)];
            foreach (array_chunk(self::customerOrderIds($db, $ownership), 500) as $orderIds) {
                $numbers = self::prepare($db, 'SELECT order_number FROM orders WHERE 1 = 1' . self::inList(' AND id', $orderIds));
                $numbers->execute(array_map('intval', $orderIds));
                foreach ($numbers->fetchAll(PDO::FETCH_COLUMN) as $number) {
                    if ((string)$number !== '') $needles[] = strtolower('"number":' . json_encode((string)$number, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
            $found = [];
            foreach (array_chunk($needles, 100) as $chunk) {
                $stmt = self::prepare($db, 'SELECT id FROM api_idempotency WHERE store_id = ? AND ('
                    . implode(' OR ', array_fill(0, count($chunk), 'INSTR(LOWER(response_body), ?) > 0')) . ')');
                $stmt->execute(array_merge([StoreContext::current()->id()], $chunk));
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $found[(int)$id] = (int)$id;
            }
            foreach (array_chunk(array_values($found), 500) as $ids) {
                $delete = self::prepare($db, 'DELETE FROM api_idempotency WHERE 1 = 1' . self::inList(' AND id', $ids));
                $delete->execute($ids);
                $rowsIdempotency += $delete->rowCount();
            }
        }

        return [
            'email_log'          => $rowsEmail,
            'audit_log'          => $rowsAudit,
            'coupon_uses'        => $rowsCoupon,
            'coupon_usage_counters' => $rowsCounters,
            'api_idempotency'    => $rowsIdempotency,
        ] + self::eraseCustomerContent($db, $customerId, $origEmail, $ownership, $placeholder, $erasureMarker);
    }

    /**
     * R50: the phone number erased from the orders (no accounting data) stayed in the customer snapshot of their invoices
     * and credit notes, which the account, the admin and /api/v1 show. The issued PDF stays as it was sent: the accounting
     * record is kept in its original form (docs/design/gdpr.md, section 4).
     */
    private static function erasePhoneFromInvoices(PDO $db, array $orderIds): int {
        if ($orderIds === [] || self::tableColumns($db, 'invoices') === []) return 0;
        $stmt = self::prepare($db, 'SELECT id, customer_snapshot FROM invoices WHERE 1 = 1' . self::inList(' AND order_id', $orderIds));
        $stmt->execute($orderIds);
        $update = self::prepare($db, 'UPDATE invoices SET customer_snapshot = :snapshot WHERE id = :id');
        $changed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
            $snapshot = json_decode((string)$invoice['customer_snapshot'], true);
            if (!is_array($snapshot) || trim((string)($snapshot['phone'] ?? '')) === '') continue;
            $snapshot['phone'] = '';
            $update->execute([':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id' => (int)$invoice['id']]);
            $changed++;
        }
        return $changed;
    }

    /**
     * R50 (review round 20, medium): LOWER(column) = LOWER(:email) cannot use the column's index, so on MySQL an erasure
     * read every order of the shop, and its UPDATE ... IN (SELECT ... FROM orders) statements locked them: a payment
     * webhook waited 4.6 s. A MySQL/MariaDB *_ci collation compares case-insensitively already (LOWER() on both sides
     * was compared in that same collation), so there the plain comparison is the same match through the index. SQLite
     * and a case-sensitive collation (_bin, _cs) keep LOWER().
     */
    private static function prepare(PDO $db, string $sql): PDOStatement {
        if (self::collationMatchesEmails($db)) {
            $sql = (string)preg_replace('/LOWER\(([A-Za-z_.]+)\) = LOWER\((:[A-Za-z_]+|\?)\)/', '$1 = $2', $sql);
        }
        return $db->prepare($sql);
    }

    /** @var WeakMap<PDO, bool>|null */
    private static ?WeakMap $collationMatches = null;

    private static function collationMatchesEmails(PDO $db): bool {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return false;
        self::$collationMatches ??= new WeakMap();
        if (!isset(self::$collationMatches[$db])) {
            $columns = ['orders' => ['customer_email'], 'customers' => ['email']];
            foreach (['coupon_uses' => 'customer_email', 'abandoned_carts' => 'customer_email', 'back_in_stock_requests' => 'email', 'email_log' => 'recipient'] as $table => $column) {
                if (self::hasTable($db, $table)) $columns[$table] = [$column];
            }
            self::$collationMatches[$db] = Db::caseInsensitiveColumns($columns, $db);
        }
        return self::$collationMatches[$db];
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
     * credit-note trail); the optional reason of a withdrawal statement is scrubbed (the statement stays as proof);
     * outbox events and queued jobs (table and file backend) lose the e-mail in
     * their e-mail fields (see scrubPayloadEmail()) and customer events are rewritten.
     */
    private static function eraseCustomerContent(PDO $db, int $customerId, string $email, array $ownership, string $placeholder, string $marker): array {
        $store = StoreContext::current()->id();
        $rows = [];
        $orderIds = self::customerOrderIds($db, $ownership);
        // Read before carts and consents are erased: they are what links the browser sessions.
        $sessionKeys = self::customerSessionKeys($db, $customerId, $orderIds);

        // Not accounting data: the orders' IP address, phone number and free-text note go now; name, address and e-mail
        // stay for the 10-year retention (PII_REGISTRY 'orders'). The ids are bound (MySQL refuses an UPDATE of orders
        // that selects from orders, error 1093).
        $rows['orders'] = 0;
        if ($orderIds !== []) {
            $stmt = self::prepare($db, "UPDATE orders SET ip_address = '', customer_phone = '', notes = '', updated_at = " . Db::now() . "
                WHERE (ip_address <> '' OR customer_phone <> '' OR notes <> '')" . self::inList(' AND id', $orderIds));
            $stmt->execute($orderIds);
            $rows['orders'] = $stmt->rowCount();
        }
        $rows['invoices'] = self::erasePhoneFromInvoices($db, $orderIds);

        $stmt = self::prepare($db, "DELETE FROM product_reviews WHERE customer_id = :id");
        $stmt->execute([':id' => $customerId]);
        $rows['product_reviews'] = $stmt->rowCount();

        $owners = array_merge(['c:' . $customerId], array_map(static fn(string $key): string => 's:' . $key, $sessionKeys));
        $stmt = self::prepare($db, "DELETE FROM wishlist_items WHERE customer_id = ?" . self::inList(' OR owner_key', $owners));
        $stmt->execute(array_merge([$customerId], $owners));
        $rows['wishlist_items'] = $stmt->rowCount();

        $rows['saved_for_later_items'] = 0;
        if (self::hasTable($db, 'saved_for_later_items')) {
            $stmt = self::prepare($db, "DELETE FROM saved_for_later_items WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $rows['saved_for_later_items'] = $stmt->rowCount();
        }

        $stmt = self::prepare($db, "DELETE FROM abandoned_carts WHERE " . self::CUSTOMER_CARTS);
        $stmt->execute($ownership + [':cart_email' => $email, ':cart_store' => $store, ':cart_verified' => $ownership[':verified']]);
        $rows['abandoned_carts'] = $stmt->rowCount();

        $rows['back_in_stock_requests'] = 0;
        if (self::hasTable($db, 'back_in_stock_requests')) {
            $stmt = self::prepare($db, "DELETE FROM back_in_stock_requests WHERE store_id = ? AND LOWER(email) = LOWER(?)");
            $stmt->execute([$store, $email]);
            $rows['back_in_stock_requests'] = $stmt->rowCount();
        }

        foreach (['password_reset_tokens', 'customer_email_verifications'] as $tokenTable) {
            $rows[$tokenTable] = 0;
            if (!self::hasTable($db, $tokenTable)) continue;
            $stmt = self::prepare($db, "DELETE FROM {$tokenTable} WHERE customer_id = ?");
            $stmt->execute([$customerId]);
            $rows[$tokenTable] = $stmt->rowCount();
        }

        // The consent choice itself stays as Art.7(1) evidence; nothing links it to the person any more.
        $rows['cookie_consent'] = 0;
        if (self::hasTable($db, 'cookie_consent')) {
            $stmt = self::prepare($db, "UPDATE cookie_consent SET customer_id = NULL, session_id = NULL, ip_hash = NULL, user_agent = NULL
                WHERE customer_id = ?" . self::inList(' OR session_id', $sessionKeys));
            $stmt->execute(array_merge([$customerId], $sessionKeys));
            $rows['cookie_consent'] = $stmt->rowCount();
        }

        // Staff notes are free text that often quotes the customer: redacted like the reason, never kept.
        $rmaScope = "store_id = :rma_store AND (customer_id = :rma_customer OR order_id IN (" . self::CUSTOMER_ORDERS . "))";
        $stmt = self::prepare($db, "UPDATE rma_requests SET reason = :marker,
                admin_note = CASE WHEN admin_note IS NULL OR admin_note = '' THEN admin_note ELSE :note_marker END,
                updated_at = " . Db::now() . "
            WHERE {$rmaScope}");
        $stmt->execute($ownership + [':marker' => $marker, ':note_marker' => $marker, ':rma_store' => $store, ':rma_customer' => $customerId]);
        $rows['rma_requests'] = $stmt->rowCount();
        $rows['return_receipts'] = 0;
        if (self::hasTable($db, 'return_receipts')) {
            $stmt = self::prepare($db, "UPDATE return_receipts SET note = :marker
                WHERE store_id = :receipt_store AND note IS NOT NULL AND note <> '' AND rma_id IN (SELECT id FROM rma_requests WHERE {$rmaScope})");
            $stmt->execute($ownership + [':marker' => $marker, ':receipt_store' => $store, ':rma_store' => $store, ':rma_customer' => $customerId]);
            $rows['return_receipts'] = $stmt->rowCount();
        }

        $rows['withdrawal_requests'] = 0;
        if ($orderIds !== [] && self::hasTable($db, 'withdrawal_requests')) {
            $stmt = self::prepare($db, "UPDATE withdrawal_requests SET reason = ? WHERE store_id = ? AND reason IS NOT NULL AND reason <> ''" . self::inList(' AND order_id', $orderIds));
            $stmt->execute(array_merge([$marker, $store], $orderIds));
            $rows['withdrawal_requests'] = $stmt->rowCount();
        }

        $rows['order_consents'] = $rows['shipping_labels'] = $rows['return_labels'] = 0;
        if ($orderIds !== []) {
            if (self::hasTable($db, 'order_consents')) {
                $stmt = self::prepare($db, "UPDATE order_consents SET user_agent = '' WHERE user_agent <> ''" . self::inList(' AND order_id', $orderIds));
                $stmt->execute($orderIds);
                $rows['order_consents'] = $stmt->rowCount();
            }
            if (self::hasTable($db, 'shipping_labels')) {
                $stmt = self::prepare($db, "UPDATE shipping_labels SET label_data = '' WHERE label_data <> ''" . self::inList(' AND order_id', $orderIds));
                $stmt->execute($orderIds);
                $rows['shipping_labels'] = $stmt->rowCount();
            }
        }
        if (self::hasTable($db, 'return_labels')) {
            $stmt = self::prepare($db, "UPDATE return_labels SET label_url = NULL
                WHERE store_id = :label_store AND label_url IS NOT NULL AND rma_id IN (SELECT id FROM rma_requests WHERE {$rmaScope})");
            $stmt->execute($ownership + [':label_store' => $store, ':rma_store' => $store, ':rma_customer' => $customerId]);
            $rows['return_labels'] = $stmt->rowCount();
        }

        $rows['payment_transactions'] = 0;
        if ($orderIds !== []) {
            $stmt = self::prepare($db, "SELECT id, raw_response FROM payment_transactions WHERE store_id = ?" . self::inList(' AND order_id', $orderIds));
            $stmt->execute(array_merge([$store], $orderIds));
            $update = self::prepare($db, "UPDATE payment_transactions SET raw_response = ? WHERE id = ?");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tx) {
                $raw = json_decode((string)$tx['raw_response'], true);
                if (!is_array($raw) || ($scrubbed = self::scrubPaymentPayer($raw, $email, $placeholder)) === $raw) continue;
                $update->execute([json_encode($scrubbed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $tx['id']]);
                $rows['payment_transactions'] += $update->rowCount();
            }
        }

        $payloads = self::customerPayloadRows($db, $customerId, $email, $ownership, $orderIds);
        $rows['event_outbox'] = 0;
        $erasedEvent = json_encode(['customer_id' => $customerId, 'erased' => true]);
        $update = self::prepare($db, "UPDATE event_outbox SET payload = ? WHERE id = ?");
        foreach ($payloads['event_outbox'] as $event) {
            $payload = $event['aggregate_type'] === 'customer' ? $erasedEvent : self::scrubPayloadJson((string)$event['payload'], $email, $placeholder);
            $update->execute([$payload, $event['id']]);
            $rows['event_outbox'] += $update->rowCount();
        }
        $rows['queue_jobs'] = 0;
        $update = self::prepare($db, "UPDATE queue_jobs SET payload = ? WHERE id = ?");
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
        $stmt = self::prepare($db, self::CUSTOMER_ORDERS);
        $stmt->execute($ownership);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Withdrawal statements or complaints of the customer's orders in the current store. */
    private static function orderRecords(PDO $db, string $table, array $orderIds): array {
        if ($orderIds === [] || !self::hasTable($db, $table)) return [];
        $stmt = self::prepare($db, "SELECT * FROM {$table} WHERE store_id = ?" . self::inList(' AND order_id', $orderIds) . ' ORDER BY id');
        $stmt->execute(array_merge([StoreContext::current()->id()], $orderIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
            $stmt = self::prepare($db, "SELECT session_id FROM cookie_consent WHERE customer_id = ? AND session_id IS NOT NULL");
            $stmt->execute([$customerId]);
            $keys = array_merge($keys, $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($orderIds !== [] && self::hasTable($db, 'checkout_sessions')) {
            $stmt = self::prepare($db, "SELECT browser_session_hash FROM checkout_sessions WHERE browser_session_hash IS NOT NULL" . self::inList(' AND order_id', $orderIds));
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
        $stmt = self::prepare($db, "SELECT * FROM event_outbox WHERE store_id = ? AND aggregate_type = 'customer' AND aggregate_id = ? ORDER BY id");
        $stmt->execute([$store, (string)$customerId]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $jobs = [];
        if ($email === '') return ['event_outbox' => $events, 'queue_jobs' => $jobs];
        // Order ids are bound as strings: a CAST(... AS CHAR) subquery mixes collations with aggregate_id on MySQL.
        if ($orderIds !== []) {
            $stmt = self::prepare($db, "SELECT * FROM event_outbox WHERE store_id = ? AND aggregate_type = 'order'" . self::inList(' AND aggregate_id', $orderIds) . " ORDER BY id");
            $stmt->execute(array_merge([$store], $orderIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
                if (self::payloadNamesEmail(json_decode((string)$event['payload'], true), $email)) $events[] = $event;
            }
        }
        if (!self::hasTable($db, 'queue_jobs')) return ['event_outbox' => $events, 'queue_jobs' => $jobs];
        $stmt = self::prepare($db, "SELECT * FROM queue_jobs WHERE store_id = ? AND INSTR(LOWER(payload), ?) > 0 ORDER BY created_at, id");
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
        return JobQueue::fileJobs(static fn(array $job): bool =>
            self::fileJobInStore($job, $store) && self::jobBelongsToCustomer($job['payload'] ?? null, $email, $ownership, $orderIds));
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

    /** Gateway fields that name the payer; the whole value (an address object too) becomes the placeholder. */
    // PayU names the payer in buyer{…, delivery{street, postalCode, city, recipientName…}} and the IP in customerIp.
    private const PAYMENT_PAYER_KEYS = '/^(?:payer|buyer|contact|customer_details|billing_details|delivery|shipping(?:_details|_address)?|address|billing_address|(?:customer_?|client_?)?ip(?:_?address)?|recipient(?:_?(?:name|email|phone))?|(?:customer_|receipt_|payer_|billing_)?(?:email|name|phone)|(?:first|last|full)_?name)$/i';

    /** A gateway response without its payer fields, and without any other value equal to the customer's e-mail. */
    private static function scrubPaymentPayer(mixed $data, string $email, string $placeholder): mixed {
        if (!is_array($data)) return is_string($data) && $email !== '' && strcasecmp(trim($data), $email) === 0 ? $placeholder : $data;
        foreach ($data as $key => $value) {
            $data[$key] = is_string($key) && preg_match(self::PAYMENT_PAYER_KEYS, $key) === 1 ? $placeholder : self::scrubPaymentPayer($value, $email, $placeholder);
        }
        return $data;
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
        $params = [];
        $expired = self::expiredWhere($p, $field, $params);
        $countStmt = self::prepare($db, "SELECT COUNT(*) AS c FROM {$table} WHERE {$expired}");
        $countStmt->execute($params);
        $affected = (int)$countStmt->fetch()['c'];
        $countStmt->closeCursor();

        if ($mode === 'apply' && $affected > 0) {
            $affected = self::retentionWrite($db, $p, "DELETE FROM {$table}", $expired, $params);
        }

        return self::recordRun($db, $key, $mode, $affected, $start, ['cutoff' => $cutoff] + self::countryCutoffs($p));
    }

    private static function retentionDeleteExpiredTtl(PDO $db, string $key, array $p, string $mode, float $start): array {
        $table = $p['table'];
        $field = (string)($p['field'] ?? 'checked_at');
        $ttlField = (string)($p['ttl_field'] ?? 'ttl_seconds');
        // Driver-aware: row expires when field + ttl_seconds is in the past.
        $sql = Db::dateAddSeconds($field, $ttlField) . " <= " . Db::now();
        $countStmt = $db->query("SELECT COUNT(*) AS c FROM {$table} WHERE {$sql}");
        $affected = (int)$countStmt->fetch()['c'];
        $countStmt->closeCursor();

        if ($mode === 'apply' && $affected > 0) {
            $affected = self::retentionWrite($db, $p, "DELETE FROM {$table}", $sql);
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
        // status_in: only rows in one of these statuses (e.g. closed orders: an open one still needs the phone for delivery).
        $statuses = $p['status_in'] ?? null;
        if ($statuses !== null && (!is_array($statuses) || $statuses === [] || array_filter($statuses, static fn($s): bool => !is_string($s) || !preg_match('/^[a-z_]+$/', $s)))) {
            throw new InvalidArgumentException("policy.status_in invalid for {$key}");
        }
        // keep_lines: field => regex of lines that are internal records (refund id, ERP marker), kept when the rest is erased.
        $keepLines = $p['keep_lines'] ?? [];
        if (!is_array($keepLines) || array_diff(array_keys($keepLines), $scrub)
            || array_filter($keepLines, static fn($re): bool => !is_string($re) || @preg_match($re, '') === false)) {
            throw new InvalidArgumentException("policy.keep_lines invalid for {$key}");
        }
        $cutoff = self::computeCutoff($p);
        $markerPrefix = '[erased per GDPR retention at ';
        $marker = $markerPrefix . gmdate('Y-m-d') . ']';

        // Only rows created before the cutoff that still hold PII in at least one field: a re-run
        // neither rewrites rows scrubbed earlier (and their marker date) nor counts them again. An empty
        // field holds nothing and stays empty (the marker would show on documents printed later).
        // Distinct placeholders: native MySQL prepares reject a repeated named parameter.
        $params = [];
        $expired = self::expiredWhere($p, $field, $params);
        $pending = [];
        $sets = [];
        foreach (array_values($scrub) as $i => $f) {
            $pending[] = "({$f} IS NOT NULL AND {$f} <> '' AND {$f} NOT LIKE :erased{$i})";
            // A field with kept lines carries the marker after them.
            $params[":erased{$i}"] = (isset($keepLines[$f]) ? '%' : '') . $markerPrefix . '%';
            $sets[] = "{$f} = CASE WHEN {$f} IS NULL OR {$f} = '' THEN {$f} ELSE :marker{$i} END";
        }
        $where = "({$expired}) AND (" . implode(' OR ', $pending) . ')';
        foreach (array_values($statuses ?? []) as $i => $status) $params[":status{$i}"] = $status;
        if ($statuses !== null) $where .= ' AND status IN (' . implode(', ', array_map(static fn(int $i): string => ":status{$i}", array_keys(array_values($statuses)))) . ')';
        $countStmt = self::prepare($db, "SELECT COUNT(*) AS c FROM {$table} WHERE {$where}");
        $countStmt->execute($params);
        $affected = (int)$countStmt->fetch()['c'];
        $countStmt->closeCursor();

        if ($mode === 'apply' && $affected > 0 && $keepLines) {
            $affected = self::scrubKeepingLines($db, $table, $scrub, $keepLines, $where, $params, $marker, $markerPrefix);
        } elseif ($mode === 'apply' && $affected > 0) {
            $markers = [];
            foreach (array_keys($sets) as $i) $markers[":marker{$i}"] = $marker;
            $affected = self::retentionWrite($db, $p, "UPDATE {$table} SET " . implode(', ', $sets), $where, $params, $markers);
        }

        return self::recordRun($db, $key, $mode, $affected, $start, [
            'cutoff' => $cutoff, 'fields' => $scrub
        ] + self::countryCutoffs($p));
    }

    /**
     * scrub_pii with keep_lines, row by row in id-keyset chunks: a field with kept lines keeps them followed by the
     * marker; the other fields get the marker unless empty or already scrubbed.
     */
    private static function scrubKeepingLines(PDO $db, string $table, array $scrub, array $keepLines, string $where, array $params, string $marker, string $markerPrefix): int {
        $columns = implode(', ', $scrub);
        $read = self::prepare($db, "SELECT id, {$columns} FROM {$table} WHERE id > :after AND {$where} ORDER BY id LIMIT 1000");
        $update = self::prepare($db, "UPDATE {$table} SET " . implode(', ', array_map(static fn(string $f): string => "{$f} = :{$f}", $scrub)) . ' WHERE id = :id');
        $count = 0;
        $after = 0;
        do {
            $read->execute($params + [':after' => $after]);
            $rows = $read->fetchAll(PDO::FETCH_ASSOC);
            $read->closeCursor();
            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) $db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $after = (int)$row['id'];
                    $values = [':id' => $after];
                    foreach ($scrub as $f) {
                        $value = $row[$f];
                        if ($value === null || $value === '' || str_contains((string)$value, $markerPrefix)) {
                            $values[":{$f}"] = $value;
                            continue;
                        }
                        $kept = isset($keepLines[$f])
                            ? array_filter(preg_split('/\R/', (string)$value), static fn(string $line): bool => preg_match($keepLines[$f], $line) === 1)
                            : [];
                        $values[":{$f}"] = implode("\n", [...$kept, $marker]);
                    }
                    $update->execute($values);
                    $count++;
                }
                if ($ownsTransaction) $db->commit();
            } catch (Throwable $e) {
                if ($ownsTransaction && Db::transactionOpen($db)) Db::rollBackOpen($db);
                throw $e;
            }
        } while (count($rows) === 1000);
        return $count;
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
        $countStmt = self::prepare($db, "
            SELECT COUNT(*) AS c FROM {$table}
            WHERE {$field} <= :cutoff
              AND {$emailCol} IS NOT NULL
              AND {$emailCol} != ''
              AND {$emailCol} NOT LIKE 'sha256:%'
              AND length({$emailCol}) != 64
        ");
        $countStmt->execute([':cutoff' => $cutoff]);
        $affected = (int)$countStmt->fetch()['c'];
        $countStmt->closeCursor();

        if ($mode === 'apply' && $affected > 0) {
            // Fetch + hash + update per row (salt is in PHP, not SQL), in id-keyset chunks: all matching rows of a
            // large table at once did not fit in a 128 MB worker.
            $rows = self::prepare($db, "
                SELECT id, {$emailCol} AS email FROM {$table}
                WHERE id > :after AND {$field} <= :cutoff
                  AND {$emailCol} IS NOT NULL
                  AND {$emailCol} != ''
                  AND {$emailCol} NOT LIKE 'sha256:%'
                  AND length({$emailCol}) != 64
                ORDER BY id LIMIT 1000
            ");
            $upd = self::prepare($db, "UPDATE {$table} SET {$emailCol} = ? WHERE id = ?");
            $count = 0;
            $after = 0;
            do {
                $rows->execute([':after' => $after, ':cutoff' => $cutoff]);
                $chunk = $rows->fetchAll();
                $rows->closeCursor();
                foreach ($chunk as $r) {
                    $upd->execute([self::hashPii(strtolower((string)$r['email'])), (int)$r['id']]);
                    $after = (int)$r['id'];
                    $count++;
                }
            } while (count($chunk) === 1000);
            $affected = $count;
        }

        return self::recordRun($db, $key, $mode, $affected, $start, ['cutoff' => $cutoff, 'column' => $emailCol]);
    }

    /**
     * A retention DELETE/UPDATE in batches by the table's key (policy `key`, default `id`): one statement over
     * years of rows held the SQLite write lock for half a minute and checkouts failed after busy_timeout.
     */
    private static function retentionWrite(PDO $db, array $p, string $statement, string $where, array $params = [], array $statementParams = []): int {
        $key = (string)($p['key'] ?? 'id');
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) throw new InvalidArgumentException("policy.key invalid: {$key}");
        return Db::writeInBatches((string)$p['table'], $statement, $where, $params, $statementParams, $key, 1000, $db);
    }

    /**
     * WHERE clause of the rows past their retention period. With ttl_days_by_country (country code => days, the
     * accounting retention differs by the seller's country) the rows of a store whose legal.country is listed
     * use that country's period, the others ttl_days. Adds its placeholders to $params.
     */
    private static function expiredWhere(array $p, string $field, array &$params): string {
        $params[':cutoff'] = self::computeCutoff($p);
        $byCountry = self::storesByCountry($p);
        if ($byCountry === []) return "{$field} <= :cutoff";
        $parts = [];
        $listed = [];
        foreach ($byCountry as $country => $storeIds) {
            $in = [];
            foreach ($storeIds as $storeId) {
                $in[] = $name = ':retention_store' . count($listed);
                $params[$name] = $listed[] = $storeId;
            }
            $params[":cutoff_{$country}"] = self::computeCutoff(['ttl_days' => (int)$p['ttl_days_by_country'][$country]]);
            $parts[] = '(store_id IN (' . implode(', ', $in) . ") AND {$field} <= :cutoff_{$country})";
        }
        $others = [];
        foreach ($listed as $i => $storeId) $params[$others[] = ":retention_other{$i}"] = $storeId;
        $parts[] = '(store_id NOT IN (' . implode(', ', $others) . ") AND {$field} <= :cutoff)";
        return implode(' OR ', $parts);
    }

    /** @return array<string, list<string>> listed country => ids of the stores whose legal.country it is */
    private static function storesByCountry(array $p): array {
        $periods = $p['ttl_days_by_country'] ?? null;
        if ($periods === null) return [];
        if (!is_array($periods) || array_filter($periods, static fn($days, $country): bool => !preg_match('/^[A-Z]{2}$/', (string)$country)
                || !is_int($days) || $days < 1, ARRAY_FILTER_USE_BOTH)) {
            throw new InvalidArgumentException('policy.ttl_days_by_country must map ISO country codes to days');
        }
        $result = [];
        foreach ((array)(Bootstrap::stores()['stores'] ?? []) as $storeId => $store) {
            // R58 (review round 23, high V1): the retention of the store's seller (legal.seller), whose books keep the rows.
            $country = strtoupper(trim((string)(SellerLegal::ofStore((string)$storeId)['country'] ?? '')));
            if (isset($periods[$country])) $result[$country][] = (string)$storeId;
        }
        return $result;
    }

    /** Run details: the cutoff used per listed country. */
    private static function countryCutoffs(array $p): array {
        $cutoffs = [];
        foreach (array_keys(self::storesByCountry($p)) as $country) {
            $cutoffs[$country] = self::computeCutoff(['ttl_days' => (int)$p['ttl_days_by_country'][$country]]);
        }
        return $cutoffs === [] ? [] : ['cutoff_by_country' => $cutoffs];
    }

    private static function computeCutoff(array $p): string {
        if (isset($p['ttl_days']))  return gmdate('Y-m-d H:i:s', time() - 86400 * (int)$p['ttl_days']);
        if (isset($p['ttl_hours'])) return gmdate('Y-m-d H:i:s', time() - 3600  * (int)$p['ttl_hours']);
        throw new InvalidArgumentException("policy must define ttl_days or ttl_hours");
    }

    private static function recordRun(PDO $db, string $key, string $mode, int $affected, float $start, array $details): array {
        $duration = (int)round((microtime(true) - $start) * 1000);
        if ($mode === 'apply') {
            $stmt = self::prepare($db, "
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
        return require AppConfig::path(dirname(__DIR__));
    }
}
