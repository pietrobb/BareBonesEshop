<?php

// Round 8 review (2026-10-21), unfixed: CSV import.
// - An empty price cell created the product at 0 € (and on an existing product reset its price to 0).
// - The status column took any text ("Aktívny", "deleted") and an empty cell reset a draft to active.
// - Cyrillic (and other non-Latin) names and categories slugified to "", so every such category was the same one.
echo "\n--- Audit round 8 (rest): CSV import price, status and non-Latin names ---\n";
$r8iRoot = test_fixture_path('r8-import');
mkdir($r8iRoot);
$r8iCsv = static function (string $name, string $content) use ($r8iRoot): string {
    file_put_contents($r8iRoot . '/' . $name, $content);
    return $r8iRoot . '/' . $name;
};
$r8iRow = static function (string $sku) use ($pdo): ?array {
    $stmt = $pdo->prepare('SELECT p.price, p.status, p.slug, c.slug AS category_slug, c.name AS category_name
        FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.sku = ?');
    $stmt->execute([$sku]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};
$r8iErrors = static fn(array $result, string $sku): array => array_values(array_filter($result['errors'], static fn(string $e): bool => str_contains($e, "SKU: {$sku})")));

try {
    // Price
    $r8iResult = Import::csv($r8iCsv('price.csv', "sku,name,price\nR8I-NOPRICE,No price,\nR8I-ZERO,Free sample,0\nR8I-PRICED,Priced,12.50\n"));
    assert_equals([null, 1], [$r8iRow('R8I-NOPRICE'), count($r8iErrors($r8iResult, 'R8I-NOPRICE'))],
        'R8 import: a new product with an empty price cell is refused with a row error (was created at 0)');
    assert_equals([0, 1250], [(int)($r8iRow('R8I-ZERO')['price'] ?? -1), (int)($r8iRow('R8I-PRICED')['price'] ?? -1)],
        'R8 import: an explicit 0 and a real price still import');
    $r8iResult = Import::csv($r8iCsv('price-update.csv', "sku,name,price\nR8I-PRICED,Priced renamed,\n"));
    assert_equals([[], 1250], [$r8iResult['errors'], (int)($r8iRow('R8I-PRICED')['price'] ?? -1)],
        'R8 import: an empty price cell on an existing product keeps its price (was reset to 0)');

    // Status
    $r8iResult = Import::csv($r8iCsv('status.csv', "sku,name,price,status\nR8I-ST-BAD,Bad status,1.00,Aktívny\nR8I-ST-DEL,Deleted,1.00,deleted\nR8I-ST-DRAFT,Draft,1.00,Draft\nR8I-ST-EMPTY,Empty,1.00,\n"));
    assert_equals([null, null, 1, 1], [$r8iRow('R8I-ST-BAD'), $r8iRow('R8I-ST-DEL'), count($r8iErrors($r8iResult, 'R8I-ST-BAD')), count($r8iErrors($r8iResult, 'R8I-ST-DEL'))],
        'R8 import: a status other than active/draft/archived refuses the row (was stored as is)');
    assert_equals(['draft', 'active'], [$r8iRow('R8I-ST-DRAFT')['status'] ?? null, $r8iRow('R8I-ST-EMPTY')['status'] ?? null],
        'R8 import: a known status in any case is stored lowercase; a new product with an empty status is active');
    $r8iResult = Import::csv($r8iCsv('status-update.csv', "sku,name,price,status\nR8I-ST-DRAFT,Draft,1.00,\nR8I-ST-EMPTY,Empty,1.00,Archived\n"));
    assert_equals([[], 'draft', 'archived'], [$r8iResult['errors'], $r8iRow('R8I-ST-DRAFT')['status'] ?? null, $r8iRow('R8I-ST-EMPTY')['status'] ?? null],
        'R8 import: an empty status cell keeps an existing draft hidden (was reset to active)');

    // Non-Latin names and categories
    $r8iResult = Import::csv($r8iCsv('cyrillic.csv', "sku,name,price,category\n"
        . "R8I-CY-1,Кофе Арабика,9.90,Напитки/Кофе\nR8I-CY-2,Чай зелёный,4.90,Напитки/Чай\nR8I-GR-1,Ελληνικό μέλι,7.00,Μέλι\nR8I-JP-1,日本茶,6.00,お茶\n"));
    $r8iCy1 = $r8iRow('R8I-CY-1') ?? [];
    $r8iCy2 = $r8iRow('R8I-CY-2') ?? [];
    assert_equals([[], 'kofe-arabika', 'chay-zelenyy'], [$r8iResult['errors'], $r8iCy1['slug'] ?? null, $r8iCy2['slug'] ?? null],
        'R8 import: Cyrillic product names get transliterated slugs (were empty)');
    assert_equals(['Кофе', 'Чай', 'kofe', 'chay'], [$r8iCy1['category_name'] ?? null, $r8iCy2['category_name'] ?? null, $r8iCy1['category_slug'] ?? null, $r8iCy2['category_slug'] ?? null],
        'R8 import: two Cyrillic categories stay two categories (the second was merged into the first)');
    assert_equals('elliniko-meli', $r8iRow('R8I-GR-1')['slug'] ?? null, 'R8 import: Greek names are transliterated too');
    $r8iJp = $r8iRow('R8I-JP-1') ?? [];
    assert_true(($r8iJp['slug'] ?? '') !== '' && ($r8iJp['category_slug'] ?? '') !== '' && ($r8iJp['category_name'] ?? '') === 'お茶',
        'R8 import: a name with no Latin transliteration still gets a non-empty slug, and its own category (' . json_encode($r8iJp, JSON_UNESCAPED_UNICODE) . ')');
} finally {
    $pdo->exec("DELETE FROM products WHERE sku LIKE 'R8I-%'");
    foreach (['Кофе', 'Чай', 'Напитки', 'Μέλι', 'お茶'] as $r8iCategory) { // children before their parent
        $pdo->prepare('DELETE FROM categories WHERE name = ? AND store_id = ?')->execute([$r8iCategory, 'default']);
    }
    foreach (glob($r8iRoot . '/*') ?: [] as $r8iFile) unlink($r8iFile);
    rmdir($r8iRoot);
}
