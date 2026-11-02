<?php

// Bookshop demo (#237): the catalog data behind demo/bookshop/build.php. 300+ distinct public-domain titles in the
// twelve genre files, each with a usable record and its own description; every cover is well-formed SVG and every
// eBook demo edition a valid EPUB container.
echo "\n--- Bookshop: demo catalog data, covers and EPUB editions ---\n";

require_once TEST_CLI_DIR . '/../demo/bookshop/lib/BookCover.php';
require_once TEST_CLI_DIR . '/../demo/bookshop/lib/EpubWriter.php';

$bcFiles = glob(TEST_CLI_DIR . '/../demo/bookshop/data/*.php') ?: [];
$bcBooks = [];
$bcProblems = [];
foreach ($bcFiles as $bcFile) {
    $bcGenre = basename($bcFile, '.php');
    foreach (require $bcFile as $bcIndex => $bcBook) {
        $bcWhere = "{$bcGenre}#{$bcIndex}";
        if (!is_array($bcBook) || count($bcBook) !== 6) { $bcProblems[] = "{$bcWhere}: not [title, author, year, pages, language, description]"; continue; }
        [$bcTitle, $bcAuthor, $bcYear, $bcPages, $bcLanguage, $bcText] = $bcBook;
        if (!is_string($bcTitle) || trim($bcTitle) === '' || !is_string($bcAuthor) || trim($bcAuthor) === '') $bcProblems[] = "{$bcWhere}: title/author";
        if (!is_int($bcYear) || $bcYear < -1000 || $bcYear > 1930) $bcProblems[] = "{$bcWhere}: year {$bcYear} (public domain means published by 1930)";
        if (!is_int($bcPages) || $bcPages < 32 || $bcPages > 1600) $bcProblems[] = "{$bcWhere}: pages";
        if (!is_string($bcLanguage) || trim($bcLanguage) === '') $bcProblems[] = "{$bcWhere}: language";
        if (!is_string($bcText) || mb_strlen($bcText) < 150) $bcProblems[] = "{$bcWhere}: description shorter than 150 characters";
        $bcBooks[] = ['genre' => $bcGenre, 'title' => (string)$bcTitle, 'author' => (string)$bcAuthor, 'year' => (int)$bcYear, 'text' => (string)$bcText,
            'slug' => Import::slugify($bcTitle . ' ' . $bcAuthor)];
    }
}
assert_equals(12, count($bcFiles), 'Bookshop catalog: twelve genre data files');
assert_equals([], $bcProblems, 'Bookshop catalog: every record is complete and published by 1930');
assert_true(count($bcBooks) >= 300, 'Bookshop catalog: at least 300 titles (' . count($bcBooks) . ')');
$bcSlugs = array_count_values(array_column($bcBooks, 'slug'));
assert_equals([], array_keys(array_filter($bcSlugs, static fn(int $n): bool => $n > 1)), 'Bookshop catalog: no title is listed twice');
$bcTexts = array_count_values(array_column($bcBooks, 'text'));
assert_equals(count($bcBooks), count($bcTexts), 'Bookshop catalog: every title has its own description');

$bcBadCovers = [];
$bcBadEpubs = [];
foreach ($bcBooks as $bcBook) {
    $bcSvg = BookshopCover::svg($bcBook['slug'], $bcBook['title'], $bcBook['author'], $bcBook['genre'], 'Demo');
    $bcDom = new DOMDocument();
    if (!@$bcDom->loadXML($bcSvg) || $bcDom->documentElement?->nodeName !== 'svg') $bcBadCovers[] = $bcBook['slug'];
    $bcEpub = BookshopEpubWriter::build($bcBook + ['gutenberg_url' => 'https://www.gutenberg.org/ebooks/search/?query=x'], 'Demo Shop');
    if (!str_starts_with($bcEpub, "PK\x03\x04") || substr($bcEpub, 30, 8) !== 'mimetype' || substr($bcEpub, 38, 20) !== 'application/epub+zip') $bcBadEpubs[] = $bcBook['slug'];
}
assert_equals([], $bcBadCovers, 'Bookshop catalog: every cover is a well-formed SVG');
assert_equals([], $bcBadEpubs, 'Bookshop catalog: every EPUB starts with the stored mimetype entry');
assert_equals(BookshopCover::svg('x', 'T', 'A', 'mystery', 'S'), BookshopCover::svg('x', 'T', 'A', 'mystery', 'S'), 'Bookshop catalog: covers are deterministic');

if (class_exists(ZipArchive::class)) {
    $bcSample = $bcBooks[0];
    $bcPath = tempnam(sys_get_temp_dir(), 'bk-epub');
    file_put_contents($bcPath, BookshopEpubWriter::build($bcSample + ['gutenberg_url' => 'https://www.gutenberg.org/'], 'Demo Shop'));
    $bcZip = new ZipArchive();
    $bcOpened = $bcZip->open($bcPath, ZipArchive::CHECKCONS) === true;
    $bcXml = true;
    if ($bcOpened) {
        for ($bcI = 0; $bcI < $bcZip->numFiles; $bcI++) {
            $bcName = (string)$bcZip->getNameIndex($bcI);
            if (preg_match('/\.(xhtml|opf|xml)$/', $bcName) && !@(new DOMDocument())->loadXML((string)$bcZip->getFromIndex($bcI))) $bcXml = false;
        }
        $bcOpf = (string)$bcZip->getFromName('OEBPS/content.opf');
        $bcZip->close();
    }
    @unlink($bcPath);
    assert_true($bcOpened && $bcXml && str_contains($bcOpf ?? '', htmlspecialchars($bcSample['title'], ENT_QUOTES | ENT_XML1)),
        'Bookshop catalog: an EPUB passes the zip consistency check, its XML parses and the package names the book');
}
