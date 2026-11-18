<?php
$pdo ??= test_commerce_fixture();

// Review round 17 (R47, low 4): R44 dropped a title joined to a person's name, but
// - a joined two-word title ("Ing.arch.Ján Novák") lost only "Ing.", so "arch" stayed a word of the name: no match;
// - a joined title went from a company name without a legal form too: "Dr.Max Holding" matched the holder "Max Holding".
// A name that carries a business word (holding, group, trade, ...) is a company: its joined title stays part of it.
echo "\n--- R47 VAT: joined two-word titles, and joined titles of company names ---\n";

$pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'CZ4747%' OR vat_id LIKE 'DE4747%'");
try {
    $r47vCache = $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, ?, 'valid', ?, ?, 86400)");
    $r47vCache->execute(['CZ4747474747', 'CZ', 'Novák Jan', gmdate('Y-m-d H:i:s')]);
    $r47vCache->execute(['DE474747474', 'DE', 'Max Holding', gmdate('Y-m-d H:i:s')]);
    $r47vCache->execute(['DE474747475', 'DE', 'Dr.Max Holding', gmdate('Y-m-d H:i:s')]);
    $r47vCache->execute(['DE474747476', 'DE', 'Max Mustermann', gmdate('Y-m-d H:i:s')]);
    $r47vGot = [];
    foreach (['CZ4747474747' => ['Ing.arch.Ján Novák', 'Ing.Arch.Jan Novak', 'Mgr.art.Jan Novák', 'Ing. arch.Ján Novák', 'Ing.arch.Petr Novák'],
        'DE474747474' => ['Dr.Max Holding', 'Dr.Max Group', 'Max Holding'],
        'DE474747475' => ['Dr.Max Holding'],
        'DE474747476' => ['Dr.Max Mustermann']] as $r47vId => $r47vNames) {
        foreach ($r47vNames as $r47vName) $r47vGot[$r47vId . ' ' . $r47vName] = VatService::vatIdHolderMismatch($r47vId, $r47vName);
    }
    assert_equals([
        'CZ4747474747 Ing.arch.Ján Novák' => null, 'CZ4747474747 Ing.Arch.Jan Novak' => null, 'CZ4747474747 Mgr.art.Jan Novák' => null,
        'CZ4747474747 Ing. arch.Ján Novák' => null, 'CZ4747474747 Ing.arch.Petr Novák' => 'name',
        'DE474747474 Dr.Max Holding' => 'name', 'DE474747474 Dr.Max Group' => 'name', 'DE474747474 Max Holding' => null,
        'DE474747475 Dr.Max Holding' => null,
        'DE474747476 Dr.Max Mustermann' => null,
    ], $r47vGot, 'R47 VIES holder: "Ing.arch.Ján Novák" is Jan Novák (was: name mismatch); "Dr.Max Holding" is not "Max Holding" (was: match); a person keeps R44\'s rule');
} catch (Throwable $r47vE) {
    assert_true(false, 'R47 VAT name (' . get_class($r47vE) . ': ' . $r47vE->getMessage() . ' @ ' . basename($r47vE->getFile()) . ':' . $r47vE->getLine() . ')');
} finally {
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'CZ4747%' OR vat_id LIKE 'DE4747%'");
}
