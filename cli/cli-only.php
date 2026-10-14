<?php

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    exit(1);
}

/**
 * getopt() for "script.php <command> --option=value": PHP's getopt() stops at the first
 * non-option argument, so options after a subcommand were silently dropped.
 * Spec as getopt long options: 'name:' required value, 'name::' optional (=value), 'name' flag.
 */
function cli_long_options(array $argv, array $spec): array
{
    $kinds = [];
    foreach ($spec as $entry) {
        $name = rtrim($entry, ':');
        $kinds[$name] = strlen($entry) - strlen($name);
    }
    $options = [];
    $args = array_values(array_slice($argv, 1));
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--') break;
        if (!preg_match('/^--([A-Za-z0-9][A-Za-z0-9_-]*)(?:=(.*))?$/s', $args[$i], $m) || !isset($kinds[$m[1]])) continue;
        $hasValue = array_key_exists(2, $m);
        if ($kinds[$m[1]] === 0) {
            if (!$hasValue) $options[$m[1]] = false;
        } elseif ($hasValue) {
            $options[$m[1]] = $m[2];
        } elseif ($kinds[$m[1]] === 1 && isset($args[$i + 1])) {
            $options[$m[1]] = $args[++$i];
        } elseif ($kinds[$m[1]] === 2) {
            $options[$m[1]] = false;
        }
    }
    return $options;
}
