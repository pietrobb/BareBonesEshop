<?php

// Review round 17, high: commit 6100a3d stored all seven templates/emails/*/order_shipped.html with CRLF and a stray
// CR in the middle of a line. A lone CR makes git treat a file as binary, so core.autocrlf never normalised them, and
// cli/build-update.php refused the tree: no release could be built from main.
echo "\n--- Audit round 17: LF line endings in the release tree ---\n";

$r17eRoot = dirname(TEST_CLI_DIR);

// 1. The e-mail templates on disk carry no CR (the files a package ships).
$r17eCr = [];
foreach (glob($r17eRoot . '/templates/emails/*/*') ?: [] as $r17eFile) {
    if (str_contains((string)file_get_contents($r17eFile), "\r")) $r17eCr[] = substr($r17eFile, strlen($r17eRoot) + 1);
}
assert_equals([], $r17eCr, 'R17 eol: no e-mail template contains a carriage return');

// 2. .gitattributes stores and checks out all text with LF, also on a Windows checkout with core.autocrlf=true.
$r17eAttributes = (string)@file_get_contents($r17eRoot . '/.gitattributes');
assert_true(preg_match('~^\*\s+text=auto\s+eol=lf\s*$~m', $r17eAttributes) === 1 && str_contains($r17eAttributes, 'releases/** binary'),
    'R17 eol: .gitattributes has "* text=auto eol=lf" and keeps signed releases binary');

// 3. The git index (what git archive exports for a release) holds no text file with CRLF, mixed endings, or a lone CR.
// Runs wherever the tree is a git checkout (CI always is); a package install has no .git to check.
$r17eGit = is_dir($r17eRoot . '/.git') || is_file($r17eRoot . '/.git');
if ($r17eGit) {
    $r17eOut = [];
    $r17eCode = 1;
    exec('git -C ' . escapeshellarg($r17eRoot) . ' -c core.quotepath=off ls-files --eol 2>&1', $r17eOut, $r17eCode);
    if ($r17eCode === 0) {
        $r17eBad = [];
        foreach ($r17eOut as $r17eLine) {
            $r17eParts = explode("\t", $r17eLine, 2);
            if (count($r17eParts) !== 2) continue;
            [$r17eInfo, $r17ePath] = $r17eParts;
            if (preg_match('~^i/(crlf|mixed)~', $r17eInfo) === 1) {
                $r17eBad[] = $r17ePath;
            } elseif (str_starts_with($r17eInfo, 'i/-text') && !str_starts_with($r17ePath, 'releases/')
                && preg_match('~\.(png|jpe?g|gif|webp|ico|woff2?|ttf|otf|pdf|zip|gz|epub|sqlite)$~i', $r17ePath) !== 1) {
                $r17eBad[] = $r17ePath . ' (git sees it as binary: lone CR?)';
            }
        }
        assert_equals([], $r17eBad, 'R17 eol: every tracked text file is stored with LF only');
    } else {
        assert_true(getenv('GITHUB_ACTIONS') !== 'true', 'R17 eol: git ls-files works in CI (' . implode(' ', $r17eOut) . ')');
    }
} else {
    assert_true(getenv('GITHUB_ACTIONS') !== 'true', 'R17 eol: CI runs the suite in a git checkout');
}

// 4. The builder refuses a stray CR too, not only CRLF (the 6100a3d files had both).
$r17eBase = test_fixture_path('r17-eol-base');
$r17eTarget = test_fixture_path('r17-eol-target');
$r17eOutput = test_fixture_path('r17-eol-package');
mkdir($r17eBase . '/templates', 0750, true);
mkdir($r17eTarget . '/templates', 0750, true);
file_put_contents($r17eBase . '/templates/mail.html', "<p>a</p>\n");
file_put_contents($r17eTarget . '/templates/mail.html', "<p>a</p>\r{{/if}}\n");
$r17eRefused = '';
try {
    UpdatePackageBuilder::build($r17eBase, $r17eTarget, $r17eOutput, '1.1.0', '1.0.0');
} catch (RuntimeException $e) {
    $r17eRefused = $e->getMessage();
}
assert_true(str_contains($r17eRefused, 'templates/mail.html contains a stray carriage return') && !is_dir($r17eOutput),
    'R17 eol: the update builder refuses a lone CR before writing a package (' . $r17eRefused . ')');
file_put_contents($r17eTarget . '/templates/mail.html', "<p>a</p>{{/if}}\n");
$r17eBuilt = UpdatePackageBuilder::build($r17eBase, $r17eTarget, $r17eOutput, '1.1.0', '1.0.0');
assert_equals(1, $r17eBuilt['files'], 'R17 eol: the same tree with LF only builds');
foreach ([$r17eOutput . '/payload/templates/mail.html', $r17eOutput . '/manifest.json', $r17eBase . '/templates/mail.html', $r17eTarget . '/templates/mail.html'] as $r17eFile) @unlink($r17eFile);
foreach ([$r17eOutput . '/payload/templates', $r17eOutput . '/payload', $r17eOutput, $r17eBase . '/templates', $r17eBase, $r17eTarget . '/templates', $r17eTarget] as $r17eDir) @rmdir($r17eDir);
