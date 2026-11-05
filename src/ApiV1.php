<?php
/** Stable, additive-first external API v1 contract. */
class ApiV1 {
    private const MAX_INT = Warehouse::MAX_QTY;
    /** How far before server_time meta.next_updated_after resumes an incremental sync (longer than any shop transaction). */
    public const SYNC_OVERLAP_SECONDS = 300;
    /** @return array{status:int,body:array,headers?:array<string,string>} */
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
                currency, stock, status, weight_g, created_at, updated_at', $store->catalogId(), $query, [self::class, 'productDto'], true));
        }
        if ($method === 'POST' && $path === '/products') {
            ApiAuth::requireScope($client, 'products:write');
            return self::withEtag(self::createProduct($body, $key, $client, $path));
        }
        if ($method === 'GET' && preg_match('#^/products/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'products:read');
            $product = Products::byId((int)$m[1]);
            return $product ? self::withEtag(self::ok(['data' => self::productDto($product)])) : self::notFound('Product not found');
        }
        if ($method === 'PATCH' && preg_match('#^/products/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'products:write');
            return self::withEtag(self::patchProduct((int)$m[1], $body, $key, $client, $path, self::header($headers, 'if-match')));
        }
        if ($method === 'GET' && $path === '/orders') {
            ApiAuth::requireScope($client, 'orders:read');
            $page = self::page('orders', '*', $store->id(), $query, static fn(array $row): array => $row, true);
            $items = self::orderItems(array_map(static fn(array $row): int => (int)$row['id'], $page['data']));
            $page['data'] = array_map(static fn(array $row): array => self::orderResource($row, $items[(int)$row['id']] ?? [], self::personalData($client)), $page['data']);
            return self::ok($page);
        }
        if ($method === 'GET' && preg_match('#^/orders/(\d+)$#', $path, $m)) {
            ApiAuth::requireScope($client, 'orders:read');
            $order = Orders::byId((int)$m[1]);
            return $order ? self::ok(['data' => self::orderResource($order, self::orderItems([(int)$order['id']])[(int)$order['id']] ?? [], self::personalData($client))])
                : self::notFound('Order not found');
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
                last_login_at, created_at, updated_at', $store->id(), $query, [self::class, 'customerDto'], true));
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
        Logger::phpLog('API v1 error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        return self::error(500, 'internal_error', 'Internal error');
    }

    /**
     * Cursor page over a store-owned table in stable id order; $table and $columns are code constants. With
     * $changeFilter the page takes updated_after (rows with updated_at at or after it) and meta.server_time is the
     * database clock read before the rows: an incremental sync passes meta.next_updated_after of its first page (server_time
     * less SYNC_OVERLAP_SECONDS) as the next run's updated_after, so a change made while it was paging, or committed
     * late by a transaction that began earlier, is fetched again rather than missed.
     */
    private static function page(string $table, string $columns, string $storeId, array $query, callable $dto, bool $changeFilter = false): array {
        $limit = self::limit($query['limit'] ?? 25);
        $afterId = self::decodeCursor((string)($query['cursor'] ?? ''));
        $updatedAfter = self::updatedAfter($query['updated_after'] ?? null, $changeFilter);
        $db = Db::get();
        $serverTime = $changeFilter ? (string)$db->query('SELECT ' . Db::now())->fetchColumn() : null;
        $stmt = $db->prepare("SELECT {$columns} FROM {$table} WHERE store_id=:store AND id>:after"
            . ($updatedAfter !== null ? ' AND updated_at>=:updated' : '') . ' ORDER BY id LIMIT :limit');
        $stmt->bindValue(':store', $storeId);
        $stmt->bindValue(':after', $afterId, PDO::PARAM_INT);
        if ($updatedAfter !== null) $stmt->bindValue(':updated', $updatedAfter);
        $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $meta = ['limit' => $limit, 'next_cursor' => $hasMore ? self::encodeCursor((int)end($rows)['id']) : null];
        if ($serverTime !== null) {
            $meta['server_time'] = $serverTime;
            // R16: a change committed after this read by a transaction that began earlier carries an updated_at before
            // server_time, so a sync resuming at server_time missed it. The next run resumes SYNC_OVERLAP_SECONDS earlier.
            $meta['next_updated_after'] = (new DateTimeImmutable($serverTime, new DateTimeZone('UTC')))
                ->modify('-' . self::SYNC_OVERLAP_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        }
        return ['data' => array_map($dto, $rows), 'meta' => $meta];
    }

    /**
     * updated_after as the stored UTC "Y-m-d H:i:s": the API's own timestamp format or ISO 8601 with Z or an offset.
     * Null when absent; a value on a list without updated_at, or one that is not such a time, is a 400.
     */
    private static function updatedAfter(mixed $value, bool $allowed): ?string {
        if ($value === null || $value === '') return null;
        if (!$allowed) throw new InvalidArgumentException('updated_after is not supported on this list');
        $pattern = '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';
        $time = is_string($value) && preg_match($pattern, $value) ? date_create_immutable($value, new DateTimeZone('UTC')) : false;
        $errors = DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('updated_after must be a UTC time like 2026-11-04 10:00:00 or 2026-11-04T10:00:00Z');
        }
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
    private static function patchProduct(int $productId, array $body, string $key, array $client, string $path, string $ifMatch = ''): array {
        $allowed = ['name', 'status', 'price', 'compare_price', 'stock', 'weight_g'];
        if ($body === [] || array_diff(array_keys($body), $allowed)) {
            throw new InvalidArgumentException('Product patch contains unsupported fields');
        }
        // The columns are signed 32-bit INTEGER on MySQL: a larger value would fail the write (500) there.
        foreach (['price', 'stock', 'weight_g'] as $field) {
            if (array_key_exists($field, $body) && (!is_int($body[$field]) || $body[$field] < 0 || $body[$field] > self::MAX_INT)) {
                throw new InvalidArgumentException("{$field} must be an integer from 0 to " . self::MAX_INT);
            }
        }
        if (array_key_exists('compare_price', $body) && $body['compare_price'] !== null
            && (!is_int($body['compare_price']) || $body['compare_price'] < 0 || $body['compare_price'] > self::MAX_INT)) {
            throw new InvalidArgumentException('compare_price must be null or an integer from 0 to ' . self::MAX_INT);
        }
        // array_key_exists, not isset: an explicit null must be rejected, not skip validation.
        if (array_key_exists('status', $body) && !in_array($body['status'], ['active', 'draft', 'archived'], true)) {
            throw new InvalidArgumentException('Invalid product status');
        }
        if (array_key_exists('name', $body)
            && (!is_string($body['name']) || trim($body['name']) === '' || strlen($body['name']) > 191)) {
            throw new InvalidArgumentException('Invalid product name');
        }

        return self::idempotentWrite($client, $key, 'PATCH', $path, $body, static function() use ($productId, $body, $allowed, $client, $ifMatch): array {
            // Row lock first, so the If-Match comparison and the update see the same version (no lost update).
            Db::get()->prepare('UPDATE products SET updated_at = updated_at WHERE id = :id AND store_id = :store')
                ->execute([':id' => $productId, ':store' => StoreContext::current()->catalogId()]);
            $old = Products::byId($productId);
            if (!$old) return self::notFound('Product not found');
            if ($ifMatch !== '' && !self::etagMatches($ifMatch, self::etag(self::productDto($old)))) {
                return self::error(412, 'precondition_failed', 'The product changed since the ETag in If-Match was read') + ['store' => false];
            }
            // Same floor as the admin editor and CSV import: a variant priced by a delta must not turn negative.
            if (array_key_exists('price', $body) && $body['price'] < ($floor = ProductVariants::minBasePrice($productId))) {
                return self::error(422, 'price_below_variant_floor', "price must be at least {$floor} so that no variant price is negative") + ['store' => false];
            }

            $set = [];
            $params = [':id' => $productId, ':store' => StoreContext::current()->catalogId()];
            foreach ($allowed as $field) {
                if (!array_key_exists($field, $body)) continue;
                $set[] = "{$field}=:_{$field}";
                $params[":_{$field}"] = $field === 'name' ? trim((string)$body[$field]) : $body[$field];
            }
            $set[] = 'updated_at=' . Db::now();
            Db::get()->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE id=:id AND store_id=:store')->execute($params);
            if (array_key_exists('price', $body)) PriceHistory::record([$productId]);
            if (($body['status'] ?? $old['status']) !== $old['status']) CategoryCounts::refresh(CategoryCounts::ofProducts([$productId]));
            $ledgerBooked = false;
            if (array_key_exists('stock', $body) && (int)$old['stock'] !== $body['stock']) {
                // "stock" is available quantity; the warehouse ledger is the source of truth (as in admin/CSV) and
                // records stock.adjusted itself.
                try {
                    $ledgerBooked = Products::setAvailableStock((string)$old['sku'], (string)($old['product_type'] ?? 'simple'), $body['stock'], 'api_adjustment', 'api_v1');
                } catch (RuntimeException $e) {
                    return self::conflict('stock_conflict', $e);
                }
            }
            $updated = Products::byId($productId);
            AuditLog::log('product', $productId, $old, $updated, 'API v1 client ' . $client['key_id']);
            Outbox::record(DomainEvents::PRODUCT_UPDATED, 'product', $productId, self::productDto($updated));
            if (!$ledgerBooked && array_key_exists('stock', $body) && (int)$old['stock'] !== (int)$updated['stock']) {
                Outbox::record(DomainEvents::STOCK_ADJUSTED, 'product', $productId, [
                    'product_id' => $productId,
                    'sku' => (string)$updated['sku'],
                    'before' => (int)$old['stock'],
                    'after' => (int)$updated['stock'],
                    'reason' => 'api_adjustment',
                    'reference_type' => 'api_v1',
                ]);
            }
            return ['status' => 200, 'body' => ['data' => self::productDto($updated), 'meta' => ['idempotent_replay' => false]],
                'after' => static fn() => Cache::catalogChanged()];
        });
    }

    /**
     * A new simple product through the CSV import path (catalog check, free slug, price history, stock booked in the
     * default warehouse ledger). A SKU already used by a product or variant is a 409 sku_exists; nothing is stored
     * and the key can be retried with another SKU.
     *
     * @return array{status:int,body:array}
     */
    private static function createProduct(array $body, string $key, array $client, string $path): array {
        $allowed = ['sku', 'name', 'price', 'compare_price', 'stock', 'status', 'weight_g', 'slug', 'category_id'];
        if (array_diff(array_keys($body), $allowed)) throw new InvalidArgumentException('Product contains unsupported fields');
        $sku = self::sku($body['sku'] ?? null);
        if (!is_string($body['name'] ?? null) || trim($body['name']) === '' || strlen($body['name']) > 191) {
            throw new InvalidArgumentException('name is required (at most 191 bytes)');
        }
        if (!array_key_exists('price', $body)) throw new InvalidArgumentException('price is required');
        foreach (['price', 'stock', 'weight_g'] as $field) {
            if (array_key_exists($field, $body) && (!is_int($body[$field]) || $body[$field] < 0 || $body[$field] > self::MAX_INT)) {
                throw new InvalidArgumentException("{$field} must be an integer from 0 to " . self::MAX_INT);
            }
        }
        $comparePrice = $body['compare_price'] ?? null;
        if ($comparePrice !== null && (!is_int($comparePrice) || $comparePrice < 0 || $comparePrice > self::MAX_INT)) {
            throw new InvalidArgumentException('compare_price must be null or an integer from 0 to ' . self::MAX_INT);
        }
        $status = $body['status'] ?? 'draft';
        if (!in_array($status, ['active', 'draft', 'archived'], true)) throw new InvalidArgumentException('Invalid product status');
        $slug = $body['slug'] ?? null;
        if ($slug !== null && (!is_string($slug) || Str::slug($slug) !== $slug || strlen($slug) > 191)) {
            throw new InvalidArgumentException('slug must be lowercase letters, digits and hyphens');
        }
        $categoryId = $body['category_id'] ?? null;
        if ($categoryId !== null && (!is_int($categoryId) || $categoryId < 1)) throw new InvalidArgumentException('category_id must be a category id');

        return self::idempotentWrite($client, $key, 'POST', $path, $body, static function() use ($body, $sku, $status, $slug, $categoryId, $comparePrice, $client): array {
            if (SkuGuard::taken($sku)) {
                return self::error(409, 'sku_exists', 'A product or variant already uses this SKU') + ['store' => false];
            }
            if ($categoryId !== null && !Categories::byId($categoryId)) {
                return self::error(422, 'unknown_category', 'category_id is not a category of this catalog') + ['store' => false];
            }
            $db = Db::get();
            $row = ['sku' => $sku, 'name' => trim($body['name']), 'price' => $body['price'], 'status' => $status,
                'weight_g' => $body['weight_g'] ?? 0, 'stock' => (string)($body['stock'] ?? 0)];
            if ($comparePrice !== null) $row['compare_price'] = $comparePrice;
            if ($slug !== null) $row['slug'] = $slug;
            Import::importProduct($row, $db);
            $productId = (int)self::catalogSku($sku);
            if ($categoryId !== null) {
                $db->prepare('UPDATE products SET category_id = :category WHERE id = :id')->execute([':category' => $categoryId, ':id' => $productId]);
            }
            CategoryCounts::refresh(CategoryCounts::ofProducts([$productId]));
            $created = Products::byId($productId);
            AuditLog::log('product', $productId, null, $created, 'API v1 client ' . $client['key_id']);
            // There is no product.created event (the event list is stable): subscribers get the new representation.
            Outbox::record(DomainEvents::PRODUCT_UPDATED, 'product', $productId, self::productDto($created));
            return ['status' => 201, 'body' => ['data' => self::productDto($created), 'meta' => ['idempotent_replay' => false]],
                'after' => static fn() => Cache::catalogChanged()];
        });
    }

    /** Lines of the given orders keyed by order id, in line order. @param list<int> $orderIds */
    private static function orderItems(array $orderIds): array {
        if ($orderIds === []) return [];
        $marks = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = Db::get()->prepare("SELECT oi.* FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE oi.order_id IN ({$marks}) AND o.store_id = ? ORDER BY oi.id");
        $stmt->execute([...$orderIds, StoreContext::current()->id()]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) $items[(int)$item['order_id']][] = $item;
        return $items;
    }

    /**
     * The API's order: the event representation (orderDto) plus shipping, payment and lines, and with $personal (a key
     * granted customers:read) the phone, delivery address and buyer note. R16: v2.1.1 gave those personal data to every
     * orders:read key without notice. A line's VAT and discount come from the order's financial snapshot (the figures
     * of the order and its invoice): the stored line VAT is before the order discount.
     */
    private static function orderResource(array $row, array $items, bool $personal): array {
        $address = json_decode((string)($row['shipping_addr'] ?? ''), true);
        $address = is_array($address) ? $address : [];
        $text = static fn(string $key): ?string => is_scalar($address[$key] ?? null) && trim((string)$address[$key]) !== '' ? trim((string)$address[$key]) : null;
        $shipping = json_decode((string)($row['shipping_rate_snapshot'] ?? ''), true);
        $point = is_array($shipping) && is_array($shipping['pickup_point'] ?? null) ? $shipping['pickup_point'] : null;
        $snapshot = json_decode((string)($row['financial_snapshot'] ?? ''), true);
        $components = is_array($snapshot) ? array_values(array_filter((array)($snapshot['components'] ?? []),
            static fn($component): bool => is_array($component) && ($component['type'] ?? '') === 'product')) : [];
        if (count($components) !== count($items)) $components = array_fill(0, count($items), null);
        $dto = self::orderDto($row);
        if ($personal) $dto['customer']['phone'] = trim((string)($row['customer_phone'] ?? '')) === '' ? null : trim((string)$row['customer_phone']);
        $personalData = !$personal ? [] : [
            'address' => [
                'company' => $text('company'),
                'street' => $text('street') ?? $text('address'),
                'city' => $text('city'),
                'zip' => $text('zip'),
                'country' => ($country = $text('country')) === null ? null : strtoupper($country),
                'ico' => $text('ico'),
                'dic' => $text('dic'),
                'ic_dph' => $text('ic_dph'),
            ],
            'notes' => trim((string)($row['notes'] ?? '')) === '' ? null : (string)$row['notes'],
        ];
        return $dto + [
            'locale' => trim((string)($row['locale'] ?? '')) === '' ? null : (string)$row['locale'],
        ] + $personalData + [
            'shipping' => [
                'method' => trim((string)($row['shipping_method'] ?? '')) === '' ? null : (string)$row['shipping_method'],
                'pickup_point' => $point === null ? null : [
                    'id' => isset($point['id']) && is_scalar($point['id']) ? (string)$point['id'] : null,
                    'name' => isset($point['name']) && is_scalar($point['name']) ? (string)$point['name'] : null,
                    'address' => isset($point['address']) && is_scalar($point['address']) ? (string)$point['address'] : null,
                ],
            ],
            'payment_method' => trim((string)($row['payment_method'] ?? '')) === '' ? null : (string)$row['payment_method'],
            'items' => array_map(static fn(array $item, ?array $component): array => [
                'id' => (int)$item['id'],
                'product_id' => $item['product_id'] === null ? null : (int)$item['product_id'],
                'variant_id' => ($item['variant_id'] ?? null) === null ? null : (int)$item['variant_id'],
                'sku' => (string)$item['sku'],
                'name' => (string)$item['name'],
                'quantity' => (int)$item['quantity'],
                'unit_price' => (int)$item['unit_price'],
                'total' => (int)$item['total'],
                'discount' => $component === null ? 0 : (int)($component['discount'] ?? 0),
                // Always a float (R17: 2000 / 100 was the int 20 while a line without a component gave 20.0).
                'vat_rate' => $component !== null ? (int)$component['rate_basis_points'] / 100.0
                    : (($item['vat_rate'] ?? null) === null ? null : (float)$item['vat_rate']),
                'vat_amount' => $component !== null ? (int)$component['vat'] : (int)($item['vat_amount'] ?? 0),
            ], $items, $components),
        ];
    }

    /**
     * Order status change through OrderWorkflow (stock, events, audit, jobs) with the same rules as the
     * admin: 'paid' needs a confirmed payment, 'refunded' the refunds:write scope.
     *
     * @return array{status:int,body:array}
     */
    private static function changeOrderStatus(int $orderId, array $body, string $key, array $client, string $path): array {
        if ($body === [] || array_diff(array_keys($body), ['status', 'note', 'acknowledge_withdrawal'])) {
            throw new InvalidArgumentException('Order status change accepts only status, note and acknowledge_withdrawal');
        }
        $acknowledged = $body['acknowledge_withdrawal'] ?? false;
        if (!is_bool($acknowledged)) throw new InvalidArgumentException('acknowledge_withdrawal must be a boolean');
        $to = $body['status'] ?? null;
        if (!is_string($to) || !in_array($to, OrderWorkflow::statuses(), true)) {
            throw new InvalidArgumentException('Invalid order status');
        }
        $note = $body['note'] ?? null;
        if ($note !== null && (!is_string($note) || strlen($note) > 500)) {
            throw new InvalidArgumentException('note must be null or a string of at most 500 bytes');
        }
        // Cancelling captured money refunds it: like 'refunded' it needs refunds:write, not just orders:write.
        $current = Orders::byId($orderId);
        if ($to === 'refunded' || ($current && OrderWorkflow::returnsMoney($current, $to))) ApiAuth::requireScope($client, 'refunds:write');

        return self::idempotentWrite($client, $key, 'POST', $path, $body, static function() use ($orderId, $to, $note, $client, $acknowledged): array {
            $order = Orders::byId($orderId);
            $mayRefund = (bool)array_intersect(['refunds:write', '*'], $client['scopes'] ?? []);
            $reason = OrderWorkflow::adminRejection($order, $to, $mayRefund, $acknowledged);
            if ($reason === 'not_found') return self::notFound('Order not found');
            if ($reason === 'forbidden') return self::error(403, 'forbidden', 'Missing API scope: refunds:write') + ['store' => false];
            if ($reason !== null) {
                return ['status' => 422, 'body' => ['error' => [
                    'code' => $reason,
                    'message' => match ($reason) {
                        'payment_not_confirmed' => 'The order payment is not confirmed',
                        'withdrawn' => 'The customer withdrew from this order; send "acknowledge_withdrawal": true to ship it anyway',
                        default => "The order cannot move from '{$order['status']}' to '{$to}'",
                    },
                    'allowed' => OrderWorkflow::allowedFor($order),
                ]]];
            }
            $label = 'API v1 client ' . $client['key_id'] . ($note !== null && trim($note) !== '' ? ': ' . trim($note) : '');
            try {
                $jobs = OrderWorkflow::transitionWithin($orderId, $to, $label);
            } catch (RuntimeException | LogicException $e) {
                // E.g. backordered items on 'shipped' or a concurrent change: nothing is kept, the key can be retried.
                return self::conflict('order_conflict', $e);
            }
            return [
                'status' => 200,
                'body' => ['data' => self::orderResource(Orders::byId($orderId), self::orderItems([$orderId])[$orderId] ?? [], self::personalData($client)),
                    'meta' => ['idempotent_replay' => false]],
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
                return self::conflict('stock_conflict', $e);
            }
            $after = Warehouse::totalAvailable($sku);
            $note = 'API v1 client ' . $client['key_id'] . ($reason !== null && trim($reason) !== '' ? ': ' . trim($reason) : '');
            AuditLog::log('inventory', $productId, ['sku' => $sku, 'available' => $before],
                ['sku' => $sku, 'available' => $after, 'warehouse' => (string)$warehouse['code'], 'delta' => $delta], $note);
            return ['status' => 200, 'body' => ['data' => self::inventoryDto($sku), 'meta' => ['idempotent_replay' => false]]];
        });
    }

    /** Whether the key may read customers' personal data on orders (phone, address, note): customers:read or *. */
    private static function personalData(array $client): bool {
        return (bool)array_intersect(['customers:read', '*'], $client['scopes'] ?? []);
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
            'tracking_url' => ($row['tracking_url'] ?? null) === null ? null : (string)$row['tracking_url'],
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

    /** Adds the ETag of the product in a 200 body (GET and PATCH, also a replayed PATCH). */
    private static function withEtag(array $result): array {
        if (in_array($result['status'], [200, 201], true) && is_array($result['body']['data'] ?? null)) {
            $result['headers'] = ['ETag' => self::etag($result['body']['data'])];
        }
        return $result;
    }

    private static function etag(array $dto): string {
        return '"' . substr(hash('sha256', json_encode($dto, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 32) . '"';
    }

    /** If-Match: "*" or a comma-separated list of entity tags (a W/ prefix is ignored). */
    private static function etagMatches(string $ifMatch, string $etag): bool {
        foreach (explode(',', $ifMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || preg_replace('#^W/#', '', $candidate) === $etag) return true;
        }
        return false;
    }

    /** @return array{status:int,body:array} */
    private static function notFound(string $message): array {
        return self::error(404, 'not_found', $message);
    }

    /** @return array{status:int,body:array} */
    private static function error(int $status, string $code, string $message): array {
        return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message]]];
    }

    /**
     * A 409 for a write that lost a conflict. PDOException is a RuntimeException too: its text (SQL, table and
     * constraint names) is logged, the client gets a generic retryable message.
     *
     * @return array{status:int,body:array,store:false}
     */
    private static function conflict(string $code, Throwable $e): array {
        if ($e instanceof PDOException) {
            Logger::phpLog('API v1 ' . $code . ': ' . $e->getMessage());
            return self::error(409, $code, 'The change conflicted with a concurrent update; retry the request') + ['store' => false];
        }
        return self::error(409, $code, $e->getMessage()) + ['store' => false];
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
