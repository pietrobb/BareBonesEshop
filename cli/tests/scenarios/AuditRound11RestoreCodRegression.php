<?php

// Round 11 review (2026-11-01) hotfix: H3 a restore attempt must not cost the backups it restores from (snapshot of a
// refused attempt, GC pushing scheduled backups out for snapshots, --latest "restoring" a snapshot of live); H4 the
// lines of an order with a planned or sent shipment (COD partially shipped) cannot be replaced.
echo "\n--- Audit round 11 (hotfix): restore snapshots and backup retention ---\n";

$r11rSnap = 'pre-restore-snapshot';
$r11rRoot = sys_get_temp_dir() . '/bbe-r11r-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($r11rRoot, 0700, true);
$r11rLivePath = $r11rRoot . '/live.db';
$r11rLive = new PDO('sqlite:' . $r11rLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r11rLive->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL)');
$r11rLive->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY)');
$r11rFail = static function (string $what, Throwable $e): void {
    assert_true(false, "R11 {$what} (" . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . ')');
};
$r11rPaths = static fn(?string $label = null): array => array_values(array_map(static fn(array $row): string => (string)$row['path'],
    array_filter(BackupService::list(), static fn(array $row): bool => $label === null || (string)($row['meta']['label'] ?? '') === $label)));
try {
    // GC after every backup; the rotation keeps two backups.
    BackupService::init($r11rLive, [
        'enabled' => true, 'compression' => false, 'auto_gc_on_backup' => true, 'wal_checkpoint' => true,
        'retention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1, 'minimum_kept' => 2],
        'log_file' => 'backup.log', 'storage_dir' => $r11rRoot . '/storage',
    ], $r11rRoot);
    $r11rLive->exec("INSERT INTO notes (body) VALUES ('first')");
    $r11rFirst = (string)BackupService::create(['label' => 'scheduled'])['remote_path'];
    $r11rLive->exec("INSERT INTO notes (body) VALUES ('second')");
    $r11rSecond = (string)BackupService::create(['label' => 'scheduled'])['remote_path'];
    $r11rLive->exec("INSERT INTO notes (body) VALUES ('live only')");
    $r11rLive = null; // BackupService holds the only connection: restore() must be able to close it

    // A refused attempt (another maintenance holds the database) leaves no snapshot and costs no backup.
    try {
        SqliteMaintenance::enter($r11rLivePath, 'R11 test: another maintenance');
        $r11rRefusals = 0;
        for ($i = 0; $i < 3; $i++) {
            try {
                BackupService::restore($r11rFirst);
            } catch (Throwable $e) {
                $r11rRefusals++;
            }
        }
    } finally {
        SqliteMaintenance::leave($r11rLivePath);
    }
    assert_equals(3, $r11rRefusals, 'R11 H3: the restore is refused while another maintenance holds the database');
    assert_equals([], $r11rPaths($r11rSnap), 'R11 H3: a refused restore leaves no pre-restore snapshot');
    assert_equals([$r11rSecond, $r11rFirst], $r11rPaths(), 'R11 H3: refused restores cost no scheduled backup');

    // A restore that happens keeps its snapshot, but the snapshot takes no rotation slot from a scheduled backup.
    $r11rRestored = BackupService::restore($r11rFirst);
    $r11rSnapshot = (string)($r11rRestored['snapshot'] ?? '');
    $r11rNotes = (new PDO('sqlite:' . $r11rLivePath))->query('SELECT body FROM notes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    assert_equals(['first'], $r11rNotes, 'R11 H3: the restore brings back the backup');
    assert_true($r11rSnapshot !== '' && in_array($r11rSnapshot, $r11rPaths($r11rSnap), true), 'R11 H3: the restore keeps the snapshot of the replaced live');
    assert_equals([$r11rSecond, $r11rFirst], $r11rPaths('scheduled'), 'R11 H3: the restored backup and its neighbour are still there after the restore');
    BackupService::gc();
    assert_equals([$r11rSecond, $r11rFirst], $r11rPaths('scheduled'), 'R11 H3: GC keeps the two scheduled backups — the snapshot is not counted in the rotation');
    assert_true(in_array($r11rSnapshot, $r11rPaths(), true), 'R11 H3: GC keeps the snapshot by its own rule');
    $r11rLatest = method_exists('BackupService', 'latestRestorePoint') ? BackupService::latestRestorePoint() : null;
    assert_equals($r11rSecond, (string)($r11rLatest['path'] ?? ''), 'R11 H3: --latest picks the newest real backup, never the newer snapshot of live');
    $r11rCli = (string)file_get_contents(dirname(TEST_CLI_DIR) . '/cli/restore.php');
    assert_true(str_contains($r11rCli, 'BackupService::latestRestorePoint()'), 'R11 H3: cli/restore.php --latest uses that restore point');

    // Restoring an old snapshot while five newer ones exist: the prune of this restore does not delete its source.
    BackupService::reset();
    BackupService::init($r11rLive = new PDO('sqlite:' . $r11rLivePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]), [
        'enabled' => true, 'compression' => false, 'auto_gc_on_backup' => true, 'wal_checkpoint' => true,
        'retention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1, 'minimum_kept' => 2],
        'log_file' => 'backup.log', 'storage_dir' => $r11rRoot . '/storage',
    ], $r11rRoot);
    for ($i = 0; $i < 5; $i++) BackupService::create(['label' => $r11rSnap, 'trigger' => 'auto']);
    $r11rOldSnapshots = $r11rPaths($r11rSnap);
    $r11rOldest = (string)end($r11rOldSnapshots);
    $r11rLive = null;
    BackupService::restore($r11rOldest);
    assert_true(in_array($r11rOldest, $r11rPaths(), true), 'R11 H3: restoring an old snapshot does not delete it while it is being restored');
    assert_equals([$r11rSecond, $r11rFirst], $r11rPaths('scheduled'), 'R11 H3: snapshots never push scheduled backups out');
} catch (Throwable $e) {
    $r11rFail('H3', $e);
} finally {
    BackupService::reset();
    $r11rLive = null;
    if (function_exists('updateTestRemoveTree')) updateTestRemoveTree($r11rRoot);
}

echo "\n--- Audit round 11 (hotfix): no line edit of an order with a shipment ---\n";
try {
    ConsentService::setDefinitionsForTesting([]);
    Security::setRateDbForTesting(new PDO('sqlite::memory:'));
    $r11rWarehouse = (int)$pdo->query("SELECT id FROM warehouses WHERE store_id = 'default' ORDER BY id LIMIT 1")->fetchColumn();
    $r11rProduct = $pdo->prepare("INSERT INTO products (store_id, category_id, name, slug, sku, product_type, price, currency, status, stock, inventory_policy)
        VALUES ('default', 1, :name, :slug, :sku, 'simple', :price, 'eur', 'active', :stock, 'deny')");
    $r11rStock = $pdo->prepare("INSERT INTO warehouse_stock (store_id, warehouse_id, sku, qty, reserved) VALUES ('default', :wh, :sku, :qty, 0)");
    foreach ([['R11R-A', 1000], ['R11R-B', 500]] as [$r11rSku, $r11rPrice]) {
        $r11rProduct->execute([':name' => $r11rSku . ' item', ':slug' => strtolower($r11rSku), ':sku' => $r11rSku, ':price' => $r11rPrice, ':stock' => 10]);
        $r11rStock->execute([':wh' => $r11rWarehouse, ':sku' => $r11rSku, ':qty' => 10]);
    }
    $r11rLines = static fn(int $orderId): array => array_map(static fn(array $i): string => $i['sku'] . '×' . $i['quantity'], Orders::items($orderId));
    $r11rBuyer = ['name' => 'R11 COD Buyer', 'email' => 'r11r-cod@example.com', 'phone' => '+421900000011', 'country' => 'SK',
        'street' => 'Dobierková 1', 'city' => 'Bratislava', 'zip' => '81101', 'shipping_method' => 'packeta', 'payment_method' => 'cod', 'locale' => 'sk'];
    $r11rCreated = ManualOrder::create(ManualOrder::parseLines("R11R-A 1\nR11R-B 1")['lines'], $r11rBuyer, false);
    $r11rOrderId = (int)($r11rCreated['order_id'] ?? 0);
    assert_true($r11rCreated['ok'] && $r11rOrderId > 0, 'R11 H4: a COD order is entered (' . ($r11rCreated['error'] ?? 'ok') . ' ' . ($r11rCreated['detail'] ?? '') . ')');
    $r11rItems = Orders::items($r11rOrderId);
    $r11rItemA = array_values(array_filter($r11rItems, static fn(array $i): bool => $i['sku'] === 'R11R-A'))[0];
    $r11rShippable = Fulfillment::shippable($r11rOrderId);
    $r11rShipment = Fulfillment::createShipment($r11rOrderId, $r11rShippable[0]['warehouse_id'] ?? null,
        [['order_item_id' => (int)$r11rItemA['id'], 'sku' => 'R11R-A', 'quantity' => 1]], 'r11r-ship-' . $r11rOrderId);
    assert_true(Fulfillment::ship($r11rShipment, 'packeta', 'R11R-TRACK'), 'R11 H4: item A leaves in a first shipment');
    $r11rTotal = (int)(Orders::byId($r11rOrderId)['total'] ?? 0);
    $r11rShipLines = static fn(int $shipmentId): array => array_map(static fn(array $l): string => $l['order_item_id'] . ':' . $l['sku'] . '×' . $l['quantity'],
        Fulfillment::shipmentItems([$shipmentId])[$shipmentId] ?? []);
    $r11rShipBefore = $r11rShipLines($r11rShipment);

    $r11rEdit = ManualOrder::editItems($r11rOrderId, ManualOrder::parseLines('R11R-B 1')['lines']);
    assert_equals([false, 'admin.order.edit.shipment'], [$r11rEdit['ok'], $r11rEdit['error']], 'R11 H4: the lines of a partially shipped COD order cannot be replaced');
    assert_equals([['R11R-A×1', 'R11R-B×1'], $r11rTotal, $r11rShipBefore], [$r11rLines($r11rOrderId), (int)(Orders::byId($r11rOrderId)['total'] ?? 0), $r11rShipLines($r11rShipment)],
        'R11 H4: the shipped item stays billed, the COD total and the shipment lines are unchanged');
    assert_true(I18n::t('admin.order.edit.shipment') !== 'admin.order.edit.shipment', 'R11 H4: the refusal is explained to the admin');

    // Only a planned shipment that was cancelled again frees the lines.
    $r11rPlanned = ManualOrder::create(ManualOrder::parseLines('R11R-A 1')['lines'], $r11rBuyer, false);
    $r11rPlannedId = (int)($r11rPlanned['order_id'] ?? 0);
    $r11rPlannedItem = Orders::items($r11rPlannedId)[0] ?? [];
    $r11rPlannedShipment = Fulfillment::createShipment($r11rPlannedId, Fulfillment::shippable($r11rPlannedId)[0]['warehouse_id'] ?? null,
        [['order_item_id' => (int)($r11rPlannedItem['id'] ?? 0), 'sku' => 'R11R-A', 'quantity' => 1]], 'r11r-plan-' . $r11rPlannedId);
    assert_equals('admin.order.edit.shipment', ManualOrder::editItems($r11rPlannedId, ManualOrder::parseLines('R11R-B 1')['lines'])['error'],
        'R11 H4: a planned shipment blocks the edit too');
    Fulfillment::cancel($r11rPlannedShipment);
    $r11rFreed = ManualOrder::editItems($r11rPlannedId, ManualOrder::parseLines('R11R-B 1')['lines']);
    assert_true($r11rFreed['ok'] && $r11rLines($r11rPlannedId) === ['R11R-B×1'], 'R11 H4: after the shipment is cancelled the lines can be changed (' . ($r11rFreed['error'] ?? 'ok') . ')');
    foreach ([$r11rOrderId, $r11rPlannedId] as $r11rCleanup) {
        if ($r11rCleanup > 0 && (string)(Orders::byId($r11rCleanup)['status'] ?? '') === 'pending') OrderWorkflow::transition($r11rCleanup, 'cancelled', 'R11 H4 test cleanup');
    }
} catch (Throwable $e) {
    $r11rFail('H4', $e);
}
