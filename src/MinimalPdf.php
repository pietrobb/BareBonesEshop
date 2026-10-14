<?php
/**
 * MinimalPdf — bare-bones PDF generator for invoices.
 *
 * Produces a valid single- or multi-page A4 PDF using built-in Helvetica
 * with /WinAnsiEncoding (CP1252) for Western European text. Characters
 * outside CP1252 (e.g. Polish ł, Hungarian ő, Czech ř) are transliterated
 * to their closest ASCII equivalent.
 *
 * For production-quality PDFs with full Unicode rendering (proper diakritika
 * for sk/cs/pl/hu), drop mPDF into vendor/mpdf/ — InvoiceService will detect
 * it and route through mPDF automatically. See vendor/mpdf/README.md.
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
    /** @var array<int,string> page content streams */
    private array $pages = [];
    private int $currentPage = -1;

    private const PAGE_W_MM = 210.0;
    private const PAGE_H_MM = 297.0;
    private const MM_TO_PT  = 2.834645669; // 72 / 25.4

    /** Latin-2 / extended chars not in CP1252 — transliterate to ASCII fallback. */
    private const TRANSLITERATE = [
        'ł' => 'l',  'Ł' => 'L',
        'ő' => 'o',  'Ő' => 'O',
        'ű' => 'u',  'Ű' => 'U',
        'ř' => 'r',  'Ř' => 'R',
        'ě' => 'e',  'Ě' => 'E',
        'ů' => 'u',  'Ů' => 'U',
        'ť' => 't',  'Ť' => 'T',
        'ľ' => 'l',  'Ľ' => 'L',
        'ŕ' => 'r',  'Ŕ' => 'R',
        'ą' => 'a',  'Ą' => 'A',
        'ę' => 'e',  'Ę' => 'E',
        'ć' => 'c',  'Ć' => 'C',
        'ń' => 'n',  'Ń' => 'N',
        'ś' => 's',  'Ś' => 'S',
        'ź' => 'z',  'Ź' => 'Z',
        'ż' => 'z',  'Ż' => 'Z',
        // "€" is in CP1252 (0x80): iconv converts it. Pre-mapping it to a raw 0x80 byte made the
        // string invalid UTF-8, so iconv //IGNORE silently dropped the euro sign.
    ];

    public function addPage(): void
    {
        $this->pages[] = '';
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

        $font = $bold ? '/F2' : '/F1';
        $px = $x * self::MM_TO_PT;
        $py = (self::PAGE_H_MM - $y) * self::MM_TO_PT;

        $encoded = $this->encodeText($str);

        if ($align !== 'left' && $width !== null) {
            $textWidth = $this->approxTextWidth($str, $size, $bold);
            if ($align === 'right') {
                $px = ($x + $width - $textWidth) * self::MM_TO_PT;
            } elseif ($align === 'center') {
                $px = ($x + ($width - $textWidth) / 2) * self::MM_TO_PT;
            }
        }

        $this->pages[$this->currentPage] .= sprintf(
            "BT %s %.2f Tf %.2f %.2f Td (%s) Tj ET\n",
            $font,
            $size,
            $px,
            $py,
            $encoded
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $w = 0.2): void
    {
        if ($this->currentPage < 0) {
            $this->addPage();
        }
        $this->pages[$this->currentPage] .= sprintf(
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
        $this->pages[$this->currentPage] .= sprintf(
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
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        // Object 4: Helvetica-Bold
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // Pages: each page = page-obj + content-obj
        foreach ($this->pages as $i => $stream) {
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

    private function encodeText(string $s): string
    {
        // Transliterate non-CP1252 chars to ASCII fallback.
        $s = strtr($s, self::TRANSLITERATE);
        // Convert UTF-8 → CP1252 (Windows-1252).
        if (function_exists('iconv')) {
            $cp = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
            if ($cp !== false) {
                $s = $cp;
            }
        } elseif (function_exists('mb_convert_encoding')) {
            $s = mb_convert_encoding($s, 'CP1252', 'UTF-8');
        }
        // Escape PDF string syntax.
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']);
    }

    private function approxTextWidth(string $s, float $sizePt, bool $bold): float
    {
        // Rough heuristic: avg char width = 0.5 × size pt for Helvetica.
        $factor = $bold ? 0.55 : 0.5;
        return strlen($s) * $sizePt * $factor / self::MM_TO_PT;
    }
}
