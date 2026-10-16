<?php

// CR-A (code review 2026-10-15): dead code removed from the core. The test harness redeclared
// QueryCountingPDO (so tests never exercised src/QueryCountingPDO.php that Db uses for benchmarks),
// the root templates/*.html were legacy e-mail drafts nothing renders (MailService reads templates/emails),
// and the never-enqueued sitemap_build job wrote a static example.com sitemap.xml into the code directory
// while /sitemap.xml is served dynamically by sitemap.php.
echo "\n--- CR-A: dead code (QueryCountingPDO duplicate, root templates, sitemap_build job) ---\n";

$daRoot = dirname(TEST_CLI_DIR);
$daPdoFile = str_replace('\\', '/', (string)(new ReflectionClass('QueryCountingPDO'))->getFileName());
assert_true(str_ends_with($daPdoFile, '/src/QueryCountingPDO.php'), 'CR-A: tests use the production QueryCountingPDO (no harness duplicate)');
assert_equals([], array_map('basename', glob($daRoot . '/templates/*.html') ?: []), 'CR-A: no unused legacy templates/*.html next to templates/emails and templates/legal');
$daWorker = (string)file_get_contents($daRoot . '/cli/worker.php');
assert_true(!str_contains($daWorker, 'sitemap_build') && !str_contains($daWorker, 'handleSitemapBuild'), 'CR-A: worker has no dead sitemap_build job writing sitemap.xml into the code directory');
