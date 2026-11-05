<?php

// Round 16 review (2026-11-04) "numbering and rollback". High 1: on MySQL/MariaDB the rollback never raised the invoice
// counters, so invoice and credit note numbers already sent were issued a second time. raiseNumbering() bound the same
// named placeholder :n twice in one UPDATE, which native prepares (PDO::ATTR_EMULATE_PREPARES = false, as Db.php
// connects) reject with HY093; SQLite accepts it, so every test passed. Medium: the rollback did not count customers,
// and a snapshot taken by v2.1.0 (which tracked only orders and payments) skipped invoices and refunds without a word.
echo "\n--- Audit round 16 (rollback): numbering raised on native prepares, customers and old snapshots counted ---\n";
$r16rFail = static fn(string $what, Throwable $e) => assert_true(false, "R16 rollback {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');

// Every SQL string literal in src/ and extensions/: a named placeholder bound twice fails on MySQL native prepares.
try {
    $r16rRoot = dirname(__DIR__, 3);
    $r16rFound = [];
    $r16rFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r16rRoot . '/src', FilesystemIterator::SKIP_DOTS));
    $r16rList = iterator_to_array($r16rFiles, false);
    if (is_dir($r16rRoot . '/extensions')) {
        $r16rList = array_merge($r16rList, iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r16rRoot . '/extensions', FilesystemIterator::SKIP_DOTS)), false));
    }
    $r16rCheck = static function (string $sql, string $file, int $line) use (&$r16rFound, $r16rRoot): void {
        if (!preg_match('/\b(SELECT|UPDATE|INSERT|DELETE|REPLACE)\b/i', $sql)) return;
        preg_match_all('/(?<![:\w]):([A-Za-z_]\w*)/', $sql, $m);
        foreach (array_count_values($m[1]) as $name => $count) {
            if ($count > 1) $r16rFound[] = str_replace('\\', '/', substr($file, strlen($r16rRoot) + 1)) . ":{$line} :{$name} x{$count}";
        }
    };
    foreach ($r16rList as $r16rFile) {
        if (!str_ends_with((string)$r16rFile, '.php')) continue;
        $r16rTokens = token_get_all((string)file_get_contents((string)$r16rFile));
        $r16rOpen = null; // [text, line] of the interpolated string being read
        $r16rLine = 1;
        foreach ($r16rTokens as $r16rToken) {
            $r16rText = is_array($r16rToken) ? $r16rToken[1] : $r16rToken;
            $r16rId = is_array($r16rToken) ? $r16rToken[0] : null;
            if (is_array($r16rToken)) $r16rLine = $r16rToken[2] + substr_count($r16rText, "\n");
            if ($r16rOpen !== null) {
                if ($r16rText === '"' || $r16rId === T_END_HEREDOC) {
                    $r16rCheck($r16rOpen[0], (string)$r16rFile, $r16rOpen[1]);
                    $r16rOpen = null;
                } elseif ($r16rId === T_ENCAPSED_AND_WHITESPACE) {
                    $r16rOpen[0] .= $r16rText;
                } else {
                    $r16rOpen[0] .= ' ? '; // an interpolated expression, never a placeholder
                }
                continue;
            }
            if ($r16rText === '"' || $r16rId === T_START_HEREDOC) {
                $r16rOpen = ['', $r16rLine];
            } elseif ($r16rId === T_CONSTANT_ENCAPSED_STRING) {
                $r16rCheck($r16rText, (string)$r16rFile, $r16rToken[2]);
            }
        }
    }
    assert_equals([], $r16rFound, 'R16 high 1: no SQL string in src/ or extensions/ binds the same named placeholder twice (HY093 on MySQL native prepares)');
} catch (Throwable $e) {
    $r16rFail('placeholder scan', $e);
}

// raiseNumbering() itself, on SQLite and (with TEST_MYSQL_DSN) on MySQL with native prepares as the shop connects.
$r16rRaise = new ReflectionMethod(Updater::class, 'raiseNumbering');
$r16rDatabases = ['sqlite' => static fn(): PDO => new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])];
if (getenv('TEST_MYSQL_DSN')) {
    $r16rDatabases['mysql'] = static fn(): PDO => new PDO((string)getenv('TEST_MYSQL_DSN'), getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASS') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
foreach ($r16rDatabases as $r16rDriver => $r16rConnect) {
    $r16rDb = null;
    $r16rCreated = false;
    try {
        $r16rDb = $r16rConnect();
        $r16rExists = $r16rDriver === 'sqlite'
            ? false
            : $r16rDb->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'invoice_number_counters'")->fetchColumn() !== false;
        if (!$r16rExists) {
            $r16rDb->exec("CREATE TABLE invoice_number_counters (store_id VARCHAR(64) NOT NULL, type VARCHAR(32) NOT NULL, year INTEGER NOT NULL,
                prefix VARCHAR(32) NOT NULL DEFAULT '', last_number INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (store_id, type, year, prefix))");
            $r16rCreated = true;
        }
        $r16rDb->exec("DELETE FROM invoice_number_counters WHERE store_id = 'r16-probe'");
        $r16rDb->exec("INSERT INTO invoice_number_counters (store_id, type, year, prefix, last_number) VALUES ('r16-probe', 'invoice', 2026, 'R16', 7), ('r16-probe', 'credit_note', 2026, 'R16D', 3)");
        $r16rRaised = $r16rRaise->invoke(null, $r16rDb, ['invoice_number_counters' => [
            ['store_id' => 'r16-probe', 'type' => 'invoice', 'year' => 2026, 'prefix' => 'R16', 'last_number' => 9],       // restored 7 < issued 9: raised
            ['store_id' => 'r16-probe', 'type' => 'credit_note', 'year' => 2026, 'prefix' => 'R16D', 'last_number' => 2],  // restored 3 >= 2: never lowered
            ['store_id' => 'r16-probe', 'type' => 'invoice', 'year' => 2026, 'prefix' => 'R16N', 'last_number' => 4],      // series created after the snapshot
        ]]);
        $r16rRows = $r16rDb->query("SELECT prefix, last_number FROM invoice_number_counters WHERE store_id = 'r16-probe' ORDER BY prefix")->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_equals([2, ['R16' => 9, 'R16D' => 3, 'R16N' => 4]], [$r16rRaised, array_map('intval', $r16rRows)],
            "R16 high 1 ({$r16rDriver}): the rollback raises a restored counter to the last number issued, never lowers one, and recreates a new series");
    } catch (Throwable $e) {
        $r16rFail("raiseNumbering on {$r16rDriver}", $e);
    } finally {
        if ($r16rDb !== null) {
            try {
                $r16rCreated ? $r16rDb->exec('DROP TABLE invoice_number_counters') : $r16rDb->exec("DELETE FROM invoice_number_counters WHERE store_id = 'r16-probe'");
            } catch (Throwable $e) {
            }
        }
        $r16rDb = null;
    }
}

// Medium: what a rollback discards, for a snapshot taken now and for one taken by v2.1.0.
try {
    $r16rShop = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (['orders', 'payment_transactions', 'invoices', 'order_refunds', 'customers'] as $r16rTable) {
        $r16rShop->exec("CREATE TABLE {$r16rTable} (id INTEGER PRIMARY KEY, note TEXT)");
        $r16rShop->exec("INSERT INTO {$r16rTable} (note) VALUES ('before'), ('after')");
    }
    $r16rBase = test_fixture_path('r16-rollback');
    mkdir($r16rBase . '/root', 0750, true);
    $r16rUpdater = new Updater($r16rBase . '/root', $r16rBase . '/state', $r16rShop);
    $r16rMarks = (new ReflectionMethod(Updater::class, 'highWaterMarks'))->invoke($r16rUpdater);
    assert_equals(2, $r16rMarks['customers'] ?? null, 'R16 medium: the update snapshot records the customers high-water mark');
    $r16rDiscarded = new ReflectionMethod(Updater::class, 'discardedWrites');
    $r16rNow = $r16rDiscarded->invoke($r16rUpdater, ['database' => ['high_water' => ['orders' => 1, 'payment_transactions' => 1, 'invoices' => 1,
        'order_refunds' => 1, 'customers' => 1], 'high_water_tables' => ['orders', 'payment_transactions', 'invoices', 'order_refunds', 'customers']]]);
    assert_equals(['orders' => 1, 'payment_transactions' => 1, 'invoices' => 1, 'order_refunds' => 1, 'customers' => 1], $r16rNow,
        'R16 medium: a rollback counts the customers registered after the snapshot');
    // A snapshot taken by v2.1.0: high_water has only orders and payments, and no list of the tables it tracked.
    $r16rOld = $r16rDiscarded->invoke($r16rUpdater, ['database' => ['high_water' => ['orders' => 1, 'payment_transactions' => 2]]]);
    assert_equals(['orders' => 1, 'payment_transactions' => 0, 'invoices' => null, 'order_refunds' => null, 'customers' => null], $r16rOld,
        'R16 medium: for a v2.1.0 snapshot the invoices, refunds and customers it never tracked are reported unknown, not skipped');
    $r16rNotice = (string)(new ReflectionMethod(Updater::class, 'reportDiscardedWrites'))->invoke($r16rUpdater, $r16rOld, 'manual rollback', 0);
    assert_true(str_contains($r16rNotice, 'an unknown number of invoices/order_refunds/customers rows') && str_contains($r16rNotice, 'their numbers are not issued again'),
        'R16 medium: the rollback of a v2.1.0 snapshot warns that invoices may have been discarded');
    $r16rUnraised = (string)(new ReflectionMethod(Updater::class, 'reportDiscardedWrites'))->invoke($r16rUpdater, $r16rNow, 'manual rollback', 'SQLSTATE[HY093]: Invalid parameter number');
    assert_true(str_contains($r16rUnraised, 'could not be raised') && str_contains($r16rUnraised, 'HY093') && !str_contains($r16rUnraised, 'not issued again'),
        'R16 high 1: when the counters could not be raised the notice says so and does not claim the discarded numbers are not issued again');
    // A table the snapshot tracked but that did not exist then (created by the update) stays out of the count.
    $r16rNew = $r16rDiscarded->invoke($r16rUpdater, ['database' => ['high_water' => ['orders' => 2, 'payment_transactions' => 2, 'invoices' => 2, 'order_refunds' => 2],
        'high_water_tables' => ['orders', 'payment_transactions', 'invoices', 'order_refunds', 'customers']]]);
    assert_equals(['orders' => 0, 'payment_transactions' => 0, 'invoices' => 0, 'order_refunds' => 0], $r16rNew,
        'R16 medium: a tracked table missing from the snapshot did not exist then and discards nothing');
} catch (Throwable $e) {
    $r16rFail('discarded writes', $e);
} finally {
    if (isset($r16rBase) && is_dir($r16rBase)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r16rBase, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $r16rItem) {
            $r16rItem->isDir() ? @rmdir($r16rItem->getPathname()) : @unlink($r16rItem->getPathname());
        }
        @rmdir($r16rBase);
    }
}
