<?php

// Round 8 review (2026-10-21), unfixed from round 7: files the web server serves itself (theme CSS/JS, images,
// uploads) carried none of the security headers Security::headers() sends on PHP responses. An uploaded SVG opened
// directly ran its script on the shop's origin, and a sniffed upload could be rendered as HTML.
// CI's Docker job checks the same headers on a real nginx response (.github/workflows/ci.yml).

echo "\n--- Audit round 8 (rest): security headers on static files and uploads ---\n";

$r8hRoot = dirname(__DIR__, 3);
$r8hHtaccess = (string)file_get_contents($r8hRoot . '/.htaccess');
$r8hNginx = (string)file_get_contents($r8hRoot . '/deploy/nginx.conf');
$r8hCi = (string)file_get_contents($r8hRoot . '/.github/workflows/ci.yml');
$r8hSandbox = "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox";

// Apache: the headers apply to static files only (a PHP response keeps its own CSP), and every upload is sandboxed.
assert_true(preg_match('#<FilesMatch "[^"]*css\|[^"]*svg[^"]*">\s*(?:Header always set [^\n]+\n\s*)*Header always set X-Content-Type-Options "nosniff"#', $r8hHtaccess) === 1, 'R8 headers .htaccess: static files are sent with X-Content-Type-Options: nosniff');
foreach (['Referrer-Policy "strict-origin-when-cross-origin"', 'X-Frame-Options "DENY"', 'Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" "expr=%{HTTPS} == \'on\'"'] as $r8hHeader) {
    assert_true(str_contains($r8hHtaccess, 'Header always set ' . $r8hHeader), "R8 headers .htaccess: static files are sent with {$r8hHeader}");
}
assert_true(preg_match('#<If "%\{REQUEST_FILENAME\} =~ m\#/uploads/\#">\s*Header always set Content-Security-Policy "' . preg_quote($r8hSandbox, '#') . '"#', $r8hHtaccess) === 1, 'R8 headers .htaccess: an upload is served under a sandboxing CSP (an SVG cannot run script on the shop origin)');
assert_true(!preg_match('#<FilesMatch "[^"]*php#i', $r8hHtaccess), 'R8 headers .htaccess: the static header block does not match PHP scripts');

// nginx: add_header is not inherited into a location that has its own, so every location serving files sets them.
$r8hLocation = static function (string $opening) use ($r8hNginx): string {
    $start = strpos($r8hNginx, $opening);
    if ($start === false) return '';
    $depth = 0;
    for ($i = $start, $n = strlen($r8hNginx); $i < $n; $i++) {
        if ($r8hNginx[$i] === '{') $depth++;
        if ($r8hNginx[$i] === '}' && --$depth === 0) return substr($r8hNginx, $start, $i - $start + 1);
    }
    return '';
};
$r8hStatic = [
    'location / {',
    'location ~* \.(?:css|js|jpe?g|png|webp|svg)$ {',
    'location ~* ^/vendor/bbf/bbf\.(?:css|js)$ {',
    'location ^~ /uploads/ {',
];
foreach ($r8hStatic as $r8hOpening) {
    $r8hBlock = $r8hLocation($r8hOpening);
    assert_true($r8hBlock !== '', "R8 headers nginx.conf: has {$r8hOpening}");
    foreach (['X-Content-Type-Options "nosniff" always', 'Referrer-Policy "strict-origin-when-cross-origin" always', 'X-Frame-Options "DENY" always'] as $r8hHeader) {
        assert_true(str_contains($r8hBlock, 'add_header ' . $r8hHeader . ';'), "R8 headers nginx.conf: {$r8hOpening} sends {$r8hHeader}");
    }
}
$r8hUploads = $r8hLocation('location ^~ /uploads/ {');
assert_true(str_contains($r8hUploads, 'add_header Content-Security-Policy "' . $r8hSandbox . '" always;'), 'R8 headers nginx.conf: an upload is served under a sandboxing CSP');
assert_true(preg_match('#location ~\* \\\\\.\(\?:php\(\?:\[0-9\]\+\|s\)\?\|phtml\?\|phar\)\(\?:\[\./\]\|\$\) \{ return 403; \}#', $r8hUploads) === 1, 'R8 headers nginx.conf: the uploads prefix location still refuses PHP-like names (^~ skips the server-level regex)');
assert_true(!str_contains($r8hLocation('location ~ \.php$ {'), 'add_header'), 'R8 headers nginx.conf: PHP responses keep only the headers PHP sends (no duplicate CSP)');

// The Docker job reads the headers off a real nginx response for a theme file and an upload.
assert_true(str_contains($r8hCi, "grep -qi '^x-content-type-options: nosniff'") && str_contains($r8hCi, "grep -qi \"^content-security-policy: default-src 'none'.*sandbox\""), 'R8 headers CI: the Docker job checks nosniff and the upload sandbox on real nginx responses');
