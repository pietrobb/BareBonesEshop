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
    public static function create(array $cartItems, array $customer, string $paymentMethod = 'stripe'): int {
        $db = Db::get();
        $db->beginTransaction();

        try {
            $outOfStock = [];
            $orderItems = [];
            $subtotal = 0;

            // 1. Atomically reserve stock for each item
            foreach ($cartItems as $item) {
                if (!empty($item['variant_id'])) {
                    $stmt = $db->prepare("UPDATE variants SET stock = stock - :qty WHERE id = :id AND stock >= :qty");
                    $stmt->execute([':qty' => $item['qty'], ':id' => $item['variant_id']]);
                } else {
                    $stmt = $db->prepare("UPDATE products SET stock = stock - :qty WHERE id = :id AND stock >= :qty");
                    $stmt->execute([':qty' => $item['qty'], ':id' => $item['product_id']]);
                }

                if ($stmt->rowCount() === 0) {
                    $outOfStock[] = $item['name'] . (!empty($item['variant_name']) ? ' — ' . $item['variant_name'] : '');
                    continue;
                }

                $total = $item['price'] * $item['qty'];
                $subtotal += $total;
                $orderItems[] = [
                    'product_id' => $item['product_id'],
                    'variant_id' => $item['variant_id'] ?? null,
                    'sku'        => $item['sku'],
                    'name'       => $item['name'] . (!empty($item['variant_name']) ? ' — ' . $item['variant_name'] : ''),
                    'quantity'   => $item['qty'],
                    'unit_price' => $item['price'],
                    'total'      => $total,
                    'attrs'      => json_encode($item['attrs'] ?? []),
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

            // 2. Calculate shipping + tax
            $shippingCost = $customer['shipping_cost'] ?? 0;
            $taxAmount = $customer['tax_amount'] ?? 0;
            $total = $subtotal + $shippingCost + $taxAmount;

            // 3. Create order
            $orderNumber = self::generateOrderNumber();
            $stmt = $db->prepare("
                INSERT INTO orders (order_number, status, customer_name, customer_email, customer_phone,
                    shipping_addr, payment_method, subtotal, shipping_cost, tax_amount, total, currency,
                    notes, ip_address)
                VALUES (:num, 'pending', :name, :email, :phone, :addr, :method,
                    :subtotal, :shipping, :tax, :total, :currency, :notes, :ip)
            ");
            $stmt->execute([
                ':num'      => $orderNumber,
                ':name'     => $customer['name'],
                ':email'    => $customer['email'],
                ':phone'    => $customer['phone'] ?? '',
                ':addr'     => json_encode($customer['address'] ?? []),
                ':method'   => $paymentMethod,
                ':subtotal' => $subtotal,
                ':shipping' => $shippingCost,
                ':tax'      => $taxAmount,
                ':total'    => $total,
                ':currency' => $customer['currency'] ?? 'eur',
                ':notes'    => $customer['notes'] ?? '',
                ':ip'       => php_sapi_name() === 'cli' ? '127.0.0.1' : ($_SERVER['REMOTE_ADDR'] ?? ''),
            ]);
            $orderId = (int)$db->lastInsertId();

            // 4. Insert order items
            $itemStmt = $db->prepare("
                INSERT INTO order_items (order_id, product_id, variant_id, sku, name, quantity, unit_price, total, attrs)
                VALUES (:oid, :pid, :vid, :sku, :name, :qty, :price, :total, :attrs)
            ");
            foreach ($orderItems as $oi) {
                $itemStmt->execute([
                    ':oid'   => $orderId,
                    ':pid'   => $oi['product_id'],
                    ':vid'   => $oi['variant_id'],
                    ':sku'   => $oi['sku'],
                    ':name'  => $oi['name'],
                    ':qty'   => $oi['quantity'],
                    ':price' => $oi['unit_price'],
                    ':total' => $oi['total'],
                    ':attrs' => $oi['attrs'],
                ]);
            }

            // 5. Audit log
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
        $result = [];
        foreach (['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'returned', 'refunded'] as $s) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE status = :s");
            $stmt->execute([':s' => $s]);
            $result[$s] = (int)$stmt->fetchColumn();
        }

        $stmt = $db->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status NOT IN ('cancelled', 'refunded')");
        $result['revenue'] = (int)$stmt->fetchColumn();

        return $result;
    }

    /**
     * Generate unique order number: ORD-YYYYMMDD-XXXX
     */
    private static function generateOrderNumber(int $attempt = 0): string {
        if ($attempt >= 10) {
            throw new \RuntimeException('Failed to generate unique order number after 10 attempts');
        }

        $date = date('Ymd');
        $rand = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $number = "ORD-{$date}-{$rand}";

        // Ensure uniqueness
        $stmt = Db::get()->prepare("SELECT COUNT(*) FROM orders WHERE order_number = :num");
        $stmt->execute([':num' => $number]);
        if ((int)$stmt->fetchColumn() > 0) {
            return self::generateOrderNumber($attempt + 1);
        }

        return $number;
    }

    /**
     * Restore stock for order items (used by cancellation/refund)
     */
    public static function restoreStock(int $orderId): void {
        $items = self::items($orderId);
        foreach ($items as $item) {
            if (!empty($item['variant_id'])) {
                $stmt = Db::get()->prepare("UPDATE variants SET stock = stock + :qty WHERE id = :id");
                $stmt->execute([':qty' => $item['quantity'], ':id' => $item['variant_id']]);
            } else {
                Products::incrementStock($item['product_id'], $item['quantity']);
            }
        }
    }
}
