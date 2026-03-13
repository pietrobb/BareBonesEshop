<?php
/**
 * BareBones Shop — Template Engine
 * {{}} syntax: variables, partials, blocks, #if/#each, raw output {{{}}}.
 * Block lookup: stores/{store}/ → themes/{theme}/ → base
 */
class Template {
    private static string $blocksDir = __DIR__ . '/../blocks';
    private static array $cache = [];
    private static ?string $store = null;
    private static ?string $theme = null;

    public static function setStore(?string $store): void { self::$store = $store; }
    public static function setTheme(?string $theme): void { self::$theme = $theme; }
    public static function setBlocksDir(string $dir): void { self::$blocksDir = $dir; }

    /**
     * Render a full page from layouts.json definition
     */
    public static function page(string $pageName, array $data = []): string {
        $layouts = self::loadLayouts();
        $page = $layouts['pages'][$pageName] ?? null;
        if (!$page) return '<!-- unknown page: ' . esc($pageName) . ' -->';

        $layout = $layouts['layouts'][$page['layout']] ?? $layouts['layouts']['one-column'] ?? null;
        $slots = [];

        foreach ($page['sections'] as $section) {
            $slot = $section['slot'] ?? 'full';
            $html = self::renderSection($section, $data);
            $slots[$slot] = ($slots[$slot] ?? '') . $html;
        }

        return self::render('layout/' . $page['layout'], array_merge($data, ['slots' => $slots]));
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

        // 3. Loops FIRST (before #if, so nested #if inside #each get correct context)
        $tpl = preg_replace_callback(
            '/\{\{#each\s+(\w[\w.]*)\}\}(.*?)\{\{\/each\}\}/s',
            function($m) use ($data) {
                $items = self::resolve($m[1], $data);
                if (!is_array($items)) return '';
                $out = '';
                $values = array_values($items);
                $count = count($values);
                foreach ($values as $i => $item) {
                    $ctx = is_array($item) ? $item : ['this' => $item];
                    $ctx['@index'] = $i;
                    $ctx['@first'] = ($i === 0);
                    $ctx['@last'] = ($i === $count - 1);
                    $ctx['..'] = $data; // parent context
                    $out .= self::process($m[2], $ctx);
                }
                return $out;
            }, $tpl
        );

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
            return esc((string)$val);
        }, $tpl);

        return $tpl;
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

            // 2. Theme override
            if (!$file && self::$theme) {
                $f = self::$blocksDir . '/themes/' . self::$theme . '/' . $name . '.html';
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

    private static function loadLayouts(): array {
        static $layouts = null;
        if (!$layouts) {
            $file = dirname(self::$blocksDir) . '/layouts.json';
            $layouts = file_exists($file) ? json_decode(file_get_contents($file), true) : ['layouts' => [], 'pages' => []];
        }
        return $layouts;
    }

    private static function renderSection(array $section, array $data): string {
        $block = $section['block'];
        $sectionData = $data;

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

    /** Clear template cache (for testing) */
    public static function clearCache(): void {
        self::$cache = [];
    }
}
