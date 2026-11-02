<?php
declare(strict_types=1);

/**
 * Bookshop demo: a small, valid EPUB 3 "demo edition" of a public-domain title (title page, about the book, where to
 * read the full text). Written as an uncompressed ZIP with fixed timestamps, so the same input gives the same bytes
 * and no zip extension is needed.
 */
final class BookshopEpubWriter
{
    /** @param array{title:string, author:string, year:int, description:string, slug:string, gutenberg_url:string, translator?:string} $book */
    public static function build(array $book, string $shopName): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $id = 'urn:uuid:' . self::uuid('bookshop-demo:' . $book['slug']);
        $page = static fn(string $title, string $body): string => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="en" xml:lang="en">'
            . '<head><meta charset="UTF-8"/><title>' . $e($title) . '</title><link rel="stylesheet" href="style.css"/></head><body>' . $body . '</body></html>';
        $paragraphs = implode('', array_map(static fn(string $p): string => '<p>' . $e($p) . '</p>',
            array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $book['description']) ?: [])))));
        $translator = isset($book['translator']) && $book['translator'] !== '' ? '<p class="small">Translated by ' . $e($book['translator']) . '</p>' : '';

        $files = [
            'mimetype' => 'application/epub+zip',
            'META-INF/container.xml' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
                . '<rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
            'OEBPS/content.opf' => '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid" xml:lang="en">'
                . '<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
                . '<dc:identifier id="bookid">' . $id . '</dc:identifier><dc:title>' . $e($book['title']) . '</dc:title>'
                . '<dc:creator>' . $e($book['author']) . '</dc:creator><dc:language>en</dc:language>'
                . '<dc:publisher>' . $e($shopName) . ' (demo edition)</dc:publisher><dc:rights>Public domain text. Demo edition.</dc:rights>'
                . '<meta property="dcterms:modified">2026-01-01T00:00:00Z</meta></metadata>'
                . '<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
                . '<item id="css" href="style.css" media-type="text/css"/>'
                . '<item id="title" href="title.xhtml" media-type="application/xhtml+xml"/>'
                . '<item id="about" href="about.xhtml" media-type="application/xhtml+xml"/>'
                . '<item id="edition" href="edition.xhtml" media-type="application/xhtml+xml"/></manifest>'
                . '<spine><itemref idref="title"/><itemref idref="about"/><itemref idref="edition"/></spine></package>',
            'OEBPS/style.css' => 'body{font-family:serif;line-height:1.5;margin:1em}h1{text-align:center;margin-top:30%}'
                . '.author{text-align:center;font-style:italic}.small{text-align:center;font-size:.85em}',
            'OEBPS/nav.xhtml' => $page('Contents', '<nav epub:type="toc" id="toc"><h2>Contents</h2><ol>'
                . '<li><a href="title.xhtml">' . $e($book['title']) . '</a></li><li><a href="about.xhtml">About this book</a></li>'
                . '<li><a href="edition.xhtml">About this edition</a></li></ol></nav>'),
            'OEBPS/title.xhtml' => $page($book['title'], '<h1>' . $e($book['title']) . '</h1><p class="author">' . $e($book['author']) . '</p>'
                . $translator . '<p class="small">First published ' . ($book['year'] < 0 ? 'c. ' . abs($book['year']) . ' BC' : $book['year']) . '</p>'),
            'OEBPS/about.xhtml' => $page('About this book', '<h2>About this book</h2>' . $paragraphs),
            'OEBPS/edition.xhtml' => $page('About this edition', '<h2>About this edition</h2>'
                . '<p>This file is a demonstration edition delivered by ' . $e($shopName) . ', a sample shop built with BareBones Shop. '
                . 'It shows how an e-book is delivered as a secure download after payment.</p>'
                . '<p>The work is in the public domain. Its complete text is freely available from Project Gutenberg: '
                . '<a href="' . $e($book['gutenberg_url']) . '">' . $e($book['gutenberg_url']) . '</a></p>'),
        ];
        return self::zip($files);
    }

    /** Uncompressed ZIP; "mimetype" must come first and stored (EPUB OCF). Fixed DOS time 2026-01-01 00:00. */
    private static function zip(array $files): string
    {
        $time = 0;
        $date = ((2026 - 1980) << 9) | (1 << 5) | 1;
        $data = '';
        $central = '';
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, strlen($data)) . $name;
            $data .= $header . $name . $content;
        }
        return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), strlen($data), 0);
    }

    private static function uuid(string $seed): string
    {
        $h = md5($seed);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-3' . substr($h, 13, 3) . '-' . dechex(8 | (hexdec($h[16]) & 3)) . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }
}
