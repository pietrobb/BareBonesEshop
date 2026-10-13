<?php
/** Stable, additive-first external API v1 contract. */
class ApiV1 {
    /** @return array{status:int,body:array} */
    public static function dispatch(
        string $method,
        string $path,
        array $query,
        array $body,
        array $headers,
        array $client
    ): array {
        $method = strtoupper($method);
        $path = '/' . trim((string)(parse_url($path, PHP_URL_PATH) ?: '/'), '/');
        $path = preg_replace('#^/api/v1#', '', $path) ?: '/';
        if ((string)$client['store_id'] !== StoreContext::current()->id()) {
            throw new DomainException('API credential store does not match StoreContext');
        }
        $key = self::header($headers, 'idempotency-key');
        $store = StoreContext::current();

        if ($method === 'GET' && $path === '/products') {
            ApiAuth::requireScope($client, 'products:read');
            return self::ok(self::page('products', 'id, sku, slug, name, product_type, price, compare_price,
                currency, stock, status, weight_g, created_at, updated_at', $store->catalogId(), $query, [self::class, 'productDto']));
        }
        if ($method === 'GET' && preg_match('#^/products/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'products:read');
            $product = Products::byId((int)$m[1]);
            return $product ? self::ok(['data' => self::productDto($product)]) : self::notFound('Product not found');
        }
        if ($method === 'PATCH' && preg_match('#^/products/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'products:write');
            return self::patchProduct((int)$m[1], $body, $key, $client, $path);
        }
        if ($method === 'GET' && $path === '/orders') {
            ApiAuth::requireScope($client, 'orders:read');
            return self::ok(self::page('orders', '*', $store->id(), $query, [self::class, 'orderDto']));
        }
        if ($method === 'GET' && preg_match('#^/orders/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'orders:read');
            $order = Orders::byId((int)$m[1]);
            return $order ? self::ok(['data' => self::orderDto($order)]) : self::notFound('Order not found');
        }
        if ($method === 'POST' && preg_match('#^/orders/(\d+)/status$#', $path, $m)) {
            ApiAuth::requireScope($client, 'orders:write');
            return self::changeOrderStatus((int)$m[1], $body, $key, $client, $path);
        }
        if ($method === 'GET' && preg_match('#^/orders/(\d+)/shipments$#', $path, $m)) {
            ApiAuth::requireScope($client, 'orders:read');
            if (!Orders::byId((int)$m[1])) return self::notFound('Order not found');
            $shipments = Fulfillment::shipments((int)$m[1]);
            $items = Fulfillment::shipmentItems(array_column($shipments, 'id'));
            return self::ok(['data' => array_map(
                static fn(array $shipment): array => self::shipmentDto($shipment, $items[(int)$shipment['id']] ?? []),
                $shipments
            )]);
        }
        if ($method === 'GET' && $path === '/categories') {
            ApiAuth::requireScope($client, 'categories:read');
            return self::ok(self::page('categories', 'id, parent_id, slug, name, path, depth, sort_order',
                $store->catalogId(), $query, [self::class, 'categoryDto']));
        }
        if ($method === 'GET' && preg_match('#^/categories/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'categories:read');
            $category = Categories::byId((int)$m[1]);
            return $category ? self::ok(['data' => self::categoryDto($category)]) : self::notFound('Category not found');
        }
        if ($method === 'GET' && $path === '/customers') {
            ApiAuth::requireScope($client, 'customers:read');
            // Explicit columns: password hashes and tokens never leave the database.
            return self::ok(self::page('customers', 'id, email, name, phone, locale, status, email_verified_at,
                last_login_at, created_at, updated_at', $store->id(), $query, [self::class, 'customerDto']));
        }
        if ($method === 'GET' && preg_match('#^/customers/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'customers:read');
            $customer = Customers::byId((int)$m[1]);
            return $customer ? self::ok(['data' => self::customerDto($customer)]) : self::notFound('Customer not found');
        }
        if ($method === 'GET' && $path === '/inventory') {
            ApiAuth::requireScope($client, 'inventory:read');
            $sku = self::sku($query['sku'] ?? null);
            return self::catalogSku($sku) ? self::ok(['data' => self::inventoryDto($sku)]) : self::notFound('SKU not found');
        }
        if ($method === 'POST' && $path === '/inventory/adjustments') {
            ApiAuth::requireScope($client, 'inventory:write');
            return self::adjustInventory($body, $key, $client, $path);
        }
        return self::notFound('Unknown API v1 route');
    }

    /**
     * The HTTP status and error body for an exception escaping dispatch() (used by api/v1/index.php).
     *
     * @return array{status:int,body:array}
     */
    public static function errorResponse(Throwable $e): array {
        if ($e instanceof JsonException || $e instanceof InvalidArgumentException) {
            return self::error(400, 'invalid_request', $e->getMessage());
        }
        if ($e instanceof UnexpectedValueException) return self::error(409, 'idempotency_conflict', $e->getMessage());
        if ($e instanceof DomainException) return self::error(403, 'forbidden', 'Insufficient scope or store permission');
        if ($e instanceof ApiAuthException) return self::error(401, 'unauthorized', 'Invalid API credential');
        error_log('API v1 error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return self::error(500, 'internal_error', 'Internal error');
    }

    /** Cursor page over a store-owned table in stable id order; $table and $columns are code constants. */
    private static function page(string $table, string $columns, string $storeId, array $query, callable $dto): array {
        $limit = self::limit($query['limit'] ?? 25);
        $afterId = self::decodeCursor((string)($query['cursor'] ?? ''));
        $stmt = Db::get()->prepare("SELECT {$columns} FROM {$table} WHERE store_id=:store AND id>:after ORDER BY id LIMIT :limit");
        $stmt->bindValue(':store', $storeId);
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        return [
            'data' => array_map($dto, $rows),
            'meta' => ['limit' => $limit, 'next_cursor' => $hasMore ? self::encodeCursor((int)end($rows)['id']) : null],
        ];
    }

    /**
     * Runs $work in one transaction with the Idempotency-Key claim: the first request applies and stores
     * its response, an identical retry (also a concurrent one) gets the stored response, the same key with
     * another request is a 409. $work returns status/body, 'store' => false to roll back without using the
     * key (it can be retried), and an optional 'after' callable that runs only after the commit.
     *
     * @return array{status:int,body:array}
     */
    private static function idempotentWrite(array $client, string $key, string $method, string $path, array $body, callable $work): array {
        $hash = ApiAuth::requestHash($method, $path, $body);
        $db = Db::get();
        $db->beginTransaction();
        try {
            if (!ApiAuth::claim($client, $key, $hash)) {
                // Completed earlier or by a concurrent request that held the claim until its commit.
                $db->rollBack();
                $replay = ApiAuth::replay($client, $key, $hash);
                if ($replay === null || $replay['status'] === 0) {
                    throw new UnexpectedValueException('Idempotency key is still being processed');
                }
                $replay['body']['meta']['idempotent_replay'] = true;
                return $replay;
            }
            $result = $work();
            if (($result['store'] ?? true) === false) {
                $db->rollBack();
                return ['status' => $result['status'], 'body' => $result['body']];
            }
            ApiAuth::storeResponse($client, $key, $hash, $result['status'], $result['body']);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        if (isset($result['after'])) ($result['after'])();
        return ['status' => $result['status'], 'body' => $result['body']];
    }

    /** @return array{status:int,body:array} */
    private static function patchProduct(int $productId, array $body, string $key, array $client, string $path): array {
        $allowed = ['name', 'status', 'price', 'compare_price', 'stock', 'weight_g'];
        if ($body === [] || array_diff(array_keys($body), $allowed)) {
            throw new InvalidArgumentException('Product patch contains unsupported fields');
        }
        foreach (['price', 'stock', 'weight_g'] as $field) {
            if (array_key_exists($field, $body) && (!is_int($body[$field]) || $body[$field] < 0)) {
                throw new InvalidArgumentException("{$field} must be a non-negative integer");
            }
        }
        if (array_key_exists('compare_price', $body) && $body['compare_price'] !== null
            && (!is_int($body['compare_price']) || $body['compare_price'] < 0)) {
            throw new InvalidArgumentException('compare_price must be null or a non-negative integer');
        }
        // array_key_exists, not isset: an explicit null must be rejected, not skip validation.
        if (array_key_exists('status', $body) && !in_array($body['status'], ['active', 'draft', 'archived'], true)) {
            throw new InvalidArgumentException('Invalid product status');
        }
        if (array_key_exists('name', $body)
            && (!is_string($body['name']) || trim($body['name']) === '' || strlen($body['name']) > 191)) {
            throw new InvalidArgumentException('Invalid product name');
        }

        return self::idempotentWrite($client, $key, 'PATCH', $path, $body, static function() use ($productId, $body, $allowed, $client): array {
            $old = Products::byId($productId);
            if (!$old) return self::notFound('Product not found');

            $set = [];
            $params = [':id' => $productId, ':store' => StoreContext::current()->catalogId()];
            foreach ($allowed as $field) {
                if (!array_key_exists($field, $body)) continue;
                $set[] = "{$field}=:_{$field}";
                $params[":_{$field}"] = $field === 'name' ? trim((string)$body[$field]) : $body[$field];
            }
            $set[] = 'updated_at=' . Db::now();
            Db::get()->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE id=:id AND store_id=:store')->execute($params);
            if (array_key_exists('stock', $body) && (int)$old['stock'] !== $body['stock']) {
                // "stock" is available quantity; the warehouse ledger is the source of truth (as in admin/CSV).
                try {
                    Products::setAvailableStock((string)$old['sku'], (string)($old['product_type'] ?? 'simple'), $body['stock'], 'api_adjustment', 'api_v1');
                } catch (RuntimeException $e) {
                    return self::error(409, 'stock_conflict', $e->getMessage()) + ['store' => false];
                }
            }
            $updated = Products::byId($productId);
            AuditLog::log('product', $productId, $old, $updated, 'API v1 client ' . $client['key_id']);
            Outbox::record(DomainEvents::PRODUCT_UPDATED, 'product', $productId, self::productDto($updated));
            if (array_key_exists('stock', $body) && (int)$old['stock'] !== (int)$updated['stock']) {
                Outbox::record(DomainEvents::STOCK_ADJUSTED, 'product', $productId, [
                    'product_id' => $productId,
                    'sku' => (string)$updated['sku'],
                    'before' => (int)$old['stock'],
                    'after' => (int)$updated['stock'],
                    'reason' => 'api.v1.product.patch',
                ]);
            }
            return ['status' => 200, 'body' => ['data' => self::productDto($updated), 'meta' => ['idempotent_replay' => false]]];
        });
    }

    /**
     * Order status change through OrderWorkflow (stock, events, audit, jobs) with the same rules as the
     * admin: 'paid' needs a confirmed payment, 'refunded' the refunds:write scope.
     *
     * @return array{status:int,body:array}
     */
    private static function changeOrderStatus(int $orderId, array $body, string $key, array $client, string $path): array {
        if ($body === [] || array_diff(array_keys($body), ['status', 'note'])) {
            throw new InvalidArgumentException('Order status change accepts only status and note');
        }
        $to = $body['status'] ?? null;
        if (!is_string($to) || !in_array($to, OrderWorkflow::statuses(), true)) {
            throw new InvalidArgumentException('Invalid order status');
        }
        $note = $body['note'] ?? null;
        if ($note !== null && (!is_string($note) || strlen($note) > 500)) {
            throw new InvalidArgumentException('note must be null or a string of at most 500 bytes');
        }
        if ($to === 'refunded') ApiAuth::requireScope($client, 'refunds:write');

        return self::idempotentWrite($client, $key, 'POST', $path, $body, static function() use ($orderId, $to, $note, $client): array {
            $order = Orders::byId($orderId);
            $reason = OrderWorkflow::adminRejection($order, $to, true);
            if ($reason === 'not_found') return self::notFound('Order not found');
            if ($reason !== null) {
                return ['status' => 422, 'body' => ['error' => [
                    'code' => $reason,
                    'message' => $reason === 'payment_not_confirmed'
                        ? 'The order payment is not confirmed'
                        : "The order cannot move from '{$order['status']}' to '{$to}'",
                    'allowed' => OrderWorkflow::allowedTransitions((string)$order['status']),
                ]]];
            }
            $label = 'API v1 client ' . $client['key_id'] . ($note !== null && trim($note) !== '' ? ': ' . trim($note) : '');
            try {
                $jobs = OrderWorkflow::transitionWithin($orderId, $to, $label);
            } catch (RuntimeException | LogicException $e) {
                // E.g. backordered items on 'shipped' or a concurrent change: nothing is kept, the key can be retried.
                return self::error(409, 'order_conflict', $e->getMessage()) + ['store' => false];
            }
            return [
                'status' => 200,
                'body' => ['data' => self::orderDto(Orders::byId($orderId)), 'meta' => ['idempotent_replay' => false]],
                'after' => static fn() => OrderWorkflow::pushDeferredJobs($jobs),
            ];
        });
    }

    /**
     * Relative on-hand change of one SKU in one warehouse (default warehouse unless "warehouse" is a
     * code), booked in the stock ledger; a change that would break reservations is a 409 stock_conflict.
     *
     * @return array{status:int,body:array}
     */
    private static function adjustInventory(array $body, string $key, array $client, string $path): array {
        if (array_diff(array_keys($body), ['sku', 'delta', 'warehouse', 'reason'])) {
            throw new InvalidArgumentException('Inventory adjustment contains unsupported fields');
        }
        $sku = self::sku($body['sku'] ?? null);
        $delta = $body['delta'] ?? null;
        if (!is_int($delta) || $delta === 0 || abs($delta) > 1000000) {
            throw new InvalidArgumentException('delta must be a non-zero integer');
        }
        $warehouseCode = $body['warehouse'] ?? null;
        if ($warehouseCode !== null && (!is_string($warehouseCode) || $warehouseCode === '' || strlen($warehouseCode) > 64)) {
            throw new InvalidArgumentException('warehouse must be a warehouse code');
        }
        $reason = $body['reason'] ?? null;
        if ($reason !== null && (!is_string($reason) || strlen($reason) > 191)) {
            throw new InvalidArgumentException('reason must be null or a string of at most 191 bytes');
        }

        return self::idempotentWrite($client, $key, 'POST', $path, $body, static function() use ($sku, $delta, $warehouseCode, $reason, $client): array {
            $productId = self::catalogSku($sku);
            if ($productId === null) return self::notFound('SKU not found');
            $warehouse = $warehouseCode === null ? Warehouse::defaultWarehouse() : Warehouse::byCode($warehouseCode);
            if (!$warehouse) return self::notFound('Warehouse not found');
            $before = Warehouse::totalAvailable($sku);
            try {
                Warehouse::adjustStock((int)$warehouse['id'], $sku, $delta, 'api_adjustment', 'api_v1', (string)$client['key_id']);
            } catch (RuntimeException $e) {
                return self::error(409, 'stock_conflict', $e->getMessage()) + ['store' => false];
            }
            $after = Warehouse::totalAvailable($sku);
            $note = 'API v1 client ' . $client['key_id'] . ($reason !== null && trim($reason) !== '' ? ': ' . trim($reason) : '');
            AuditLog::log('inventory', $productId, ['sku' => $sku, 'available' => $before],
                ['sku' => $sku, 'available' => $after, 'warehouse' => (string)$warehouse['code'], 'delta' => $delta], $note);
            Outbox::record(DomainEvents::STOCK_ADJUSTED, 'product', $productId, [
                'product_id' => $productId,
                'sku' => $sku,
                'before' => $before,
                'after' => $after,
                'reason' => 'api.v1.inventory.adjust',
            ]);
            return ['status' => 200, 'body' => ['data' => self::inventoryDto($sku), 'meta' => ['idempotent_replay' => false]]];
        });
    }

    /** Owning product id of a product or variant SKU in the active catalog, null for other catalogs. */
    private static function catalogSku(string $sku): ?int {
        $catalog = StoreContext::current()->catalogId();
        $stmt = Db::get()->prepare('SELECT id FROM products WHERE sku = :sku AND store_id = :store
            UNION ALL SELECT p.id FROM variants v JOIN products p ON p.id = v.product_id WHERE v.sku = :sku2 AND p.store_id = :store2');
        $stmt->execute([':sku' => $sku, ':store' => $catalog, ':sku2' => $sku, ':store2' => $catalog]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private static function sku(mixed $value): string {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 191) throw new InvalidArgumentException('sku is required');
        return trim($value);
    }

    public static function productDto(array $row): array {
        return [
            'id' => (int)$row['id'],
            'sku' => (string)$row['sku'],
            'slug' => (string)$row['slug'],
            'name' => (string)$row['name'],
            'type' => (string)($row['product_type'] ?? 'simple'),
            'price' => ['amount' => (int)$row['price'], 'currency' => strtolower((string)($row['currency'] ?? 'eur'))],
            'compare_price' => $row['compare_price'] === null ? null : (int)$row['compare_price'],
            'stock' => (int)$row['stock'],
            'status' => (string)$row['status'],
            'weight_g' => (int)($row['weight_g'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public static function orderDto(array $row): array {
        return [
            'id' => (int)$row['id'],
            'number' => (string)$row['order_number'],
            'status' => (string)$row['status'],
            'payment_status' => (string)($row['payment_status'] ?? 'pending'),
            'customer' => [
                'id' => $row['customer_id'] === null ? null : (int)$row['customer_id'],
                'name' => (string)$row['customer_name'],
                'email' => (string)$row['customer_email'],
            ],
            'totals' => [
                'subtotal' => (int)$row['subtotal'],
                'discount' => (int)$row['discount_amount'],
                'shipping' => (int)$row['shipping_cost'],
                'tax' => (int)$row['tax_amount'],
                'total' => (int)$row['total'],
                'currency' => strtolower((string)$row['currency']),
            ],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    public static function categoryDto(array $row): array {
        return [
            'id' => (int)$row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (int)$row['parent_id'],
            'slug' => (string)$row['slug'],
            'name' => (string)$row['name'],
            'path' => (string)$row['path'],
            'depth' => (int)$row['depth'],
            'sort_order' => (int)($row['sort_order'] ?? 0),
        ];
    }

    public static function customerDto(array $row): array {
        return [
            'id' => (int)$row['id'],
            'email' => (string)$row['email'],
            'name' => (string)($row['name'] ?? ''),
            'phone' => $row['phone'] === null || $row['phone'] === '' ? null : (string)$row['phone'],
            'locale' => $row['locale'] === null || $row['locale'] === '' ? null : (string)$row['locale'],
            'status' => (string)$row['status'],
            'email_verified_at' => $row['email_verified_at'] === null ? null : (string)$row['email_verified_at'],
            'last_login_at' => $row['last_login_at'] === null ? null : (string)$row['last_login_at'],
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    public static function inventoryDto(string $sku): array {
        return [
            'sku' => $sku,
            'available' => Warehouse::totalAvailable($sku),
            'warehouses' => array_map(static fn(array $row): array => [
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'qty' => (int)$row['qty'],
                'reserved' => (int)$row['reserved'],
                'available' => max(0, (int)$row['qty'] - (int)$row['reserved']),
            ], Warehouse::stockBySku($sku)),
        ];
    }

    public static function shipmentDto(array $row, array $items): array {
        return [
            'id' => (int)$row['id'],
            'status' => (string)$row['status'],
            'warehouse_id' => $row['warehouse_id'] === null ? null : (int)$row['warehouse_id'],
            'carrier' => $row['carrier'] === null ? null : (string)$row['carrier'],
            'tracking_number' => $row['tracking_number'] === null ? null : (string)$row['tracking_number'],
            'shipped_at' => $row['shipped_at'] === null ? null : (string)$row['shipped_at'],
            'created_at' => (string)($row['created_at'] ?? ''),
            'items' => array_map(static fn(array $item): array => [
                'order_item_id' => (int)$item['order_item_id'],
                'sku' => (string)$item['sku'],
                'quantity' => (int)$item['quantity'],
                'status' => (string)$item['status'],
            ], $items),
        ];
    }

    /** @return array{status:int,body:array} */
    private static function ok(array $body): array {
        return ['status' => 200, 'body' => $body];
    }

    /** @return array{status:int,body:array} */
    private static function notFound(string $message): array {
        return self::error(404, 'not_found', $message);
    }

    /** @return array{status:int,body:array} */
    private static function error(int $status, string $code, string $message): array {
        return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message]]];
    }

    private static function limit(mixed $value): int {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) throw new InvalidArgumentException('Invalid limit');
        return max(1, min(100, (int)$value));
    }

    private static function encodeCursor(int $id): string {
        return rtrim(strtr(base64_encode('v1:' . $id), '+/', '-_'), '=');
    }

    private static function decodeCursor(string $cursor): int {
        if ($cursor === '') return 0;
        if (!preg_match('/^[A-Za-z0-9_-]{4,64}$/D', $cursor)) throw new InvalidArgumentException('Invalid cursor');
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || !preg_match('/^v1:(\d+)$/D', $decoded, $m)) throw new InvalidArgumentException('Invalid cursor');
        return (int)$m[1];
    }

    private static function header(array $headers, string $name): string {
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === strtolower($name)) return trim((string)$value);
        }
        return '';
    }
}
