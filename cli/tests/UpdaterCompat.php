<?php
/**
 * R62 (review round 27, high 1): a signed update runs this release's migrations inside the updater of the installed
 * release. The classes that updater had loaded before it swapped the files (DataOverrides, Db, Security, ...) keep their
 * old code for the rest of the process, so a migration that reaches a method or constant they gained later dies with
 * "Call to undefined method" and the update rolls back on every shop (Bootstrap::stores() → DataOverrides::readStores()).
 *
 * The static half of the guard: from every migration a release has not applied yet, follow the static calls
 * (Class::method(), self::/static::, $this->method()) through src/ and report each use of a member that the release's
 * preloaded version of a class lacks. A use is guarded when a function on the way checks it first
 * (method_exists('Class', 'member'), defined('Class::CONSTANT')). Calls through other objects are not followed: the real
 * update from each released tree (cli/test-release-upgrade.php) covers what this cannot see.
 *
 * Fixture: cli/tests/fixtures/updater-preloaded-api.json, written by
 * php cli/test-release-upgrade.php --from=vX.Y.Z --record-preloaded=cli/tests/fixtures/updater-preloaded-api.json
 */
final class UpdaterCompat
{
    /** @var array<string, array{class: ?string, calls: list<array{0: string, 1: int}>, guards: array<string, true>, file: string}> */
    private array $functions = [];

    public function __construct(private string $root)
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.php')) $this->functions += self::functionsOf($file->getPathname());
        }
    }

    /**
     * @param array{migrations: list<string>, classes: array<string, array{methods: list<string>, constants: list<string>, props?: list<string>}>} $release
     * @return list<string> "file:line Class::member via chain" of every unguarded use
     */
    public function findings(array $release): array
    {
        $applied = array_flip($release['migrations']);
        $preloaded = $release['classes'];
        $findings = [];
        $visited = [];
        foreach (glob($this->root . '/migrations/*.php') ?: [] as $migration) {
            if (isset($applied[basename($migration, '.php')])) continue;
            foreach (self::functionsOf($migration, 'migration ' . basename($migration, '.php')) as $key => $info) {
                $this->functions[$key] = $info;
                $this->walk($key, [], [$key], $preloaded, $visited, $findings);
            }
        }
        return array_values(array_unique($findings));
    }

    /**
     * @param array<string, true> $guards
     * @param list<string> $chain
     * @param array<string, array{methods: list<string>, constants: list<string>, props?: list<string>}> $preloaded
     * @param array<string, true> $visited
     * @param list<string> $findings
     */
    private function walk(string $key, array $guards, array $chain, array $preloaded, array &$visited, array &$findings): void
    {
        $info = $this->functions[$key] ?? null;
        if ($info === null) return;
        $guards += $info['guards'];
        ksort($guards);
        $state = $key . '|' . implode(',', array_keys($guards));
        if (isset($visited[$state])) return;
        $visited[$state] = true;
        foreach ($info['calls'] as [$target, $line]) {
            [$class, $member] = explode('::', $target, 2);
            if (isset($preloaded[$class])) {
                // The preloaded class runs its own (old) code; only what it lacks matters.
                $api = $preloaded[$class];
                $known = in_array($member, $api['methods'], true) || in_array($member, $api['constants'], true)
                    || in_array($member, $api['props'] ?? [], true) || strtolower($member) === 'class';
                if (!$known && !isset($guards[strtolower($target)]) && !isset($guards[strtolower($class) . '::*'])) {
                    $findings[] = substr($info['file'], strlen($this->root) + 1) . ":{$line} {$target} via " . implode(' <- ', array_reverse($chain));
                }
                continue;
            }
            if (isset($this->functions[$target]) && count($chain) < 40) {
                $this->walk($target, $guards, [...$chain, $target], $preloaded, $visited, $findings);
            }
        }
    }

    /**
     * Functions of one file (methods as "Class::name", a migration's methods under $forcedClass) with their static calls
     * and the members they check with method_exists() / defined() first.
     * @return array<string, array{class: ?string, calls: list<array{0: string, 1: int}>, guards: array<string, true>, file: string}>
     */
    public static function functionsOf(string $file, ?string $forcedClass = null): array
    {
        $tokens = array_values(array_filter(token_get_all((string)file_get_contents($file)),
            static fn($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $out = [];
        $class = $forcedClass;
        $depth = 0;
        $classDepth = null;
        $function = null;
        $functionDepth = null;
        $expectClass = false;
        $expectFunction = false;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                if ($token === '{') {
                    $depth++;
                    $expectClass = false;
                    if ($class !== null && $classDepth === null) $classDepth = $depth;
                    if ($function !== null && $functionDepth === null) $functionDepth = $depth;
                } elseif ($token === '}') {
                    if ($function !== null && $depth === $functionDepth) [$function, $functionDepth] = [null, null];
                    if ($classDepth !== null && $depth === $classDepth) {
                        $classDepth = null;
                        if ($forcedClass === null) $class = null;
                    }
                    $depth--;
                } elseif ($token === ';' && $function !== null && $functionDepth === null) {
                    $function = null; // abstract or interface method
                }
                continue;
            }
            [$id, $text, $line] = $token;
            if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
                continue;
            }
            if (($id === T_CLASS || $id === T_TRAIT || $id === T_INTERFACE || $id === T_ENUM) && $forcedClass === null
                && !(is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_COLON)) {
                $expectClass = true;
                continue;
            }
            if ($id === T_STRING && $expectClass) {
                $class = $text;
                $expectClass = false;
                continue;
            }
            if ($id === T_FUNCTION) {
                $expectFunction = true;
                continue;
            }
            if ($expectFunction) {
                $expectFunction = false;
                if ($id === T_STRING && $class !== null && $function === null) {
                    $function = $class . '::' . $text;
                    $out[$function] = ['class' => $class, 'calls' => [], 'guards' => [], 'file' => $file];
                    continue;
                }
            }
            if ($function === null || $class === null) continue;
            $next = $tokens[$i + 1] ?? null;
            if (($id === T_STRING || $id === T_STATIC) && is_array($next) && $next[0] === T_DOUBLE_COLON) {
                $member = $tokens[$i + 2] ?? null;
                if (is_array($member) && in_array($member[0], [T_STRING, T_CLASS], true)) {
                    $target = in_array(strtolower($text), ['self', 'static'], true) ? $class : $text;
                    if (strtolower($text) !== 'parent') $out[$function]['calls'][] = [$target . '::' . $member[1], $member[2]];
                }
                continue;
            }
            if ($id === T_VARIABLE && $text === '$this' && is_array($next) && $next[0] === T_OBJECT_OPERATOR
                && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_STRING && ($tokens[$i + 3] ?? null) === '(') {
                $out[$function]['calls'][] = [$class . '::' . $tokens[$i + 2][1], $tokens[$i + 2][2]];
                continue;
            }
            if ($id === T_STRING && in_array(strtolower($text), ['method_exists', 'defined', 'property_exists'], true) && ($next ?? null) === '(') {
                $first = $tokens[$i + 2] ?? null;
                $second = $tokens[$i + 4] ?? null;
                if (is_array($first) && $first[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $a = trim($first[1], '\'"');
                    if (strtolower($text) === 'defined') {
                        $out[$function]['guards'][strtolower($a)] = true;
                    } elseif (($tokens[$i + 3] ?? null) === ',') {
                        // method_exists('Db', $method) in a loop over the names: every member of the class counts as checked.
                        $member = is_array($second) && $second[0] === T_CONSTANT_ENCAPSED_STRING ? trim($second[1], '\'"') : '*';
                        $out[$function]['guards'][strtolower($a . '::' . $member)] = true;
                    }
                }
            }
        }
        return $out;
    }
}
