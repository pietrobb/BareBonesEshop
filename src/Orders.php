<?php
/**
 * BareBones Shop — Orders
 * Order creation with atomic stock reservation, queries, status updates.
 */
class Orders {
    /**
     * Create order from cart with atomic stock reservation.
     * Returns order ID on success, throws on failure.
     */
    public static function create(array $cartItems, array $customer, string $paymentMethod = 'stripe', ?array $coupon = null): int {
        $db = Db::get();
        $db->beginTransaction();

        try {
            $outOfStock = [];
            $orderItems = [];
            $subtotal = 0;

            // 1. Atomically reserve stock for each item
            $warehouseAllocations = []; // track per-SKU warehouse allocations for notes
            foreach ($cartItems as $item) {
                if (!empty($item['variant_id'])) {
                    $stmt = $db->prepare("UPDATE variants SET stock = stock - :qty WHERE id = :id AND stock >= :qty");
                    $stmt->execute([':qty' => $item['qty'], ':id' => $item['variant_id']]);
                    if ($stmt->rowCount() === 0) {
                        $outOfStock[] = $item['name'] . (!empty($item['variant_name']) ? ' — ' . $item['variant_name'] : '');
                        continue;
                    }
                } else {
                    // Use warehouse-based reservation if warehouses exist
                    try {
                        $allocs = Warehouse::reserve($item['sku'], $item['qty']);
                        $warehouseAllocations[$item['sku']] = $allocs;
                        // Also decrement products.stock to keep it in sync
                        $db->prepare("UPDATE products SET stock = stock - :qty WHERE id = :id")
                           ->execute([':qty' => $item['qty'], ':id' => $item['product_id']]);
                    } catch (\RuntimeException $e) {
                        $outOfStock[] = $item['name'] . (!empty($item['variant_name']) ? ' — ' . $item['variant_name'] : '');
                        continue;
                    }
                }
                $total = $item['price'] * $item['qty'];
                $subtotal += $total;

                // Merge warehouse allocations into attrs for restore tracking
                $itemAttrs = $item['attrs'] ?? [];
                if (!empty($warehouseAllocations[$item['sku']])) {
                    $itemAttrs['_warehouse_allocations'] = $warehouseAllocations[$item['sku']];
                }

                $orderItems[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'sku'        => $item['sku'],
                    'name'       => $item['name'] . (!empty($item['variant_name']) ? ' — ' . $item['variant_name'] : ''),
                    'quantity'   => $item['qty'],
                    'unit_price' => $item['price'],
                    'total'      => $total,
                    'attrs'      => json_encode($itemAttrs),
                    'vat_class'  => $item['vat_class']  ?? 'standard',
                    'vat_rate'   => isset($item['vat_rate'])   ? (float)$item['vat_rate']   : null,
                    'vat_amount' => isset($item['vat_amount']) ? (int)$item['vat_amount']   : null,
                    'net_amount' => isset($item['net_amount']) ? (int)$item['net_amount']   : null,
                ];
            }

            if (!empty($outOfStock)) {
                $db->rollBack();
                throw new \RuntimeException('Out of stock: ' . implode(', ', $outOfStock));
            }

            if (empty($orderItems)) {
                $db->rollBack();
                throw new \RuntimeException('No items in order');
            }

            // 2. Calculate shipping + tax - coupon
            $shippingCost = $customer['shipping_cost'] ?? 0;
            $taxAmount = $customer['tax_amount'] ?? 0;
            $couponDiscount = $customer['coupon_discount'] ?? 0;
            $paymentFee = $customer['payment_fee'] ?? 0;
            $total = max(0, $subtotal - $couponDiscount) + $shippingCost + $taxAmount + $paymentFee;

            // 2a. Per-order VAT snapshot (sum from items if any line has VAT data)
            $vatTotal = 0;
            $netTotal = 0;
            $hasVat = false;
            foreach ($orderItems as $oi) {
                if ($oi['vat_amount'] !== null) { $hasVat = true; $vatTotal += (int)$oi['vat_amount']; }
                if ($oi['net_amount'] !== null) { $netTotal += (int)$oi['net_amount']; }
            }
            $vatScenario = $customer['vat_scenario'] ?? null;
            if (!$hasVat) { $vatTotal = null; $netTotal = null; }

            // 3. Create order (retry on unique constraint collision)
            $orderNumber = null;
            $orderId = null;
            // Resolve locale: explicit customer arg wins; else current I18n locale; else system default.
            $stores = json_decode(@file_get_contents(__DIR__ . '/../stores.json') ?: '{}', true) ?: [];
            $sysDefaultLocale = $stores['default_locale'] ?? 'en';
            $orderLocale = $customer['locale'] ?? (class_exists('I18n') ? I18n::locale() : $sysDefaultLocale);
            $customerId = !empty($customer['customer_id']) ? (int)$customer['customer_id'] : null;
            $orderParams = [
                ':cid'      => $customerId,
                ':name'     => $customer['name'],
                ':email'    => $customer['email'],
                ':phone'    => $customer['phone'] ?? '',
                ':addr'     => json_encode($customer['address'] ?? []),
                ':method'   => $paymentMethod,
                ':subtotal' => $subtotal,
                ':discount' => $couponDiscount,
                ':shipping' => $shippingCost,
                ':tax'      => $taxAmount,
                ':total'    => $total,
                ':currency' => $customer['currency'] ?? 'eur',
                ':locale'   => $orderLocale,
                ':notes'    => $customer['notes'] ?? '',
                ':ip'       => php_sapi_name() === 'cli' ? '127.0.0.1' : ($_SERVER['REMOTE_ADDR'] ?? ''),
                ':vatscen'  => $vatScenario,
                ':vattotal' => $vatTotal,
                ':nettotal' => $netTotal,
                ':shipmethod' => $customer['shipping_method'] ?? null,
                ':shipzone' => $customer['shipping_zone'] ?? null,
                ':shipweight' => (int)($customer['shipping_weight_g'] ?? 0),
                ':shipsnapshot' => $customer['shipping_rate_snapshot'] ?? null,
                ':paymentfee' => (int)($customer['payment_fee'] ?? 0),
                ':paymentsnapshot' => $customer['payment_method_snapshot'] ?? null,
            ];
            $paymentColumns = self::hasColumn('orders', 'payment_method_snapshot');
            $shippingColumns = self::hasColumn('orders', 'shipping_method');
            $extraColumns = $shippingColumns ? ', shipping_method, shipping_zone, shipping_weight_g, shipping_rate_snapshot' : '';
            $extraValues = $shippingColumns ? ', :shipmethod, :shipzone, :shipweight, :shipsnapshot' : '';
            if ($paymentColumns) {
                $extraColumns .= ', payment_fee, payment_method_snapshot';
                $extraValues .= ', :paymentfee, :paymentsnapshot';
            }
            if (!$shippingColumns) {
                unset($orderParams[':shipmethod'], $orderParams[':shipzone'], $orderParams[':shipweight'], $orderParams[':shipsnapshot']);
            }
            if (!$paymentColumns) {
                unset($orderParams[':paymentfee'], $orderParams[':paymentsnapshot']);
            }
            $orderStmt = $db->prepare("
                INSERT INTO orders (order_number, status, customer_id, customer_name, customer_email, customer_phone,
                    shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency,
                    locale, notes, ip_address, vat_scenario, vat_total, net_total{$extraColumns})
                VALUES (:num, 'pending', :cid, :name, :email, :phone, :addr, :method,
                    :subtotal, :discount, :shipping, :tax, :total, :currency, :locale, :notes, :ip,
                    :vatscen, :vattotal, :nettotal{$extraValues})
            ");
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $orderNumber = self::generateOrderNumber();
                try {
                    $orderStmt->execute(array_merge([':num' => $orderNumber], $orderParams));
                    $orderId = (int)$db->lastInsertId();
                    break;
                } catch (\PDOException $e) {
                    // Retry only on unique constraint violation (SQLSTATE 23000)
                    if ($e->getCode() !== '23000' || $attempt >= 4) throw $e;
                }
            }

            // 4. Insert order items (with per-line VAT snapshot when available)
            $itemStmt = $db->prepare("
                INSERT INTO order_items (order_id, product_id, variant_id, sku, name, quantity, unit_price, total, attrs,
                    vat_class, vat_rate, vat_amount, net_amount)
                VALUES (:oid, :pid, :vid, :sku, :name, :qty, :price, :total, :attrs,
                    :vclass, :vrate, :vamount, :namount)
            ");
            foreach ($orderItems as $oi) {
                $itemStmt->execute([
                    ':oid'    => $orderId,
                    ':pid'    => $oi['product_id'],
                    ':vid'    => $oi['variant_id'],
                    ':sku'    => $oi['sku'],
                    ':name'   => $oi['name'],
                    ':qty'    => $oi['quantity'],
                    ':price'  => $oi['unit_price'],
                    ':total'  => $oi['total'],
                    ':attrs'  => $oi['attrs'],
                    ':vclass' => $oi['vat_class'],
                    ':vrate'  => $oi['vat_rate'],
                    ':vamount'=> $oi['vat_amount'],
                    ':namount'=> $oi['net_amount'],
                ]);
            }

            // 5. Record coupon use atomically within the order transaction
            if ($coupon && !empty($coupon['code']) && ($coupon['discount'] ?? 0) > 0) {
                Pricing::recordCouponUse(
                    $coupon['code'], $orderId,
                    $customer['email'], $coupon['discount']
                );
            }

            // 6. Audit log
            AuditLog::log('order', $orderId, null, [
                'order_number' => $orderNumber,
                'total'        => $total,
                'items'        => count($orderItems),
            ], 'Order created');

            $db->commit();
            return $orderId;

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function hasColumn(string $table, string $column): bool {
        try {
            $stmt = Db::get()->query("PRAGMA table_info({$table})");
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (($row['name'] ?? '') === $column) return true;
            }
        } catch (\Throwable $e) {
            try {
                $stmt = Db::get()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME=? AND COLUMN_NAME=?");
                $stmt->execute([$table, $column]);
                return ((int)$stmt->fetchColumn()) > 0;
            } catch (\Throwable $ignored) {}
        }
        return false;
    }

    /**
     * Get order by ID
     */
    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM orders WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get order by order number
     */
    public static function byNumber(string $number): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM orders WHERE order_number = :num");
        $stmt->execute([':num' => $number]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get order items
     */
    public static function items(int $orderId): array {
        $stmt = Db::get()->prepare("SELECT * FROM order_items WHERE order_id = :oid ORDER BY id");
        $stmt->execute([':oid' => $orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Get orders by email
     */
    public static function byEmail(string $email, int $limit = 20): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM orders WHERE customer_email = :email
            ORDER BY created_at DESC LIMIT :limit
        ");
        $stmt->execute([':email' => $email, ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get orders by status
     */
    public static function byStatus(string $status, int $limit = 50): array {
        $stmt = Db::get()->prepare("
            SELECT * FROM orders WHERE status = :status
            ORDER BY created_at DESC LIMIT :limit
        ");
        $stmt->execute([':status' => $status, ':limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Update order status
     */
    public static function updateStatus(int $orderId, string $status): void {
        $now = Db::isSqlite() ? "datetime('now')" : 'NOW()';
        $stmt = Db::get()->prepare("UPDATE orders SET status = :status, updated_at = {$now} WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $orderId]);
    }

    /**
     * Set payment ID (after Stripe charge)
     */
    public static function setPaymentId(int $orderId, string $paymentId): void {
        $stmt = Db::get()->prepare("UPDATE orders SET payment_id = :pid WHERE id = :id");
        $stmt->execute([':pid' => $paymentId, ':id' => $orderId]);
    }

    /**
     * Record refund ID (in notes, preserving original payment_id)
     */
    public static function setRefundId(int $orderId, string $refundId): void {
        $order = self::byId($orderId);
        $notes = trim(($order['notes'] ?? '') . "\nRefund: " . $refundId);
        $stmt = Db::get()->prepare("UPDATE orders SET notes = :notes WHERE id = :id");
        $stmt->execute([':notes' => $notes, ':id' => $orderId]);
    }

    /**
     * Order statistics
     */
    public static function stats(): array {
        $db = Db::get();
        $statuses = ['pending','paid','processing','shipped','delivered','cancelled','returned','refunded'];
        $result = array_fill_keys($statuses, 0);

        $rows = $db->query("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status")->fetchAll();
        foreach ($rows as $r) {
            if (isset($result[$r['status']])) {
                $result[$r['status']] = (int)$r['cnt'];
            }
        }

        $rev = $db->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status NOT IN ('cancelled', 'refunded')");
        $result['revenue'] = (int)$rev->fetchColumn();

        return $result;
    }

    /**
     * Generate unique order number: ORD-YYYYMMDD-XXXXXX
     * Uses 6 hex chars (16M combinations/day) — collisions handled by
     * UNIQUE constraint + retry in create().
     */
    private static function generateOrderNumber(): string {
        $date = date('Ymd');
        $rand = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        return "ORD-{$date}-{$rand}";
    }

    /**
     * Restore stock for order items (used by cancellation/refund)
     */
    public static function restoreStock(int $orderId): void {
        $db = Db::get();
        $items = self::items($orderId);

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();

        try {
            // Guard against double restore — check inside transaction for atomicity
            $order = self::byId($orderId);
            if ($order && str_contains($order['notes'] ?? '', 'stock_restored')) {
                if ($ownTransaction) $db->rollBack();
                return;
            }
            foreach ($items as $item) {
                if (!empty($item['variant_id'])) {
                    $stmt = $db->prepare("UPDATE variants SET stock = stock + :qty WHERE id = :id");
                    $stmt->execute([':qty' => $item['quantity'], ':id' => $item['variant_id']]);
                } else {
                    $attrs = json_decode($item['attrs'] ?? '{}', true);
                    $allocs = $attrs['_warehouse_allocations'] ?? null;

                    if ($allocs) {
                        // Release back to exact warehouses where stock was reserved
                        Warehouse::release($item['sku'], $allocs);
                        Warehouse::syncProductStock([$item['sku']]);
                    } else {
                        // Fallback: no allocation info, add to default warehouse
                        Products::incrementStock($item['product_id'], $item['quantity']);
                    }
                }
            }

            // Mark as restored inside the same transaction
            $concat = Db::isSqlite() ? "COALESCE(notes,'') || :note" : "CONCAT(COALESCE(notes,''), :note)";
            $db->prepare("UPDATE orders SET notes = {$concat} WHERE id = :id")
               ->execute([':note' => "\nstock_restored", ':id' => $orderId]);

            if ($ownTransaction) $db->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    // ============================================================
    // ERP Sync support
    // ============================================================

    /** Get orders not yet synced to ERP */
    public static function unsyncedForErp(): array {
        $stmt = Db::get()->query("SELECT * FROM orders WHERE status != 'pending' AND erp_id IS NULL ORDER BY created_at ASC LIMIT 50");
        return $stmt->fetchAll();
    }

    /** Mark order as synced to ERP */
    public static function markSynced(int $orderId, string $externalId): void {
        $stmt = Db::get()->prepare("UPDATE orders SET erp_id = :eid, erp_synced_at = " . Db::now() . ", erp_error = NULL, updated_at = " . Db::now() . " WHERE id = :id");
        $stmt->execute([':eid' => $externalId, ':id' => $orderId]);
    }

    /** Mark order ERP sync as failed */
    public static function markSyncFailed(int $orderId, string $error): void {
        $stmt = Db::get()->prepare("UPDATE orders SET erp_error = :err, updated_at = " . Db::now() . " WHERE id = :id");
        $stmt->execute([':err' => substr($error, 0, 500), ':id' => $orderId]);
    }

    /** Get orders pending ERP status sync */
    public static function pendingStatusSync(): array {
        $stmt = Db::get()->query("SELECT * FROM orders WHERE erp_id IS NOT NULL AND status NOT IN ('delivered','cancelled','refunded') ORDER BY updated_at ASC LIMIT 50");
        return $stmt->fetchAll();
    }

    /** Update ERP status on order */
    public static function updateErpStatus(int $orderId, string $erpStatus): void {
        $stmt = Db::get()->prepare("UPDATE orders SET erp_status = :status, updated_at = " . Db::now() . " WHERE id = :id");
        $stmt->execute([':status' => $erpStatus, ':id' => $orderId]);
    }
}
