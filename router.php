<?php
/**
 * Development router for PHP's built-in web server:
 *
 *   php -S localhost:8080 router.php
 *
 * Plain `php -S` serves every file below the document root (config/*.json, the
 * SQLite database, releases/, cli/ ...). This router applies the deny and rewrite
 * rules of the bundled .htaccess — parsed from it, so there is a single source of
 * truth — and fails closed on any rule it does not understand. Not for production:
 * use Apache with .htaccess or deploy/nginx.conf.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$bbeRouterRoot = __DIR__;
$bbeRouterUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
$bbeRouterQuery = (string)($_SERVER['QUERY_STRING'] ?? '');

$bbeRouterStop = static function (int $status): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $status === 403 ? "Forbidden\n" : ($status === 400 ? "Bad Request\n" : "Router error\n");
    exit;
};

// Apache matches per-directory rules against the decoded path without the leading slash.
// Not parse_url(): it reads "//config/x" as host "config" and path "/x".
$bbeRouterPath = rawurldecode(explode('?', $bbeRouterUri, 2)[0]);
if (str_contains($bbeRouterPath, "\0") || preg_match('#(?:^|[/\\\\])\.\.(?:[/\\\\]|$)#', $bbeRouterPath)) {
    $bbeRouterStop(400);
}
$bbeRouterPath = ltrim((string)preg_replace('#[/\\\\]+#', '/', $bbeRouterPath), '/');
if (DIRECTORY_SEPARATOR === '\\') {
    // Windows resolves "config./x", "config /x" and "x::$DATA" to the same file as "config/x".
    if (str_contains($bbeRouterPath, ':')) $bbeRouterStop(403);
    $bbeRouterPath = implode('/', array_map(static fn(string $s): string => rtrim($s, '. '), explode('/', $bbeRouterPath)));
}

$bbeRouterLines = @file($bbeRouterRoot . '/.htaccess', FILE_IGNORE_NEW_LINES);
if ($bbeRouterLines === false) $bbeRouterStop(500);

// Parse RewriteCond/RewriteRule plus the <If> request/query deny blocks.
$bbeRouterRules = [];
$bbeRouterIfDenies = [];
$bbeRouterConds = [];
$bbeRouterHeaderBlock = null;
foreach ($bbeRouterLines as $bbeRouterLine) {
    $bbeRouterLine = trim($bbeRouterLine);
    if ($bbeRouterLine === '' || $bbeRouterLine[0] === '#' || $bbeRouterLine === 'RewriteEngine On') continue;
    // Response-header blocks do not route; anything but a Header line inside one fails closed.
    if ($bbeRouterHeaderBlock !== null) {
        if ($bbeRouterLine === $bbeRouterHeaderBlock) $bbeRouterHeaderBlock = null;
        elseif (!str_starts_with($bbeRouterLine, 'Header ')) $bbeRouterStop(500);
        continue;
    }
    if (preg_match('/^<FilesMatch "[^"]+">$/', $bbeRouterLine)) { $bbeRouterHeaderBlock = '</FilesMatch>'; continue; }
    if (preg_match('/^<If "%\{REQUEST_FILENAME\} =~ m#[^#]+#i?">$/', $bbeRouterLine)) { $bbeRouterHeaderBlock = '</If>'; continue; }
    if (preg_match('/^RewriteCond\s+(\S+)\s+("[^"]*"|\S+)(?:\s+\[([^\]]*)\])?$/', $bbeRouterLine, $m)) {
        $bbeRouterConds[] = ['var' => $m[1], 'test' => trim($m[2], '"'), 'nc' => stripos($m[3] ?? '', 'NC') !== false];
        continue;
    }
    if (preg_match('/^RewriteRule\s+(\S+)\s+(\S+)(?:\s+\[([^\]]*)\])?$/', $bbeRouterLine, $m)) {
        $flags = array_map('strtoupper', array_map('trim', explode(',', $m[3] ?? '')));
        $bbeRouterRules[] = ['pattern' => $m[1], 'target' => $m[2], 'flags' => $flags, 'conds' => $bbeRouterConds];
        $bbeRouterConds = [];
        continue;
    }
    if (str_starts_with($bbeRouterLine, '<If ')) {
        if (preg_match('/^<If "\(-f %\{REQUEST_FILENAME\} \|\| -d %\{REQUEST_FILENAME\}\) && %\{REQUEST_URI\} =~ m#(.+?)#(i?)">$/', $bbeRouterLine, $m)) {
            $bbeRouterIfDenies[] = ['#' . $m[1] . '#' . $m[2], null];
            continue;
        }
        if (!preg_match('/^<If "%\{REQUEST_URI\} =~ m#(.+?)#(i?) && %\{QUERY_STRING\} =~ m#(.+?)#(i?)">$/', $bbeRouterLine, $m)) $bbeRouterStop(500);
        $bbeRouterIfDenies[] = ['#' . $m[1] . '#' . $m[2], '#' . $m[3] . '#' . $m[4]];
        continue;
    }
    if (preg_match('/^(?:<\/If>|Require all denied|<\/?IfModule\b.*|AddOutputFilterByType\b.*|Expires(?:Active|ByType)\b.*)$/', $bbeRouterLine)) continue;
    $bbeRouterStop(500); // unknown directive: fail closed instead of silently serving
}
if ($bbeRouterRules === [] || $bbeRouterHeaderBlock !== null) $bbeRouterStop(500);

foreach ($bbeRouterIfDenies as [$uriPattern, $queryPattern]) {
    $bbeRouterIfApplies = $queryPattern === null
        ? $bbeRouterPath !== '' && file_exists($bbeRouterRoot . '/' . $bbeRouterPath)
        : preg_match($queryPattern, $bbeRouterQuery) === 1;
    if ($bbeRouterIfApplies && preg_match($uriPattern, '/' . $bbeRouterPath) === 1) {
        $bbeRouterStop(403);
    }
}

$bbeRouterRegex = static fn(string $pattern, bool $nc): string => '#' . str_replace('#', '\#', $pattern) . '#' . ($nc ? 'i' : '');
$bbeRouterDeniedPath = static function (string $path) use ($bbeRouterRules, $bbeRouterRegex): bool {
    foreach ($bbeRouterRules as $rule) {
        if ($rule['conds'] === [] && $rule['target'] === '-' && in_array('F', $rule['flags'], true)
            && preg_match($bbeRouterRegex($rule['pattern'], in_array('NC', $rule['flags'], true)), $path) === 1) {
            return true;
        }
    }
    return false;
};

// The file the request resolves to on disk is checked as well (8.3 short names, symlinks).
$bbeRouterFile = $bbeRouterRoot . '/' . $bbeRouterPath;
if ($bbeRouterPath !== '' && file_exists($bbeRouterFile)) {
    $bbeRouterReal = realpath($bbeRouterFile);
    $bbeRouterRealRoot = realpath($bbeRouterRoot);
    if ($bbeRouterReal === false || $bbeRouterRealRoot === false) $bbeRouterStop(403);
    $bbeRouterReal = str_replace('\\', '/', $bbeRouterReal);
    $bbeRouterRealRoot = rtrim(str_replace('\\', '/', $bbeRouterRealRoot), '/');
    $bbeRouterCaseFold = DIRECTORY_SEPARATOR === '\\' ? 'strtolower' : static fn(string $s): string => $s;
    if ($bbeRouterReal !== $bbeRouterRealRoot && !str_starts_with($bbeRouterCaseFold($bbeRouterReal), $bbeRouterCaseFold($bbeRouterRealRoot . '/'))) {
        $bbeRouterStop(403);
    }
    $bbeRouterRealRelative = ltrim(substr($bbeRouterReal, strlen($bbeRouterRealRoot)), '/');
    if ($bbeRouterRealRelative !== '' && $bbeRouterDeniedPath($bbeRouterRealRelative)) $bbeRouterStop(403);
    $bbeRouterFile = $bbeRouterReal;
}

// Only decoded-path conditions: %{THE_REQUEST} is the raw request line, which percent-encoding bypasses.
$bbeRouterCondMatches = static function (array $cond) use ($bbeRouterFile, $bbeRouterPath, $bbeRouterStop): bool {
    if ($cond['var'] === '%{REQUEST_FILENAME}' && $cond['test'] === '-d') return $bbeRouterPath !== '' && is_dir($bbeRouterFile);
    if ($cond['var'] === '%{REQUEST_FILENAME}' && $cond['test'] === '-f') return $bbeRouterPath !== '' && is_file($bbeRouterFile);
    $bbeRouterStop(500);
};

foreach ($bbeRouterRules as $rule) {
    foreach ($rule['conds'] as $cond) {
        if (!$bbeRouterCondMatches($cond)) continue 2;
    }
    if (preg_match($bbeRouterRegex($rule['pattern'], in_array('NC', $rule['flags'], true)), $bbeRouterPath, $match) !== 1) continue;
    if (in_array('F', $rule['flags'], true)) $bbeRouterStop(403);
    if ($rule['target'] === '-') return false; // pass-through: the built-in server serves the existing file

    $target = preg_replace_callback('/\$(\d)/', static fn(array $g): string => (string)($match[(int)$g[1]] ?? ''), $rule['target']);
    [$script, $query] = array_pad(explode('?', $target, 2), 2, '');
    if (in_array('QSA', $rule['flags'], true) && $bbeRouterQuery !== '') {
        $query = $query === '' ? $bbeRouterQuery : $query . '&' . $bbeRouterQuery;
    }
    $script = ltrim($script, '/');
    $scriptFile = $bbeRouterRoot . '/' . $script;
    if (!preg_match('/^[A-Za-z0-9_\/.-]+\.php$/', $script) || !is_file($scriptFile)) $bbeRouterStop(500);

    parse_str($query, $_GET);
    $_REQUEST = array_merge($_GET, $_POST);
    $_SERVER['QUERY_STRING'] = $query;
    $_SERVER['SCRIPT_NAME'] = '/' . $script;
    $_SERVER['PHP_SELF'] = '/' . $script;
    $_SERVER['SCRIPT_FILENAME'] = $scriptFile;
    chdir(dirname($scriptFile));
    unset($rule, $cond, $match, $target, $query, $m, $bbeRouterLine, $bbeRouterLines, $bbeRouterRules, $bbeRouterConds, $bbeRouterIfDenies);
    require $scriptFile;
    return true;
}

return false;
