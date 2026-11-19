<?php
$pdo ??= test_commerce_fixture();

// Review round 18 (R48, low 3): R47's business words kept a joined title in a company name, but
// - the list missed trades: "Doc.Morris Garages" still matched the holder "Morris Garages";
// - a title written with a space still went: "Dr. Max Holding" matched "Max Holding";
// - "handel" is a surname too: the person "Dr.Peter Handel" no longer matched "Peter Handel" (VAT charged needlessly).
echo "\n--- R48 VAT: business names keep spaced titles, more trades, no surname as a business word ---\n";

$pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'DE4848%'");
try {
    $r48vCache = $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, 'DE', 'valid', ?, ?, 86400)");
    $r48vCache->execute(['DE484848481', 'Morris Garages', gmdate('Y-m-d H:i:s')]);
    $r48vCache->execute(['DE484848482', 'Max Holding', gmdate('Y-m-d H:i:s')]);
    $r48vCache->execute(['DE484848483', 'Peter Handel', gmdate('Y-m-d H:i:s')]);
    $r48vCache->execute(['DE484848484', 'Dr. Max Holding', gmdate('Y-m-d H:i:s')]);
    $r48vGot = [];
    foreach (['DE484848481' => ['Doc.Morris Garages', 'Doc. Morris Garages', 'Morris Garages'],
        'DE484848482' => ['Dr. Max Holding', 'Dr.Max Holding', 'Max Holding'],
        'DE484848483' => ['Dr.Peter Handel', 'Dr. Peter Handel', 'Handel Peter'],
        'DE484848484' => ['Dr. Max Holding', 'Dr.Max Holding']] as $r48vId => $r48vNames) {
        foreach ($r48vNames as $r48vName) $r48vGot[$r48vId . ' ' . $r48vName] = VatService::vatIdHolderMismatch($r48vId, $r48vName);
    }
    assert_equals([
        'DE484848481 Doc.Morris Garages' => 'name', 'DE484848481 Doc. Morris Garages' => 'name', 'DE484848481 Morris Garages' => null,
        'DE484848482 Dr. Max Holding' => 'name', 'DE484848482 Dr.Max Holding' => 'name', 'DE484848482 Max Holding' => null,
        'DE484848483 Dr.Peter Handel' => null, 'DE484848483 Dr. Peter Handel' => null, 'DE484848483 Handel Peter' => null,
        'DE484848484 Dr. Max Holding' => null, 'DE484848484 Dr.Max Holding' => null,
    ], $r48vGot, 'R48 VIES holder: "Doc.Morris Garages" and "Dr. Max Holding" are not the holders without the title (were: match); the person "Dr.Peter Handel" is "Peter Handel" (was: mismatch)');
} catch (Throwable $r48vE) {
    assert_true(false, 'R48 VAT name (' . get_class($r48vE) . ': ' . $r48vE->getMessage() . ' @ ' . basename($r48vE->getFile()) . ':' . $r48vE->getLine() . ')');
} finally {
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'DE4848%'");
}
