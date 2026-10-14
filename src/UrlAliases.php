<?php
/**
 * Shop-owned public URL aliases (config 'url_aliases'), so a shop can keep its own
 * URL scheme without editing core routing:
 *
 *   'url_aliases' => [
 *       '/kniha/{slug}' => '/product/{slug}',   // pattern alias
 *       '/knihy'        => '/category/knihy',    // exact path alias
 *       '/kosik'        => '/?page=cart',        // storefront page alias
 *   ]
 *
 * outbound() turns internal links into public aliases, inbound() resolves a public
 * alias to the internal route, and dispatch() serves it from the 404 fallback.
 */
final class UrlAliases {
    private static ?array $rules = null;
    private static bool $dispatched = false;

    /** Test hook / runtime override. Null reloads from config.php. */
    public static function setRules(?array $aliases): void {
        self::$rules = $aliases === null ? null : self::compile($aliases);
    }

    private static function rules(): array {
        if (self::$rules === null) {
            $cfg = is_file(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : [];
            self::$rules = self::compile((array)($cfg['url_aliases'] ?? []));
        }
        return self::$rules;
    }

    private static function compile(array $aliases): array {
        $rules = [];
        foreach ($aliases as $public => $internal) {
            $public = '/' . trim((string)$public, '/');
            $internal = (string)$internal;
            if (!preg_match('#^/[A-Za-z0-9_\-./{}]*$#', $public) || !str_starts_with($internal, '/')) {
                throw new RuntimeException('Invalid url_aliases entry: ' . $public);
            }
            $iPath = (string)parse_url($internal, PHP_URL_PATH);
            parse_str((string)parse_url($internal, PHP_URL_QUERY), $iQuery);
            $rules[] = ['public' => $public, 'path' => $iPath === '' ? '/' : $iPath, 'query' => $iQuery];
        }
        return $rules;
    }

    private static function regex(string $pattern): string {
        $quoted = preg_quote($pattern, '#');
        return '#^' . preg_replace('#\\\\\{([a-z_]+)\\\\\}#', '(?P<$1>[A-Za-z0-9_-]+)', $quoted) . '/?$#';
    }

    private static function fill(string $pattern, array $vars): string {
        return preg_replace_callback('#\{([a-z_]+)\}#', static fn(array $m): string => rawurlencode((string)($vars[$m[1]] ?? '')), $pattern);
    }

    /** Internal local path (optionally with query/fragment) → public alias; unchanged when no rule matches. */
    public static function outbound(string $path): string {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || self::rules() === []) return $path;
        $fragment = '';
        if (($hash = strpos($path, '#')) !== false) { $fragment = substr($path, $hash); $path = substr($path, 0, $hash); }
        $p = (string)parse_url($path, PHP_URL_PATH);
        parse_str((string)parse_url($path, PHP_URL_QUERY), $query);
        // ?page=product|category&slug=x are the same routes as /product/x and /category/x.
        if ($p === '/' && in_array($query['page'] ?? '', ['product', 'category'], true) && is_string($query['slug'] ?? null)) {
            $p = '/' . $query['page'] . '/' . $query['slug'];
            unset($query['page'], $query['slug']);
        }
        foreach (self::rules() as $rule) {
            if ($rule['query'] !== []) {
                if ($p !== '/' || array_intersect_assoc($rule['query'], $query) != $rule['query']) continue;
                $rest = array_diff_key($query, $rule['query']);
                return $rule['public'] . ($rest ? '?' . http_build_query($rest) : '') . $fragment;
            }
            if (preg_match(self::regex($rule['path']), $p, $m)) {
                return self::fill($rule['public'], $m) . ($query ? '?' . http_build_query($query) : '') . $fragment;
            }
        }
        return (string)parse_url($path, PHP_URL_PATH) === $p ? $path . $fragment
            : $p . ($query ? '?' . http_build_query($query) : '') . $fragment;
    }

    /** Public alias path → ['path' => internal path, 'query' => array], or null. */
    public static function inbound(string $path): ?array {
        $path = '/' . trim($path, '/');
        foreach (self::rules() as $rule) {
            if (!preg_match(self::regex($rule['public']), $path, $m)) continue;
            return ['path' => self::fill($rule['path'], $m), 'query' => $rule['query']];
        }
        return null;
    }

    /**
     * Path under a configured store url_prefix (/cs/product/x) → its store-relative route. The entry
     * script resolves the store itself from REQUEST_URI (longest prefix, as StoreContext does).
     */
    public static function storeRoute(string $path): ?array {
        $path = '/' . trim($path, '/');
        $best = '';
        foreach ((array)(Bootstrap::stores()['stores'] ?? []) as $store) {
            $prefix = rtrim((string)($store['url_prefix'] ?? ''), '/');
            if ($prefix === '' || !str_starts_with($prefix, '/') || strlen($prefix) <= strlen($best)) continue;
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) $best = $prefix;
        }
        if ($best === '') return null;
        $rest = substr($path, strlen($best));
        $rest = $rest === '' ? '/' : $rest;
        return self::inbound($rest) ?? ['path' => $rest, 'query' => []];
    }

    /** Entry script and $_GET for an internal route, or null if the route has no entrypoint. */
    public static function entry(array $route): ?array {
        $path = $route['path'];
        $get = $route['query'];
        if ($path === '/') return ['index.php', $get];
        if (preg_match('#^/product/([A-Za-z0-9_-]+)$#', $path, $m)) return ['product.php', $get + ['slug' => $m[1]]];
        if (preg_match('#^/category/([A-Za-z0-9/_-]+)$#', $path, $m)) return ['index.php', $get + ['category' => $m[1]]];
        if (preg_match('#^/page/([A-Za-z0-9_-]+)$#', $path, $m)) return ['page.php', $get + ['slug' => $m[1]]];
        return null;
    }

    /**
     * Prepare $_GET for a public alias and return the entry script to require, or null.
     * The caller must require it at global scope (entrypoints rely on global variables).
     */
    public static function dispatch(string $path): ?string {
        $route = self::inbound($path) ?? self::storeRoute($path);
        $entry = $route ? self::entry($route) : null;
        if (!$entry) return null;
        [$script, $get] = $entry;
        unset($_GET['path']);
        $_GET = $get + $_GET;
        self::$dispatched = true;
        return $script;
    }

    /**
     * 301 from an internal path (/product/x) to its public alias (/kniha/x) so each page
     * has one URL. Only GET/HEAD on path routes; query-routed pages (?page=cart) keep working.
     */
    public static function redirectInternal(): void {
        if (self::$dispatched || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return;
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)parse_url($uri, PHP_URL_PATH);
        $base = Template::baseUrl();
        if ($base !== '' && str_starts_with($path, $base . '/')) $path = substr($path, strlen($base));
        if (!preg_match('#^/(?:product|category|page)/#', $path)) return;
        $public = self::outbound($path);
        if ($public === $path) return;
        $query = (string)parse_url($uri, PHP_URL_QUERY);
        header('Location: ' . $base . $public . ($query !== '' ? (str_contains($public, '?') ? '&' : '?') . $query : ''), true, 301);
        exit;
    }
}
