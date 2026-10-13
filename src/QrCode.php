<?php
/**
 * Minimal QR Code model 2 encoder (ISO/IEC 18004) for payment QR codes: byte or
 * alphanumeric mode, error correction level M, smallest fitting version, mask chosen
 * by the standard penalty score. Output is an inline SVG with a 4-module quiet zone.
 */
final class QrCode {
    private const ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
    // Level M: error correction codewords per block and number of blocks, by version.
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26,
        26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
    private const BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16,
        17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

    private int $size;
    /** @var array<int,array<int,bool>> */
    private array $modules = [];
    /** @var array<int,array<int,bool>> */
    private array $function = [];

    private function __construct(private int $version) {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->function = array_fill(0, $this->size, $row);
    }

    public static function svg(string $text, string $label = '', int $moduleSize = 4): string {
        $matrix = self::matrix($text);
        $count = count($matrix);
        $dim = ($count + 8) * $moduleSize;
        $path = '';
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) $path .= 'M' . ($x + 4) . ',' . ($y + 4) . 'h1v1h-1z';
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $dim . '" height="' . $dim . '" viewBox="0 0 '
            . ($count + 8) . ' ' . ($count + 8) . '" shape-rendering="crispEdges" role="img" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    /** @return array<int,array<int,bool>> dark modules, row by row */
    public static function matrix(string $text): array {
        $alnum = $text !== '' && strspn($text, self::ALNUM) === strlen($text);
        for ($version = 1; $version <= 40; $version++) {
            $bits = self::dataBits($text, $alnum, $version);
            $capacity = self::dataCodewords($version) * 8;
            if ($bits !== null && strlen($bits) <= $capacity) {
                $qr = new self($version);
                $qr->build(self::finishBits($bits, $capacity));
                return $qr->modules;
            }
        }
        throw new InvalidArgumentException('Text too long for a QR code');
    }

    private static function dataBits(string $text, bool $alnum, int $version): ?string {
        $countBits = $alnum ? ($version <= 9 ? 9 : ($version <= 26 ? 11 : 13)) : ($version <= 9 ? 8 : 16);
        $length = strlen($text);
        if ($length >= (1 << $countBits)) return null;
        $bits = ($alnum ? '0010' : '0100') . self::bin($length, $countBits);
        if ($alnum) {
            for ($i = 0; $i + 1 < $length; $i += 2) {
                $bits .= self::bin(strpos(self::ALNUM, $text[$i]) * 45 + strpos(self::ALNUM, $text[$i + 1]), 11);
            }
            if ($length % 2 === 1) $bits .= self::bin(strpos(self::ALNUM, $text[$length - 1]), 6);
        } else {
            for ($i = 0; $i < $length; $i++) $bits .= self::bin(ord($text[$i]), 8);
        }
        return $bits;
    }

    private static function finishBits(string $bits, int $capacity): array {
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $bytes = array_map('bindec', str_split($bits, 8));
        for ($pad = 0xEC; count($bytes) < $capacity / 8; $pad ^= 0xEC ^ 0x11) $bytes[] = $pad;
        return $bytes;
    }

    private static function bin(int $value, int $width): string {
        return str_pad(decbin($value), $width, '0', STR_PAD_LEFT);
    }

    private static function rawModules(int $version): int {
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $align = intdiv($version, 7) + 2;
            $result -= (25 * $align - 10) * $align - 55;
            if ($version >= 7) $result -= 36;
        }
        return $result;
    }

    private static function dataCodewords(int $version): int {
        return intdiv(self::rawModules($version), 8) - self::ECC_PER_BLOCK[$version] * self::BLOCKS[$version];
    }

    private function build(array $data): void {
        $this->drawFunctionPatterns();
        $this->drawCodewords($this->interleave($data));
        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();
            if ($penalty < $bestPenalty) {
                $best = $mask;
                $bestPenalty = $penalty;
            }
            $this->applyMask($mask);
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    private function set(int $x, int $y, bool $dark): void {
        $this->modules[$y][$x] = $dark;
        $this->function[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $this->size || $y >= $this->size) continue;
                    $dist = max(abs($dx), abs($dy));
                    $this->set($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
        $positions = $this->alignmentPositions();
        $last = count($positions) - 1;
        foreach ($positions as $i => $px) {
            foreach ($positions as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) $this->set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                }
            }
        }
        $this->drawFormatBits(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $this->size - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array {
        if ($this->version === 1) return [];
        $count = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $count * 3 + 5, $count * 4 - 4) * 2;
        $result = [];
        for ($i = 0, $pos = $this->size - 7; $i < $count - 1; $i++, $pos -= $step) array_unshift($result, $pos);
        array_unshift($result, 6);
        return $result;
    }

    private function drawFormatBits(int $mask): void {
        $data = (0 << 3) | $mask;   // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        for ($i = 0; $i <= 5; $i++) $this->set(8, $i, $bit($i));
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->set(14 - $i, 8, $bit($i));
        for ($i = 0; $i < 8; $i++) $this->set($this->size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->set(8, $this->size - 15 + $i, $bit($i));
        $this->set(8, $this->size - 8, true);
    }

    /** @return list<int> */
    private function interleave(array $data): array {
        $blocks = self::BLOCKS[$this->version];
        $eccLen = self::ECC_PER_BLOCK[$this->version];
        $raw = intdiv(self::rawModules($this->version), 8);
        $shortBlocks = $blocks - $raw % $blocks;
        $shortLen = intdiv($raw, $blocks);
        $divisor = self::rsDivisor($eccLen);
        $all = [];
        for ($i = 0, $k = 0; $i < $blocks; $i++) {
            $chunk = array_slice($data, $k, $shortLen - $eccLen + ($i < $shortBlocks ? 0 : 1));
            $k += count($chunk);
            $ecc = self::rsRemainder($chunk, $divisor);
            if ($i < $shortBlocks) $chunk[] = 0;
            $all[] = array_merge($chunk, $ecc);
        }
        $result = [];
        for ($i = 0, $n = count($all[0]); $i < $n; $i++) {
            foreach ($all as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $shortBlocks) $result[] = $block[$i];
            }
        }
        return $result;
    }

    private static function gfMultiply(int $x, int $y): int {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    /** @return list<int> */
    private static function rsDivisor(int $degree): array {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) $result[$j] ^= $result[$j + 1];
            }
            $root = self::gfMultiply($root, 0x02);
        }
        return $result;
    }

    /** @return list<int> */
    private static function rsRemainder(array $data, array $divisor): array {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) $result[$i] ^= self::gfMultiply($coef, $factor);
        }
        return $result;
    }

    private function drawCodewords(array $data): void {
        $total = count($data) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->function[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->function[$y][$x]) continue;
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert) $this->modules[$y][$x] = !$this->modules[$y][$x];
            }
        }
    }

    private function penalty(): int {
        $penalty = 0;
        $dark = 0;
        $lines = [];
        for ($y = 0; $y < $this->size; $y++) {
            $row = '';
            $col = '';
            for ($x = 0; $x < $this->size; $x++) {
                $row .= $this->modules[$y][$x] ? '1' : '0';
                $col .= $this->modules[$x][$y] ? '1' : '0';
                if ($this->modules[$y][$x]) $dark++;
                if ($x > 0 && $y > 0) {
                    $c = $this->modules[$y][$x];
                    if ($c === $this->modules[$y][$x - 1] && $c === $this->modules[$y - 1][$x] && $c === $this->modules[$y - 1][$x - 1]) $penalty += 3;
                }
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ($lines as $line) {
            preg_match_all('/0{5,}|1{5,}/', $line, $runs);
            foreach ($runs[0] as $run) $penalty += strlen($run) - 2;
            $penalty += 40 * (substr_count($line, '00001011101') + substr_count($line, '10111010000'));
        }
        $total = $this->size * $this->size;
        $penalty += intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) * 10 - 10;
        return max(0, $penalty);
    }
}
