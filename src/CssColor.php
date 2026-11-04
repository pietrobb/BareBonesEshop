<?php

/**
 * CSS colours as a mail client paints them: parsing (hex, rgb[a](), hsl[a](), the 148 named colours, transparent),
 * alpha compositing over the backdrop and the WCAG contrast ratio. Colours are [r, g, b, alpha], 0–255 and 0–1.
 */
final class CssColor {
    /** Below this ratio text is not legible against its background (the shipped templates are at 2.9:1 or more). */
    public const MIN_CONTRAST = 2.0;
    public const BLACK = [0.0, 0.0, 0.0, 1.0];
    public const WHITE = [255.0, 255.0, 255.0, 1.0];

    private const NAMED = 'aliceblue:f0f8ff,antiquewhite:faebd7,aqua:00ffff,aquamarine:7fffd4,azure:f0ffff,beige:f5f5dc,bisque:ffe4c4,'
        . 'black:000000,blanchedalmond:ffebcd,blue:0000ff,blueviolet:8a2be2,brown:a52a2a,burlywood:deb887,'
        . 'cadetblue:5f9ea0,chartreuse:7fff00,chocolate:d2691e,coral:ff7f50,cornflowerblue:6495ed,cornsilk:fff8dc,'
        . 'crimson:dc143c,cyan:00ffff,darkblue:00008b,darkcyan:008b8b,darkgoldenrod:b8860b,darkgray:a9a9a9,'
        . 'darkgreen:006400,darkgrey:a9a9a9,darkkhaki:bdb76b,darkmagenta:8b008b,darkolivegreen:556b2f,'
        . 'darkorange:ff8c00,darkorchid:9932cc,darkred:8b0000,darksalmon:e9967a,darkseagreen:8fbc8f,'
        . 'darkslateblue:483d8b,darkslategray:2f4f4f,darkslategrey:2f4f4f,darkturquoise:00ced1,darkviolet:9400d3,'
        . 'deeppink:ff1493,deepskyblue:00bfff,dimgray:696969,dimgrey:696969,dodgerblue:1e90ff,firebrick:b22222,'
        . 'floralwhite:fffaf0,forestgreen:228b22,fuchsia:ff00ff,gainsboro:dcdcdc,ghostwhite:f8f8ff,gold:ffd700,'
        . 'goldenrod:daa520,gray:808080,green:008000,greenyellow:adff2f,grey:808080,honeydew:f0fff0,hotpink:ff69b4,'
        . 'indianred:cd5c5c,indigo:4b0082,ivory:fffff0,khaki:f0e68c,lavender:e6e6fa,lavenderblush:fff0f5,'
        . 'lawngreen:7cfc00,lemonchiffon:fffacd,lightblue:add8e6,lightcoral:f08080,lightcyan:e0ffff,'
        . 'lightgoldenrodyellow:fafad2,lightgray:d3d3d3,lightgreen:90ee90,lightgrey:d3d3d3,lightpink:ffb6c1,'
        . 'lightsalmon:ffa07a,lightseagreen:20b2aa,lightskyblue:87cefa,lightslategray:778899,lightslategrey:778899,'
        . 'lightsteelblue:b0c4de,lightyellow:ffffe0,lime:00ff00,limegreen:32cd32,linen:faf0e6,magenta:ff00ff,'
        . 'maroon:800000,mediumaquamarine:66cdaa,mediumblue:0000cd,mediumorchid:ba55d3,mediumpurple:9370db,'
        . 'mediumseagreen:3cb371,mediumslateblue:7b68ee,mediumspringgreen:00fa9a,mediumturquoise:48d1cc,'
        . 'mediumvioletred:c71585,midnightblue:191970,mintcream:f5fffa,mistyrose:ffe4e1,moccasin:ffe4b5,'
        . 'navajowhite:ffdead,navy:000080,oldlace:fdf5e6,olive:808000,olivedrab:6b8e23,orange:ffa500,orangered:ff4500,'
        . 'orchid:da70d6,palegoldenrod:eee8aa,palegreen:98fb98,paleturquoise:afeeee,palevioletred:db7093,'
        . 'papayawhip:ffefd5,peachpuff:ffdab9,peru:cd853f,pink:ffc0cb,plum:dda0dd,powderblue:b0e0e6,purple:800080,'
        . 'rebeccapurple:663399,red:ff0000,rosybrown:bc8f8f,royalblue:4169e1,saddlebrown:8b4513,salmon:fa8072,'
        . 'sandybrown:f4a460,seagreen:2e8b57,seashell:fff5ee,sienna:a0522d,silver:c0c0c0,skyblue:87ceeb,'
        . 'slateblue:6a5acd,slategray:708090,slategrey:708090,snow:fffafa,springgreen:00ff7f,steelblue:4682b4,'
        . 'tan:d2b48c,teal:008080,thistle:d8bfd8,tomato:ff6347,turquoise:40e0d0,violet:ee82ee,wheat:f5deb3,'
        . 'white:ffffff,whitesmoke:f5f5f5,yellow:ffff00,yellowgreen:9acd32';

    /** @return array{0: float, 1: float, 2: float, 3: float}|null null = not a colour (or one this parser cannot tell) */
    public static function parse(string $value): ?array {
        $value = strtolower(trim(str_replace('!important', '', $value)));
        if ($value === 'transparent') return [0.0, 0.0, 0.0, 0.0];
        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/D', $value, $m)) {
            $hex = strlen($m[1]) <= 4 ? implode('', array_map(static fn(string $c): string => $c . $c, str_split($m[1]))) : $m[1];
            $c = array_map('hexdec', str_split($hex, 2));
            return [(float)$c[0], (float)$c[1], (float)$c[2], isset($c[3]) ? $c[3] / 255 : 1.0];
        }
        if (preg_match('/^(rgba?|hsla?)\((.*)\)$/D', $value, $m)) {
            $args = preg_split('~\s*[,/]\s*|\s+~', trim($m[2])) ?: [];
            if (count($args) < 3 || count($args) > 4) return null;
            $alpha = isset($args[3]) ? self::number($args[3], 1.0) : 1.0;
            if ($alpha === null) return null;
            if ($m[1][0] === 'r') {
                $rgb = array_map(static fn(string $a): ?float => self::number($a, 255.0), array_slice($args, 0, 3));
                if (in_array(null, $rgb, true)) return null;
                return [...array_map(static fn(float $v): float => max(0.0, min(255.0, $v)), $rgb), max(0.0, min(1.0, $alpha))];
            }
            $h = preg_match('/^(-?\d*\.?\d+)(deg)?$/D', $args[0], $hm) ? fmod(fmod((float)$hm[1], 360) + 360, 360) : null;
            $s = self::number($args[1], 1.0);
            $l = self::number($args[2], 1.0);
            if ($h === null || $s === null || $l === null || !str_ends_with($args[1], '%') || !str_ends_with($args[2], '%')) return null;
            return [...self::hslToRgb($h, max(0.0, min(1.0, $s)), max(0.0, min(1.0, $l))), max(0.0, min(1.0, $alpha))];
        }
        if (preg_match('/^[a-z]+$/D', $value) && preg_match('/(?:^|,)' . $value . ':([0-9a-f]{6})(?:,|$)/', self::NAMED, $m)) {
            return self::parse('#' . $m[1]);
        }
        return null;
    }

    /** The opaque colour seen when $color is painted over the opaque $backdrop. */
    public static function over(array $color, array $backdrop): array {
        $a = $color[3];
        return [$color[0] * $a + $backdrop[0] * (1 - $a), $color[1] * $a + $backdrop[1] * (1 - $a), $color[2] * $a + $backdrop[2] * (1 - $a), 1.0];
    }

    /** WCAG 2 contrast ratio of two opaque colours, 1 (same) to 21 (black on white). */
    public static function contrast(array $a, array $b): float {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(array $c): float {
        $lin = static function (float $v): float {
            $v /= 255;
            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $lin($c[0]) + 0.7152 * $lin($c[1]) + 0.0722 * $lin($c[2]);
    }

    /** A number, or a percentage of $full. */
    private static function number(string $arg, float $full): ?float {
        if (!preg_match('/^(-?\d*\.?\d+)(%)?$/D', $arg, $m)) return null;
        return isset($m[2]) ? (float)$m[1] / 100 * $full : (float)$m[1];
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function hslToRgb(float $h, float $s, float $l): array {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (intdiv((int)$h, 60)) {
            0 => [$c, $x, 0.0], 1 => [$x, $c, 0.0], 2 => [0.0, $c, $x], 3 => [0.0, $x, $c], 4 => [$x, 0.0, $c], default => [$c, 0.0, $x],
        };
        return [($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255];
    }
}
