<?php

// Ops review (2026-10-16), GDPR + ERP group.
// OG 1: erasure also covered cookie consents, password-reset and e-mail verification tokens, RMA
//       admin notes, return receipt notes, anonymous (session-keyed) wishlists and queued jobs, and
//       scrubbed mixed-case guest e-mails from order outbox payloads (the SQL REPLACE was case-sensitive).
// OG 2: one PII registry drives erasure and the SAR export; the export now carries reviews, wishlist,
//       carts, RMAs, coupon uses, order consents, outbox events and queued jobs.
// OG 3: a schema walk fails when a table with personal-data-like columns is neither registered nor exempt.
// OG 4: the ERP status pull read erp_synced:/erp_status: note markers that nothing writes any more, so
//       statuses_updated stayed 0; it now reads the erp_id/erp_status columns.
// Follow-up review (2026-10-16):
// OG 5: queued jobs and order events were matched by e-mail substring and rewritten with str_ireplace,
//       so ann@x.sk exported and corrupted joann@x.sk's mail; only e-mail fields matching exactly count.
// OG 6: an abandoned cart is no proof a browser session is the customer's (anyone can type the e-mail):
//       its session's anonymous wishlist and consents are neither exported nor erased.
// OG 7: file-backend job files (pending/processing/failed/completed) are exported and scrubbed too.
// OG 8: the ERP status pull ordered by updated_at polled the same 50 open orders forever; it now
//       rotates by erp_status_checked_at.
echo "\n--- OG: GDPR registry, erasure gaps and ERP status pull ---\n";

$ogChecks = static function (PDO $ogDb, array $ogOrderTemplate, string $ogLabel): void {
    $ogRand = bin2hex(random_bytes(4));
    $ogJobsDir = test_fixture_path('og-jobs');
    JobQueue::setDir($ogJobsDir);
    $ogEmail = "og-{$ogRand}@example.test";
    $ogMixedEmail = "OG-{$ogRand}@Example.TEST";
    $ogOtherEmail = "og-other-{$ogRand}@example.test";
    // OG 5: the subject's address is a substring of this customer's ('ann@x.sk' vs 'joann@x.sk').
    $ogJoEmail = 'jo' . $ogEmail;
    $ogKey = static fn(string $label): string => hash('sha256', "og-{$ogRand}-{$label}");
    $ogRegister = static function (string $email, string $name) use ($ogDb): int {
        [$ok, $id] = Customers::register($email, 'Str0ng-pass-OG-1', $name);
        if (!$ok) throw new RuntimeException('OG fixture: registration failed');
        $ogDb->prepare("UPDATE customers SET email_verified_at = '2026-10-01 00:00:00' WHERE id = ?")->execute([$id]);
        return (int)$id;
    };
    $ogSubject = $ogRegister($ogEmail, 'Olga Erased');
    $ogOther = $ogRegister($ogOtherEmail, 'Kept Other');
    $ogJo = $ogRegister($ogJoEmail, 'Jo Kept');
    $ogOrder = static function (?int $customerId, string $email) use ($ogDb, $ogOrderTemplate, $ogRand): int {
        $row = array_replace($ogOrderTemplate, ['order_number' => 'OG-' . $ogRand . '-' . bin2hex(random_bytes(3)), 'customer_id' => $customerId, 'customer_email' => $email]);
        $ogDb->prepare('INSERT INTO orders (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        return (int)$ogDb->lastInsertId();
    };
    // A verified account owns its guest orders, here placed under a mixed-case spelling of its e-mail.
    $ogGuestOrder = $ogOrder(null, $ogMixedEmail);
    $ogOtherOrder = $ogOrder($ogOther, $ogOtherEmail);
    $ogJoOrder = $ogOrder($ogJo, $ogJoEmail);
    $ogExec = static fn(string $sql, array $params) => $ogDb->prepare($sql)->execute($params);

    $ogExec('INSERT INTO password_reset_tokens (customer_id, token, expires_at) VALUES (?, ?, ?)', [$ogSubject, 'og-reset-' . $ogRand, '2099-01-01 00:00:00']);
    $ogExec('INSERT INTO customer_email_verifications (customer_id, email, token_hash, expires_at) VALUES (?, ?, ?, ?)', [$ogSubject, $ogEmail, $ogKey('verify'), '2099-01-01 00:00:00']);
    $ogExec('INSERT INTO password_reset_tokens (customer_id, token, expires_at) VALUES (?, ?, ?)', [$ogOther, 'og-reset-other-' . $ogRand, '2099-01-01 00:00:00']);
    // Browser sessions linked to the customer: a signed-in consent, a cart under the e-mail and the guest order's checkout.
    $ogConsent = "INSERT INTO cookie_consent (session_id, customer_id, ip_hash, user_agent, categories_json, method) VALUES (?, ?, 'og-ip-hash', 'OG-Agent/1.0', '{\"necessary\":true}', 'preferences')";
    $ogExec($ogConsent, [$ogKey('consent'), $ogSubject]);
    $ogExec($ogConsent, [$ogKey('consent'), null]);
    $ogExec($ogConsent, [$ogKey('stranger'), null]);
    // OG 6: the abandoned cart under the subject's e-mail may be a stranger's browser.
    $ogExec($ogConsent, [$ogKey('cart'), null]);
    $ogExec("INSERT INTO abandoned_carts (session_id, customer_email, cart_data, store_id) VALUES (?, ?, '{\"items\":[]}', 'default')", [$ogKey('cart'), $ogMixedEmail]);
    $ogExec("INSERT INTO checkout_sessions (order_id, provider, session_id, browser_session_hash) VALUES (?, 'stripe', ?, ?)", [$ogGuestOrder, 'cs_og_' . $ogRand, $ogKey('checkout')]);
    $ogProduct = (int)$ogDb->query("SELECT id FROM products ORDER BY id LIMIT 1")->fetchColumn();
    foreach (['consent', 'cart', 'checkout', 'stranger'] as $ogSession) {
        $ogExec('INSERT INTO wishlist_items (owner_key, customer_id, product_id) VALUES (?, NULL, ?)', ['s:' . $ogKey($ogSession), $ogProduct]);
    }
    $ogExec("INSERT INTO rma_requests (rma_number, order_id, customer_id, reason, admin_note, store_id) VALUES (?, ?, ?, 'Broken', ?, 'default')",
        ['OG-' . $ogRand, $ogGuestOrder, $ogSubject, 'Called Olga on +421 900 111']);
    $ogRma = (int)$ogDb->lastInsertId();
    $ogExec("INSERT INTO rma_items (rma_id, order_item_id, sku, name, quantity) VALUES (?, 0, 'OG', 'OG item', 1)", [$ogRma]);
    $ogRmaItem = (int)$ogDb->lastInsertId();
    $ogExec("INSERT INTO return_receipts (store_id, rma_id, rma_item_id, quantity, disposition, note, idempotency_key) VALUES ('default', ?, ?, 1, 'restock', 'Olga brought it in person', ?)", [$ogRma, $ogRmaItem, 'og-' . $ogRand]);
    $ogExec("INSERT INTO rma_requests (rma_number, order_id, customer_id, reason, admin_note, store_id) VALUES (?, ?, ?, 'Broken', 'Other note', 'default')",
        ['OG-other-' . $ogRand, $ogOtherOrder, $ogOther]);
    $ogDb->beginTransaction();
    Outbox::record('order.created', 'order', $ogGuestOrder, ['order_id' => $ogGuestOrder, 'customer_email' => $ogMixedEmail]);
    Outbox::record('order.created', 'order', $ogOtherOrder, ['order_id' => $ogOtherOrder, 'customer_email' => $ogOtherEmail]);
    // OG 5: events of the subject's own order naming joann@ (nested gift recipient, and alone).
    Outbox::record('order.updated', 'order', $ogGuestOrder, ['order_id' => $ogGuestOrder, 'customer_email' => $ogMixedEmail, 'gift' => ['recipient' => $ogJoEmail]]);
    Outbox::record('order.note', 'order', $ogGuestOrder, ['order_id' => $ogGuestOrder, 'email' => $ogJoEmail]);
    $ogDb->commit();
    $ogJob = "INSERT INTO queue_jobs (id, store_id, channel_id, type, payload, state, available_at, correlation_id) VALUES (?, 'default', 'storefront', 'email_send', ?, ?, '2026-10-16 00:00:00', 'og')";
    $ogExec($ogJob, ['og-job-' . $ogRand, json_encode(['template' => 'order_confirmation', 'to' => $ogMixedEmail, 'order_id' => $ogGuestOrder]), 'completed']);
    $ogExec($ogJob, ['og-job-other-' . $ogRand, json_encode(['template' => 'order_confirmation', 'to' => $ogOtherEmail, 'order_id' => $ogOtherOrder]), 'completed']);
    $ogJoJob = json_encode(['template' => 'order_confirmation', 'to' => $ogJoEmail, 'order_id' => $ogJoOrder]);
    $ogExec($ogJob, ['og-job-jo-' . $ogRand, $ogJoJob, 'pending']);

    // OG 7: file-backend jobs in every state directory.
    $ogFileJob = static function (string $state, string $type, array $payload) use ($ogJobsDir): string {
        $id = JobQueue::push($type, $payload);
        if ($state !== 'pending') {
            $file = glob($ogJobsDir . '/pending/*_' . $id . '.json')[0];
            @mkdir($ogJobsDir . '/' . $state, 0750, true);
            rename($file, $ogJobsDir . '/' . $state . '/' . basename($file));
        }
        return $id;
    };
    $ogFileJobs = [
        'pending' => $ogFileJob('pending', 'email_send', ['template' => 'order_shipped', 'to' => $ogMixedEmail, 'order_id' => $ogGuestOrder]),
        'processing' => $ogFileJob('processing', 'merchant_notify', ['order_id' => $ogGuestOrder, 'vars' => ['customer_email' => ' ' . $ogEmail . ' ', 'note' => 'keep']]),
        'failed' => $ogFileJob('failed', 'email_send', ['template' => 'cart_reminder', 'to' => $ogEmail]),
    ];
    $ogJoFileJob = $ogFileJob('completed', 'email_send', ['template' => 'order_shipped', 'to' => $ogJoEmail, 'order_id' => $ogJoOrder]);
    $ogReadFileJob = static function (string $id) use ($ogJobsDir): array {
        $files = glob($ogJobsDir . '/*/*_' . $id . '.json');
        return count($files) === 1 ? ['state' => basename(dirname($files[0]))] + json_decode((string)file_get_contents($files[0]), true) : [];
    };

    $ogCount = static function (string $sql, array $params = []) use ($ogDb): int {
        $stmt = $ogDb->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    };
    // Case-insensitive search done in PHP so the check itself does not depend on the driver's collation.
    $ogMentions = static function (string $table, string $needle) use ($ogDb): int {
        $payloads = $ogDb->query("SELECT payload FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
        return count(array_filter($payloads, static fn($payload): bool => stripos((string)$payload, $needle) !== false));
    };
    // Payloads with a value that IS the address (any letter case): 'joann@' does not count for 'ann@'.
    $ogNames = static function (string $table, string $address) use ($ogDb): int {
        $count = 0;
        foreach ($ogDb->query("SELECT payload FROM {$table}")->fetchAll(PDO::FETCH_COLUMN) as $payload) {
            $hit = false;
            $decoded = json_decode((string)$payload, true);
            if (is_array($decoded)) array_walk_recursive($decoded, static function ($value) use ($address, &$hit): void {
                if (is_string($value) && strcasecmp(trim($value), $address) === 0) $hit = true;
            });
            $count += $hit ? 1 : 0;
        }
        return $count;
    };

    // OG 2: the SAR export carries every registered section, including the session-linked data.
    $ogExport = GdprService::exportCustomer($ogSubject);
    foreach (GdprService::PII_REGISTRY as $ogTable => $ogEntry) {
        if ($ogEntry['export'] !== null) assert_true(array_key_exists($ogEntry['export'], $ogExport), "OG 2 {$ogLabel}: export has the '{$ogEntry['export']}' section for {$ogTable}");
    }
    assert_equals(['s:' . $ogKey('checkout'), 's:' . $ogKey('consent')], (static function (array $keys): array { sort($keys); return $keys; })(array_column($ogExport['wishlist'], 'owner_key')),
        "OG 2/6 {$ogLabel}: export lists the wishlists of the signed-in and checkout sessions, not of the abandoned cart's session");
    assert_equals(2, count($ogExport['consent_history']), "OG 2 {$ogLabel}: export lists the signed-in and the same-session anonymous consent");
    assert_equals([], array_values(array_filter(array_column($ogExport['consent_history'], 'session_id'), static fn($id): bool => $id === $ogKey('cart'))),
        "OG 6 {$ogLabel}: the anonymous consent of the abandoned cart's session is not exported");
    assert_equals(1, count($ogExport['abandoned_carts']), "OG 2 {$ogLabel}: export lists the cart under the mixed-case e-mail");
    assert_equals(['Olga brought it in person'], array_column($ogExport['rma_requests'][0]['receipts'] ?? [], 'note'), "OG 2 {$ogLabel}: export lists RMA receipts");
    assert_equals(['order.created', 'order.updated'], (static function (array $types): array { sort($types); return $types; })(array_column(array_filter($ogExport['events'], static fn(array $e): bool => $e['aggregate_type'] === 'order'), 'event_type')),
        "OG 2/5 {$ogLabel}: export lists the order events naming the mixed-case e-mail, not the one naming only joann@");
    $ogExpectedJobs = array_merge(['og-job-' . $ogRand], array_values($ogFileJobs));
    $ogExportedJobs = array_column($ogExport['queued_jobs'], 'id');
    sort($ogExpectedJobs);
    sort($ogExportedJobs);
    assert_equals($ogExpectedJobs, $ogExportedJobs, "OG 2/5/7 {$ogLabel}: export lists the customer's table and file jobs only, not joann@'s");
    assert_equals(['failed', 'pending', 'processing'], (static function (array $jobs, array $ids): array {
        $states = array_column(array_filter($jobs, static fn(array $job): bool => in_array($job['id'], $ids, true)), 'state');
        sort($states);
        return $states;
    })($ogExport['queued_jobs'], array_values($ogFileJobs)), "OG 7 {$ogLabel}: exported file jobs carry their state");

    $ogResult = GdprService::anonymizeCustomer($ogSubject, 'og-test');
    assert_equals('anonymized', $ogResult['status'], "OG 1 {$ogLabel}: erasure succeeds");
    foreach (GdprService::PII_REGISTRY as $ogTable => $ogEntry) {
        if ($ogEntry['erase'] !== 'retain') assert_true(array_key_exists($ogTable, $ogResult['rows']), "OG 2 {$ogLabel}: erasure reports a step for {$ogTable}");
    }

    assert_equals(0, $ogCount('SELECT COUNT(*) FROM password_reset_tokens WHERE customer_id = ?', [$ogSubject]), "OG 1 {$ogLabel}: password-reset tokens are deleted");
    assert_equals(0, $ogCount('SELECT COUNT(*) FROM customer_email_verifications WHERE customer_id = ?', [$ogSubject]), "OG 1 {$ogLabel}: e-mail verification tokens are deleted");
    assert_equals(1, $ogCount('SELECT COUNT(*) FROM password_reset_tokens WHERE customer_id = ?', [$ogOther]), "OG 1 {$ogLabel}: other customers' tokens are untouched");
    assert_equals(0, $ogCount("SELECT COUNT(*) FROM cookie_consent WHERE customer_id = ? OR session_id = ? OR (user_agent = 'OG-Agent/1.0' AND session_id IS NULL AND ip_hash IS NOT NULL)", [$ogSubject, $ogKey('consent')]),
        "OG 1 {$ogLabel}: consents lose customer, session, IP hash and user agent");
    assert_equals(2, $ogCount("SELECT COUNT(*) FROM cookie_consent WHERE customer_id IS NULL AND session_id IS NULL AND ip_hash IS NULL AND user_agent IS NULL AND method = 'preferences' AND categories_json LIKE '%necessary%'"),
        "OG 1 {$ogLabel}: the consent choice itself stays as evidence");
    assert_equals(1, $ogCount("SELECT COUNT(*) FROM cookie_consent WHERE session_id = ? AND ip_hash = 'og-ip-hash'", [$ogKey('stranger')]), "OG 1 {$ogLabel}: unrelated consents are untouched");
    foreach (['consent', 'checkout'] as $ogSession) {
        assert_equals(0, $ogCount('SELECT COUNT(*) FROM wishlist_items WHERE owner_key = ?', ['s:' . $ogKey($ogSession)]), "OG 1 {$ogLabel}: the session wishlist linked via {$ogSession} is deleted");
    }
    assert_equals(1, $ogCount('SELECT COUNT(*) FROM wishlist_items WHERE owner_key = ?', ['s:' . $ogKey('stranger')]), "OG 1 {$ogLabel}: unrelated session wishlists are untouched");
    assert_equals(1, $ogCount('SELECT COUNT(*) FROM wishlist_items WHERE owner_key = ?', ['s:' . $ogKey('cart')]), "OG 6 {$ogLabel}: the wishlist of the abandoned cart's session is not erased");
    assert_equals(1, $ogCount("SELECT COUNT(*) FROM cookie_consent WHERE session_id = ? AND ip_hash = 'og-ip-hash' AND user_agent = 'OG-Agent/1.0'", [$ogKey('cart')]),
        "OG 6 {$ogLabel}: the consent of the abandoned cart's session is not scrubbed");
    assert_equals(0, $ogCount("SELECT COUNT(*) FROM rma_requests WHERE id = ? AND (admin_note LIKE '%Olga%' OR admin_note LIKE '%+421%')", [$ogRma]), "OG 1 {$ogLabel}: the RMA admin note is redacted");
    assert_equals(1, $ogCount("SELECT COUNT(*) FROM rma_requests WHERE id = ? AND admin_note LIKE '[erased%'", [$ogRma]), "OG 1 {$ogLabel}: the redacted admin note carries the erasure marker");
    assert_equals(1, $ogCount("SELECT COUNT(*) FROM rma_requests WHERE order_id = ? AND admin_note = 'Other note'", [$ogOtherOrder]), "OG 1 {$ogLabel}: other RMA admin notes are untouched");
    assert_equals(0, $ogCount("SELECT COUNT(*) FROM return_receipts WHERE rma_id = ? AND note LIKE '%Olga%'", [$ogRma]), "OG 1 {$ogLabel}: return receipt notes are scrubbed");
    assert_equals(0, $ogNames('event_outbox', $ogEmail), "OG 1 {$ogLabel}: no outbox payload keeps the e-mail in any letter case");
    assert_equals(0, $ogNames('queue_jobs', $ogEmail), "OG 1 {$ogLabel}: no queued job keeps the e-mail in any letter case");
    assert_equals(0, $ogMentions('event_outbox', 'jodeleted-') + $ogMentions('queue_jobs', 'jodeleted-'), "OG 5 {$ogLabel}: no joann@ address is rewritten to 'jo<placeholder>'");
    assert_equals(3, $ogNames('event_outbox', $ogJoEmail), "OG 5 {$ogLabel}: joann@ stays in the subject's order events (nested gift recipient and alone) and in joann@'s own customer event");
    assert_equals(1, $ogCount("SELECT COUNT(*) FROM queue_jobs WHERE id = ? AND payload = ?", ['og-job-jo-' . $ogRand, $ogJoJob]), "OG 5 {$ogLabel}: joann@'s queued job is byte for byte unchanged");
    $ogUpdated = array_values(array_filter($ogDb->query("SELECT event_type, payload FROM event_outbox WHERE event_type = 'order.updated'")->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $row): bool => (json_decode((string)$row['payload'], true)['order_id'] ?? null) === $ogGuestOrder));
    assert_equals(['order_id' => $ogGuestOrder, 'customer_email' => 'deleted-' . $ogSubject . '@anonymized.local', 'gift' => ['recipient' => $ogJoEmail]],
        json_decode((string)($ogUpdated[0]['payload'] ?? ''), true), "OG 5 {$ogLabel}: only the e-mail field holding the subject's address is rewritten");
    $ogPlaceholder = 'deleted-' . $ogSubject . '@anonymized.local';
    $ogAfter = array_map($ogReadFileJob, $ogFileJobs);
    assert_equals(['pending' => 'pending', 'processing' => 'processing', 'failed' => 'failed'], array_map(static fn(array $job): string => $job['state'] ?? '', $ogAfter), "OG 7 {$ogLabel}: scrubbed job files stay in their state directory");
    assert_equals([$ogPlaceholder, $ogPlaceholder, 'keep', $ogPlaceholder], [$ogAfter['pending']['payload']['to'] ?? null, $ogAfter['processing']['payload']['vars']['customer_email'] ?? null, $ogAfter['processing']['payload']['vars']['note'] ?? null, $ogAfter['failed']['payload']['to'] ?? null],
        "OG 7 {$ogLabel}: the e-mail fields of the customer's job files (top-level and nested) are rewritten, other values kept");
    assert_equals($ogGuestOrder, $ogAfter['pending']['payload']['order_id'] ?? null, "OG 7 {$ogLabel}: the rest of the job payload is kept");
    assert_equals(['completed', $ogJoEmail], [($ogJo2 = $ogReadFileJob($ogJoFileJob))['state'] ?? null, $ogJo2['payload']['to'] ?? null], "OG 5/7 {$ogLabel}: joann@'s job file is untouched");
    assert_equals([], array_values(array_filter(glob($ogJobsDir . '/*/*') ?: [], static fn(string $file): bool => !str_ends_with($file, '.json'))), "OG 7 {$ogLabel}: no hold or temp file is left behind");
    assert_true($ogMentions('event_outbox', 'deleted-' . $ogSubject . '@anonymized.local') >= 1, "OG 1 {$ogLabel}: the order event names the placeholder instead");
    assert_true($ogMentions('event_outbox', $ogOtherEmail) >= 2, "OG 1 {$ogLabel}: other customers' events are untouched");
    assert_equals(1, $ogMentions('queue_jobs', $ogOtherEmail), "OG 1 {$ogLabel}: other customers' queued jobs are untouched");
    assert_equals($ogMixedEmail, (string)$ogDb->query('SELECT customer_email FROM orders WHERE id = ' . $ogGuestOrder)->fetchColumn(), "OG 1 {$ogLabel}: the legally retained order keeps its e-mail");
};

$ogSuite = Db::get();
$ogQueueDir = new ReflectionProperty(JobQueue::class, 'jobsDir');
$ogQueueForce = new ReflectionProperty(JobQueue::class, 'forceFile');
$ogSavedQueue = [$ogQueueDir->getValue(), $ogQueueForce->getValue()];
$ogRestoreQueue = static function () use ($ogQueueDir, $ogQueueForce, $ogSavedQueue): void {
    $ogQueueDir->setValue(null, $ogSavedQueue[0]);
    $ogQueueForce->setValue(null, $ogSavedQueue[1]);
};
$ogTemplate = $ogSuite->query("SELECT * FROM orders WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
unset($ogTemplate['id'], $ogTemplate['erp_id'], $ogTemplate['erp_status']);

// The suite schema is built up by the scenarios; the registry is checked against a fully migrated one.
$ogFresh = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$ogFreshLock = test_fixture_path('og-schema') . '.lock';
try {
    Db::init($ogFresh, 'sqlite');
    (new Migrator($ogFresh, dirname(TEST_CLI_DIR) . '/migrations', $ogFreshLock))->apply();

    // OG 3: every table with personal-data-like columns is registered or exempt with a reason.
    $ogPiiColumn = '/^(name|[a-z_]*_name|username|[a-z_]*email[a-z_]*|customer_id|ip|ip_address|ip_hash|last_ip|user_agent|[a-z_]*phone[a-z_]*|[a-z_]*address[a-z_]*|[a-z_]*_addr|recipient)$/';
    $ogSchema = [];
    foreach ($ogFresh->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $ogTable) {
        $ogSchema[$ogTable] = array_column($ogFresh->query('PRAGMA table_info(' . $ogTable . ')')->fetchAll(), 'name');
    }
    $ogUnlisted = [];
    foreach ($ogSchema as $ogTable => $ogColumns) {
        $ogPii = preg_grep($ogPiiColumn, $ogColumns);
        if ($ogPii !== [] && !isset(GdprService::PII_REGISTRY[$ogTable]) && !isset(GdprService::PII_EXEMPT_TABLES[$ogTable])) {
            $ogUnlisted[] = $ogTable . ' (' . implode(', ', $ogPii) . ')';
        }
    }
    assert_equals([], $ogUnlisted, 'OG 3: every table with personal-data columns is in GdprService::PII_REGISTRY or PII_EXEMPT_TABLES');
    $ogStale = [];
    foreach (GdprService::PII_REGISTRY as $ogTable => $ogEntry) {
        if (!isset($ogSchema[$ogTable])) { $ogStale[] = $ogTable; continue; }
        foreach (array_diff($ogEntry['columns'], $ogSchema[$ogTable]) as $ogMissing) $ogStale[] = "{$ogTable}.{$ogMissing}";
        if (!in_array($ogEntry['erase'], ['anonymize', 'delete', 'scrub', 'retain'], true)) $ogStale[] = "{$ogTable}: erase '{$ogEntry['erase']}'";
        if (($ogEntry['erase'] === 'retain' || $ogEntry['export'] === null) && trim((string)($ogEntry['reason'] ?? '')) === '') $ogStale[] = "{$ogTable}: no reason";
    }
    assert_equals([], $ogStale, 'OG 3: registered tables and columns exist, actions are known and every exclusion has a reason');
    assert_equals([], array_keys(array_filter(GdprService::PII_EXEMPT_TABLES, static fn(string $reason): bool => trim($reason) === '')), 'OG 3: every exempt table names its reason');
    assert_equals([], array_keys(array_intersect_key(GdprService::PII_EXEMPT_TABLES, GdprService::PII_REGISTRY)), 'OG 3: no table is both registered and exempt');

    // OG 1/2 on the fully migrated schema (the suite schema may lack later tables at this point).
    $ogFresh->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'og', 'OG', 'og', 0)");
    $ogFresh->exec("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', " . (int)$ogFresh->lastInsertId() . ", 'OG-P', 'og-p', 'OG Product', 1000, 5, 'active')");
    $ogChecks($ogFresh, ['store_id' => 'default', 'customer_name' => 'OG', 'payment_method' => 'card', 'subtotal' => 1000, 'total' => 1000], 'SQLite');
} catch (Throwable $e) {
    assert_true(false, 'OG GDPR regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    Db::init($ogSuite, 'sqlite');
    $ogRestoreQueue();
    $ogFresh = null;
    @unlink($ogFreshLock);
}

if (getenv('TEST_MYSQL_DSN')) {
    $ogNative = new PDO(getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $ogNativeDatabase = 'bbe_og_' . bin2hex(random_bytes(6)) . '_test';
    $ogNativeLock = test_fixture_path('og-native') . '.lock';
    $ogNative->exec("CREATE DATABASE {$ogNativeDatabase} CHARACTER SET utf8mb4");
    try {
        $ogNative->exec("USE {$ogNativeDatabase}");
        Db::init($ogNative, 'mysql');
        (new Migrator($ogNative, dirname(TEST_CLI_DIR) . '/migrations', $ogNativeLock))->apply();
        $ogNative->exec("INSERT INTO categories (store_id, slug, name, path, depth) VALUES ('default', 'og', 'OG', 'og', 0)");
        $ogNative->exec("INSERT INTO products (store_id, category_id, sku, slug, name, price, stock, status) VALUES ('default', " . (int)$ogNative->lastInsertId() . ", 'OG-P', 'og-p', 'OG Product', 1000, 5, 'active')");
        $ogChecks($ogNative, ['store_id' => 'default', 'customer_name' => 'OG', 'payment_method' => 'card', 'subtotal' => 1000, 'total' => 1000], 'native');
    } catch (Throwable $e) {
        assert_true(false, 'OG native GDPR regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    } finally {
        Db::init($ogSuite, 'sqlite');
        $ogRestoreQueue();
        $ogNative->exec("DROP DATABASE IF EXISTS {$ogNativeDatabase}");
        $ogNative = null;
        @unlink($ogNativeLock);
    }
} else {
    echo "  [SKIP] OG native MySQL/MariaDB erasure (set TEST_MYSQL_DSN)\n";
}

// OG 4: the ERP status pull reads the erp_id/erp_status columns that markSynced/updateErpStatus write.
// Runs in a rolled-back transaction; every other order is parked as delivered + synced so only the fixtures move.
if (!class_exists('OgErpAdapter', false)) {
    final class OgErpAdapter implements IntegrationAdapter {
        /** @var array<string, string> */
        public array $statuses = [];
        public array $pulled = [];
        public function pushOrder(array $order): string { return 'og-erp-pushed-' . $order['id']; }
        public function pullOrderStatus(string $externalId): string { $this->pulled[] = $externalId; return $this->statuses[$externalId] ?? 'confirmed'; }
        public function pushCustomer(array $customer): string { return ''; }
        public function healthCheck(): bool { return true; }
        public function pullProducts(?\DateTimeInterface $since = null): iterable { return []; }
        public function pushProduct(array $product): void {}
        public function pullStock(): iterable { return []; }
    }
}
$ogAdapters = new ReflectionProperty(Adapters::class, 'instances');
$ogSavedAdapters = $ogAdapters->getValue();
$ogLastSync = Cache::get('last_erp_sync');
$ogErp = new OgErpAdapter();
$ogAdapters->setValue(null, ['integration' => $ogErp] + $ogSavedAdapters);
$ogSuite->beginTransaction();
try {
    $ogSuite->exec("UPDATE orders SET status = 'delivered', erp_id = COALESCE(erp_id, 'og-parked') WHERE store_id = 'default'");
    $ogErpOrder = static function (?string $erpId, ?string $erpStatus, string $notes = '') use ($ogSuite, $ogTemplate): int {
        $row = array_replace($ogTemplate, ['order_number' => 'OG-ERP-' . bin2hex(random_bytes(4)), 'status' => 'paid', 'erp_id' => $erpId, 'erp_status' => $erpStatus, 'notes' => $notes]);
        $ogSuite->prepare('INSERT INTO orders (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        return (int)$ogSuite->lastInsertId();
    };
    $ogErp->statuses = ['og-erp-new' => 'shipped', 'og-erp-same' => 'shipped', 'og-erp-legacy' => 'packed'];
    $ogChanged = $ogErpOrder('og-erp-new', null);
    $ogSame = $ogErpOrder('og-erp-same', 'shipped');
    $ogLegacy = $ogErpOrder('og-erp-legacy', null, 'erp_status:packed');
    $ogUnsynced = $ogErpOrder(null, null);
    $ogErpState = static fn(int $id): array => array_values($ogSuite->query('SELECT erp_id, erp_status FROM orders WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC));

    $ogRun = Sync::run();
    assert_equals(1, $ogRun['orders_pushed'] ?? null, 'OG 4: the unsynced order is pushed');
    assert_true(in_array('og-erp-new', $ogErp->pulled, true) && in_array('og-erp-pushed-' . $ogUnsynced, $ogErp->pulled, true), 'OG 4: statuses are pulled by the erp_id column, including an order pushed in the same run');
    assert_equals(2, $ogRun['statuses_updated'] ?? null, 'OG 4: changed ERP statuses are counted (it was always 0)');
    assert_equals(['og-erp-new', 'shipped'], $ogErpState($ogChanged), 'OG 4: a new ERP status is stored');
    assert_equals(['og-erp-pushed-' . $ogUnsynced, 'confirmed'], $ogErpState($ogUnsynced), 'OG 4: the freshly pushed order gets its ERP status');
    assert_equals(['og-erp-same', 'shipped'], $ogErpState($ogSame), 'OG 4: an unchanged ERP status is left alone');
    assert_equals(['og-erp-legacy', null], $ogErpState($ogLegacy), 'OG 4: a legacy order whose note already records the status is not rewritten');
    assert_equals(0, Sync::run()['statuses_updated'] ?? null, 'OG 4: a second run without ERP changes updates nothing');
    $ogErp->statuses['og-erp-new'] = 'delivered';
    assert_equals(1, Sync::run()['statuses_updated'] ?? null, 'OG 4: a later ERP change is picked up');
    assert_equals(['og-erp-new', 'delivered'], $ogErpState($ogChanged), 'OG 4: the later ERP status is stored');
} catch (Throwable $e) {
    assert_true(false, 'OG ERP regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($ogSuite->inTransaction()) $ogSuite->rollBack();
    $ogAdapters->setValue(null, $ogSavedAdapters);
    if ($ogLastSync === null) Cache::invalidate('kv/' . md5('last_erp_sync'));
    else Cache::set('last_erp_sync', $ogLastSync);
}
// OG 8: with more open ERP orders than one pull takes (50), consecutive runs poll all of them.
$ogErp = new OgErpAdapter();
$ogAdapters->setValue(null, ['integration' => $ogErp] + $ogSavedAdapters);
$ogSuite->beginTransaction();
try {
    $ogSuite->exec("UPDATE orders SET status = 'delivered', erp_id = COALESCE(erp_id, 'og-parked') WHERE store_id = 'default'");
    $ogRotation = [];
    for ($ogI = 0; $ogI < 60; $ogI++) {
        $row = array_replace($ogTemplate, ['order_number' => 'OG-ROT-' . bin2hex(random_bytes(4)), 'status' => 'paid', 'erp_id' => 'og-rot-' . $ogI, 'erp_status' => 'confirmed']);
        $ogSuite->prepare('INSERT INTO orders (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        $ogRotation[] = 'og-rot-' . $ogI;
    }
    Sync::run();
    $ogFirst = $ogErp->pulled;
    $ogErp->pulled = [];
    Sync::run();
    $ogSecond = $ogErp->pulled;
    assert_equals([50, 50], [count($ogFirst), count($ogSecond)], 'OG 8: each run polls one batch of 50');
    assert_equals(array_slice($ogRotation, 50), array_slice($ogSecond, 0, 10), 'OG 8: the second run starts with the orders the first run did not poll');
    $ogPolled = array_values(array_unique(array_merge($ogFirst, $ogSecond)));
    sort($ogPolled);
    $ogExpected = $ogRotation;
    sort($ogExpected);
    assert_equals($ogExpected, $ogPolled, 'OG 8: two runs poll all 60 open ERP orders (it was the same 50 forever)');
    assert_equals(0, (int)$ogSuite->query("SELECT COUNT(*) FROM orders WHERE erp_id LIKE 'og-rot-%' AND erp_status_checked_at IS NULL")->fetchColumn(), 'OG 8: every poll stamps erp_status_checked_at');
} catch (Throwable $e) {
    assert_true(false, 'OG ERP rotation regression raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($ogSuite->inTransaction()) $ogSuite->rollBack();
    $ogAdapters->setValue(null, $ogSavedAdapters);
    if ($ogLastSync === null) Cache::invalidate('kv/' . md5('last_erp_sync'));
    else Cache::set('last_erp_sync', $ogLastSync);
}

$ogSyncCli = file_get_contents(TEST_CLI_DIR . '/sync.php') ?: '';
assert_true(str_contains($ogSyncCli, "Products skipped:  {\$result['products_skipped']}"), 'OG 4: cli/sync.php --verbose prints the skipped product count');
