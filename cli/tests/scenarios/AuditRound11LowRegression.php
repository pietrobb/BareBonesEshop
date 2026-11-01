<?php

// Round 11 review (2026-11-01) "tests and low": the logs mask HTTP Basic credentials, URL passwords, Stripe secret
// keys, webhook secrets and PEM private keys; Apache and nginx refuse SQLite -journal files; the sample stores
// pl/hu/de/us of a fresh install are not public; an e-mail address gets one cart reminder per window however many
// carts carry it; customers read carrier names, not adapter codes.
echo "\n--- Audit round 11: tests and low findings ---\n";

Bootstrap::initStoreContext('/', '', 'default');
$r11lFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R11 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r11lRmTree = static function (string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
};
$r11lRoot = dirname(TEST_CLI_DIR);

// Logs: secrets recognisable without a key name are masked; look-alikes stay readable.
try {
    $r11lPem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAr11low\n-----END RSA PRIVATE KEY-----";
    $r11lLogged = Logger::redact(['note' => 'Authorization: Basic dXNlcjpzM2NyZXQ=', 'url' => 'https://shop:hunter2@api.example.com/x',
        'stripe' => 'key sk_live_51R11lowSecretValue and rk_test_R11lowRestricted9', 'hook' => 'signing whsec_R11lowWebhook42', 'pem' => "pem {$r11lPem} end",
        'plan' => 'Basic shipping plan', 'public' => 'pk_live_public123456 stays']);
    assert_equals('Authorization: Basic [REDACTED]', $r11lLogged['note'], 'R11 logs: HTTP Basic credentials are masked');
    assert_equals('https://shop:[REDACTED]@api.example.com/x', $r11lLogged['url'], 'R11 logs: the password in a URL is masked, user and host stay');
    assert_equals('key [REDACTED] and [REDACTED]', $r11lLogged['stripe'], 'R11 logs: Stripe secret and restricted keys are masked');
    assert_equals('signing [REDACTED]', $r11lLogged['hook'], 'R11 logs: webhook signing secrets are masked');
    assert_equals('pem [REDACTED RSA PRIVATE KEY] end', $r11lLogged['pem'], 'R11 logs: a PEM private key is masked whole');
    assert_equals(['Basic shipping plan', 'pk_live_public123456 stays'], [$r11lLogged['plan'], $r11lLogged['public']],
        'R11 logs: the word "Basic" and publishable keys are not secrets');
    // A PEM key after a secret-named key: the key=value rule stops at whitespace and used to mask only "-----BEGIN".
    $r11lKeyed = Logger::redact(['yaml' => "signing_secret: {$r11lPem}", 'json' => '{"client_secret":"' . $r11lPem . '"}',
        'mixed' => 'token=sk_live_51R11lowSecretValue password=hunter2']);
    assert_true(!str_contains($r11lKeyed['yaml'] . $r11lKeyed['json'], 'MIIEowIBAAKCAQEAr11low') && !str_contains($r11lKeyed['yaml'] . $r11lKeyed['json'], '-----END'),
        'R11 logs: a PEM private key after a secret-named key is masked whole (' . str_replace("\n", '\n', $r11lKeyed['yaml'] . ' | ' . $r11lKeyed['json']) . ')');
    assert_equals('token=[REDACTED] password=[REDACTED]', $r11lKeyed['mixed'], 'R11 logs: key=value secrets are still masked');
    $r11lErrLog = tempnam(sys_get_temp_dir(), 'r11l');
    $r11lSavedErrLog = ini_set('error_log', $r11lErrLog);
    try {
        Logger::phpLog('Signing failed: token=' . $r11lPem);
    } finally {
        ini_set('error_log', (string)$r11lSavedErrLog);
    }
    $r11lPhpLine = (string)file_get_contents($r11lErrLog);
    @unlink($r11lErrLog);
    assert_true($r11lPhpLine !== '' && !str_contains($r11lPhpLine, 'MIIEowIBAAKCAQEAr11low') && str_contains($r11lPhpLine, '[REDACTED RSA PRIVATE KEY]'),
        'R11 logs: the PHP error log masks a PEM private key after token= (' . trim($r11lPhpLine) . ')');
} catch (Throwable $e) { $r11lFail('logs', $e); }

// Web server rules: the SQLite rollback journal is as private as the database itself.
try {
    $r11lRules = [];
    foreach (['.htaccess' => '/^RewriteRule \\\\\.\(\?:\(\?:sqlite.*?\)\$ - /m', 'deploy/nginx.conf' => '/location ~\* \\\\\.\(\?:\(\?:sqlite.*?\)\$ \{/m'] as $r11lFile => $r11lFind) {
        preg_match_all($r11lFind, (string)file_get_contents($r11lRoot . '/' . $r11lFile), $r11lMatches);
        foreach ($r11lMatches[0] as $r11lLine) {
            $r11lRules[] = '~' . preg_replace('/^(?:RewriteRule |location ~\* )(.*?) (?:- |\{)$/', '$1', $r11lLine) . '~i';
        }
    }
    assert_equals(3, count($r11lRules), 'R11 journal: the deny rule is found in .htaccess and in both nginx server blocks');
    foreach ($r11lRules as $r11lRule) {
        assert_true(preg_match($r11lRule, '/db/shop.sqlite-journal') === 1 && preg_match($r11lRule, '/cache/rate_limit.sqlite-journal') === 1
            && preg_match($r11lRule, '/db/x.db-journal') === 1 && preg_match($r11lRule, '/db/shop.sqlite-wal') === 1 && preg_match($r11lRule, '/product/journal-a5') === 0,
            'R11 journal: SQLite -journal files are refused like -wal, a page slug with "journal" is not (' . $r11lRule . ')');
    }
} catch (Throwable $e) { $r11lFail('journal', $e); }

// Sample stores: a fresh install hides pl/hu/de/us (made-up sellers) until the merchant enters the seller and publishes.
$r11lTmp = sys_get_temp_dir() . '/bbe-r11l-' . getmypid() . '-' . bin2hex(random_bytes(3));
$r11lStoresProp = new ReflectionProperty(Bootstrap::class, 'stores');
$r11lStoresProp->setAccessible(true);
$r11lStoresBefore = $r11lStoresProp->getValue();
try {
    mkdir($r11lTmp . '/app', 0700, true);
    $r11lExample = (string)file_get_contents($r11lRoot . '/stores.example.json');
    file_put_contents($r11lTmp . '/app/stores.example.json', $r11lExample);
    $r11lConfig = ['private_runtime_dir' => $r11lTmp . '/private'];
    assert_equals('created from stores.example.json', DataOverrides::seedStores($r11lTmp . '/app', $r11lConfig)['action'] ?? null, 'R11 samples: setup apply seeds the shop\'s stores.json');
    $r11lSeeded = json_decode((string)file_get_contents($r11lTmp . '/private/stores.json'), true);
    $r11lPublic = array_map(static fn(array $store): bool => StoreContext::isPublicConfig($store), $r11lSeeded['stores']);
    assert_equals(['default' => true, 'cz' => true, 'pl' => false, 'hu' => false, 'de' => false, 'us' => false], $r11lPublic,
        'R11 samples: the seeded stores.json keeps default and cz public, the sample stores pl/hu/de/us not');
    assert_true(!str_contains((string)file_get_contents($r11lTmp . '/private/stores.json'), '"sample"'), 'R11 samples: the seeded copy carries "public": false, not the example marker');

    $r11lDoctor = array_column(Setup::doctor($r11lConfig, $r11lTmp . '/app')['checks'] ?? [], 'status', 'name');
    assert_equals(['fail', 'fail', 'warn', 'warn'], [$r11lDoctor['stores.legal.default'] ?? null, $r11lDoctor['stores.legal.cz'] ?? null, $r11lDoctor['stores.legal.pl'] ?? null, $r11lDoctor['stores.legal.us'] ?? null],
        'R11 samples: doctor fails a public store with example seller data, a hidden sample store is only a warning');

    // Storefront, API and feeds answer a hidden store's prefix with 404; hreflang and the sitemap leave it out.
    $r11lStoresProp->setValue(null, $r11lSeeded);
    $r11lHreflang = Bootstrap::hreflangAlternates('/');
    assert_equals(['sk' => '', 'cs' => '/cs'], $r11lHreflang['alternates'], 'R11 samples: hreflang lists only the public stores');
    assert_true(!in_array('/en', $r11lHreflang['secondary'], true) && !isset($r11lHreflang['secondary']['es']), 'R11 samples: a hidden store\'s further languages are no alternate either');
    $r11lSitemap = new ReflectionMethod(Sitemap::class, 'stores');
    $r11lSitemap->setAccessible(true);
    assert_equals(['default', 'cz'], array_keys($r11lSitemap->invoke(null)), 'R11 samples: the sitemap covers only the public stores');
    file_put_contents($r11lTmp . '/child.php', "<?php\nrequire \$argv[1] . '/src/autoload.php';\n"
        . "(new ReflectionProperty(Bootstrap::class, 'stores'))->setValue(null, json_decode((string)file_get_contents(\$argv[3]), true));\n"
        . "\$lang = Bootstrap::initLocale(\$argv[2]);\necho 'SERVED ' . StoreContext::current()->id() . ' ' . \$lang;\n");
    $r11lRequest = static function (string $uri) use ($r11lRoot, $r11lTmp): string {
        $process = proc_open([PHP_BINARY, $r11lTmp . '/child.php', $r11lRoot, $uri, $r11lTmp . '/private/stores.json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return 'no process';
        fclose($pipes[0]);
        $out = trim((string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $out;
    };
    assert_equals(['Not Found', 'Not Found', 'Not Found', 'SERVED cz cs', 'SERVED default sk'], [$r11lRequest('/pl/'), $r11lRequest('/en/?page=cart'), $r11lRequest('/de/api/v1/storefront/cart'), $r11lRequest('/cs/'), $r11lRequest('/')],
        'R11 samples: a hidden store\'s URL prefix is not found, public stores are served');
    $r11lStoresProp->setValue(null, $r11lStoresBefore);

    // Admin: a hidden store goes public only with a real seller; the default store is always public.
    StoreSettings::setPathForTesting($r11lTmp . '/private/stores.json');
    $r11lLegal = $r11lSeeded['stores']['pl']['legal'];
    $r11lInput = ['store_name' => 'Sklep polski', 'iban' => ''] + array_map('strval', $r11lLegal) + ['payment_terms_days' => '14', 'vat_registered' => '1'];
    $r11lSaved = StoreSettings::save('pl', ['public' => '1'] + $r11lInput);
    assert_true(!$r11lSaved['ok'] && in_array(I18n::t('admin.stores.err.publish_example'), $r11lSaved['errors'], true)
        && !StoreContext::isPublicConfig(StoreSettings::load()['stores']['pl']), 'R11 samples: a sample store with the made-up seller cannot be published');
    $r11lSaved = StoreSettings::save('pl', $r11lInput);
    assert_true($r11lSaved['ok'] && !StoreContext::isPublicConfig(StoreSettings::load()['stores']['pl']),
        'R11 samples: saving without the switch keeps the store hidden (' . implode(' ', $r11lSaved['errors']) . ')');
    $r11lReal = ['name' => 'R11L Handel Sp. z o.o.', 'email' => 'biuro@r11l-handel.pl'] + $r11lInput;
    assert_true(StoreSettings::save('pl', ['public' => '1'] + $r11lReal)['ok'] && StoreContext::isPublicConfig(StoreSettings::load()['stores']['pl']),
        'R11 samples: with its own seller entered the store is published');
    assert_true(StoreSettings::save('pl', ['public' => '0'] + $r11lReal)['ok'] && !StoreContext::isPublicConfig(StoreSettings::load()['stores']['pl']),
        'R11 samples: the merchant can hide a store again');
    $r11lDefault = ['store_name' => 'Default', 'public' => '0', 'iban' => ''] + array_map('strval', $r11lSeeded['stores']['default']['legal']) + ['payment_terms_days' => '14', 'vat_registered' => '1'];
    assert_true(StoreSettings::save('default', $r11lDefault)['ok'] && StoreContext::isPublicConfig(StoreSettings::load()['stores']['default']),
        'R11 samples: the default store cannot be hidden');
} catch (Throwable $e) { $r11lFail('sample stores', $e); } finally {
    $r11lStoresProp->setValue(null, $r11lStoresBefore);
    StoreSettings::setPathForTesting(null);
    $r11lRmTree($r11lTmp);
}

// Cart reminders: one per e-mail address per window, however many sessions captured a cart with it.
$r11lCarts = [];
try {
    $r11lAgo = static fn(int $hours): string => Db::isSqlite() ? "datetime('now', '-{$hours} hours')" : "DATE_SUB(NOW(), INTERVAL {$hours} HOUR)";
    $r11lCart = static function (string $email, int $hoursAgo, ?int $remindedHoursAgo = null) use ($pdo, $r11lAgo, &$r11lCarts): int {
        $reminded = $remindedHoursAgo === null ? 'NULL' : $r11lAgo($remindedHoursAgo);
        $pdo->prepare("INSERT INTO abandoned_carts (session_id, customer_email, cart_data, cart_value, item_count, currency, locale, store_id, recovery_token,
                created_at, updated_at, reminded_at, reminder_status, reminder_consent_version, reminder_consent_text, reminder_consent_at)
            VALUES (?, ?, '[]', 1000, 1, 'eur', 'sk', 'default', ?, {$r11lAgo($hoursAgo)}, {$r11lAgo($hoursAgo)}, {$reminded}, ?, 'r11l', 'Remind me', {$r11lAgo($hoursAgo)})")
            ->execute(['r11l-' . bin2hex(random_bytes(6)), $email, bin2hex(random_bytes(16)), $remindedHoursAgo === null ? null : AbandonedCarts::STATUS_SENT]);
        return $r11lCarts[] = (int)$pdo->lastInsertId();
    };
    $r11lStatus = static function (int $id) use ($pdo): ?string {
        $value = $pdo->query('SELECT reminder_status FROM abandoned_carts WHERE id = ' . $id)->fetchColumn();
        return $value === false || $value === null ? null : (string)$value;
    };
    $r11lVictim = [$r11lCart('r11l-victim@example.test', 30), $r11lCart('r11l-victim@example.test', 30), $r11lCart('R11L-Victim@example.test', 29)];
    $r11lOther = $r11lCart('r11l-other@example.test', 30);
    $r11lEarlier = $r11lCart('r11l-earlier@example.test', 30);
    $r11lCart('r11l-earlier@example.test', 100, 100);
    $r11lLongAgo = $r11lCart('r11l-longago@example.test', 30);
    $r11lCart('r11l-longago@example.test', 200, 200);
    JobQueue::pull(500);
    AbandonedCarts::sweep(['base_url' => 'https://shop.example']);
    $r11lMailed = array_count_values(array_map(static fn(array $job): string => (string)($job['payload']['to'] ?? ''),
        array_filter(JobQueue::pull(500), static fn(array $job): bool => ($job['type'] ?? '') === 'email_send' && ($job['payload']['template'] ?? '') === 'cart_reminder')));
    assert_equals(1, $r11lMailed['r11l-victim@example.test'] ?? 0, 'R11 reminders: three carts with one address queue one reminder');
    assert_equals([AbandonedCarts::STATUS_SENT, AbandonedCarts::STATUS_LIMITED, AbandonedCarts::STATUS_LIMITED], array_map($r11lStatus, $r11lVictim),
        'R11 reminders: the other carts of that address are closed as limited, not left to be mailed on the next sweep');
    assert_equals([1, AbandonedCarts::STATUS_SENT], [$r11lMailed['r11l-other@example.test'] ?? 0, $r11lStatus($r11lOther)], 'R11 reminders: another address gets its own reminder');
    assert_equals([0, AbandonedCarts::STATUS_LIMITED], [$r11lMailed['r11l-earlier@example.test'] ?? 0, $r11lStatus($r11lEarlier)],
        'R11 reminders: an address reminded 100 hours ago is not reminded again within the 7-day window');
    assert_equals([1, AbandonedCarts::STATUS_SENT], [$r11lMailed['r11l-longago@example.test'] ?? 0, $r11lStatus($r11lLongAgo)],
        'R11 reminders: after the window the address can be reminded again');
} catch (Throwable $e) { $r11lFail('cart reminders', $e); } finally {
    if ($r11lCarts !== []) $pdo->exec('DELETE FROM abandoned_carts WHERE id IN (' . implode(',', array_map('intval', $r11lCarts)) . ')');
}

// Carrier names: the shipped e-mail and the account show the carrier's name, a merchant-typed carrier as entered.
try {
    assert_equals(['Packeta (Zásielkovňa)', 'Packeta (Zásilkovna)', 'GLS', 'In-store pickup', 'DPD'],
        [MethodLabels::carrier('packeta', 'sk'), MethodLabels::carrier('Packeta', 'cs'), MethodLabels::carrier('gls', 'de'), MethodLabels::carrier('pickup', 'en'), MethodLabels::carrier('DPD', 'sk')],
        'R11 carriers: built-in carrier codes read as names per language, others as entered');
    $pdo->prepare("INSERT INTO orders (order_number, store_id, status, payment_status, customer_name, customer_email, payment_method, subtotal, total, currency, locale)
        VALUES ('R11L-SHIP-1', 'default', 'shipped', 'paid', 'R11L Buyer', 'r11l-buyer@example.test', 'bank_transfer', 1000, 1000, 'eur', 'sk')")->execute();
    $r11lOrderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO shipments (store_id, order_id, status, carrier, tracking_number, tracking_url, idempotency_key, shipped_at) VALUES ('default', ?, 'shipped', 'packeta', 'Z1100', NULL, ?, ?)")
        ->execute([$r11lOrderId, 'r11l-ship-' . $r11lOrderId, gmdate('Y-m-d H:i:s')]);
    LogMail::clearCaptured();
    MailService::useAdapter(new LogMail(['log_file' => TEST_CLI_DIR . '/../db/emails.test.log']));
    handleEmailSend(['template' => 'order_shipped', 'to' => 'r11l-buyer@example.test', 'order_id' => $r11lOrderId], []);
    $r11lMail = (string)(LogMail::lastCaptured()['html'] ?? '');
    assert_true(str_contains($r11lMail, 'prepravcovi Packeta (Zásielkovňa)') && !str_contains($r11lMail, 'prepravcovi packeta'),
        'R11 carriers: the shipped e-mail names the carrier, not its code (' . substr(strip_tags($r11lMail), 0, 160) . ')');
} catch (Throwable $e) { $r11lFail('carrier names', $e); } finally {
    MailService::useAdapter(null);
    I18n::setLocale('sk');
}
