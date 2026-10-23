<?php
/**
 * MinimalPdf — bare-bones PDF generator for invoices.
 *
 * Produces a valid single- or multi-page A4 PDF using the built-in Helvetica fonts (nothing embedded).
 * Text is encoded at render time: CP1252 characters keep their /WinAnsiEncoding byte, and the Latin
 * Extended-A letters of sk/cs/pl/hu (č ř ů ő ű ł ą …) get free byte slots of this document through an
 * /Encoding /Differences array with their standard glyph names, so invoices keep their diacritics
 * ("Faktúra č.", not "Faktura c."). Only characters outside both sets are transliterated.
 *
 * Layout primitives:
 *   addPage()                   start a new page
 *   text($x,$y,$str,$opts)      write text at (x,y) in mm; opts: size, bold, align, width
 *   line($x1,$y1,$x2,$y2,$w)    draw line in mm
 *   rect($x,$y,$w,$h,$fill)     draw rectangle
 *   render(): string            full PDF binary
 *
 * Origin is top-left, units are millimetres. Page size A4 (210×297 mm).
 */
final class MinimalPdf
{
    /** @var array<int,list<string|array{0:string,1:float,2:float,3:float,4:string}>> page content: drawing operators or text runs */
    private array $pages = [];
    private int $currentPage = -1;

    private const PAGE_W_MM = 210.0;
    private const PAGE_H_MM = 297.0;
    private const MM_TO_PT  = 2.834645669; // 72 / 25.4

    /** Standard glyph names of U+0100..U+017F (Latin Extended-A), in code point order. */
    private const LATIN_EXT_A = [
        'Amacron', 'amacron', 'Abreve', 'abreve', 'Aogonek', 'aogonek', 'Cacute', 'cacute', 'Ccircumflex',
        'ccircumflex', 'Cdotaccent', 'cdotaccent', 'Ccaron', 'ccaron', 'Dcaron', 'dcaron', 'Dcroat', 'dcroat',
        'Emacron', 'emacron', 'Ebreve', 'ebreve', 'Edotaccent', 'edotaccent', 'Eogonek', 'eogonek', 'Ecaron',
        'ecaron', 'Gcircumflex', 'gcircumflex', 'Gbreve', 'gbreve', 'Gdotaccent', 'gdotaccent', 'Gcommaaccent',
        'gcommaaccent', 'Hcircumflex', 'hcircumflex', 'Hbar', 'hbar', 'Itilde', 'itilde', 'Imacron', 'imacron',
        'Ibreve', 'ibreve', 'Iogonek', 'iogonek', 'Idotaccent', 'dotlessi', 'IJ', 'ij', 'Jcircumflex', 'jcircumflex',
        'Kcommaaccent', 'kcommaaccent', 'kgreenlandic', 'Lacute', 'lacute', 'Lcommaaccent', 'lcommaaccent', 'Lcaron',
        'lcaron', 'Ldot', 'ldot', 'Lslash', 'lslash', 'Nacute', 'nacute', 'Ncommaaccent', 'ncommaaccent', 'Ncaron',
        'ncaron', 'napostrophe', 'Eng', 'eng', 'Omacron', 'omacron', 'Obreve', 'obreve', 'Ohungarumlaut',
        'ohungarumlaut', 'OE', 'oe', 'Racute', 'racute', 'Rcommaaccent', 'rcommaaccent', 'Rcaron', 'rcaron',
        'Sacute', 'sacute', 'Scircumflex', 'scircumflex', 'Scedilla', 'scedilla', 'Scaron', 'scaron', 'Tcommaaccent',
        'tcommaaccent', 'Tcaron', 'tcaron', 'Tbar', 'tbar', 'Utilde', 'utilde', 'Umacron', 'umacron', 'Ubreve',
        'ubreve', 'Uring', 'uring', 'Uhungarumlaut', 'uhungarumlaut', 'Uogonek', 'uogonek', 'Wcircumflex',
        'wcircumflex', 'Ycircumflex', 'ycircumflex', 'Ydieresis', 'Zacute', 'zacute', 'Zdotaccent', 'zdotaccent',
        'Zcaron', 'zcaron', 'longs',
    ];

    /** Spacing accents used on their own (e.g. "ˇ" in Czech typesetting). */
    private const EXTRA_GLYPHS = ['ˇ' => 'caron', '˘' => 'breve', '˙' => 'dotaccent', '˛' => 'ogonek', '˝' => 'hungarumlaut'];

    /** CP1252 bytes with no character: always free for /Differences. */
    private const UNDEFINED_CP1252 = [0x81, 0x8D, 0x8F, 0x90, 0x9D];

    public function addPage(): void
    {
        $this->pages[] = [];
        $this->currentPage = count($this->pages) - 1;
    }

    public function text(float $x, float $y, string $str, array $opts = []): void
    {
        if ($this->currentPage < 0) {
            $this->addPage();
        }
        $size  = (float)($opts['size'] ?? 10);
        $bold  = !empty($opts['bold']);
        $align = $opts['align'] ?? 'left';
        $width = isset($opts['width']) ? (float)$opts['width'] : null;

        $px = $x * self::MM_TO_PT;
        $py = (self::PAGE_H_MM - $y) * self::MM_TO_PT;

        if ($align !== 'left' && $width !== null) {
            $textWidth = $this->approxTextWidth($str, $size, $bold);
            if ($align === 'right') {
                $px = ($x + $width - $textWidth) * self::MM_TO_PT;
            } elseif ($align === 'center') {
                $px = ($x + ($width - $textWidth) / 2) * self::MM_TO_PT;
            }
        }

        $this->pages[$this->currentPage][] = [$bold ? '/F2' : '/F1', $size, $px, $py, $str];
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.2): void
    {
        if ($this->currentPage < 0) {
            $this->addPage();
        }
        $this->pages[$this->currentPage][] = sprintf(
            "%.2f w %.2f %.2f m %.2f %.2f l S\n",
            $w,
            $x1 * self::MM_TO_PT,
            (self::PAGE_H_MM - $y1) * self::MM_TO_PT,
            $x2 * self::MM_TO_PT,
            (self::PAGE_H_MM - $y2) * self::MM_TO_PT
        );
    }

    public function rect(float $x, float $y, float $w, float $h, bool $fill = false): void
    {
        if ($this->currentPage < 0) {
            $this->addPage();
        }
        $op = $fill ? 'f' : 'S';
        $this->pages[$this->currentPage][] = sprintf(
            "%.2f %.2f %.2f %.2f re %s\n",
            $x * self::MM_TO_PT,
            (self::PAGE_H_MM - $y - $h) * self::MM_TO_PT,
            $w * self::MM_TO_PT,
            $h * self::MM_TO_PT,
            $op
        );
    }

    public function render(): string
    {
        if (!$this->pages) {
            $this->addPage();
        }

        [$slots, $differences] = $this->encodingFor($this->pages);
        $encoding = $differences === ''
            ? '/WinAnsiEncoding'
            : "<< /Type /Encoding /BaseEncoding /WinAnsiEncoding /Differences [{$differences}] >>";

        $objects = [];

        // Object 1: catalog
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

        // Object 2: pages tree (kids placeholder, fixed below)
        // Compute kid object IDs first.
        $pageObjStart = 5;
        $kids = [];
        for ($i = 0; $i < count($this->pages); $i++) {
            $kids[] = ($pageObjStart + $i * 2) . ' 0 R';
        }
        $objects[] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($this->pages) . " >>";

        // Object 3: Helvetica
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding {$encoding} >>";
        // Object 4: Helvetica-Bold
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding {$encoding} >>";

        // Pages: each page = page-obj + content-obj
        foreach ($this->pages as $i => $items) {
            $stream = '';
            foreach ($items as $item) {
                $stream .= is_string($item) ? $item : sprintf(
                    "BT %s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
                    $item[0], $item[1], $item[2], $item[3], $this->encodeText($item[4], $slots)
                );
            }
            $contentObjId = $pageObjStart + $i * 2 + 1;
            $pageObjId    = $pageObjStart + $i * 2;
            $w = self::PAGE_W_MM * self::MM_TO_PT;
            $h = self::PAGE_H_MM * self::MM_TO_PT;
            $objects[$pageObjId - 1] = "<< /Type /Page /Parent 2 0 R "
                . "/MediaBox [0 0 " . sprintf('%.2f %.2f', $w, $h) . "] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> "
                . "/Contents {$contentObjId} 0 R >>";
            $objects[$contentObjId - 1] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
        }

        // Build PDF body.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $idx => $body) {
            $offsets[$idx + 1] = strlen($pdf);
            $pdf .= ($idx + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";
        return $pdf;
    }

    /**
     * Byte slots for the document's characters outside CP1252 and the matching /Differences entries.
     * Slots come from undefined CP1252 bytes first, then from CP1252 characters the document never uses.
     *
     * @return array{0: array<string,int>, 1: string}
     */
    private function encodingFor(array $pages): array
    {
        $usedBytes = [];
        $needed = [];
        foreach ($pages as $items) {
            foreach ($items as $item) {
                if (is_string($item)) continue;
                foreach (preg_split('//u', $item[4], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                    if (strlen($char) === 1) continue;
                    $cp = self::toCp1252($char);
                    if ($cp !== null) $usedBytes[ord($cp)] = true;
                    elseif (self::glyphName($char) !== null) $needed[$char] = true;
                }
            }
        }
        if (!$needed) return [[], ''];

        $free = self::UNDEFINED_CP1252;
        for ($b = 0x80; $b <= 0xFF; $b++) {
            if (!isset($usedBytes[$b]) && !in_array($b, self::UNDEFINED_CP1252, true) && $b !== 0xA0) $free[] = $b;
        }
        $slots = [];
        $differences = [];
        foreach (array_keys($needed) as $i => $char) {
            if (!isset($free[$i])) break; // more than ~120 distinct extra letters: the rest is transliterated
            $slots[$char] = $free[$i];
            $differences[] = $free[$i] . ' /' . self::glyphName($char);
        }
        return [$slots, implode(' ', $differences)];
    }

    private static function toCp1252(string $char): ?string
    {
        // A lookup, not iconv(): iconv raises a notice for every unmappable letter, which an application error
        // handler turns into an exception even under @.
        static $map = null;
        if ($map === null) {
            $map = [];
            for ($b = 0x80; $b <= 0xFF; $b++) {
                if (in_array($b, self::UNDEFINED_CP1252, true)) continue;
                $map[mb_convert_encoding(chr($b), 'UTF-8', 'Windows-1252')] = chr($b);
            }
        }
        return $map[$char] ?? null;
    }

    private static function glyphName(string $char): ?string
    {
        if (isset(self::EXTRA_GLYPHS[$char])) return self::EXTRA_GLYPHS[$char];
        $code = mb_ord($char, 'UTF-8');
        return $code >= 0x100 && $code <= 0x17F ? self::LATIN_EXT_A[$code - 0x100] : null;
    }

    /** @param array<string,int> $slots */
    private function encodeText(string $s, array $slots): string
    {
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (strlen($char) === 1) {
                $out .= $char;
            } elseif (isset($slots[$char])) {
                $out .= chr($slots[$char]);
            } elseif (($cp = self::toCp1252($char)) !== null) {
                $out .= $cp;
            } else {
                set_error_handler(static fn(): bool => true); // see toCp1252(): no notice may escape
                try {
                    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $char) : false;
                } finally {
                    restore_error_handler();
                }
                $out .= is_string($ascii) && $ascii !== '' ? $ascii : '?';
            }
        }
        // Escape PDF string syntax.
        return strtr($out, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']);
    }

    private function approxTextWidth(string $s, float $sizePt, bool $bold): float
    {
        // Rough heuristic: avg char width = 0.5 × size pt for Helvetica.
        $factor = $bold ? 0.55 : 0.5;
        return mb_strlen($s, 'UTF-8') * $sizePt * $factor / self::MM_TO_PT;
    }
}
