<?php
$pdo ??= test_commerce_fixture();

// Review round 16 (R44, low 4): R42 kept a title joined to the next word ("Dr.Max s.r.o." is the company Dr.Max), and so a
// sole trader typing "Ing.Ján Novák" (no space after the title) no longer matched the VIES holder "Novák Jan": reverse
// charge was refused to a legitimate business customer. Joined to a person's name (no legal form, first name and
// surname after it) the title is dropped again; "Dr.Max s.r.o." and "Dr.Max" alone stay companies.
echo "\n--- R44 VAT: a title joined to a person's name ---\n";

$pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'CZ4444%' OR vat_id LIKE 'DE4444%'");
try {
    $r44vCache = $pdo->prepare("INSERT INTO vat_id_cache (vat_id, country, status, trader_name, checked_at, ttl_seconds) VALUES (?, ?, 'valid', ?, ?, 86400)");
    $r44vCache->execute(['CZ4444444444', 'CZ', 'Novák Jan', gmdate('Y-m-d H:i:s')]);
    $r44vCache->execute(['DE444444444', 'DE', 'Max Mustermann', gmdate('Y-m-d H:i:s')]);
    $r44vGot = [];
    foreach (['CZ4444444444' => ['Ing.Ján Novák', 'Ing.Jan Novak', 'Mgr.Ing.Jan Novák', 'Ing.Petr Novák', 'Ing. Ján Novák'],
        'DE444444444' => ['Dr.Max s.r.o.', 'Dr.Max', 'Dr.Max Mustermann', 'Dr.Max Mustermann GmbH']] as $r44vId => $r44vNames) {
        foreach ($r44vNames as $r44vName) $r44vGot[$r44vName] = VatService::vatIdHolderMismatch($r44vId, $r44vName);
    }
    assert_equals(['Ing.Ján Novák' => null, 'Ing.Jan Novak' => null, 'Mgr.Ing.Jan Novák' => null, 'Ing.Petr Novák' => 'name', 'Ing. Ján Novák' => null,
        'Dr.Max s.r.o.' => 'name', 'Dr.Max' => 'name', 'Dr.Max Mustermann' => null, 'Dr.Max Mustermann GmbH' => 'name'], $r44vGot,
        'R44 VIES holder: "Ing.Ján Novák" is Jan Novák (was: name mismatch); another person, "Dr.Max s.r.o." and "Dr.Max" still do not match');
} catch (Throwable $r44vE) {
    assert_true(false, 'R44 VAT name (' . get_class($r44vE) . ': ' . $r44vE->getMessage() . ' @ ' . basename($r44vE->getFile()) . ':' . $r44vE->getLine() . ')');
} finally {
    $pdo->exec("DELETE FROM vat_id_cache WHERE vat_id LIKE 'CZ4444%' OR vat_id LIKE 'DE4444%'");
}
