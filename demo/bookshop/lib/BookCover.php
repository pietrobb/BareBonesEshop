<?php
declare(strict_types=1);

/**
 * Bookshop demo: an original SVG cover per title (no third-party artwork). Colours and the motif follow the genre;
 * small variations come from a hash of the slug, so every build draws the same cover.
 */
final class BookshopCover
{
    /** Per genre: palettes [background, accent, text] and the motif drawn on them. */
    private const GENRES = [
        'adventure' => ['motif' => 'waves', 'palettes' => [['#12355b', '#e0a458', '#f7efe2'], ['#1f4e5f', '#f2c14e', '#fdf6e3'], ['#3d2c4e', '#e07a5f', '#f4f1de']]],
        'science-fiction' => ['motif' => 'orbits', 'palettes' => [['#0b132b', '#5bc0be', '#e6f1f2'], ['#1c1b3a', '#c084fc', '#f1eaff'], ['#102a2c', '#9ad1a4', '#eef7ef']]],
        'mystery' => ['motif' => 'lens', 'palettes' => [['#1b1b1e', '#c9a227', '#f2ede1'], ['#2b2d42', '#d90429', '#edf2f4'], ['#22333b', '#c6ac8f', '#eae0d5']]],
        'horror' => ['motif' => 'moon', 'palettes' => [['#140d0f', '#a4161a', '#f5ebe0'], ['#1a1423', '#b8b8d1', '#f0eef5'], ['#0f1a14', '#8fb996', '#ecf3ec']]],
        'classics' => ['motif' => 'lattice', 'palettes' => [['#5e2b2b', '#e6c79c', '#fbf3e4'], ['#264027', '#d8c99b', '#f6f1e1'], ['#2f3e5c', '#d6b370', '#f7f1e3']]],
        'romance' => ['motif' => 'petals', 'palettes' => [['#7d2e46', '#f4c2c2', '#fff4f2'], ['#4a3b5c', '#e8b4bc', '#fbf0f2'], ['#8c4a3c', '#f1d6b8', '#fff7ee']]],
        'historical' => ['motif' => 'arches', 'palettes' => [['#4b3621', '#d4a373', '#faf3e0'], ['#33415c', '#e9c46a', '#f8f3e6'], ['#5a3d31', '#b7c9a8', '#f4f1e8']]],
        'children' => ['motif' => 'stars', 'palettes' => [['#2a9d8f', '#f4a261', '#fffaf0'], ['#e76f51', '#ffd166', '#fff8ec'], ['#3a86ff', '#ffbe0b', '#fffbea']]],
        'humour' => ['motif' => 'confetti', 'palettes' => [['#f6bd60', '#3d405b', '#2b2d42'], ['#84a59d', '#f28482', '#1f2421'], ['#f5cac3', '#355070', '#22223b']]],
        'poetry-drama' => ['motif' => 'flourish', 'palettes' => [['#3c1642', '#e0b1cb', '#f9eef6'], ['#283618', '#dda15e', '#fefae0'], ['#1d3557', '#a8dadc', '#f1faee']]],
        'ideas' => ['motif' => 'geometry', 'palettes' => [['#222831', '#00adb5', '#eeeeee'], ['#3e3e3e', '#e9c46a', '#f4f4f4'], ['#2d3047', '#e0a890', '#f8f4f0']]],
        'travel' => ['motif' => 'mountains', 'palettes' => [['#386641', '#f2e8cf', '#f2e8cf'], ['#1d4e89', '#f79256', '#fbfaf5'], ['#6b4226', '#d9ae94', '#fbf4ec']]],
    ];

    public static function svg(string $slug, string $title, string $author, string $genre, string $seriesLabel): string
    {
        $config = self::GENRES[$genre] ?? self::GENRES['classics'];
        $hash = hexdec(substr(hash('sha256', $slug), 0, 8));
        [$bg, $accent, $ink] = $config['palettes'][$hash % count($config['palettes'])];
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $lines = self::wrap($title, 15);
        $longest = max(array_map('mb_strlen', $lines));
        $size = max(40, min(76, (int)floor(500 / max(6, $longest) * 1.75)));
        if (count($lines) > 4) $size = (int)min($size, 46);
        $lineHeight = (int)round($size * 1.12);
        $top = 330 - (int)round((count($lines) - 1) * $lineHeight / 2);
        $titleSvg = '';
        foreach ($lines as $i => $line) {
            $titleSvg .= '<text x="300" y="' . ($top + $i * $lineHeight) . '" font-size="' . $size . '">' . $e($line) . '</text>';
        }
        $authorSize = mb_strlen($author) > 26 ? 24 : 28;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 900" width="600" height="900" role="img" aria-label="'
            . $e($title . ' by ' . $author) . '">'
            . '<rect width="600" height="900" fill="' . $bg . '"/>'
            . '<g fill="none" stroke="' . $accent . '" opacity="0.9">' . self::motif($config['motif'], $hash, $accent) . '</g>'
            . '<rect x="28" y="28" width="544" height="844" fill="none" stroke="' . $accent . '" stroke-width="3"/>'
            . '<rect x="40" y="40" width="520" height="820" fill="none" stroke="' . $accent . '" stroke-width="1" opacity="0.7"/>'
            . '<rect x="70" y="' . ($top - $size - 40) . '" width="460" height="' . (count($lines) * $lineHeight + 70) . '" fill="' . $bg . '" opacity="0.82"/>'
            . '<g font-family="Georgia, \'Times New Roman\', serif" fill="' . $ink . '" text-anchor="middle">'
            . '<text x="300" y="110" font-size="18" letter-spacing="6" fill="' . $accent . '">' . $e(mb_strtoupper($seriesLabel)) . '</text>'
            . $titleSvg
            . '<line x1="230" y1="' . ($top + count($lines) * $lineHeight - 10) . '" x2="370" y2="' . ($top + count($lines) * $lineHeight - 10) . '" stroke="' . $accent . '" stroke-width="2"/>'
            . '<text x="300" y="800" font-size="' . $authorSize . '" letter-spacing="3" font-style="italic">' . $e($author) . '</text>'
            . '</g></svg>';
    }

    /** @return list<string> */
    private static function wrap(string $text, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($line !== '' && mb_strlen($line . ' ' . $word) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $line === '' ? $word : $line . ' ' . $word;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: [$text];
    }

    private static function motif(string $motif, int $hash, string $accent): string
    {
        $r = static fn(int $salt, int $min, int $max): int => $min + (int)(($hash >> ($salt % 24)) % max(1, $max - $min + 1));
        $out = '';
        switch ($motif) {
            case 'waves':
                for ($i = 0; $i < 7; $i++) {
                    $y = 560 + $i * 38;
                    $out .= '<path stroke-width="3" d="M40 ' . $y . ' q 40 -30 80 0 t 80 0 t 80 0 t 80 0 t 80 0 t 80 0 t 80 0"/>';
                }
                $out .= '<circle cx="' . $r(3, 380, 470) . '" cy="190" r="60" stroke-width="3"/><path stroke-width="2" d="M' . $r(3, 380, 470) . ' 120 v140 M' . ($r(3, 380, 470) - 70) . ' 190 h140"/>';
                break;
            case 'orbits':
                $cx = $r(2, 180, 420);
                for ($i = 1; $i <= 5; $i++) $out .= '<ellipse cx="' . $cx . '" cy="660" rx="' . (60 * $i) . '" ry="' . (22 * $i) . '" stroke-width="2"/>';
                $out .= '<circle cx="' . $cx . '" cy="660" r="34" fill="' . $accent . '"/><circle cx="' . $r(5, 90, 510) . '" cy="170" r="14" fill="' . $accent . '"/>';
                for ($i = 0; $i < 14; $i++) $out .= '<circle cx="' . (60 + ($hash >> $i) % 480) . '" cy="' . (130 + (($hash >> ($i + 3)) % 120)) . '" r="2" fill="' . $accent . '"/>';
                break;
            case 'lens':
                $out .= '<circle cx="' . $r(4, 220, 380) . '" cy="650" r="110" stroke-width="10"/><path stroke-width="18" stroke-linecap="round" d="M' . ($r(4, 220, 380) + 80) . ' 730 l 90 90"/>';
                for ($i = 0; $i < 6; $i++) $out .= '<path stroke-width="1.5" d="M' . (40 + $i * 90) . ' 120 l 120 120"/>';
                break;
            case 'moon':
                $cx = $r(6, 360, 470);
                $out .= '<circle cx="' . $cx . '" cy="190" r="70" fill="' . $accent . '" stroke="none"/><circle cx="' . ($cx + 30) . '" cy="170" r="62" fill="#000" fill-opacity="0.55" stroke="none"/>';
                $out .= '<path stroke-width="4" d="M120 860 C 130 760 110 700 150 620 M150 620 C 180 580 210 590 240 560 M150 640 C 120 600 90 600 70 570 M140 700 C 170 690 190 700 215 680"/>';
                break;
            case 'lattice':
                for ($x = 60; $x <= 540; $x += 60) for ($y = 600; $y <= 840; $y += 60) $out .= '<path stroke-width="1.5" d="M' . $x . ' ' . ($y - 26) . ' l 26 26 l -26 26 l -26 -26 z"/>';
                $out .= '<path stroke-width="2" d="M150 170 h300 M190 185 h220"/>';
                break;
            case 'petals':
                $cx = $r(7, 220, 380);
                for ($i = 0; $i < 8; $i++) {
                    $a = $i * 45;
                    $out .= '<ellipse cx="' . $cx . '" cy="640" rx="34" ry="96" stroke-width="2.5" transform="rotate(' . $a . ' ' . $cx . ' 640)"/>';
                }
                $out .= '<circle cx="' . $cx . '" cy="640" r="18" fill="' . $accent . '"/>';
                break;
            case 'arches':
                for ($i = 0; $i < 5; $i++) {
                    $x = 70 + $i * 96;
                    $out .= '<path stroke-width="3" d="M' . $x . ' 840 V680 a40 40 0 0 1 80 0 V840"/>';
                }
                $out .= '<path stroke-width="3" d="M60 660 H540 M60 650 L300 560 L540 650"/>';
                break;
            case 'stars':
                for ($i = 0; $i < 9; $i++) {
                    $x = 70 + (($hash >> $i) % 460);
                    $y = 560 + (($hash >> ($i + 5)) % 280);
                    $s = 10 + ($i % 3) * 8;
                    $out .= '<path fill="' . $accent . '" stroke="none" d="M' . $x . ' ' . ($y - $s) . ' L' . ($x + $s * 0.3) . ' ' . ($y - $s * 0.3) . ' L' . ($x + $s) . ' ' . $y
                        . ' L' . ($x + $s * 0.3) . ' ' . ($y + $s * 0.3) . ' L' . $x . ' ' . ($y + $s) . ' L' . ($x - $s * 0.3) . ' ' . ($y + $s * 0.3)
                        . ' L' . ($x - $s) . ' ' . $y . ' L' . ($x - $s * 0.3) . ' ' . ($y - $s * 0.3) . ' Z"/>';
                }
                break;
            case 'confetti':
                for ($i = 0; $i < 18; $i++) {
                    $x = 60 + (($hash >> ($i % 20)) * ($i + 7)) % 480;
                    $y = 560 + (($hash >> (($i + 4) % 20)) * ($i + 3)) % 290;
                    $out .= '<rect x="' . $x . '" y="' . $y . '" width="16" height="6" fill="' . $accent . '" stroke="none" transform="rotate(' . (($i * 37) % 180) . ' ' . $x . ' ' . $y . ')"/>';
                }
                break;
            case 'flourish':
                $out .= '<path stroke-width="3" d="M80 700 C 160 600 240 800 300 700 S 440 600 520 700"/><path stroke-width="2" d="M120 760 C 200 680 260 840 300 760 S 400 680 480 760"/>';
                $out .= '<circle cx="300" cy="700" r="10" fill="' . $accent . '"/>';
                break;
            case 'geometry':
                $out .= '<circle cx="' . $r(8, 220, 380) . '" cy="680" r="120" stroke-width="3"/><path stroke-width="3" d="M' . $r(8, 220, 380) . ' 540 L' . ($r(8, 220, 380) + 140) . ' 800 H' . ($r(8, 220, 380) - 140) . ' Z"/>';
                break;
            case 'mountains':
                $out .= '<path stroke-width="3" d="M40 840 L170 640 L240 730 L340 580 L460 760 L520 700 L560 760"/><path stroke-width="2" d="M40 860 L200 720 L300 800 L420 690 L560 820"/>';
                $out .= '<circle cx="' . $r(9, 380, 480) . '" cy="190" r="40" stroke-width="3"/>';
                break;
        }
        return $out;
    }
}
