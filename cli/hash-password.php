<?php
require_once __DIR__ . '/cli-only.php';

/**
 * BareBones Shop — Admin Password Hasher
 * Usage: php cli/hash-password.php            (type the password at the prompt)
 *        printf '%s' "$PASSWORD" | php cli/hash-password.php
 *
 * The password is read from stdin (or BBE_ADMIN_PASSWORD), never from the command line:
 * arguments are visible to other users in the process list and end up in the shell history.
 * Copy the output to config.php → admin_password
 */
if (isset($argv[1])) {
    fwrite(STDERR, "Passwords are not accepted in command arguments (process list, shell history).\n");
    fwrite(STDERR, "Usage: php cli/hash-password.php   then type the password, or pipe it on stdin.\n");
    exit(1);
}

$password = (string)(getenv('BBE_ADMIN_PASSWORD') ?: '');
if ($password === '') {
    $interactive = function_exists('stream_isatty') && stream_isatty(STDIN);
    $hideEcho = $interactive && DIRECTORY_SEPARATOR === '/';
    if ($interactive) fwrite(STDERR, 'Password: ');
    if ($hideEcho) shell_exec('stty -echo');
    $password = rtrim((string)fgets(STDIN), "\r\n");
    if ($hideEcho) {
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    }
}
if ($password === '') {
    fwrite(STDERR, "No password given on stdin.\n");
    exit(1);
}

$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
echo password_hash($password, $algo) . "\n";
