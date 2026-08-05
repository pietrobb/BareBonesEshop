<?php
/**
 * BareBones Shop — Template Engine
 * {{}} syntax: variables, partials, blocks, #if/#each, raw output {{{}}}.
 * Block lookup: stores/{store}/ → themes/{theme}/ → base
 */
class Template {
    private static string $blocksDir = __DIR__ . '/../blocks';
    private static string $themesDir = __DIR__ . '/../themes';
    private static array $cache = [];
    private static array $manifestCache = [];
    private static array $layoutsCache = [];
    private static ?string $store = null;
    private static ?string $theme = null;

    public static function setStore(?string $store): void {
        self::$store = self::safePackageName($store);
        self::clearCache();
    }
    public static function setTheme(?string $theme): void {
        self::$theme = self::safePackageName($theme);
        self::clearCache();
    }
    public static function setBlocksDir(string $dir): void { self::$blocksDir = $dir; self::clearCache(); }
    public static function setThemesDir(string $dir): void { self::$themesDir = $dir; self::$manifestCache = []; self::clearCache(); }

    public static function activate(StoreContext $store): void {
        self::setStore($store->id());
        self::setTheme((string)($store->config()['theme'] ?? 'default'));
    }

    /** Normalized storefront identity from store config with theme defaults. */
    public static function branding(): array {
        $config = StoreContext::current()->config();
        $theme = (array)(self::manifest()['branding'] ?? []);
        $store = (array)($config['branding'] ?? []);
        return array_replace([
            'name' => (string)($config['name'] ?? 'Shop'),
            'title_pattern' => '%page% — %store%',
            'logo_url' => '',
            'footer_text' => '',
            'contact' => (array)($config['legal'] ?? []),
        ], $theme, $store);
    }

    public static function pageTitle(string $pageTitle): string {
        $brand = self::branding();
        return strtr((string)$brand['title_pattern'], ['%page%' => $pageTitle, '%store%' => (string)$brand['name']]);
    }

    /** Validated theme package manifest (theme.json). */
    public static function manifest(?string $theme = null): array {
        $theme = self::safePackageName($theme ?? self::$theme ?? 'default') ?? 'default';
        if (isset(self::$manifestCache[$theme])) return self::$manifestCache[$theme];
        $path = self::$themesDir . '/' . $theme . '/theme.json';
        if (!is_file($path)) throw new RuntimeException("Theme manifest not found: {$theme}");
        $manifest = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['name'] ?? '') === '' || ($manifest['version'] ?? '') === '') {
            throw new RuntimeException("Invalid theme manifest: {$theme}");
        }
        foreach ((array)($manifest['assets'] ?? []) as $kind => $assets) {
            if (!in_array($kind, ['css', 'js'], true) || !is_array($assets)) throw new RuntimeException('Invalid theme asset group');
            foreach ($assets as $asset) self::safeAssetPath((string)$asset);
        }
        if (isset($manifest['parent'])) self::safePackageName((string)$manifest['parent']);
        foreach ((array)($manifest['sections'] ?? []) as $type => $block) {
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', (string)$type)
                || !preg_match('#^[a-z0-9][a-z0-9/_-]{0,159}$#', (string)$block)) {
                throw new RuntimeException('Invalid theme section mapping');
            }
        }
        return self::$manifestCache[$theme] = $manifest;
    }

    /** Installation base path, empty for a domain-root deployment. */
    public static function baseUrl(): string {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $directory = $script === '' ? '' : str_replace('\\', '/', dirname($script));
        if ($directory === '' || $directory === '.' || $directory === '/') return '';
        return '/' . trim($directory, '/');
    }

    /** Build a local URL that also works when the shop is installed in a subdirectory. */
    public static function url(string $path = ''): string {
        if ($path !== '' && (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || str_starts_with($path, '//'))) {
            return $path;
        }
        $suffix = '/' . ltrim($path, '/');
        return self::baseUrl() . ($suffix === '/' ? '/' : $suffix);
    }

    /** Store/locale-aware local URL, separate from public asset URLs. */
    public static function storeUrl(string $path = ''): string {
        if ($path !== '' && (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path) || str_starts_with($path, '//'))) {
            return $path;
        }
        $prefix = rtrim((string)(StoreContext::current()->config()['url_prefix'] ?? ''), '/');
        if ($prefix !== '' && (!str_starts_with($prefix, '/') || str_contains($prefix, '..'))) {
            throw new RuntimeException('Invalid store URL prefix');
        }
        $suffix = '/' . ltrim($path, '/');
        return self::baseUrl() . $prefix . ($suffix === '/' ? '/' : $suffix);
    }

    /** Public versioned asset URLs declared by the active theme manifest. */
    public static function assets(): array {
        $result = ['css' => [], 'js' => []];
        foreach (array_reverse(self::themeChain()) as $theme) {
            $manifest = self::manifest($theme);
            $version = rawurlencode((string)$manifest['version']);
            foreach ($result as $kind => $_) {
                foreach ((array)($manifest['assets'][$kind] ?? []) as $asset) {
                    $asset = self::safeAssetPath((string)$asset);
                    $url = self::url('/themes/' . rawurlencode($theme) . '/assets/' . $asset) . '?v=' . $version;
                    if (!in_array($url, $result[$kind], true)) $result[$kind][] = $url;
                }
            }
        }
        return $result;
    }

    /**
     * Render a full page from layouts.json definition
     */
    public static function page(string $pageName, array $data = []): string {
        $layouts = self::loadLayouts();
        $page = $layouts['pages'][$pageName] ?? null;
        if (!$page) return '<!-- unknown page: ' . esc($pageName) . ' -->';

        $layoutName = (string)($data['page']['layout'] ?? $page['layout'] ?? 'storefront');
        if (!isset($layouts['layouts'][$layoutName])) $layoutName = isset($layouts['layouts']['storefront']) ? 'storefront' : 'one-column';
        $slots = [];

        $sections = !empty($data['page']['sections']) && is_array($data['page']['sections'])
            ? $data['page']['sections']
            : (array)($page['sections'] ?? []);
        foreach ($sections as $section) {
            $slot = $section['slot'] ?? 'full';
            $html = self::renderSection($section, $data);
            $slots[$slot] = ($slots[$slot] ?? '') . $html;
        }

        return self::render('layout/' . $layoutName, array_merge($data, ['slots' => $slots]));
    }

    /** Remove the deployment base path before joining a local URL to configured app_url. */
    public static function seoPath(string $url): string {
        $base = self::baseUrl();
        if ($base !== '' && ($url === $base || str_starts_with($url, $base . '/'))) {
            $url = substr($url, strlen($base));
        }
        return $url === '' ? '/' : $url;
    }

    /** Serialize canonical page SEO data without putting markup in the view model. */
    public static function viewModelSeoHead(array $model, string $fallbackTitle): string {
        $seo = (array)($model['page']['seo'] ?? []);
        $head = Seo::head(
            (string)($seo['title'] ?? $model['page']['title'] ?? $fallbackTitle),
            (string)($seo['description'] ?? ''),
            self::seoPath((string)($seo['canonical_url'] ?? self::storeUrl('/'))),
            true
        );
        if (!empty($seo['image_url'])) {
            $head .= "\n<meta property=\"og:image\" content=\"" . esc((string)$seo['image_url']) . '">';
        }
        foreach ((array)($seo['json_ld'] ?? []) as $payload) {
            if (is_array($payload)) $head .= "\n" . JsonLd::render($payload, Security::cspNonce());
        }
        return $head;
    }

    /** Shared accessible storefront document shell for SSR entry points. */
    public static function document(string $title, string $content, array $data = []): string {
        $brand = self::branding();
        $defaults = [
            'title' => $title,
            'content' => $content,
            'locale' => I18n::locale(),
            't' => I18n::all(),
            'shop_name' => (string)$brand['name'],
            'brand' => $brand,
            'base_url' => self::baseUrl(),
            'navigation' => ContentService::menu('primary'),
            'cart' => ['count' => 0],
            'assets' => self::assets(),
            'seo_head' => '',
            'body_html' => '',
            'body_end' => '',
            'base_css' => '<link rel="stylesheet" href="' . esc(self::url('/shop.css')) . '">',
            'shop_js' => '<script src="' . esc(self::url('/shop.js')) . '" defer></script>',
            'marketing_hooks' => MarketingConsent::hooks($_COOKIE, Security::cspNonce()),
        ];
        return self::render('global/document', array_replace($defaults, $data));
    }

    private static int $depth = 0;
    private const MAX_DEPTH = 20;

    /**
     * Render a block (template) with data
     */
    public static function render(string $block, array $data = []): string {
        if (++self::$depth > self::MAX_DEPTH) {
            self::$depth--;
            return "<!-- max template depth reached: {$block} -->";
        }
        $tpl = self::loadBlock($block);
        $result = self::process($tpl, $data);
        self::$depth--;
        return $result;
    }

    /**
     * Core processing — tokenized {{}} syntax with a real context stack.
     */
    public static function process(string $tpl, array $data): string {
        $offset = 0;
        [$nodes] = self::parseNodes($tpl, $offset);
        return self::renderNodes($nodes, [$data]);
    }

    private static function parseNodes(string $tpl, int &$offset, array $stops = []): array {
        $nodes = [];
        $length = strlen($tpl);
        while ($offset < $length) {
            $open = strpos($tpl, '{{', $offset);
            if ($open === false) {
                $nodes[] = ['type' => 'text', 'value' => substr($tpl, $offset)];
                $offset = $length;
                break;
            }
            if ($open > $offset) $nodes[] = ['type' => 'text', 'value' => substr($tpl, $offset, $open - $offset)];
            $raw = substr($tpl, $open, 3) === '{{{';
            $closeToken = $raw ? '}}}' : '}}';
            $close = strpos($tpl, $closeToken, $open + ($raw ? 3 : 2));
            if ($close === false) {
                $offset = $length;
                break;
            }
            $inner = trim(substr($tpl, $open + ($raw ? 3 : 2), $close - $open - ($raw ? 3 : 2)));
            $offset = $close + strlen($closeToken);
            if (!$raw && in_array($inner, $stops, true)) return [$nodes, $inner];

            if ($raw) {
                $nodes[] = ['type' => 'raw', 'path' => $inner];
                continue;
            }
            if (str_starts_with($inner, '#')) {
                [$command, $argument] = array_pad(preg_split('/\s+/', substr($inner, 1), 2), 2, '');
                if (!in_array($command, ['if', 'unless', 'each', 'block'], true)) continue;
                [$body, $stop] = self::parseNodes($tpl, $offset, ['else', '/' . $command]);
                $alternate = [];
                if ($stop === 'else') [$alternate] = self::parseNodes($tpl, $offset, ['/' . $command]);
                $nodes[] = [
                    'type' => $command,
                    'argument' => trim($argument, $command === 'block' ? " \t\n\r\0\x0B\"'" : " \t\n\r\0\x0B"),
                    'body' => $body,
                    'alternate' => $alternate,
                ];
                continue;
            }
            if ($inner === 'else' || str_starts_with($inner, '/')) continue;
            if (str_starts_with($inner, '>')) {
                $block = trim(substr($inner, 1));
                if (preg_match('#^[a-z0-9][a-z0-9/_-]{0,159}$#', $block)) $nodes[] = ['type' => 'partial', 'block' => $block];
                continue;
            }
            $nodes[] = ['type' => 'value', 'path' => $inner];
        }
        return [$nodes, null];
    }

    private static function renderNodes(array $nodes, array $contexts): string {
        $output = '';
        foreach ($nodes as $node) {
            switch ($node['type']) {
                case 'text':
                    $output .= $node['value'];
                    break;
                case 'value':
                    $value = self::resolve($node['path'], $contexts);
                    if (is_scalar($value) && $value !== false) {
                        $output .= str_replace(['{', '}'], ['&#123;', '&#125;'], esc((string)$value));
                    }
                    break;
                case 'raw':
                    $value = self::resolve($node['path'], $contexts);
                    if (is_scalar($value) && $value !== false) $output .= (string)$value;
                    break;
                case 'partial':
                    $output .= self::renderWithContexts($node['block'], $contexts);
                    break;
                case 'block':
                    $slot = self::resolve('slots.' . $node['argument'], $contexts);
                    $output .= is_string($slot) ? $slot : self::renderNodes($node['body'], $contexts);
                    break;
                case 'if':
                case 'unless':
                    $truthy = self::evaluate($node['argument'], $contexts);
                    if ($node['type'] === 'unless') $truthy = !$truthy;
                    $output .= self::renderNodes($truthy ? $node['body'] : $node['alternate'], $contexts);
                    break;
                case 'each':
                    $items = self::resolve($node['argument'], $contexts);
                    if (!is_array($items) || $items === []) {
                        $output .= self::renderNodes($node['alternate'], $contexts);
                        break;
                    }
                    $keys = array_keys($items);
                    $count = count($items);
                    foreach (array_values($items) as $index => $item) {
                        $context = is_array($item) ? $item : ['this' => $item];
                        $context['@index'] = $index;
                        $context['@key'] = $keys[$index];
                        $context['@first'] = $index === 0;
                        $context['@last'] = $index === $count - 1;
                        $output .= self::renderNodes($node['body'], [...$contexts, $context]);
                    }
                    break;
            }
        }
        return $output;
    }

    private static function renderWithContexts(string $block, array $contexts): string {
        if (++self::$depth > self::MAX_DEPTH) {
            self::$depth--;
            return '';
        }
        $offset = 0;
        [$nodes] = self::parseNodes(self::loadBlock($block), $offset);
        $result = self::renderNodes($nodes, $contexts);
        self::$depth--;
        return $result;
    }

    /** Resolve dot notation and explicit ../ parent traversal. */
    private static function resolve(string $path, array $contexts): mixed {
        $path = trim($path);
        $level = count($contexts) - 1;
        while (str_starts_with($path, '../')) {
            $level--;
            $path = substr($path, 3);
        }
        if ($path === '..') {
            $level--;
            $path = '';
        }
        if ($level < 0) return null;
        $value = $contexts[$level];
        if ($path === '' || $path === 'this' || $path === '.') return $path === 'this' && array_key_exists('this', $value) ? $value['this'] : $value;
        $keys = explode('.', $path);
        foreach ($keys as $index => $key) {
            if ($key === 'length' && is_array($value)) return count($value);
            if (!is_array($value)) return null;
            if (array_key_exists($key, $value)) {
                $value = $value[$key];
                continue;
            }
            $compoundKey = implode('.', array_slice($keys, $index));
            return array_key_exists($compoundKey, $value) ? $value[$compoundKey] : null;
        }
        return $value;
    }

    /** Evaluate safe comparisons and truthiness without executing expressions. */
    private static function evaluate(string $expr, array $contexts): bool {
        $expr = trim($expr);
        if (preg_match('/^((?:\.\.\/)*[@\w][\w.@-]*)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', $expr, $match)) {
            $left = self::resolve($match[1], $contexts);
            $rightToken = trim($match[3]);
            $right = match (strtolower($rightToken)) {
                'true' => true,
                'false' => false,
                'null' => null,
                default => is_numeric($rightToken) ? (float)$rightToken : trim($rightToken, "\"'"),
            };
            return match($match[2]) {
                '==' => $left == $right,
                '!=' => $left != $right,
                '>=' => $left >= $right,
                '<=' => $left <= $right,
                '>' => $left > $right,
                '<' => $left < $right,
            };
        }
        return !empty(self::resolve($expr, $contexts));
    }

    /**
     * Load block template with fallback chain: store → theme → base
     */
    private static function loadBlock(string $name): string {
        if (!isset(self::$cache[$name])) {
            $file = null;

            // 1. Store-specific override
            if (self::$store) {
                $f = self::$blocksDir . '/stores/' . self::$store . '/' . $name . '.html';
                if (file_exists($f)) $file = $f;
            }

            // 2. Active theme, its parents, then default theme
            foreach (self::themeChain() as $theme) {
                if ($file) break;
                $f = self::$themesDir . '/' . $theme . '/blocks/' . $name . '.html';
                if (file_exists($f)) $file = $f;
            }
            foreach (self::themeChain() as $theme) {
                if ($file) break;
                $f = self::$blocksDir . '/themes/' . $theme . '/' . $name . '.html';
                if (file_exists($f)) $file = $f;
            }

            // 3. Base
            if (!$file) {
                $file = self::$blocksDir . '/' . $name . '.html';
            }

            self::$cache[$name] = file_exists($file) ? file_get_contents($file) : "<!-- missing: {$name} -->";
        }
        return self::$cache[$name];
    }

    public static function loadLayouts(): array {
        $cacheKey = (self::$store ?? '') . ':' . (self::$theme ?? 'default');
        if (isset(self::$layoutsCache[$cacheKey])) return self::$layoutsCache[$cacheKey];

        $layouts = self::readLayoutFile(dirname(self::$blocksDir) . '/layouts.json');
        foreach (array_reverse(self::themeChain()) as $theme) {
            $layouts = self::mergeConfig($layouts, self::readLayoutFile(self::$themesDir . '/' . $theme . '/layouts.json'));
        }
        if (self::$store) {
            $layouts = self::mergeConfig($layouts, self::readLayoutFile(self::$blocksDir . '/stores/' . self::$store . '/layouts.json'));
        }
        return self::$layoutsCache[$cacheKey] = $layouts;
    }

    private static function renderSection(array $section, array $data): string {
        $alias = (string)($section['block'] ?? $section['type'] ?? '');
        $block = self::sectionBlocks()[$alias] ?? '';
        if ($block === '') return '';
        $sectionData = array_replace($data, $section, ['section' => $section]);
        return self::render($block, $sectionData);
    }

    private static function themeChain(): array {
        $chain = [];
        $theme = self::$theme ?? 'default';
        while ($theme !== '') {
            if (in_array($theme, $chain, true)) throw new RuntimeException('Theme inheritance cycle');
            $chain[] = $theme;
            if (count($chain) > 10) throw new RuntimeException('Theme inheritance is too deep');
            $parent = (string)(self::manifest($theme)['parent'] ?? '');
            $theme = $parent === '' ? '' : (self::safePackageName($parent) ?? '');
        }
        if (!in_array('default', $chain, true) && is_file(self::$themesDir . '/default/theme.json')) $chain[] = 'default';
        return $chain;
    }

    private static function sectionBlocks(): array {
        $blocks = [];
        foreach (array_reverse(self::themeChain()) as $theme) {
            $blocks = array_replace($blocks, (array)(self::manifest($theme)['sections'] ?? []));
        }
        return $blocks;
    }

    private static function readLayoutFile(string $file): array {
        if (!is_file($file)) return [];
        $value = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException("Invalid layouts file: {$file}");
        return $value;
    }

    private static function mergeConfig(array $base, array $override): array {
        foreach ($override as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null) && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::mergeConfig($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private static function safePackageName(?string $name): ?string {
        if ($name === null || $name === '') return null;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name)) throw new InvalidArgumentException('Invalid theme/store package name');
        return $name;
    }

    private static function safeAssetPath(string $path): string {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)
            || preg_match('/[\x00-\x1F\x7F]/', $path) || !preg_match('/^[a-zA-Z0-9._\/-]+$/', $path)) {
            throw new RuntimeException('Invalid theme asset path');
        }
        return $path;
    }

    /** Clear template cache (for testing or package changes). */
    public static function clearCache(): void {
        self::$cache = [];
        self::$layoutsCache = [];
    }
}
