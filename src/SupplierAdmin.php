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
    /** Purchase order states; their labels are the lang keys admin.po.status.<state>. */
    public const STATUSES = ['ordered', 'partially_received', 'received', 'draft'];

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
        if ($id > 0 && !$old) return self::result(false, 0, I18n::t('admin.msg.supplier.missing'));
        $fields = self::scalars($post, ['code', 'name', 'email', 'phone', 'note', 'active']);
        if ($fields === null) return self::result(false, $id, I18n::t('admin.msg.invalid_form'));
        try {
            $name = self::text($fields['name'], true, I18n::t('admin.th.name'));
            $code = Str::slug(trim($fields['code']) !== '' ? $fields['code'] : $name);
            if ($code === '' || strlen($code) > self::MAX_TEXT) self::fail(I18n::format('admin.msg.code_invalid', ['max' => self::MAX_TEXT]));
            if (self::storeRows('SELECT 1 AS taken FROM suppliers WHERE store_id = :store AND code = :code AND id != :id', [':code' => $code, ':id' => $id ?: -1])) {
                self::fail(I18n::format('admin.msg.supplier.code_taken', ['code' => $code]));
            }
            $email = trim($fields['email']);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) self::fail(I18n::t('admin.msg.supplier.email'));
            $contact = array_filter(['email' => $email, 'phone' => self::text($fields['phone'], false, I18n::t('admin.customers.phone')), 'note' => self::text($fields['note'], false, I18n::t('admin.common.note'))],
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
            if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) return self::result(false, $old ? $id : 0, I18n::format('admin.msg.supplier.code_taken', ['code' => $row['code']]));
            throw $e;
        }
        return self::result(true, $id, I18n::t($old ? 'admin.msg.supplier.saved' : 'admin.msg.supplier.created'));
    }

    public static function deleteSupplier(int $id): array {
        $supplier = $id > 0 ? self::supplier($id) : null;
        if (!$supplier) return self::result(false, 0, I18n::t('admin.msg.supplier.missing'));
        $orders = (int)(self::storeRows('SELECT COUNT(*) AS n FROM purchase_orders WHERE store_id = :store AND supplier_id = :id', [':id' => $id])[0]['n'] ?? 0);
        if ($orders > 0) return self::result(false, $id, I18n::format('admin.msg.supplier.delete_blocked', ['code' => $supplier['code'], 'count' => $orders]));
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
        return self::result(true, 0, I18n::t('admin.msg.supplier.deleted'));
    }

    /** Offer lines "SKU | supplier code | purchase price | min. quantity | pack size | lead time". */
    public static function offerLines(int $supplierId): string {
        return implode("\n", array_map(static fn(array $r): string => implode(' | ', [$r['sku'], (string)($r['supplier_sku'] ?? ''), (string)$r['cost_price'],
            (string)$r['min_order_qty'], (string)$r['pack_size'], (string)$r['lead_time_days']]), self::offerRows($supplierId)));
    }

    /** Replace the supplier's offers from editor lines; SKUs must be products or variants of the store's catalog. */
    public static function saveOffers(int $supplierId, mixed $post): array {
        $supplier = $supplierId > 0 ? self::supplier($supplierId) : null;
        if (!$supplier) return self::result(false, 0, I18n::t('admin.msg.supplier.missing'));
        $fields = self::scalars($post, ['products']);
        if ($fields === null) return self::result(false, $supplierId, I18n::t('admin.msg.invalid_form'));
        $lines = self::lines($fields['products']);
        if (count($lines) > self::MAX_LINES) return self::result(false, $supplierId, I18n::format('admin.msg.supplier.max_lines', ['max' => self::MAX_LINES]));
        $rows = [];
        try {
            foreach ($lines as $n => $line) {
                $label = I18n::format('admin.msg.line', ['n' => $n + 1]);
                $p = array_map('trim', explode('|', $line));
                if (count($p) < 3 || count($p) > 6) self::fail(I18n::format('admin.msg.supplier.offer_format', ['line' => $label]));
                $sku = self::catalogSku($p[0], $label);
                if (isset($rows[$sku])) self::fail(I18n::format('admin.msg.supplier.offer_duplicate', ['line' => $label, 'sku' => $sku]));
                $rows[$sku] = [
                    'sku' => $sku, 'supplier_sku' => $p[1] === '' ? null : self::text($p[1], false, I18n::format('admin.msg.supplier.code_label', ['line' => $label])),
                    'cost_price' => self::int($p[2], 0, self::MAX_INT, I18n::format('admin.msg.supplier.cost', ['line' => $label])),
                    'min_order_qty' => self::int(($p[3] ?? '') === '' ? '1' : $p[3], 1, 1000000, I18n::format('admin.msg.min_qty_line', ['line' => $label])),
                    'pack_size' => self::int(($p[4] ?? '') === '' ? '1' : $p[4], 1, 1000000, I18n::format('admin.msg.supplier.pack', ['line' => $label])),
                    'lead_time_days' => self::int(($p[5] ?? '') === '' ? '0' : $p[5], 0, 365, I18n::format('admin.msg.supplier.lead_time', ['line' => $label])),
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
        return self::result(true, $supplierId, I18n::format('admin.msg.supplier.offer_saved', ['count' => count($rows)]));
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
        if ($fields === null) return self::result(false, 0, I18n::t('admin.msg.invalid_form'));
        $items = [];
        try {
            $supplier = ctype_digit($fields['supplier_id']) ? self::supplier((int)$fields['supplier_id']) : null;
            if (!$supplier) self::fail(I18n::t('admin.msg.po.supplier_foreign'));
            if (!$supplier['active']) self::fail(I18n::format('admin.msg.po.supplier_inactive', ['code' => $supplier['code']]));
            $warehouse = ctype_digit($fields['warehouse_id']) ? Warehouse::byId((int)$fields['warehouse_id']) : null;
            if (!$warehouse) self::fail(I18n::t('admin.msg.po.warehouse_foreign'));
            $number = trim($fields['number']) === '' ? 'PO-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2))) : trim($fields['number']);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/-]{0,63}$/', $number)) self::fail(I18n::t('admin.msg.po.number'));
            if (self::storeRows('SELECT 1 AS taken FROM purchase_orders WHERE store_id = :store AND number = :number', [':number' => $number])) self::fail(I18n::format('admin.msg.po.exists', ['number' => $number]));
            $expected = trim($fields['expected_at']);
            if ($expected !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expected, new DateTimeZone('UTC'));
                if ($date === false || $date->format('Y-m-d') !== $expected) self::fail(I18n::t('admin.msg.po.expected_date'));
                $expected = $date->format('Y-m-d H:i:s');
            }
            $offers = array_column(self::offerRows((int)$supplier['id']), null, 'sku');
            foreach (self::lines($fields['items']) as $n => $line) {
                $label = I18n::format('admin.msg.line', ['n' => $n + 1]);
                $p = array_map('trim', explode('|', $line));
                if (count($p) < 2 || count($p) > 3) self::fail(I18n::format('admin.msg.po.line_format', ['line' => $label]));
                $offer = $offers[$p[0]] ?? null;
                if ($offer === null) self::fail(I18n::format('admin.msg.po.not_offered', ['line' => $label, 'code' => $supplier['code'], 'sku' => $p[0]]));
                $qty = self::int($p[1], 1, 1000000, I18n::format('admin.msg.po.qty', ['line' => $label]));
                if ($qty < (int)$offer['min_order_qty'] || $qty % max(1, (int)$offer['pack_size']) !== 0) {
                    self::fail(I18n::format('admin.msg.po.pack_rule', ['line' => $label, 'sku' => $p[0], 'min' => $offer['min_order_qty'], 'pack' => $offer['pack_size']]));
                }
                $items[] = ['sku' => $p[0], 'quantity' => $qty] + (($p[2] ?? '') === '' ? [] : ['unit_cost' => self::int($p[2], 0, self::MAX_INT, I18n::format('admin.msg.supplier.cost', ['line' => $label]))]);
            }
            if (!$items) self::fail(I18n::t('admin.msg.po.no_items'));
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
            return self::result(false, 0, I18n::format('admin.msg.po.create_failed', ['error' => $e->getMessage()]));
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::result(true, $id, I18n::format('admin.msg.po.created', ['number' => $number]));
    }

    /**
     * Receive quantities per order line. The form's receipt_key makes a resubmission a no-op; each line is booked
     * through Inventory::receivePurchaseOrder into the order's warehouse and stock ledger, all lines in one transaction.
     */
    public static function receive(int $orderId, mixed $post): array {
        $order = $orderId > 0 ? self::purchaseOrder($orderId) : null;
        if (!$order) return self::result(false, 0, I18n::t('admin.msg.po.missing'));
        $key = is_array($post) && is_scalar($post['receipt_key'] ?? null) ? trim((string)$post['receipt_key']) : '';
        $quantities = is_array($post) ? ($post['receive'] ?? []) : null;
        if (!preg_match('/^[a-f0-9]{16,64}$/', $key) || !is_array($quantities)) return self::result(false, $orderId, I18n::t('admin.msg.invalid_form'));
        $items = array_column($order['items'], null, 'id');
        $receipts = [];
        try {
            foreach ($quantities as $itemId => $value) {
                if (!is_scalar($value) || !isset($items[(int)$itemId]) || (string)(int)$itemId !== (string)$itemId) self::fail(I18n::t('admin.msg.invalid_form'));
                $item = $items[(int)$itemId];
                if (trim((string)$value) === '' || trim((string)$value) === '0') continue;
                $open = (int)$item['quantity'] - (int)$item['received_qty'];
                $receipts[] = [$item, self::int((string)$value, 1, max(1, $open), I18n::format('admin.msg.po.receive_range', ['sku' => $item['sku'], 'open' => $open, 'ordered' => $item['quantity'], 'received' => $item['received_qty']]))];
            }
            if (!$receipts) self::fail(I18n::t('admin.msg.po.receive_none'));
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
            if ($e instanceof RuntimeException && !$e instanceof PDOException) return self::result(false, $orderId, I18n::format('admin.msg.po.receive_failed', ['error' => $e->getMessage()]));
            throw $e;
        }
        return $booked
            ? self::result(true, $orderId, I18n::format('admin.msg.po.received', ['items' => implode(', ', array_map(static fn(string $sku, int $q): string => I18n::format('admin.msg.po.received_item', ['sku' => $sku, 'qty' => $q]), array_keys($booked), $booked))]))
            : self::result(true, $orderId, I18n::t('admin.msg.po.receipt_repeat'));
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
        self::fail(I18n::format('admin.msg.sku_not_item', ['label' => $label, 'sku' => $sku]));
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
            self::fail(I18n::format($required ? 'admin.msg.field_required_line' : 'admin.msg.field_long_line', ['label' => $label, 'max' => self::MAX_TEXT]));
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
