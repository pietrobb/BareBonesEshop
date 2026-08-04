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

        $layout = $layouts['layouts'][$page['layout']] ?? $layouts['layouts']['one-column'] ?? null;
        $slots = [];

        $sections = !empty($data['page']['sections']) && is_array($data['page']['sections'])
            ? $data['page']['sections']
            : (array)($page['sections'] ?? []);
        foreach ($sections as $section) {
            $slot = $section['slot'] ?? 'full';
            $html = self::renderSection($section, $data);
            $slots[$slot] = ($slots[$slot] ?? '') . $html;
        }

        return self::render('layout/' . $page['layout'], array_merge($data, ['slots' => $slots]));
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
        $store = StoreContext::current();
        $defaults = [
            'title' => $title,
            'content' => $content,
            'locale' => I18n::locale(),
            't' => I18n::all(),
            'shop_name' => (string)($store->config()['name'] ?? 'BareBones Shop'),
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
     * Core processing — {{}} syntax
     */
    public static function process(string $tpl, array $data): string {
        // 1. Partials: {{> block-name}}
        $tpl = preg_replace_callback('/\{\{>\s*([a-z0-9\/_-]+)\s*\}\}/', function($m) use ($data) {
            return self::render($m[1], $data);
        }, $tpl);

        // 2. Blocks: {{#block "name"}}default{{/block}}
        $tpl = preg_replace_callback(
            '/\{\{#block\s+"([^"]+)"\}\}(.*?)\{\{\/block\}\}/s',
            function($m) use ($data) {
                $name = $m[1];
                return $data['slots'][$name] ?? self::process($m[2], $data);
            }, $tpl
        );

        // 3. Loops FIRST (paired recursively so nested collections keep parent context)
        $tpl = self::processEach($tpl, $data);

        // 4. Conditions: {{#if expr}}...{{else}}...{{/if}}
        $tpl = preg_replace_callback(
            '/\{\{#if\s+(.+?)\}\}(.*?)(?:\{\{else\}\}(.*?))?\{\{\/if\}\}/s',
            function($m) use ($data) {
                $condition = self::evaluate($m[1], $data);
                return self::process($condition ? $m[2] : ($m[3] ?? ''), $data);
            }, $tpl
        );

        // 5. Raw output: {{{variable}}} (no escaping)
        $tpl = preg_replace_callback('/\{\{\{([@\w][\w.]*)\}\}\}/', function($m) use ($data) {
            return (string)(self::resolve($m[1], $data) ?? '');
        }, $tpl);

        // 6. Escaped output: {{variable}}
        $tpl = preg_replace_callback('/\{\{([@\w][\w.@]*)\}\}/', function($m) use ($data) {
            $val = self::resolve($m[1], $data);
            if ($val === null || $val === false) return '';
            return str_replace(['{', '}'], ['&#123;', '&#125;'], esc((string)$val));
        }, $tpl);

        return $tpl;
    }

    private static function processEach(string $tpl, array $data): string {
        if (!preg_match('/\{\{#each\s+([@\w][\w.]*)\}\}/', $tpl, $open, PREG_OFFSET_CAPTURE)) return $tpl;
        $openText = $open[0][0];
        $openOffset = $open[0][1];
        $bodyOffset = $openOffset + strlen($openText);
        preg_match_all('/\{\{#each\s+[@\w][\w.]*\}\}|\{\{\/each\}\}/', $tpl, $tokens, PREG_OFFSET_CAPTURE, $bodyOffset);
        $depth = 1;
        $closeOffset = null;
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_starts_with($token, '{{#each')) $depth++;
            else $depth--;
            if ($depth === 0) { $closeOffset = $offset; break; }
        }
        if ($closeOffset === null) return $tpl;

        $body = substr($tpl, $bodyOffset, $closeOffset - $bodyOffset);
        $items = self::resolve($open[1][0], $data);
        $output = '';
        if (is_array($items)) {
            $values = array_values($items);
            $count = count($values);
            foreach ($values as $index => $item) {
                $context = is_array($item) ? $item : ['this' => $item];
                $context['@index'] = $index;
                $context['@first'] = $index === 0;
                $context['@last'] = $index === $count - 1;
                $context['..'] = $data;
                $output .= self::process($body, $context);
            }
        }
        $after = $closeOffset + strlen('{{/each}}');
        return substr($tpl, 0, $openOffset) . $output . self::processEach(substr($tpl, $after), $data);
    }

    /**
     * Resolve dot-notation: "product.price" → $data['product']['price']
     */
    private static function resolve(string $path, array $data) {
        // Special: ".." for parent context
        if (str_starts_with($path, '..')) {
            $rest = substr($path, 2);
            if (str_starts_with($rest, '.')) $rest = substr($rest, 1);
            if (isset($data['..'])) {
                return $rest ? self::resolve($rest, $data['..']) : $data['..'];
            }
            return null;
        }

        $keys = explode('.', $path);
        $val = $data;
        foreach ($keys as $key) {
            if ($key === 'length' && is_array($val)) {
                return count($val);
            }
            if (is_array($val) && array_key_exists($key, $val)) {
                $val = $val[$key];
            } else {
                return null;
            }
        }
        return $val;
    }

    /**
     * Evaluate simple conditions: "stock > 0", "compare_price", "images.length > 1"
     */
    private static function evaluate(string $expr, array $data): bool {
        $expr = trim($expr);

        // Comparison: "field > value", "field == value"
        if (preg_match('/^([@\w][\w.]*)\s*(==|!=|>|<|>=|<=)\s*(.+)$/', $expr, $m)) {
            $left = self::resolve($m[1], $data);
            $op = $m[2];
            $right = trim($m[3], '"\'');
            if (is_numeric($right)) $right = (float)$right;
            return match($op) {
                '==' => $left == $right,
                '!=' => $left != $right,
                '>'  => $left > $right,
                '<'  => $left < $right,
                '>=' => $left >= $right,
                '<=' => $left <= $right,
            };
        }

        // Truthy check: "compare_price", "variants", "@first"
        $val = self::resolve($expr, $data);
        return !empty($val);
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
        $block = (string)($section['block'] ?? '');
        if (!empty($section['type'])) {
            $type = (string)$section['type'];
            $block = self::sectionBlocks()[$type] ?? '';
        }
        if ($block === '' || !preg_match('#^[a-z0-9][a-z0-9/_-]{0,159}$#', $block)) return '';
        $sectionData = array_replace($data, $section, ['section' => $section]);

        // Data source
        if (!empty($section['data'])) {
            $sectionData['section_data'] = self::resolve($section['data'], $data);
        }
        // Title per locale
        if (!empty($section['title'])) {
            $locale = I18n::locale();
            $sectionData['section_title'] = $section['title'][$locale] ?? reset($section['title']);
        }
        // Tabs
        if (!empty($section['tabs'])) {
            $sectionData['tabs'] = $section['tabs'];
        }
        // Nested children
        if (!empty($section['children'])) {
            $childSlots = [];
            foreach ($section['children'] as $child) {
                $slot = $child['slot'] ?? 'full';
                $childSlots[$slot] = ($childSlots[$slot] ?? '') . self::renderSection($child, $data);
            }
            $sectionData['slots'] = $childSlots;
        }

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
        return $value + ['layouts' => [], 'pages' => []];
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
