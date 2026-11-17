<?php

echo "\n--- R43 rate limit: a refused attempt is not recorded, so a third party cannot hold a subject limit closed (idea #9) ---\n";

$r43rlDb = new PDO('sqlite::memory:');
Security::setRateDbForTesting($r43rlDb);
try {
    $r43rlAction = 'checkout_subject_' . hash('sha256', 'victim@example.test');
    $r43rlResults = [];
    for ($i = 0; $i < 13; $i++) $r43rlResults[] = Security::rateLimit($r43rlAction, 3, 60, false);
    assert_equals([true, true, true, false, false], array_slice($r43rlResults, 0, 5), 'R43 rate limit: three attempts pass, the fourth is refused');
    assert_equals(3, (int)$r43rlDb->query('SELECT COUNT(*) FROM hits')->fetchColumn(), 'R43 rate limit: the ten refused attempts left no hit');

    // The window of the three allowed hits passes: the next attempt goes through although refused ones kept coming.
    $r43rlDb->exec('UPDATE hits SET ts = ts - 61');
    assert_true(Security::rateLimit($r43rlAction, 3, 60, false), 'R43 rate limit: once the allowed hits are older than the window the subject is free again');
    assert_true(!Security::rateLimit('r43_zero', 0, 60, false), 'R43 rate limit: a limit of 0 refuses every attempt');
    assert_equals(0, (int)$r43rlDb->query("SELECT COUNT(*) FROM hits WHERE key = '" . hash('sha256', 'r43_zero|global') . "'")->fetchColumn(),
        'R43 rate limit: a refused attempt at a limit of 0 records nothing');
    assert_true(!$r43rlDb->inTransaction(), 'R43 rate limit: no transaction is left open');
} catch (Throwable $e) {
    assert_true(false, 'R43 rate limit: the scenario raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
}
