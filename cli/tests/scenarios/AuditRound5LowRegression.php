<?php

// Round 5 review (2026-10-18), low findings named in the summary: the structured log grew without
// rotation, the compose sample had no healthchecks and no container log limits, and docs/openapi.yaml
// drifted from the routes and status codes ApiV1 really serves.
echo "\n--- Audit round 5 (low): log rotation, compose healthchecks, OpenAPI contract ---\n";
$r5lRoot = dirname(TEST_CLI_DIR);

// --- Structured log rotation ------------------------------------------------------------------
$r5lLogDir = test_fixture_path('r5-low-log');
mkdir($r5lLogDir, 0700, true);
$r5lLog = $r5lLogDir . '/application.jsonl';
$r5lConfig = new ReflectionProperty(Logger::class, 'config');
$r5lPrevious = $r5lConfig->getValue();
$r5lConfig->setValue(null, ['structured_log_file' => $r5lLog, 'structured_log_max_bytes' => 600, 'structured_log_max_files' => 2]);
try {
    for ($i = 0; $i < 40; $i++) Logger::info('r5.low.rotation', ['n' => $i]);
} finally {
    $r5lConfig->setValue(null, $r5lPrevious);
}
clearstatcache();
$r5lFiles = array_values(array_filter(array_map('basename', glob($r5lLog . '*') ?: []), static fn(string $f): bool => !str_ends_with($f, '.lock')));
sort($r5lFiles);
assert_equals(['application.jsonl', 'application.jsonl.1', 'application.jsonl.2'], $r5lFiles, 'R5 low log: the structured log rotates by size and keeps only structured_log_max_files old files');
assert_true(is_file($r5lLog) && filesize($r5lLog) <= 600 + 400, 'R5 low log: the current structured log stays near structured_log_max_bytes');
$r5lLast = (string)file_get_contents($r5lLog);
assert_true(str_contains($r5lLast, '"n":39'), 'R5 low log: the newest record is in the current file (nothing lost on rotation)');
foreach (glob($r5lLogDir . '/*') ?: [] as $r5lFile) @unlink($r5lFile);
@rmdir($r5lLogDir);
$r5lExample = (string)file_get_contents($r5lRoot . '/config.example.php');
assert_true(str_contains($r5lExample, "'structured_log_max_bytes'") && str_contains($r5lExample, "'structured_log_max_files'"), 'R5 low log: config.example.php documents the structured log rotation limits');

// --- Compose healthchecks and container log limits ---------------------------------------------
$r5lCompose = str_replace("\r\n", "\n", (string)file_get_contents($r5lRoot . '/deploy/docker-compose.yml'));
$r5lServices = [];
if (preg_match('/^services:\n(.*?)(?=^\S)/ms', $r5lCompose, $r5lMatch)) {
    foreach (preg_split('/^  (?=[a-z][a-z0-9_-]*:\s*$)/m', $r5lMatch[1]) as $r5lBlock) {
        if (preg_match('/^([a-z][a-z0-9_-]*):/', $r5lBlock, $r5lName)) $r5lServices[$r5lName[1]] = $r5lBlock;
    }
}
foreach (['php', 'worker', 'nginx'] as $r5lService) {
    $r5lBlock = $r5lServices[$r5lService] ?? '';
    assert_true(preg_match('/^    healthcheck:\n      test: \[/m', $r5lBlock) === 1, "R5 low compose: {$r5lService} has a healthcheck");
    assert_true(preg_match('/max-size:\s*"?\d+m"?/', $r5lBlock) === 1 && preg_match('/max-file:\s*"?\d+"?/', $r5lBlock) === 1, "R5 low compose: {$r5lService} container logs are size-limited and rotated");
}
assert_true(str_contains($r5lServices['php'] ?? '', '9000'), 'R5 low compose: the php healthcheck probes PHP-FPM on port 9000');
assert_true(str_contains($r5lServices['worker'] ?? '', 'Health::readiness'), 'R5 low compose: the worker healthcheck checks readiness (database, jobs and cache directories)');
assert_true(str_contains($r5lServices['nginx'] ?? '', '/healthz'), 'R5 low compose: the nginx healthcheck goes through nginx and PHP to /healthz');
assert_true(preg_match('/depends_on:\n\s+php:\n\s+condition: service_healthy/', $r5lServices['nginx'] ?? '') === 1
    && preg_match('/depends_on:\n\s+php:\n\s+condition: service_healthy/', $r5lServices['worker'] ?? '') === 1,
    'R5 low compose: nginx and the worker start only after PHP-FPM is healthy');

// --- OpenAPI contract: routes and status codes ------------------------------------------------
$r5lSource = (string)file_get_contents($r5lRoot . '/src/ApiV1.php');
preg_match_all("/\\\$method === '([A-Z]+)' && (?:\\\$path === '([^']+)'|preg_match\\('#\\^([^#]+)\\$#')/", $r5lSource, $r5lRouteMatches, PREG_SET_ORDER);
$r5lServed = [];
foreach ($r5lRouteMatches as $r5lRoute) {
    $r5lPath = ($r5lRoute[2] ?? '') !== '' ? $r5lRoute[2] : str_replace('(\d+)', '{id}', $r5lRoute[3]);
    $r5lServed[] = strtolower($r5lRoute[1]) . ' ' . $r5lPath;
}
sort($r5lServed);
$r5lSpec = str_replace("\r\n", "\n", (string)file_get_contents($r5lRoot . '/docs/openapi.yaml'));
$r5lOps = [];
$r5lPathNow = $r5lMethodNow = null;
$r5lInPaths = $r5lInResponses = false;
foreach (explode("\n", $r5lSpec) as $r5lLine) {
    if (preg_match('/^(\S[^:]*):/', $r5lLine, $r5lTop)) { $r5lInPaths = $r5lTop[1] === 'paths'; continue; }
    if (!$r5lInPaths) continue;
    if (preg_match('#^  (/\S*):\s*$#', $r5lLine, $r5lM)) { $r5lPathNow = $r5lM[1]; $r5lMethodNow = null; continue; }
    if (preg_match('/^    (get|post|patch|put|delete):\s*$/', $r5lLine, $r5lM)) { $r5lMethodNow = $r5lM[1]; $r5lOps["{$r5lMethodNow} {$r5lPathNow}"] = []; $r5lInResponses = false; continue; }
    if ($r5lMethodNow === null) continue;
    if (preg_match('/^      responses:\s*$/', $r5lLine)) { $r5lInResponses = true; continue; }
    if (preg_match('/^      \S/', $r5lLine)) { $r5lInResponses = false; continue; }
    if ($r5lInResponses && preg_match("/^        '(\\d{3})':/", $r5lLine, $r5lM)) $r5lOps["{$r5lMethodNow} {$r5lPathNow}"][] = $r5lM[1];
}
$r5lDocumented = array_keys($r5lOps);
sort($r5lDocumented);
assert_true(count($r5lServed) >= 12, 'R5 low OpenAPI: the ApiV1 route table was read (' . count($r5lServed) . ' routes)');
assert_equals($r5lServed, $r5lDocumented, 'R5 low OpenAPI: docs/openapi.yaml documents exactly the routes ApiV1 serves');
foreach ($r5lOps as $r5lOp => $r5lCodes) {
    // Every route authenticates (401), checks a scope or store (403) and is rate limited (429) in ApiExternal.php.
    $r5lNeed = ['401', '403', '429'];
    [$r5lMethod, $r5lPath] = explode(' ', $r5lOp, 2);
    if (str_contains($r5lPath, '{id}') || $r5lPath === '/inventory') $r5lNeed[] = '404';
    // Cursor/limit, query and JSON body validation errors are 400 (ApiV1::errorResponse).
    if ($r5lMethod !== 'get' || !str_contains($r5lPath, '{id}')) $r5lNeed[] = '400';
    if ($r5lMethod !== 'get') $r5lNeed[] = '409';
    $r5lMissing = array_values(array_diff($r5lNeed, $r5lCodes));
    assert_equals([], $r5lMissing, "R5 low OpenAPI: {$r5lOp} documents every status ApiV1 can return");
}
