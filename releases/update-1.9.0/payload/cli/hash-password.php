<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Admin Password Hasher
 * Usage: php cli/hash-password.php "your-password"
 *
 * Copy the output to config.php → admin_password
 */
$password = $argv[1] ?? null;
if (!$password) {
    echo "Usage: php cli/hash-password.php <password>\n";
    echo "  Generates an Argon2id hash for config.php admin_password.\n";
    exit(1);
}

$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
echo password_hash($password, $algo) . "\n";
