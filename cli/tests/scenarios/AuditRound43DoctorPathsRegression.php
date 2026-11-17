<?php

echo "\n--- R43 doctor: jobs_dir, private_cache_dir and gdpr_salt_file must be usable by the shop (idea #7) ---\n";

$r43dBase = test_fixture_path('r43-doctor-paths');
try {
    mkdir($r43dBase . '/installed/src', 0750, true);
    mkdir($r43dBase . '/state', 0750, true);
    mkdir($r43dBase . '/private/secrets', 0750, true);
    $r43dLevels = static function (array $config) use ($r43dBase): array {
        $checks = (new Updater($r43dBase . '/installed', $r43dBase . '/state', null, null, null, null, $config))->doctor(null, null, false)['checks'];
        return array_column($checks, 'level', 'name') + ['_messages' => implode(' | ', array_column($checks, 'message'))];
    };
    $r43dConfig = ['jobs_dir' => $r43dBase . '/private/jobs', 'private_cache_dir' => $r43dBase . '/private/cache',
        'gdpr_salt_file' => $r43dBase . '/private/secrets/gdpr_salt', 'update_storage_dir' => $r43dBase . '/state'];

    // Not created yet, in a writable private directory: created on first use.
    $r43dFresh = $r43dLevels($r43dConfig);
    assert_equals(['ok', 'ok', 'ok'], [$r43dFresh['jobs_dir'] ?? null, $r43dFresh['private_cache_dir'] ?? null, $r43dFresh['gdpr_salt_file'] ?? null],
        'R43 doctor: missing private paths below a writable directory are OK (' . $r43dFresh['_messages'] . ')');

    // A file where the directory should be, and a corrupt salt: FAIL, the shop would 500 on first use.
    file_put_contents($r43dBase . '/private/jobs', 'x');
    file_put_contents($r43dBase . '/private/secrets/gdpr_salt', "not-a-salt\n");
    $r43dBroken = $r43dLevels($r43dConfig);
    assert_equals(['fail', 'fail'], [$r43dBroken['jobs_dir'] ?? null, $r43dBroken['gdpr_salt_file'] ?? null],
        'R43 doctor: jobs_dir that is a file and a corrupt GDPR salt are FAIL (' . $r43dBroken['_messages'] . ')');
    unlink($r43dBase . '/private/jobs');
    mkdir($r43dBase . '/private/jobs', 0750);
    file_put_contents($r43dBase . '/private/secrets/gdpr_salt', bin2hex(random_bytes(32)) . "\n");
    $r43dGood = $r43dLevels($r43dConfig);
    assert_equals(['ok', 'ok'], [$r43dGood['jobs_dir'] ?? null, $r43dGood['gdpr_salt_file'] ?? null], 'R43 doctor: an existing jobs_dir and a valid salt are OK');

    // A configured gdpr_hash_salt needs no file.
    $r43dHash = $r43dLevels($r43dConfig + ['gdpr_hash_salt' => 'configured']);
    assert_equals('ok', $r43dHash['gdpr_salt_file'] ?? null, 'R43 doctor: gdpr_hash_salt makes the salt file irrelevant');

    // Not writable by the shop (only meaningful for a POSIX account other than root).
    if (DIRECTORY_SEPARATOR === '/' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        chmod($r43dBase . '/private/jobs', 0550);
        chmod($r43dBase . '/private', 0550);
        try {
            $r43dReadonly = $r43dLevels($r43dConfig);
        } finally {
            chmod($r43dBase . '/private', 0750);
            chmod($r43dBase . '/private/jobs', 0750);
        }
        assert_equals(['fail', 'fail'], [$r43dReadonly['jobs_dir'] ?? null, $r43dReadonly['private_cache_dir'] ?? null],
            'R43 doctor: a read-only jobs_dir and an uncreatable private_cache_dir are FAIL (' . $r43dReadonly['_messages'] . ')');
    }

    // Inside the application root: only the config check's FAIL, no second report.
    $r43dInside = $r43dLevels(['jobs_dir' => $r43dBase . '/installed/jobs'] + $r43dConfig);
    assert_equals(['fail', null], [$r43dInside['config'] ?? null, $r43dInside['jobs_dir'] ?? null], 'R43 doctor: jobs_dir in the web root stays the config FAIL');
} catch (Throwable $e) {
    assert_true(false, 'R43 doctor: the scenario raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
}
