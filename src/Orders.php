<?php
declare(strict_types=1);
/**
 * BareBones Shop — Orders
 * Order creation with atomic stock reservation, queries, status updates.
 */
class Orders {
    /**
     * Lowercased gateway getStatus() values meaning the payment can no longer be paid. Covers the states each adapter's
     * webhook maps to payment.failed (Stripe, Comgate, GoPay TIMEOUTED, PayU REJECTED, ThePay error/preauth_*);
     * anything else (created, waiting_for_payment, open, ...) may still be paid and keeps the reservation.
     */
    private const PROVIDER_FINAL_UNPAID = ['expired', 'cancelled', 'canceled', 'failed', 'timeouted', 'rejected', 'error',
        'preauth_expired', 'preauth_cancelled'];

    /** A gateway getStatus() value after which the payment can no longer be paid. */
    public static function providerFinalUnpaid(string $status): bool {
        return in_array(strtolower(trim($status)), self::PROVIDER_FINAL_UNPAID, true);
    }

    /**
     * Create order from cart with atomic stock reservation.
     * Returns order ID on success, throws on failure.
     */
    public static function create(array $cartItems, array $customer, string $paymentMethod = 'stripe', ?array $coupon = null): int {
        $db = Db::get();
        $storeContext = StoreContext::current();
        $storeContext->assertPermission('checkout');
        if (isset($customer['store_id']) && $customer['store_id'] !== $storeContext->id()) {
            throw new InvalidArgumentException('Order store does not match the active StoreContext');
        }
        // A store sells only in its own currency (Pricing::soldInStore): no order and no line priced in another one.
        $orderCurrency = (string)($customer['currency'] ?? 'eur');
        foreach ([$orderCurrency, ...array_map(static fn(array $item): string => (string)($item['currency'] ?? $orderCurrency), $cartItems)] as $currency) {
            if (!Pricing::soldInStore($currency)) throw new OrderRejectedException("Not priced in the store currency: {$currency}");
        }
        $sqliteLock = self::acquireCheckoutLock();

        try {
            $db->beginTransaction();
            // 1. Atomically reserve stock for each item and freeze its exact allocation.
            ['items' => $orderItems, 'subtotal' => $subtotal] = self::reserveItems($cartItems);

            // 2. Freeze the canonical checkout calculation. New checkout calls must
            // provide this snapshot; direct/legacy callers retain the old path so
            // existing integrations and historical imports remain readable.
            $financialSnapshot = $customer['financial_snapshot'] ?? null;
            if (is_string($financialSnapshot) && $financialSnapshot !== '') {
                $financialSnapshot = json_decode($financialSnapshot, true, 512, JSON_THROW_ON_ERROR);
            }
            $financialSnapshotJson = null;
            if (is_array($financialSnapshot)) {
                $taxProvider = Adapters::tax((string)($financialSnapshot['providers']['tax'] ?? Adapters::defaultProviderCode('tax')));
                $taxProvider::validateSnapshot($financialSnapshot);
                self::assertSnapshotItems($financialSnapshot, $orderItems);
                $snapshotTotals = $financialSnapshot['totals'];
                $shippingCost = (int)$snapshotTotals['shipping'];
                $taxAmount = (int)$snapshotTotals['vat'];
                $couponDiscount = (int)$snapshotTotals['discount'];
                $paymentFee = (int)$snapshotTotals['payment_fee'];
                $total = (int)$snapshotTotals['gross'];
                $vatTotal = $taxAmount;
                $netTotal = (int)$snapshotTotals['net'];
                $vatScenario = (string)$financialSnapshot['scenario'];
                if ((int)$snapshotTotals['subtotal'] !== $subtotal
                    || strtolower((string)$financialSnapshot['currency']) !== strtolower((string)($customer['currency'] ?? 'eur'))
                    || $shippingCost !== (int)($customer['shipping_cost'] ?? 0)
                    || $couponDiscount !== (int)($customer['coupon_discount'] ?? 0)
                    || $paymentFee !== (int)($customer['payment_fee'] ?? 0)) {
                    throw new \InvalidArgumentException('Financial snapshot does not match order inputs');
                }
                $financialSnapshotJson = json_encode($financialSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } else {
                $shippingCost = $customer['shipping_cost'] ?? 0;
                $taxAmount = $customer['tax_amount'] ?? 0;
                $couponDiscount = $customer['coupon_discount'] ?? 0;
                $paymentFee = $customer['payment_fee'] ?? 0;
                // Legacy direct callers may still supply net prices. The storefront
                // always uses the snapshot path and therefore never reads prices_include_tax here.
                $taxInTotal = (Security::config()['prices_include_tax'] ?? true) ? 0 : $taxAmount;
                $total = max(0, $subtotal - $couponDiscount) + $shippingCost + $taxInTotal + $paymentFee;

                $vatTotal = 0;
                $netTotal = 0;
                $hasVat = false;
                foreach ($orderItems as $oi) {
                    if ($oi['vat_amount'] !== null) { $hasVat = true; $vatTotal += (int)$oi['vat_amount']; }
                    if ($oi['net_amount'] !== null) { $netTotal += (int)$oi['net_amount']; }
                }
                $vatScenario = $customer['vat_scenario'] ?? null;
                if (!$hasVat) {
                    $vatTotal = 0; $netTotal = 0;
                } elseif ($couponDiscount > 0 && $subtotal > 0) {
                    $discountedGross = max(0, $subtotal - $couponDiscount);
                    $vatTotal = (int) round($vatTotal * $discountedGross / $subtotal);
                    $netTotal = $discountedGross - $vatTotal;
                }
            }

            // 3. Create order (retry on unique constraint collision)
            $orderNumber = null;
            $orderId = null;
            // Resolve locale: explicit customer arg wins; else current I18n locale; else system default.
            $stores = json_decode(@file_get_contents(DataOverrides::storesPath()) ?: '{}', true) ?: [];
            $sysDefaultLocale = $stores['default_locale'] ?? 'en';
            $orderLocale = $customer['locale'] ?? (class_exists('I18n') ? I18n::locale() : $sysDefaultLocale);
            $customerId = !empty($customer['customer_id']) ? (int)$customer['customer_id'] : null;
            $orderParams = [
                ':store'    => $storeContext->id(),
                ':channel'  => $storeContext->channel(),
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
                ':ip'       => php_sapi_name() === 'cli' ? '127.0.0.1' : Security::clientIp(),
                ':vatscen'  => $vatScenario,
                ':vattotal' => $vatTotal,
                ':nettotal' => $netTotal,
                ':shipmethod' => $customer['shipping_method'] ?? null,
                ':shipzone' => $customer['shipping_zone'] ?? null,
                ':shipweight' => (int)($customer['shipping_weight_g'] ?? 0),
                ':shipsnapshot' => $customer['shipping_rate_snapshot'] ?? null,
                ':paymentfee' => (int)($customer['payment_fee'] ?? 0),
                ':paymentsnapshot' => $customer['payment_method_snapshot'] ?? null,
                ':financialsnapshot' => $financialSnapshotJson,
            ];
            $paymentColumns = self::hasColumn('orders', 'payment_method_snapshot');
            $shippingColumns = self::hasColumn('orders', 'shipping_method');
            $financialColumns = self::hasColumn('orders', 'financial_snapshot');
            $extraColumns = $shippingColumns ? ', shipping_method, shipping_zone, shipping_weight_g, shipping_rate_snapshot' : '';
            $extraValues = $shippingColumns ? ', :shipmethod, :shipzone, :shipweight, :shipsnapshot' : '';
            if ($paymentColumns) {
                $extraColumns .= ', payment_fee, payment_method_snapshot';
                $extraValues .= ', :paymentfee, :paymentsnapshot';
            }
            if ($financialColumns) {
                $extraColumns .= ', financial_snapshot';
                $extraValues .= ', :financialsnapshot';
            }
            if (!$shippingColumns) {
                unset($orderParams[':shipmethod'], $orderParams[':shipzone'], $orderParams[':shipweight'], $orderParams[':shipsnapshot']);
            }
            if (!$paymentColumns) {
                unset($orderParams[':paymentfee'], $orderParams[':paymentsnapshot']);
            }
            if (!$financialColumns) {
                unset($orderParams[':financialsnapshot']);
            }
            $synthetic = !empty($customer['is_synthetic']);
            if ($synthetic) {
                if (!self::hasColumn('orders', 'is_synthetic')) throw new \RuntimeException('Synthetic orders need the payment monitoring migration');
                $extraColumns .= ', is_synthetic';
                $extraValues .= ', 1';
            }
            $orderStmt = $db->prepare("
                INSERT INTO orders (order_number, store_id, channel_id, status, customer_id, customer_name, customer_email, customer_phone,
                    shipping_addr, payment_method, subtotal, discount_amount, shipping_cost, tax_amount, total, currency,
                    locale, notes, ip_address, vat_scenario, vat_total, net_total{$extraColumns})
                VALUES (:num, :store, :channel, 'pending', :cid, :name, :email, :phone, :addr, :method,
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
                    $message = strtolower($e->getMessage());
                    $orderNumberCollision = $e->getCode() === '23000'
                        && (str_contains($message, 'unique') || str_contains($message, 'duplicate'))
                        && str_contains($message, 'order_number');
                    if (!$orderNumberCollision || $attempt >= 4) throw $e;
                }
            }

            // 4. Insert order items (with per-line VAT snapshot when available)
            self::insertItems($orderId, $orderItems);

            // 5. Record coupon use atomically within the order transaction
            // A free-shipping coupon discounts no goods (discount 0) but is still a use against its limits.
            if ($coupon && !empty($coupon['code'])) {
                Pricing::recordCouponUse(
                    $coupon['code'], $orderId,
                    $customer['email'], max(0, (int)($coupon['discount'] ?? 0))
                );
            }

            if (isset($customer['consents'])) {
                ConsentService::record($orderId, $customer['consents'], $customer['consent_context'], Security::clientIp(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            }

            // 6. Audit log
            AuditLog::log('order', $orderId, null, [
                'order_number' => $orderNumber,
                'total'        => $total,
                'items'        => count($orderItems),
            ], $synthetic ? 'Synthetic smoke order created' : 'Order created');
            // A synthetic smoke order never leaves the shop: no ERP/webhook/stock integration events.
            if (!$synthetic) {
                Outbox::record(DomainEvents::ORDER_CREATED, 'order', $orderId, [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber,
                    'status' => 'pending',
                    'total' => $total,
                    'currency' => strtolower((string)($customer['currency'] ?? 'eur')),
                    'customer_id' => $customerId,
                ]);
                Outbox::record(DomainEvents::STOCK_RESERVED, 'order', $orderId, [
                    'order_id' => $orderId,
                    'items' => array_map(static fn(array $item): array => [
                        'product_id' => (int)$item['product_id'],
                        'variant_id' => $item['variant_id'] === null ? null : (int)$item['variant_id'],
                        'sku' => (string)$item['sku'],
                        'quantity' => (int)$item['quantity'],
                    ], $orderItems),
                ]);
            }

            $db->commit();
            return $orderId;

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        } finally {
            self::releaseCheckoutLock($sqliteLock);
        }
    }

    /**
     * R10 UX7: replace the lines of a pending, not yet paid order the admin edits. The old reservation is released
     * first, so $price() (called inside the transaction) sees the units this order held; the new lines are then reserved
     * the way checkout reserves them and the totals come from the new financial snapshot. $price() returns
     * ['error' => key] or ['items', 'financial_snapshot', 'shipping_cost', 'payment_fee', 'shipping_weight_g',
     * 'shipping_rate_snapshot', 'payment_method_snapshot']. Returns null on success, else the refusal key.
     */
    public static function replaceItems(int $orderId, callable $price): ?string {
        $db = Db::get();
        $sqliteLock = self::acquireCheckoutLock();
        try {
            $db->beginTransaction();
            self::lockWithin($orderId);
            $order = self::byId($orderId);
            $refusal = $order === null ? 'admin.order.edit.not_found' : self::editRefusal($order);
            if ($refusal !== null) {
                $db->rollBack();
                return $refusal;
            }
            $oldItems = self::items($orderId);
            $touchedSkus = [];
            foreach ($oldItems as $item) {
                if (VariantAvailability::isDigitalProduct((int)$item['product_id'])) continue;
                Inventory::releaseOrderItem($item, $orderId);
                foreach (Inventory::reservationLines($item) as $line) $touchedSkus[] = (string)$line['sku'];
            }
            if ($touchedSkus !== []) Warehouse::syncProductStock(array_values(array_unique($touchedSkus)));
            $db->prepare("DELETE FROM order_items WHERE order_id = :id")->execute([':id' => $orderId]);
            $pricing = $price();
            if (isset($pricing['error'])) {
                $db->rollBack();
                return (string)$pricing['error'];
            }
            ['items' => $orderItems, 'subtotal' => $subtotal] = self::reserveItems($pricing['items']);

            $snapshot = $pricing['financial_snapshot'];
            Adapters::tax((string)($snapshot['providers']['tax'] ?? Adapters::defaultProviderCode('tax')))::validateSnapshot($snapshot);
            self::assertSnapshotItems($snapshot, $orderItems);
            $totals = $snapshot['totals'];
            if ((int)$totals['subtotal'] !== $subtotal || (int)$totals['shipping'] !== (int)$pricing['shipping_cost']
                || (int)$totals['payment_fee'] !== (int)$pricing['payment_fee']
                || strtolower((string)$snapshot['currency']) !== strtolower((string)$order['currency'])) {
                throw new \InvalidArgumentException('Financial snapshot does not match the edited order');
            }
            $params = [
                ':subtotal' => $subtotal, ':discount' => (int)$totals['discount'], ':shipping' => (int)$totals['shipping'],
                ':tax' => (int)$totals['vat'], ':total' => (int)$totals['gross'], ':vattotal' => (int)$totals['vat'],
                ':nettotal' => (int)$totals['net'], ':vatscen' => (string)$snapshot['scenario'],
                ':id' => $orderId, ':store' => StoreContext::current()->id(),
            ];
            $extra = '';
            if (self::hasColumn('orders', 'shipping_rate_snapshot')) {
                $extra .= ', shipping_weight_g = :shipweight, shipping_rate_snapshot = :shipsnapshot';
                $params += [':shipweight' => (int)$pricing['shipping_weight_g'], ':shipsnapshot' => (string)$pricing['shipping_rate_snapshot']];
            }
            if (self::hasColumn('orders', 'payment_method_snapshot')) {
                $extra .= ', payment_fee = :paymentfee, payment_method_snapshot = :paymentsnapshot';
                $params += [':paymentfee' => (int)$pricing['payment_fee'], ':paymentsnapshot' => (string)$pricing['payment_method_snapshot']];
            }
            if (self::hasColumn('orders', 'financial_snapshot')) {
                $extra .= ', financial_snapshot = :financialsnapshot';
                $params[':financialsnapshot'] = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            $db->prepare("UPDATE orders SET subtotal = :subtotal, discount_amount = :discount, shipping_cost = :shipping,
                    tax_amount = :tax, total = :total, vat_total = :vattotal, net_total = :nettotal, vat_scenario = :vatscen{$extra},
                    updated_at = " . Db::now() . " WHERE id = :id AND store_id = :store")->execute($params);
            self::insertItems($orderId, $orderItems);
            foreach ($orderItems as $item) {
                foreach (Inventory::reservationLines($item) as $line) $touchedSkus[] = (string)$line['sku'];
            }
            Warehouse::syncProductStock(array_values(array_unique($touchedSkus)));
            // Units the edit gave back go to orders waiting for them.
            BackorderAllocation::allocate(array_values(array_unique($touchedSkus)));

            $summary = static fn(array $items): array => array_map(static fn(array $i): string => $i['sku'] . ' ×' . $i['quantity'], $items);
            AuditLog::log('order', $orderId, ['total' => (int)$order['total'], 'items' => $summary($oldItems)],
                ['total' => (int)$totals['gross'], 'items' => $summary($orderItems)], 'Order items edited by admin');
            Outbox::record(DomainEvents::STOCK_RESERVED, 'order', $orderId, [
                'order_id' => $orderId,
                'items' => array_map(static fn(array $item): array => [
                    'product_id' => (int)$item['product_id'],
                    'variant_id' => $item['variant_id'] === null ? null : (int)$item['variant_id'],
                    'sku' => (string)$item['sku'],
                    'quantity' => (int)$item['quantity'],
                ], $orderItems),
            ]);
            $db->commit();
            return null;
        } catch (OrderRejectedException $e) {
            if ($db->inTransaction()) $db->rollBack();
            return 'admin.order.edit.out_of_stock';
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        } finally {
            self::releaseCheckoutLock($sqliteLock);
        }
    }

    /**
     * Why the admin may not change the lines of $order, or null. Only a pending order nobody has started paying:
     * no gateway session (it would charge the old amount), no invoice, no coupon (its discount was priced on the old
     * lines), reservation still held.
     */
    public static function editRefusal(array $order): ?string {
        if ((string)$order['status'] !== 'pending' || !in_array((string)($order['payment_status'] ?? 'pending'), ['pending', 'failed'], true)) {
            return 'admin.order.edit.not_pending';
        }
        if ((int)($order['stock_restored'] ?? 0) === 1) return 'admin.order.edit.not_pending';
        $orderId = (int)$order['id'];
        $db = Db::get();
        $paymentStarted = trim((string)($order['payment_id'] ?? '')) !== '';
        if (!$paymentStarted) {
            $stmt = $db->prepare("SELECT 1 FROM payment_transactions WHERE order_id = :id LIMIT 1");
            $stmt->execute([':id' => $orderId]);
            $paymentStarted = $stmt->fetchColumn() !== false;
        }
        if ($paymentStarted) return 'admin.order.edit.payment_started';
        if (InvoiceService::byOrderId($orderId) !== null) return 'admin.order.edit.invoiced';
        $stmt = $db->prepare("SELECT 1 FROM coupon_uses WHERE order_id = :id LIMIT 1");
        $stmt->execute([':id' => $orderId]);
        if ($stmt->fetchColumn() !== false) return 'admin.order.edit.coupon';
        // A planned or sent shipment points at these order lines (and a COD label at this total): replacing the lines
        // would drop a sent item from the bill and leave the shipment lines naming another product.
        $stmt = $db->prepare("SELECT 1 FROM shipments WHERE order_id = :id AND store_id = :store AND status <> 'cancelled' LIMIT 1");
        $stmt->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
        if ($stmt->fetchColumn() !== false) return 'admin.order.edit.shipment';
        return null;
    }

    /** SQLite has no row locks: checkout and order edits serialise their stock reservation on a lock file. */
    private static function acquireCheckoutLock(): mixed {
        if (!Db::isSqlite()) return null;
        $database = Db::get()->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
        $databasePath = (string)($database['file'] ?? '');
        $lockPath = $databasePath !== ''
            ? $databasePath . '.checkout.lock'
            : sys_get_temp_dir() . '/barebones-checkout-' . getmypid() . '.lock';
        $sqliteLock = fopen($lockPath, 'c');
        if ($sqliteLock === false) throw new RuntimeException('Cannot open SQLite checkout lock');
        $deadline = microtime(true) + 5.0;
        while (!flock($sqliteLock, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($sqliteLock);
                throw new RuntimeException('Could not acquire SQLite checkout lock within 5 seconds');
            }
            usleep(10000);
        }
        return $sqliteLock;
    }

    private static function releaseCheckoutLock(mixed $sqliteLock): void {
        if (is_resource($sqliteLock)) {
            flock($sqliteLock, LOCK_UN);
            fclose($sqliteLock);
        }
    }

    /**
     * Reserve stock for each cart line inside the caller's transaction and freeze its allocation on the order line.
     * @return array{items: list<array<string,mixed>>, subtotal: int}
     */
    private static function reserveItems(array $cartItems): array {
        $outOfStock = [];
        $orderItems = [];
        $subtotal = 0;
        foreach ($cartItems as $item) {
            // Reject non-positive quantity or negative price: a negative qty would
            // increase stock and lower the subtotal.
            $requestedQty = (int)($item['qty'] ?? 0);
            if ($requestedQty <= 0 || (int)($item['price'] ?? 0) < 0) {
                $outOfStock[] = ($item['name'] ?? ($item['sku'] ?? 'item'))
                    . (!empty($item['variant_name']) ? ' — ' . $item['variant_name'] : '');
                continue;
            }
            $isDigital = VariantAvailability::isDigitalProduct((int)$item['product_id']);
            $qty = $isDigital ? 1 : $requestedQty;
            $inventorySnapshot = [
                'allocations' => [], 'bundle_components' => [],
                'reserved_qty' => 0, 'backordered_qty' => 0, 'preorder_at' => null,
            ];
            if (!$isDigital) {
                try {
                    $inventorySnapshot = Inventory::reserveLine($item, $qty);
                } catch (\RuntimeException | \InvalidArgumentException $e) {
                    if ($e instanceof \PDOException) throw $e;
                    $outOfStock[] = $item['name'] . (!empty($item['variant_name']) ? ' — ' . $item['variant_name'] : '');
                    continue;
                }
            }
            $total = $item['price'] * $qty;
            $subtotal += $total;

            // Freeze physical, bundle and personalization facts on the order line.
            $itemAttrs = $item['attrs'] ?? [];
            if (!empty($inventorySnapshot['allocations'])) {
                $itemAttrs['_inventory_allocations'] = $inventorySnapshot['allocations'];
            }
            if (!empty($inventorySnapshot['bundle_components'])) {
                $itemAttrs['_bundle_components'] = $inventorySnapshot['bundle_components'];
            }

            $orderItems[] = [
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'sku'        => $item['sku'],
                'name'       => $item['name'] . (!empty($item['variant_name']) ? ' — ' . $item['variant_name'] : ''),
                'quantity'   => $qty,
                'reserved_qty' => (int)$inventorySnapshot['reserved_qty'],
                'backordered_qty' => (int)$inventorySnapshot['backordered_qty'],
                'fulfillment_status' => (int)$inventorySnapshot['backordered_qty'] > 0 ? 'backordered' : 'unfulfilled',
                'preorder_at' => $inventorySnapshot['preorder_at'],
                'unit_price' => $item['price'],
                'total'      => $total,
                'attrs'      => json_encode($itemAttrs),
                'vat_class'  => $item['vat_class']  ?? 'standard',
                'vat_rate'   => isset($item['vat_rate'])   ? (float)$item['vat_rate']   : null,
                'vat_amount' => isset($item['vat_amount']) ? (int)$item['vat_amount']   : null,
                'net_amount' => isset($item['net_amount']) ? (int)$item['net_amount']   : null,
            ];
        }
        if (!empty($outOfStock)) throw new OrderRejectedException('Out of stock: ' . implode(', ', $outOfStock));
        if (empty($orderItems)) throw new OrderRejectedException('No items in order');
        return ['items' => $orderItems, 'subtotal' => $subtotal];
    }

    private static function insertItems(int $orderId, array $orderItems): void {
        $itemStmt = Db::get()->prepare("
            INSERT INTO order_items (order_id, product_id, variant_id, sku, name, quantity,
                reserved_qty, backordered_qty, fulfillment_status, preorder_at,
                unit_price, total, attrs, vat_class, vat_rate, vat_amount, net_amount)
            VALUES (:oid, :pid, :vid, :sku, :name, :qty,
                :reserved_qty, :backordered_qty, :fulfillment_status, :preorder_at,
                :price, :total, :attrs, :vclass, :vrate, :vamount, :namount)
        ");
        foreach ($orderItems as $oi) {
            $itemStmt->execute([
                ':oid'    => $orderId,
                ':pid'    => $oi['product_id'],
                ':vid'    => $oi['variant_id'],
                ':sku'    => $oi['sku'],
                ':name'   => $oi['name'],
                ':qty'    => $oi['quantity'],
                ':reserved_qty' => $oi['reserved_qty'],
                ':backordered_qty' => $oi['backordered_qty'],
                ':fulfillment_status' => $oi['fulfillment_status'],
                ':preorder_at' => $oi['preorder_at'],
                ':price'  => $oi['unit_price'],
                ':total'  => $oi['total'],
                ':attrs'  => $oi['attrs'],
                ':vclass' => $oi['vat_class'],
                ':vrate'  => $oi['vat_rate'] ?? 0,
                ':vamount'=> $oi['vat_amount'] ?? 0,
                ':namount'=> $oi['net_amount'] ?? 0,
            ]);
        }
    }

    private static function assertSnapshotItems(array $snapshot, array $orderItems): void {
        $components = array_values(array_filter(
            $snapshot['components'],
            static fn(array $component): bool => ($component['type'] ?? '') === 'product'
        ));
        if (count($components) !== count($orderItems)) {
            throw new \InvalidArgumentException('Financial snapshot item count does not match order');
        }
        foreach ($orderItems as $index => $item) {
            $component = $components[$index];
            $variantId = $item['variant_id'] === null ? null : (int)$item['variant_id'];
            if ((int)$component['product_id'] !== (int)$item['product_id']
                || ($component['variant_id'] === null ? null : (int)$component['variant_id']) !== $variantId
                || (string)$component['sku'] !== (string)$item['sku']
                || (int)$component['quantity'] !== (int)$item['quantity']
                || (int)$component['unit_gross'] !== (int)$item['unit_price']
                || (int)$component['gross_before_discount'] !== (int)$item['total']
                || (string)$component['vat_class'] !== (string)$item['vat_class']) {
                throw new \InvalidArgumentException('Financial snapshot item does not match reserved order item');
            }
        }
    }

    private static function hasColumn(string $table, string $column): bool {
        try {
            return Db::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get order by ID
     */
    public static function byId(int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM orders WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->id()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get order by order number
     */
    public static function byNumber(string $number): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM orders WHERE order_number = :num AND store_id = :store");
        $stmt->execute([':num' => $number, ':store' => StoreContext::current()->id()]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get order items
     */
    public static function items(int $orderId): array {
        $stmt = Db::get()->prepare("SELECT oi.* FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.order_id = :oid AND o.store_id = :store ORDER BY oi.id");
        $stmt->execute([':oid' => $orderId, ':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll();
    }

    /**
     * Update order status; with $expectedFrom only while the order is still in that status.
     * Returns whether the row changed.
     */
    public static function updateStatus(int $orderId, string $status, ?string $expectedFrom = null): bool {
        $params = [':status' => $status, ':id' => $orderId, ':store' => StoreContext::current()->id()];
        if ($expectedFrom !== null) $params[':from'] = $expectedFrom;
        // The return window counts from the first delivery.
        $delivered = $status === 'delivered' ? ', delivered_at = COALESCE(delivered_at, ' . Db::now() . ')' : '';
        $stmt = Db::get()->prepare("UPDATE orders SET status = :status, updated_at = " . Db::now() . "{$delivered} WHERE id = :id AND store_id = :store"
            . ($expectedFrom !== null ? ' AND status = :from' : ''));
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    /**
     * Set payment ID (after Stripe charge)
     */
    public static function setPaymentId(int $orderId, string $paymentId): void {
        $stmt = Db::get()->prepare("UPDATE orders SET payment_id = :pid WHERE id = :id AND store_id = :store");
        $stmt->execute([':pid' => $paymentId, ':id' => $orderId, ':store' => StoreContext::current()->id()]);
    }

    /**
     * Record refund ID (in notes, preserving original payment_id)
     */
    public static function setRefundId(int $orderId, string $refundId): void {
        $order = self::byId($orderId);
        $notes = trim(($order['notes'] ?? '') . "\nRefund: " . $refundId);
        $stmt = Db::get()->prepare("UPDATE orders SET notes = :notes WHERE id = :id AND store_id = :store");
        $stmt->execute([':notes' => $notes, ':id' => $orderId, ':store' => StoreContext::current()->id()]);
    }

    /**
     * Order statistics
     */
    public static function stats(): array {
        $db = Db::get();
        $statuses = ['pending','paid','processing','shipped','delivered','cancelled','returned','refunded'];
        $result = array_fill_keys($statuses, 0);

        $stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM orders WHERE store_id = :store GROUP BY status");
        $stmt->execute([':store' => StoreContext::current()->id()]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            if (isset($result[$r['status']])) {
                $result[$r['status']] = (int)$r['cnt'];
            }
        }

        [$source, $params] = SalesReport::revenueSource(StoreContext::current()->id());
        $rev = $db->prepare("SELECT COALESCE(SUM(o.total - COALESCE(r.refunded, 0)), 0) FROM {$source} WHERE " . SalesReport::revenueCondition());
        $rev->execute($params + [':store' => StoreContext::current()->id()]);
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
     * Serialise work on one order inside the open transaction (shipments, cancellation, refunds): MySQL/MariaDB
     * lock the order row; SQLite takes its single writer lock now. Call it before reading the order's state.
     */
    public static function lockWithin(int $orderId): void {
        $sql = Db::isMysql()
            ? 'SELECT id FROM orders WHERE id = :id AND store_id = :store FOR UPDATE'
            : 'UPDATE orders SET updated_at = updated_at WHERE id = :id AND store_id = :store';
        Db::get()->prepare($sql)->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
    }

    /**
     * Restore stock for order items.
     *
     * $mode:
     *  - 'release' (cancellation): the order's reservation is still held
     *    (order never shipped) — decrement warehouse_stock.reserved.
     *  - 'return' (returned after shipping): the reservation was already
     *    consumed at 'shipped' (reserved -= qty AND qty -= qty). Goods
     *    physically came back, so ONLY increment warehouse_stock.qty.
     *    Decrementing 'reserved' here would eat reservations belonging
     *    to OTHER open orders and cause oversell.
     */
    public static function restoreStock(int $orderId, string $mode = 'release'): void {
        $db = Db::get();
        $items = self::items($orderId);

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) $db->beginTransaction();

        try {
            // Guard against double restore — atomically claim the flag.
            // Only one caller can flip 0 → 1; everyone else (including a
            // parallel transaction blocked on the row lock) sees 0 rows
            // affected and bails out. Replaces the fragile
            // notes LIKE '%stock_restored%' substring heuristic (bug #11).
            $claim = $db->prepare("UPDATE orders SET stock_restored = 1 WHERE id = :id AND store_id = :store AND stock_restored = 0");
            $claim->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
            if ($claim->rowCount() === 0) {
                if ($ownTransaction) $db->rollBack();
                return; // already restored (or order does not exist)
            }
            $skus = [];
            foreach ($items as $item) {
                if (VariantAvailability::isDigitalProduct((int)$item['product_id'])) continue;
                if ($mode === 'return') {
                    Inventory::returnOrderItem($item, $orderId);
                } else {
                    Inventory::releaseOrderItem($item, $orderId);
                }
                foreach (Inventory::reservationLines($item) as $line) {
                    Warehouse::syncProductStock([(string)$line['sku']]);
                    $skus[] = (string)$line['sku'];
                }
            }
            // The released or returned units go to orders waiting for them first.
            BackorderAllocation::allocate($skus);

            if ($ownTransaction) $db->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Cancel genuinely expired, unpaid checkouts (payment pending or declined) and release stock once.
     * Online: eligible after $olderThanSeconds and, when a provider session exists,
     * only after the provider confirms a terminal unpaid state (errors fail closed).
     * Offline: eligible only for payment service types listed in $offlineExpirySeconds
     * (service_type => seconds, e.g. bank_transfer); unlisted types (COD, invoice) never expire.
     * Ineligible old orders are skipped by keyset scan, so they cannot starve the batch.
     */
    public static function expirePending(int $olderThanSeconds = 172800, int $limit = 100, ?callable $paymentStatusResolver = null, array $offlineExpirySeconds = []): int {
        $olderThanSeconds = max(3600, $olderThanSeconds);
        $limit = max(1, min(100, $limit));
        $offlineTtls = [];
        foreach ($offlineExpirySeconds as $type => $seconds) {
            if ((int)$seconds > 0) $offlineTtls[(string)$type] = max(3600, (int)$seconds);
        }
        $minAge = $offlineTtls ? min($olderThanSeconds, ...array_values($offlineTtls)) : $olderThanSeconds;
        $cutoff = static fn(int $seconds): string => Db::isSqlite()
            ? "datetime('now', '-{$seconds} seconds')"
            : "DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)";
        $db = Db::get();
        $storeId = StoreContext::current()->id();
        $scan = $db->prepare("SELECT * FROM orders
            WHERE store_id = :store AND status = 'pending' AND payment_status IN ('pending', 'failed')
              AND created_at <= {$cutoff($minAge)} AND id > :after
            ORDER BY id ASC LIMIT 100");

        $expired = 0;
        $afterId = 0;
        $scanned = 0;
        while ($expired < $limit && $scanned < $limit * 50) {
            $scan->bindValue(':store', $storeId);
            $scan->bindValue(':after', $afterId, PDO::PARAM_INT);
            $scan->execute();
            $rows = $scan->fetchAll(\PDO::FETCH_ASSOC);
            if ($rows === []) break;
            foreach ($rows as $order) {
                $afterId = (int)$order['id'];
                $scanned++;
                $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
                if (!is_array($snapshot)) continue;
                $online = ($snapshot['requires_online'] ?? false) === true;
                $paymentId = trim((string)($order['payment_id'] ?? ''));
                if ($online) {
                    $ttl = $olderThanSeconds;
                } else {
                    $type = (string)(($snapshot['service_type'] ?? '') ?: ($order['payment_method'] ?? ''));
                    $ttl = $offlineTtls[$type] ?? 0;
                    if ($ttl === 0 || $paymentId !== '') continue;
                }
                $created = strtotime((string)$order['created_at'] . ' UTC');
                if ($created === false || $created > time() - $ttl) continue;
                // A transfer is due at the end of its due day on the shop's calendar and may still be on its way for the
                // grace days after it: the order is not released before.
                if (!$online && $type === 'bank_transfer' && ShopTime::now() <= BankTransferInstructions::cancelAfter($order, $ttl)) continue;
                // The customer was sent a link that promises time to pay; the order waits for it.
                if ($online && PaymentLink::hasLiveLink((int)$order['id'])) continue;
                if ($online && $paymentId !== '') {
                    if ($paymentStatusResolver === null) continue;
                    try {
                        $providerStatus = strtolower(trim((string)$paymentStatusResolver($order)));
                    } catch (\Throwable $e) {
                        Logger::phpLog("Pending order expiry skipped for #{$order['id']}: " . $e->getMessage());
                        continue;
                    }
                    if (!in_array($providerStatus, self::PROVIDER_FINAL_UNPAID, true)) continue;
                }
                // One order that cannot be released (e.g. broken reservation) must not stall every later one.
                try {
                    if (self::expireClaimed($order, $cutoff($ttl), $online)) $expired++;
                } catch (\Throwable $e) {
                    // The poisoned order fails again on every cron run: one incident (and log line) a day, not one per run.
                    if (!IncidentMonitor::reportedForOrder('order.expiry_failed', (int)$order['id'])) {
                        Logger::phpLog("Pending order expiry failed for #{$order['id']}: " . $e->getMessage());
                        IncidentMonitor::report('order.expiry_failed', 'orders', 'critical', ['order_id' => (int)$order['id'], 'exception' => $e]);
                    }
                    continue;
                }
                if ($expired >= $limit) break 2;
            }
        }
        return $expired;
    }

    /** Atomically cancel one still-pending order older than $cutoffSql and release its stock. */
    private static function expireClaimed(array $order, string $cutoffSql, bool $online): bool {
        $db = Db::get();
        $orderId = (int)$order['id'];
        $note = $online ? 'Expired unpaid online checkout' : 'Expired unpaid offline order';
        $db->beginTransaction();
        try {
            $claim = $db->prepare("UPDATE orders
                SET status = 'cancelled', payment_status = 'cancelled', updated_at = " . Db::now() . "
                WHERE id = :id AND store_id = :store AND status = 'pending' AND payment_status IN ('pending', 'failed')
                  AND created_at <= {$cutoffSql}");
            $claim->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
            if ($claim->rowCount() !== 1) {
                $db->rollBack();
                return false;
            }

            self::restoreStock($orderId, 'release');
            Pricing::releaseCouponUse($orderId);
            $db->prepare("UPDATE checkout_sessions SET status = 'expired', updated_at = " . Db::now() . " WHERE order_id = :id AND status = 'pending'")
               ->execute([':id' => $orderId]);
            AuditLog::log('order_status', $orderId, 'pending', 'cancelled', $note);
            Outbox::record(DomainEvents::ORDER_STATUS_CHANGED, 'order', $orderId, [
                'order_id' => $orderId,
                'order_number' => (string)$order['order_number'],
                'from' => 'pending',
                'to' => 'cancelled',
                'note' => $note,
            ]);
            Outbox::record(DomainEvents::STOCK_CHANGED, 'order', $orderId, [
                'order_id' => $orderId,
                'reason' => 'order.expired',
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        // An offline buyer may still be about to pay: tell them the order no longer exists.
        if (!$online && trim((string)($order['customer_email'] ?? '')) !== '') {
            JobQueue::push('email_send', [
                'template' => 'order_cancelled',
                'to' => (string)$order['customer_email'],
                'locale' => (string)($order['locale'] ?? ''),
                'order_id' => $orderId,
            ], 5);
        }
        return true;
    }

    // ============================================================
    // ERP Sync support
    // ============================================================

    /**
     * Orders of the active store not yet pushed to the ERP, with their items (erpPayload()). A cancelled order
     * that never reached the ERP is not pushed; one whose push failed waits for its erp_next_retry_at.
     */
    public static function unsyncedForErp(): array {
        $stmt = Db::get()->prepare("SELECT * FROM orders WHERE store_id = :store AND status NOT IN ('pending', 'cancelled') AND erp_id IS NULL
            AND (erp_next_retry_at IS NULL OR erp_next_retry_at <= " . Db::now() . ") ORDER BY created_at ASC LIMIT 50");
        $stmt->execute([':store' => StoreContext::current()->id()]);
        return array_map([self::class, 'erpPayload'], $stmt->fetchAll());
    }

    /** The order row as IntegrationAdapter::pushOrder() receives it: with its items. */
    public static function erpPayload(array $order): array {
        $order['items'] = self::items((int)$order['id']);
        return $order;
    }

    /** Mark order as synced to ERP */
    public static function markSynced(int $orderId, string $externalId): void {
        $stmt = Db::get()->prepare("UPDATE orders SET erp_id = :eid, erp_synced_at = " . Db::now() . ", erp_error = NULL, erp_attempts = 0, erp_next_retry_at = NULL, updated_at = " . Db::now() . " WHERE id = :id AND store_id = :store");
        $stmt->execute([':eid' => $externalId, ':id' => $orderId, ':store' => StoreContext::current()->id()]);
    }

    /** Mark order ERP sync as failed; the next push waits 15 minutes, doubling per failure up to a day. */
    public static function markSyncFailed(int $orderId, string $error): void {
        $db = Db::get();
        $attempts = $db->prepare('SELECT erp_attempts FROM orders WHERE id = :id AND store_id = :store');
        $attempts->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
        $delay = min(86400, 900 * 2 ** min(7, (int)$attempts->fetchColumn()));
        $stmt = $db->prepare("UPDATE orders SET erp_error = :err, erp_attempts = erp_attempts + 1, erp_next_retry_at = :retry, updated_at = " . Db::now() . " WHERE id = :id AND store_id = :store");
        $stmt->execute([':err' => substr($error, 0, 500), ':retry' => gmdate('Y-m-d H:i:s', time() + $delay), ':id' => $orderId, ':store' => StoreContext::current()->id()]);
    }

    /**
     * Open ERP orders whose status is due for a pull: never polled first (NULL sorts first in ascending
     * order on SQLite and MySQL), then the longest unpolled. updated_at only moves on a status change,
     * so ordering by it polled the same 50 orders forever. Until the erp_status_checked_at migration
     * has run, the pull falls back to id order instead of failing.
     */
    public static function pendingStatusSync(int $limit = 50): array {
        $order = self::hasColumn('orders', 'erp_status_checked_at') ? 'erp_status_checked_at ASC, id ASC' : 'id ASC';
        $stmt = Db::get()->prepare("SELECT * FROM orders WHERE store_id = :store AND erp_id IS NOT NULL AND status NOT IN ('delivered','cancelled','refunded')
            ORDER BY {$order} LIMIT " . max(1, min(500, $limit)));
        $stmt->execute([':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll();
    }

    /** Stamp an ERP status poll (whatever its outcome) so the next pull moves on to other orders. */
    public static function markErpStatusChecked(int $orderId): void {
        if (!self::hasColumn('orders', 'erp_status_checked_at')) return;
        $stmt = Db::get()->prepare("UPDATE orders SET erp_status_checked_at = " . Db::now() . " WHERE id = :id AND store_id = :store");
        $stmt->execute([':id' => $orderId, ':store' => StoreContext::current()->id()]);
    }

    /** Update ERP status on order */
    public static function updateErpStatus(int $orderId, string $erpStatus): void {
        $stmt = Db::get()->prepare("UPDATE orders SET erp_status = :status, updated_at = " . Db::now() . " WHERE id = :id AND store_id = :store");
        $stmt->execute([':status' => $erpStatus, ':id' => $orderId, ':store' => StoreContext::current()->id()]);
    }

    /**
     * The total lines of a placed order, in the order they add up to `total`. With gross prices the VAT is already inside
     * the other lines, so it is an "of which" line (vat_included) that is not added.
     *
     * @return list<array{key: string, cents: int}> keys: subtotal, discount, shipping, payment_fee, vat, vat_included
     */
    public static function totalLines(array $order): array {
        $subtotal = (int)$order['subtotal'];
        $discount = abs((int)($order['discount_amount'] ?? 0));
        $shipping = (int)($order['shipping_cost'] ?? 0);
        $fee = (int)($order['payment_fee'] ?? 0);
        $vat = (int)($order['tax_amount'] ?? 0);
        $lines = [['key' => 'subtotal', 'cents' => $subtotal]];
        if ($discount !== 0) $lines[] = ['key' => 'discount', 'cents' => -$discount];
        $lines[] = ['key' => 'shipping', 'cents' => $shipping];
        if ($fee !== 0) $lines[] = ['key' => 'payment_fee', 'cents' => $fee];
        if ($vat !== 0) {
            $added = max(0, $subtotal - $discount) + $shipping + $fee + $vat === (int)$order['total'];
            $lines[] = ['key' => $added ? 'vat' : 'vat_included', 'cents' => $vat];
        }
        return $lines;
    }
}
