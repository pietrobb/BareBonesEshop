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

        if ($method === 'GET' && $path === '/products') {
            ApiAuth::requireScope($client, 'products:read');
            return ['status' => 200, 'body' => self::listProducts($query)];
        }
        if ($method === 'GET' && preg_match('#^/products/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'products:read');
            $product = Products::byId((int)$m[1]);
            return $product
                ? ['status' => 200, 'body' => ['data' => self::productDto($product)]]
                : ['status' => 404, 'body' => ['error' => ['code' => 'not_found', 'message' => 'Product not found']]];
        }
        if ($method === 'PATCH' && preg_match('#^/products/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'products:write');
            return self::patchProduct((int)$m[1], $body, self::header($headers, 'idempotency-key'), $client, $path);
        }
        if ($method === 'GET' && $path === '/orders') {
            ApiAuth::requireScope($client, 'orders:read');
            return ['status' => 200, 'body' => self::listOrders($query)];
        }
        if ($method === 'GET' && preg_match('#^/orders/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'orders:read');
            $order = Orders::byId((int)$m[1]);
            return $order
                ? ['status' => 200, 'body' => ['data' => self::orderDto($order)]]
                : ['status' => 404, 'body' => ['error' => ['code' => 'not_found', 'message' => 'Order not found']]];
        }
        return ['status' => 404, 'body' => ['error' => ['code' => 'not_found', 'message' => 'Unknown API v1 route']]];
    }

    private static function listProducts(array $query): array {
        $limit = self::limit($query['limit'] ?? 25);
        $afterId = self::decodeCursor((string)($query['cursor'] ?? ''));
        $stmt = Db::get()->prepare("SELECT id, sku, slug, name, product_type, price, compare_price,
                currency, stock, status, weight_g, created_at, updated_at
            FROM products WHERE store_id=:store AND id>:after ORDER BY id LIMIT :limit");
        $stmt->bindValue(':store', StoreContext::current()->catalogId());
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        return [
            'data' => array_map([self::class, 'productDto'], $rows),
            'meta' => ['limit' => $limit, 'next_cursor' => $hasMore ? self::encodeCursor((int)end($rows)['id']) : null],
        ];
    }

    private static function listOrders(array $query): array {
        $limit = self::limit($query['limit'] ?? 25);
        $afterId = self::decodeCursor((string)($query['cursor'] ?? ''));
        $stmt = Db::get()->prepare("SELECT * FROM orders
            WHERE store_id=:store AND id>:after ORDER BY id LIMIT :limit");
        $stmt->bindValue(':store', StoreContext::current()->id());
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        return [
            'data' => array_map([self::class, 'orderDto'], $rows),
            'meta' => ['limit' => $limit, 'next_cursor' => $hasMore ? self::encodeCursor((int)end($rows)['id']) : null],
        ];
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
        if (isset($body['status']) && !in_array($body['status'], ['active', 'draft', 'archived'], true)) {
            throw new InvalidArgumentException('Invalid product status');
        }
        if (isset($body['name']) && (trim((string)$body['name']) === '' || strlen((string)$body['name']) > 191)) {
            throw new InvalidArgumentException('Invalid product name');
        }

        $hash = ApiAuth::requestHash('PATCH', $path, $body);
        $db = Db::get();
        $db->beginTransaction();
        try {
            $replay = ApiAuth::replay($client, $key, $hash);
            if ($replay !== null) {
                $db->rollBack();
                $replay['body']['meta']['idempotent_replay'] = true;
                return $replay;
            }
            $old = Products::byId($productId);
            if (!$old) {
                $response = ['error' => ['code' => 'not_found', 'message' => 'Product not found']];
                ApiAuth::storeResponse($client, $key, $hash, 404, $response);
                $db->commit();
                return ['status' => 404, 'body' => $response];
            }

            $set = [];
            $params = [':id' => $productId, ':store' => StoreContext::current()->catalogId()];
            foreach ($allowed as $field) {
                if (!array_key_exists($field, $body)) continue;
                $set[] = "{$field}=:_{$field}";
                $params[":_{$field}"] = $field === 'name' ? trim((string)$body[$field]) : $body[$field];
            }
            $set[] = 'updated_at=' . Db::now();
            $stmt = $db->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE id=:id AND store_id=:store');
            $stmt->execute($params);
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
            $response = ['data' => self::productDto($updated), 'meta' => ['idempotent_replay' => false]];
            ApiAuth::storeResponse($client, $key, $hash, 200, $response);
            $db->commit();
            return ['status' => 200, 'body' => $response];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
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
