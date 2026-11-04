<?php

/**
 * Whether an e-mail's text can be read against its background: the text and background colour of every element as
 * the mail client cascades them (<style> rules, inline style, <font color>, bgcolor), composited over the parent's
 * background. Text below CssColor::MIN_CONTRAST (white on white, the text colour equal to the background) is hidden.
 * A colour this cannot evaluate (var(), a gradient, an unknown keyword) counts as unreadable: the guard fails closed.
 */
final class EmailLegibility {
    private const UNKNOWN = 'unknown';
    private const LINK = [0.0, 0.0, 238.0, 1.0];

    /** @var list<array{chain: list<array{0: string, 1: string, 2: list<string>, 3: list<string>}>, approx: bool, prop: string, value: string, prio: list<int>}> */
    private array $rules = [];

    public function __construct(string $html) {
        if (!preg_match_all('~<style\b[^>]*>(.*?)(?:</style\s*>|$)~is', $html, $styles)) return;
        $order = 0;
        foreach ($styles[1] as $css) {
            preg_match_all('~([^{}]+)\{([^{}]*)\}~', self::cssText($css), $blocks, PREG_SET_ORDER);
            foreach ($blocks as [, $selectors, $declarations]) {
                $decls = self::declarations($declarations);
                if (!$decls) continue;
                foreach (explode(',', $selectors) as $selector) {
                    // States the reader has to cause do not apply to the message as it is shown.
                    if (preg_match('~:(?:hover|focus|focus-within|focus-visible|active)\b~', $selector)) continue;
                    [$chain, $approx, $specificity] = self::selector(trim($selector));
                    $order++;
                    foreach ($decls as [$prop, $value, $important]) {
                        // A background of a selector not followed exactly could make text look readable where it is not.
                        if ($approx && $prop === 'background') continue;
                        $this->rules[] = ['chain' => $chain, 'approx' => $approx, 'prop' => $prop, 'value' => $value,
                            'prio' => [$important ? 1 : 0, 0, ...$specificity, $order]];
                    }
                }
            }
        }
    }

    /** The <html> and <body> the message is shown in (also when the template has no such tags). @return list<array> */
    public function rootFrames(): array {
        $html = $this->frame('html', '', [], ['name' => '', 'classes' => [], 'ids' => [], 'color' => CssColor::BLACK, 'fill' => null, 'bg' => CssColor::WHITE]);
        $body = $this->frame('body', '', [$html[1]], $html[1]);
        return [$html, $body];
    }

    /**
     * The element's frame and whether its text is unreadable: [bool, frame].
     * @param list<array> $ancestors frames from the root down to the parent
     * @return array{0: bool, 1: array}
     */
    public function frame(string $name, string $attrs, array $ancestors, ?array $parent = null): array {
        $parent ??= end($ancestors);
        $element = ['name' => $name, 'classes' => self::attrList($attrs, 'class'), 'ids' => self::attrList($attrs, 'id')];
        $winning = [];
        foreach ($this->rules as $rule) {
            if (self::matches($rule['chain'], $element, $ancestors, $rule['approx'])) self::offer($winning, $rule['prop'], $rule['value'], $rule['prio']);
        }
        if (preg_match('~\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $attrs, $style)) {
            foreach (self::declarations(self::cssText($style[1] . ($style[2] ?? ''))) as [$prop, $value, $important]) {
                self::offer($winning, $prop, $value, [$important ? 1 : 0, 1, 0, 0, 0, 0]);
            }
        }
        // Presentational attributes lose to any CSS.
        foreach ([[$name === 'font' ? 'color' : '', 'color'], ['bgcolor', 'background'], [$name === 'body' ? 'text' : '', 'color']] as [$attr, $prop]) {
            $value = $attr === '' ? null : self::attr($attrs, $attr);
            if ($value === null) continue;
            $value = strtolower(trim($value));
            self::offer($winning, $prop, preg_match('/^[0-9a-f]{6}$/D', $value) ? '#' . $value : $value, [0, 0, -1, 0, 0, 0]);
        }
        $bg = self::background($winning['background'][0] ?? null, $parent['bg']);
        $color = self::color($winning['color'][0] ?? ($name === 'a' ? null : 'inherit'), $parent['color'], $name === 'a');
        $fill = isset($winning['fill']) ? self::color($winning['fill'][0], $color ?? $parent['color'], false) : $parent['fill'];
        $frame = $element + ['color' => $color ?? $parent['color'], 'fill' => $fill, 'bg' => $bg ?? $parent['bg']];
        $text = $fill ?? $color;
        $unreadable = $bg === null || $text === null || ($winning['fill'] ?? null) !== null && $fill === null
            || CssColor::contrast(CssColor::over($text, $frame['bg']), $frame['bg']) < CssColor::MIN_CONTRAST;
        return [$unreadable, $frame];
    }

    /** Keep the declaration that wins the cascade: !important, then inline, then specificity, then source order. */
    private static function offer(array &$winning, string $prop, string $value, array $prio): void {
        if (!isset($winning[$prop]) || $prio >= $winning[$prop][1]) $winning[$prop] = [$value, $prio];
    }

    /** Opaque background over the parent's; null = cannot tell. */
    private static function background(?string $value, array $parentBg): ?array {
        if ($value === null || in_array($value, ['inherit', 'initial', 'unset', 'none', 'transparent'], true)) return $parentBg;
        $color = $value === self::UNKNOWN ? null : CssColor::parse($value);
        return $color === null ? null : CssColor::over($color, $parentBg);
    }

    /** Text colour (with its alpha); null = cannot tell. An undeclared link colour is the client's link blue. */
    private static function color(?string $value, array $parentColor, bool $link): ?array {
        if ($value === null) return $link ? self::LINK : $parentColor;
        if (in_array($value, ['inherit', 'currentcolor', 'unset'], true)) return $parentColor;
        if ($value === 'initial') return CssColor::BLACK;
        return $value === self::UNKNOWN ? null : CssColor::parse($value);
    }

    /**
     * color, background(-color) and -webkit-text-fill-color of a declaration block: [prop, value, important].
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private static function declarations(string $css): array {
        $found = [];
        foreach (explode(';', $css) as $declaration) {
            if (!preg_match('~^\s*(color|background-color|background|-webkit-text-fill-color)\s*:(.*)$~s', $declaration, $m)) continue;
            $important = str_contains($m[2], '!important');
            $value = trim(str_replace('!important', '', $m[2]));
            $prop = ['color' => 'color', '-webkit-text-fill-color' => 'fill'][$m[1]] ?? 'background';
            if ($m[1] === 'background') $value = self::shorthandColor($value);
            elseif (str_contains($value, 'var(')) $value = self::UNKNOWN;
            $found[] = [$prop, $value, $important];
        }
        return $found;
    }

    /** The colour of a background shorthand ("url(x) no-repeat #000"); without one the background is transparent. */
    private static function shorthandColor(string $value): string {
        if (in_array($value, ['inherit', 'initial', 'unset', 'none', 'transparent'], true)) return $value;
        if (preg_match('~var\(|gradient\(~', $value)) return self::UNKNOWN;
        preg_match_all('~[a-z-]+\([^)]*\)|[^\s()]+~', $value, $tokens);
        $color = 'transparent';
        foreach ($tokens[0] as $token) {
            if (!str_starts_with($token, 'url(') && CssColor::parse($token) !== null) $color = $token;
        }
        return $color;
    }

    /**
     * [chain, approximate, specificity]. Pseudo-classes, attribute selectors and sibling combinators are not followed:
     * such a selector keeps only its subject element (so its text colour applies more widely than the client would).
     * @return array{0: list<array{0: string, 1: string, 2: list<string>, 3: list<string>}>, 1: bool, 2: list<int>}
     */
    private static function selector(string $selector): array {
        $specificity = [substr_count($selector, '#'), substr_count($selector, '.') + substr_count($selector, '[')
            + preg_match_all('~(?<!:):(?!:)~', $selector), preg_match_all('~(?:^|[\s>+\~])[a-z]~', $selector) + substr_count($selector, '::')];
        $approx = preg_match('~[:\[+\~]~', $selector) === 1;
        $clean = trim((string)preg_replace(['~::?[\w-]+(?:\([^)]*\))?~', '~\[[^\]]*\]~'], '', $selector));
        $clean = (string)preg_replace('~\s*>\s*~', '>', $clean);
        $parts = preg_split('~(\s+|>|\+|\~)~', $clean, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        if ($approx) $parts = [end($parts) ?: '*'];
        $chain = [];
        $combinator = ' ';
        foreach ($parts as $part) {
            if (trim($part) === '' || $part === '>') { $combinator = trim($part) === '' ? ' ' : '>'; continue; }
            if (!preg_match('~^(\*|[a-z][\w-]*)?((?:[.#][\w-]+)*)$~', $part, $m)) return [[[' ', '*', [], []]], true, $specificity];
            preg_match_all('~([.#])([\w-]+)~', $m[2], $names, PREG_SET_ORDER);
            $chain[] = [$combinator, $m[1] === '' ? '*' : $m[1], array_values(array_map(static fn(array $n): string => $n[2], array_filter($names, static fn(array $n): bool => $n[1] === '.'))),
                array_values(array_map(static fn(array $n): string => $n[2], array_filter($names, static fn(array $n): bool => $n[1] === '#')))];
            $combinator = ' ';
        }
        return [$chain ?: [[' ', '*', [], []]], $approx, $specificity];
    }

    /** Right to left: the subject is the element, each earlier compound an ancestor (">" the parent itself). */
    private static function matches(array $chain, array $element, array $ancestors, bool $approx): bool {
        $last = count($chain) - 1;
        if (!self::compound($chain[$last], $element)) return false;
        if ($approx) return true;
        $i = count($ancestors) - 1;
        for ($c = $last - 1; $c >= 0; $c--) {
            $child = $chain[$c + 1][0];
            if ($child === '>') {
                if ($i < 0 || !self::compound($chain[$c], $ancestors[$i])) return false;
                $i--;
                continue;
            }
            while ($i >= 0 && !self::compound($chain[$c], $ancestors[$i])) $i--;
            if ($i < 0) return false;
            $i--;
        }
        return true;
    }

    private static function compound(array $compound, array $element): bool {
        [, $tag, $classes, $ids] = $compound;
        return ($tag === '*' || $tag === $element['name']) && !array_diff($classes, $element['classes']) && !array_diff($ids, $element['ids']);
    }

    private static function attr(string $attrs, string $name): ?string {
        if (!preg_match('~\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+))~i', $attrs, $m)) return null;
        return html_entity_decode($m[1] . ($m[2] ?? '') . ($m[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<string> */
    private static function attrList(string $attrs, string $name): array {
        return preg_split('/\s+/', strtolower(trim((string)self::attr($attrs, $name))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** CSS as the browser reads it: entities and CSS escapes decoded, comments removed, whitespace collapsed, lower case. */
    private static function cssText(string $css): string {
        $css = html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $css = (string)preg_replace('~/\*.*?(?:\*/|$)~s', '', $css);
        $css = (string)preg_replace_callback('~\\\\([0-9a-fA-F]{1,6})\s?|\\\\(.)~su',
            static fn(array $m): string => $m[1] !== '' ? mb_chr((int)hexdec($m[1]), 'UTF-8') : $m[2], $css);
        return strtolower(trim((string)preg_replace('/\s+/', ' ', $css)));
    }
}
