<?php

// Review round 22 (R57), medium S2: Debian/Ubuntu Apache enables "AddHandler type-map var". A restored upload named
// m.var.jpg passed the R56 allowlist (it ends in .jpg) and stayed live in the web root, where Apache read it as a type map
// naming its own Content-Type (text/javascript): a script on the shop's origin. asis (send-as-is) and map (image map)
// are handlers by extension too. Restore now sets such names aside and .htaccess refuses them under uploads/.
echo "\n--- R57 uploads: Apache handler extensions inside a name ---\n";
try {
    $r57uActive = new ReflectionMethod(BackupService::class, 'activeInBrowser');
    $r57uVerdict = [];
    foreach (['m.var.jpg', 'products/x.VAR.png', 'a.asis.gif', 'b.map.webp', 'c.imap.jpg', 'products/photo.jpg', 'docs/manual.pdf',
        'products/variant.jpg', 'products/map-detail.png', 'products/2024.11.jpg'] as $r57uName) {
        $r57uVerdict[$r57uName] = $r57uActive->invoke(null, $r57uName);
    }
    assert_equals(['m.var.jpg' => true, 'products/x.VAR.png' => true, 'a.asis.gif' => true, 'b.map.webp' => true, 'c.imap.jpg' => true,
        'products/photo.jpg' => false, 'docs/manual.pdf' => false, 'products/variant.jpg' => false, 'products/map-detail.png' => false,
        'products/2024.11.jpg' => false], $r57uVerdict,
        'R57 S2 restore: a type-map, send-as-is or image-map extension inside an upload\'s name is set aside, not restored live (was: m.var.jpg served as text/javascript)');

    $r57uHtaccess = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/.htaccess');
    $r57uRule = '';
    foreach (explode("\n", $r57uHtaccess) as $r57uLine) {
        if (str_starts_with($r57uLine, 'RewriteRule ^uploads/')) $r57uRule = $r57uLine;
    }
    $r57uPattern = '#' . explode(' ', substr($r57uRule, strlen('RewriteRule ')))[0] . '#i';
    $r57uBlocked = [];
    foreach (['uploads/m.var.jpg', 'uploads/a.asis.png', 'uploads/b.map.gif', 'uploads/s.php.jpg', 'uploads/products/photo.jpg', 'uploads/variant.jpg'] as $r57uPath) {
        $r57uBlocked[$r57uPath] = preg_match($r57uPattern, $r57uPath) === 1;
    }
    assert_equals(['uploads/m.var.jpg' => true, 'uploads/a.asis.png' => true, 'uploads/b.map.gif' => true, 'uploads/s.php.jpg' => true,
        'uploads/products/photo.jpg' => false, 'uploads/variant.jpg' => false], $r57uBlocked,
        'R57 S2 .htaccess: Apache refuses an upload whose name carries the var, asis or map handler extension (was: PHP only)');
} catch (Throwable $e) {
    assert_true(false, 'R57 uploads type-map: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
}
