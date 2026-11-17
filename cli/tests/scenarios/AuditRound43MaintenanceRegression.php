<?php

echo "\n--- R43 maintenance: the step that holds its window reads its own marker, also where locks are mandatory (Windows, ideas #54-#56) ---\n";

$r43mRoot = test_fixture_path('r43-maintenance');
try {
    if (!is_dir($r43mRoot)) mkdir($r43mRoot, 0750, true);
    MaintenanceMode::enter($r43mRoot, 'r43 test: pending migrations');
    MaintenanceMode::arm($r43mRoot, 'r43 test was interrupted during a migration');
    $r43mMarker = MaintenanceMode::marker($r43mRoot);
    assert_equals(['r43 test was interrupted during a migration', true, true],
        [$r43mMarker['reason'] ?? null, $r43mMarker['failed'] ?? null, MaintenanceMode::owned($r43mRoot)],
        'R43 maintenance: the armed marker this process holds locked is read back and owned (' . PHP_OS_FAMILY . ')');
    MaintenanceMode::settle($r43mRoot, []);
    clearstatcache();
    assert_true(!is_file(MaintenanceMode::path($r43mRoot)) && !MaintenanceMode::active($r43mRoot),
        'R43 maintenance: a successful step lifts its armed window (no failed .maintenance left, no 503)');
} catch (Throwable $e) {
    assert_true(false, 'R43 maintenance: the scenario raised ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    MaintenanceMode::unclaim($r43mRoot);
}
