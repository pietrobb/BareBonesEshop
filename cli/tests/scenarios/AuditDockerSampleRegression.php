<?php

// CR-M15 (code review 2026-10-15): the Docker sample ran PHP-FPM as root, had no GD (product image
// variants failed) and mounted the whole checkout writable into PHP, so a code-execution bug could
// rewrite the application. The live image is probed separately (docker build + run); these checks
// pin the sample files.
echo "\n--- CR-M15: Docker sample runs non-root, has GD and mounts code read-only ---\n";
$dkRoot = dirname(TEST_CLI_DIR);
$dkFile = (string)file_get_contents($dkRoot . '/deploy/Dockerfile');
$dkCompose = str_replace("\r\n", "\n", (string)file_get_contents($dkRoot . '/deploy/docker-compose.yml'));
$dkReadme = (string)file_get_contents($dkRoot . '/README.md');

preg_match_all('/^USER\s+(\S+)/m', $dkFile, $dkUsers);
$dkLastUser = end($dkUsers[1]) ?: 'root';
assert_true(!in_array($dkLastUser, ['root', '0'], true), 'CR-M15: the image runs as an unprivileged user');
assert_true(preg_match('/docker-php-ext-install[^\n]*\bgd\b/', $dkFile) === 1 && str_contains($dkFile, '--with-jpeg') && str_contains($dkFile, '--with-webp'), 'CR-M15: the image builds GD with JPEG and WebP for image variants');

// Split the compose file into service blocks.
$dkServices = [];
if (preg_match('/^services:\n(.*?)(?=^\S)/ms', $dkCompose, $dkMatch)) {
    foreach (preg_split('/^  (?=[a-z][a-z0-9_-]*:\s*$)/m', $dkMatch[1]) as $dkBlock) {
        if (preg_match('/^([a-z][a-z0-9_-]*):/', $dkBlock, $dkName)) $dkServices[$dkName[1]] = $dkBlock;
    }
}
foreach (['php', 'worker'] as $dkService) {
    $dkBlock = $dkServices[$dkService] ?? '';
    assert_true(str_contains($dkBlock, '- ..:/var/www/html:ro'), "CR-M15: {$dkService} mounts the application code read-only");
    assert_true(preg_match('/^\s+read_only:\s*true\s*$/m', $dkBlock) === 1 && str_contains($dkBlock, '/tmp'), "CR-M15: {$dkService} has a read-only root filesystem with a tmpfs /tmp");
    foreach (['cache', 'uploads', 'import', 'logs'] as $dkDir) {
        assert_true(str_contains($dkBlock, "- ../{$dkDir}:/var/www/html/{$dkDir}") && !str_contains($dkBlock, "- ../{$dkDir}:/var/www/html/{$dkDir}:ro"), "CR-M15: {$dkService} gets {$dkDir}/ as the only writable part of the checkout");
    }
    assert_true(!preg_match('/^\s+user:\s*(?:root|0)\b/m', $dkBlock), "CR-M15: {$dkService} is not switched back to root");
}
$dkSetup = $dkServices['setup'] ?? '';
assert_true(str_contains($dkSetup, '- ..:/var/www/html') && !str_contains($dkSetup, '- ..:/var/www/html:ro') && str_contains($dkSetup, 'profiles:'), 'CR-M15: only the opt-in setup service can write config.php into the checkout');
assert_true(str_contains($dkReadme, ' setup php cli/setup.php install'), 'CR-M15: README installs through the setup service');
