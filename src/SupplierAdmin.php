<?php
/**
 * A12.7: admin suppliers, their catalog offers (supplier_products) and purchase orders of the active store.
 * Orders are created by Inventory::createPurchaseOrder (store supplier and warehouse, catalog SKUs, minimum and pack size)
 * and received by Inventory::receivePurchaseOrder, which books the stock ledger idempotently: a receipt form carries one
 * key, so a resubmitted form is a no-op. Every write is audited.
 * Write methods return ['ok' => bool, 'id' => int, 'message' => flash text].
 */
final class SupplierAdmin {
    private const MAX_TEXT = 191;
    private const MAX_INT = 2000000000;
    private const MAX_LINES = 2000;
    public const STATUSES = ['ordered' => 'objednaná', 'partially_received' => 'čiastočne prijatá', 'received' => 'prijatá', 'draft' => 'koncept'];

    public static function suppliers(): array {
        return self::storeRows('SELECT s.*, (SELECT COUNT(*) FROM supplier_products sp WHERE sp.supplier_id = s.id) AS product_count,
                (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id AND po.store_id = s.store_id) AS order_count
            FROM suppliers s WHERE s.store_id = :store ORDER BY s.name, s.code');
    }

    public static function supplier(int $id): ?array {
        $row = self::storeRows('SELECT * FROM suppliers WHERE id = :id AND store_id = :store', [':id' => $id])[0] ?? null;
        if ($row !== null) $row['contact'] = json_decode((string)$row['contact'], true) ?: [];
        return $row;
    }

    public static function saveSupplier(int $id, mixed $post): array {
        $old = $id > 0 ? self::supplier($id) : null;
        if ($id > 0 && !$old) return self::result(false, 0, 'Dodávateľ neexistuje v tomto obchode.');
        $fields = self::scalars($post, ['code', 'name', 'email', 'phone', 'note', 'active']);
        if ($fields === null) return self::result(false, $id, 'Neplatný formulár.');
        try {
            $name = self::text($fields['name'], true, 'Názov');
            $code = Str::slug(trim($fields['code']) !== '' ? $fields['code'] : $name);
            if ($code === '' || strlen($code) > self::MAX_TEXT) self::fail('Kód musí obsahovať písmená alebo číslice (najviac ' . self::MAX_TEXT . ' znakov).');
            if (self::storeRows('SELECT 1 AS taken FROM suppliers WHERE store_id = :store AND code = :code AND id != :id', [':code' => $code, ':id' => $id ?: -1])) {
                self::fail("Kód dodávateľa \"{$code}\" už v tomto obchode existuje.");
            }
            $email = trim($fields['email']);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) self::fail('E-mail dodávateľa nie je platný.');
            $contact = array_filter(['email' => $email, 'phone' => self::text($fields['phone'], false, 'Telefón'), 'note' => self::text($fields['note'], false, 'Poznámka')],
                static fn(string $v): bool => $v !== '');
        } catch (DomainException $e) {
            return self::result(false, $id, $e->getMessage());
        }
        $row = ['code' => $code, 'name' => $name, 'contact' => json_encode((object)$contact, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'active' => self::flag($fields['active'])];
        $db = Db::get();
        $db->beginTransaction();
        try {
            $params = [':code' => $row['code'], ':name' => $row['name'], ':contact' => $row['contact'], ':active' => $row['active'], ':store' => StoreContext::current()->id()];
            if ($old) {
                $db->prepare('UPDATE suppliers SET code = :code, name = :name, contact = :contact, active = :active WHERE id = :id AND store_id = :store')->execute($params + [':id' => $id]);
            } else {
                $db->prepare('INSERT INTO suppliers (store_id, code, name, contact, active) VALUES (:store, :code, :name, :contact, :active)')->execute($params);
                $id = (int)$db->lastInsertId();
            }
            AuditLog::log('supplier', $id, $old, $row, 'Admin supplier');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) return self::result(false, $old ? $id : 0, "Kód dodávateľa \"{$row['code']}\" už v tomto obchode existuje.");
            throw $e;
        }
        return self::result(true, $id, $old ? 'Dodávateľ uložený.' : 'Dodávateľ vytvorený.');
    }

    public static function deleteSupplier(int $id): array {
        $supplier = $id > 0 ? self::supplier($id) : null;
        if (!$supplier) return self::result(false, 0, 'Dodávateľ neexistuje v tomto obchode.');
        $orders = (int)(self::storeRows('SELECT COUNT(*) AS n FROM purchase_orders WHERE store_id = :store AND supplier_id = :id', [':id' => $id])[0]['n'] ?? 0);
        if ($orders > 0) return self::result(false, $id, "Dodávateľa {$supplier['code']} nemožno zmazať: má nákupné objednávky ({$orders}); deaktivujte ho.");
        $db = Db::get();
        $db->beginTransaction();
        try {
            $offers = self::offerRows($id);
            $db->prepare('DELETE FROM supplier_products WHERE supplier_id = :id')->execute([':id' => $id]);
            $db->prepare('DELETE FROM suppliers WHERE id = :id AND store_id = :store')->execute([':id' => $id, ':store' => StoreContext::current()->id()]);
            AuditLog::log('supplier', $id, $supplier + ['products' => $offers], null, 'Admin supplier delete');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::result(true, 0, 'Dodávateľ zmazaný.');
    }

    /** Offer lines "SKU | kód dodávateľa | nákupná cena | min. množstvo | balenie | dodacia lehota". */
    public static function offerLines(int $supplierId): string {
        return implode("\n", array_map(static fn(array $r): string => implode(' | ', [$r['sku'], (string)($r['supplier_sku'] ?? ''), (string)$r['cost_price'],
            (string)$r['min_order_qty'], (string)$r['pack_size'], (string)$r['lead_time_days']]), self::offerRows($supplierId)));
    }

    /** Replace the supplier's offers from editor lines; SKUs must be products or variants of the store's catalog. */
    public static function saveOffers(int $supplierId, mixed $post): array {
        $supplier = $supplierId > 0 ? self::supplier($supplierId) : null;
        if (!$supplier) return self::result(false, 0, 'Dodávateľ neexistuje v tomto obchode.');
        $fields = self::scalars($post, ['products']);
        if ($fields === null) return self::result(false, $supplierId, 'Neplatný formulár.');
        $lines = self::lines($fields['products']);
        if (count($lines) > self::MAX_LINES) return self::result(false, $supplierId, 'Najviac ' . self::MAX_LINES . ' riadkov.');
        $rows = [];
        try {
            foreach ($lines as $n => $line) {
                $label = 'Riadok ' . ($n + 1);
                $p = array_map('trim', explode('|', $line));
                if (count($p) < 3 || count($p) > 6) self::fail("{$label}: zadajte SKU | kód dodávateľa | nákupná cena v centoch [| min. množstvo | balenie | dodacia lehota v dňoch].");
                $sku = self::catalogSku($p[0], $label);
                if (isset($rows[$sku])) self::fail("{$label}: SKU {$sku} je uvedené dvakrát.");
                $rows[$sku] = [
                    'sku' => $sku, 'supplier_sku' => $p[1] === '' ? null : self::text($p[1], false, "{$label}: kód dodávateľa"),
                    'cost_price' => self::int($p[2], 0, self::MAX_INT, "{$label}: nákupná cena musí byť nezáporná suma v centoch."),
                    'min_order_qty' => self::int(($p[3] ?? '') === '' ? '1' : $p[3], 1, 1000000, "{$label}: minimálne množstvo musí byť 1 až 1000000."),
                    'pack_size' => self::int(($p[4] ?? '') === '' ? '1' : $p[4], 1, 1000000, "{$label}: balenie musí byť 1 až 1000000."),
                    'lead_time_days' => self::int(($p[5] ?? '') === '' ? '0' : $p[5], 0, 365, "{$label}: dodacia lehota musí byť 0 až 365 dní."),
                ];
            }
        } catch (DomainException $e) {
            return self::result(false, $supplierId, $e->getMessage());
        }
        $db = Db::get();
        $db->beginTransaction();
        try {
            $before = self::offerRows($supplierId);
            $db->prepare('DELETE FROM supplier_products WHERE supplier_id = :id')->execute([':id' => $supplierId]);
            $insert = $db->prepare('INSERT INTO supplier_products (supplier_id, sku, supplier_sku, cost_price, min_order_qty, pack_size, lead_time_days)
                VALUES (:supplier, :sku, :supplier_sku, :cost_price, :min_order_qty, :pack_size, :lead_time_days)');
            foreach ($rows as $row) $insert->execute([':supplier' => $supplierId] + array_combine(array_map(static fn(string $k): string => ":{$k}", array_keys($row)), $row));
            AuditLog::log('supplier', $supplierId, ['products' => $before], ['products' => array_values($rows)], 'Admin supplier products');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::result(true, $supplierId, 'Ponuka dodávateľa uložená (' . count($rows) . ').');
    }

    public static function purchaseOrders(): array {
        return self::storeRows('SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name,
                (SELECT COALESCE(SUM(quantity), 0) FROM purchase_order_items i WHERE i.purchase_order_id = po.id) AS ordered_qty,
                (SELECT COALESCE(SUM(received_qty), 0) FROM purchase_order_items i WHERE i.purchase_order_id = po.id) AS received_qty,
                (SELECT COALESCE(SUM(quantity * unit_cost), 0) FROM purchase_order_items i WHERE i.purchase_order_id = po.id) AS total_cost
            FROM purchase_orders po LEFT JOIN suppliers s ON s.id = po.supplier_id LEFT JOIN warehouses w ON w.id = po.warehouse_id
            WHERE po.store_id = :store ORDER BY po.id DESC');
    }

    public static function purchaseOrder(int $id): ?array {
        $order = self::storeRows('SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id = po.supplier_id LEFT JOIN warehouses w ON w.id = po.warehouse_id WHERE po.id = :id AND po.store_id = :store', [':id' => $id])[0] ?? null;
        if ($order === null) return null;
        $stmt = Db::get()->prepare('SELECT id, sku, quantity, received_qty, unit_cost FROM purchase_order_items WHERE purchase_order_id = :id ORDER BY sku');
        $stmt->execute([':id' => $id]);
        return $order + ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public static function createOrder(mixed $post): array {
        $fields = self::scalars($post, ['supplier_id', 'warehouse_id', 'number', 'expected_at', 'items']);
        if ($fields === null) return self::result(false, 0, 'Neplatný formulár.');
        $items = [];
        try {
            $supplier = ctype_digit($fields['supplier_id']) ? self::supplier((int)$fields['supplier_id']) : null;
            if (!$supplier) self::fail('Dodávateľ nepatrí do tohto obchodu.');
            if (!$supplier['active']) self::fail("Dodávateľ {$supplier['code']} je neaktívny.");
            $warehouse = ctype_digit($fields['warehouse_id']) ? Warehouse::byId((int)$fields['warehouse_id']) : null;
            if (!$warehouse) self::fail('Sklad nepatrí do tohto obchodu.');
            $number = trim($fields['number']) === '' ? 'PO-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2))) : trim($fields['number']);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/-]{0,63}$/', $number)) self::fail('Číslo objednávky: 1 až 64 znakov A–Z, 0–9, _ . / -.');
            if (self::storeRows('SELECT 1 AS taken FROM purchase_orders WHERE store_id = :store AND number = :number', [':number' => $number])) self::fail("Objednávka {$number} už existuje.");
            $expected = trim($fields['expected_at']);
            if ($expected !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expected, new DateTimeZone('UTC'));
                if ($date === false || $date->format('Y-m-d') !== $expected) self::fail('Očakávaný dátum musí byť RRRR-MM-DD.');
                $expected = $date->format('Y-m-d H:i:s');
            }
            $offers = array_column(self::offerRows((int)$supplier['id']), null, 'sku');
            foreach (self::lines($fields['items']) as $n => $line) {
                $label = 'Riadok ' . ($n + 1);
                $p = array_map('trim', explode('|', $line));
                if (count($p) < 2 || count($p) > 3) self::fail("{$label}: zadajte SKU | množstvo [| nákupná cena v centoch].");
                $offer = $offers[$p[0]] ?? null;
                if ($offer === null) self::fail("{$label}: dodávateľ {$supplier['code']} nemá v ponuke SKU {$p[0]}.");
                $qty = self::int($p[1], 1, 1000000, "{$label}: množstvo musí byť 1 až 1000000.");
                if ($qty < (int)$offer['min_order_qty'] || $qty % max(1, (int)$offer['pack_size']) !== 0) {
                    self::fail("{$label}: {$p[0]} sa objednáva najmenej po {$offer['min_order_qty']} ks v baleniach po {$offer['pack_size']} ks.");
                }
                $items[] = ['sku' => $p[0], 'quantity' => $qty] + (($p[2] ?? '') === '' ? [] : ['unit_cost' => self::int($p[2], 0, self::MAX_INT, "{$label}: nákupná cena musí byť nezáporná suma v centoch.")]);
            }
            if (!$items) self::fail('Objednávka musí mať aspoň jednu položku.');
        } catch (DomainException $e) {
            return self::result(false, 0, $e->getMessage());
        }
        $db = Db::get();
        $db->beginTransaction();
        try {
            $id = Inventory::createPurchaseOrder((int)$supplier['id'], (int)$warehouse['id'], $number, $items, StoreContext::current()->currency(), $expected === '' ? null : $expected);
            AuditLog::log('purchase_order', $id, null, ['number' => $number, 'supplier_id' => (int)$supplier['id'], 'warehouse_id' => (int)$warehouse['id'], 'items' => $items], 'Admin purchase order');
            $db->commit();
        } catch (InvalidArgumentException $e) {
            $db->rollBack();
            return self::result(false, 0, 'Objednávku nemožno vytvoriť: ' . $e->getMessage());
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::result(true, $id, "Nákupná objednávka {$number} vytvorená.");
    }

    /**
     * Receive quantities per order line. The form's receipt_key makes a resubmission a no-op; each line is booked
     * through Inventory::receivePurchaseOrder into the order's warehouse and stock ledger, all lines in one transaction.
     */
    public static function receive(int $orderId, mixed $post): array {
        $order = $orderId > 0 ? self::purchaseOrder($orderId) : null;
        if (!$order) return self::result(false, 0, 'Nákupná objednávka neexistuje v tomto obchode.');
        $key = is_array($post) && is_scalar($post['receipt_key'] ?? null) ? trim((string)$post['receipt_key']) : '';
        $quantities = is_array($post) ? ($post['receive'] ?? []) : null;
        if (!preg_match('/^[a-f0-9]{16,64}$/', $key) || !is_array($quantities)) return self::result(false, $orderId, 'Neplatný formulár.');
        $items = array_column($order['items'], null, 'id');
        $receipts = [];
        try {
            foreach ($quantities as $itemId => $value) {
                if (!is_scalar($value) || !isset($items[(int)$itemId]) || (string)(int)$itemId !== (string)$itemId) self::fail('Neplatný formulár.');
                $item = $items[(int)$itemId];
                if (trim((string)$value) === '' || trim((string)$value) === '0') continue;
                $open = (int)$item['quantity'] - (int)$item['received_qty'];
                $receipts[] = [$item, self::int((string)$value, 1, max(1, $open), "{$item['sku']}: prijať možno 1 až {$open} ks (objednané {$item['quantity']}, prijaté {$item['received_qty']}).")];
            }
            if (!$receipts) self::fail('Zadajte prijímané množstvo aspoň pri jednej položke.');
        } catch (DomainException $e) {
            return self::result(false, $orderId, $e->getMessage());
        }
        $db = Db::get();
        $db->beginTransaction();
        try {
            $booked = [];
            foreach ($receipts as [$item, $qty]) {
                if (Inventory::receivePurchaseOrder($orderId, (string)$item['sku'], $qty, "admin:{$key}:{$item['id']}")) $booked[$item['sku']] = $qty;
            }
            if ($booked) AuditLog::log('purchase_order', $orderId, ['status' => $order['status']], ['status' => self::purchaseOrder($orderId)['status'], 'received' => $booked], 'Admin purchase order receipt');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            if ($e instanceof RuntimeException && !$e instanceof PDOException) return self::result(false, $orderId, 'Príjem nemožno zaúčtovať: ' . $e->getMessage());
            throw $e;
        }
        return $booked
            ? self::result(true, $orderId, 'Prijaté: ' . implode(', ', array_map(static fn(string $sku, int $q): string => "{$sku} {$q} ks", array_keys($booked), $booked)) . '.')
            : self::result(true, $orderId, 'Tento príjem už bol zaúčtovaný; nič sa nezmenilo.');
    }

    private static function offerRows(int $supplierId): array {
        $stmt = Db::get()->prepare('SELECT sku, supplier_sku, cost_price, min_order_qty, pack_size, lead_time_days FROM supplier_products WHERE supplier_id = :id ORDER BY sku');
        $stmt->execute([':id' => $supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @throws DomainException */
    private static function catalogSku(string $sku, string $label): string {
        if ($sku !== '' && CatalogScope::rows('SELECT 1 AS found FROM products WHERE sku = :sku AND store_id = :catalog
                UNION SELECT 1 AS found FROM variants v JOIN products p ON p.id = v.product_id WHERE v.sku = :variant_sku AND p.store_id = :catalog_variant',
                [':sku' => $sku, ':variant_sku' => $sku, ':catalog_variant' => StoreContext::current()->catalogId()])) {
            return $sku;
        }
        self::fail("{$label}: SKU \"{$sku}\" nie je produkt ani variant katalógu tohto obchodu.");
    }

    private static function lines(string $text): array {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text)), static fn(string $l): bool => $l !== ''));
    }

    private static function storeRows(string $sql, array $params = []): array {
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params + [':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @throws DomainException */
    private static function int(string $value, int $min, int $max, string $message): int {
        $value = trim($value);
        if (!preg_match('/^-?\d{1,10}$/', $value) || (int)$value < $min || (int)$value > $max) self::fail($message);
        return (int)$value;
    }

    /** @throws DomainException */
    private static function text(string $value, bool $required, string $label): string {
        $value = trim($value);
        if (($value === '' && $required) || mb_strlen($value) > self::MAX_TEXT || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            self::fail("{$label}: " . ($required ? 'povinné, ' : '') . 'najviac ' . self::MAX_TEXT . ' znakov, bez riadiacich znakov.');
        }
        return $value;
    }

    private static function flag(string $value): int {
        return $value !== '' && $value !== '0' ? 1 : 0;
    }

    /** @throws DomainException */
    private static function fail(string $message): never {
        throw new DomainException($message);
    }

    private static function result(bool $ok, int $id, string $message): array {
        return ['ok' => $ok, 'id' => $id, 'message' => $message];
    }

    /** The posted fields as strings (missing = ''), or null when the post or a field is not scalar. */
    private static function scalars(mixed $post, array $names): ?array {
        if (!is_array($post)) return null;
        $fields = [];
        foreach ($names as $name) {
            $value = $post[$name] ?? '';
            if (!is_scalar($value)) return null;
            $fields[$name] = (string)$value;
        }
        return $fields;
    }
}
