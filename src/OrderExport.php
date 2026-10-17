<?php
/**
 * Admin order list filters and the orders CSV export of the current store.
 * Every cell goes through Security::csvCell(), so customer-entered text such as
 * "=HYPERLINK(...)" opens in a spreadsheet as text, never as a formula.
 */
final class OrderExport {
    public const PERIODS = ['today', '7', '30', 'all'];
    public const COLUMNS = ['order_number', 'created_at', 'status', 'payment_status', 'payment_method', 'shipping_method',
        'customer_name', 'customer_email', 'customer_phone', 'company', 'street', 'zip', 'city', 'country',
        'currency', 'subtotal', 'shipping_cost', 'payment_fee', 'tax_amount', 'total', 'items', 'notes'];

    /**
     * Normalized list filters (unknown values fall back to defaults) and the matching WHERE on alias o.
     *
     * @return array{q: string, period: string, status: string, payment_status: string, where: string, params: array<string,string>}
     */
    public static function filters(array $input, string $defaultPeriod = '30'): array {
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $filters = ['q' => $text('q'), 'period' => $text('period'), 'status' => $text('status'), 'payment_status' => $text('payment_status')];
        if (!in_array($filters['period'], self::PERIODS, true)) $filters['period'] = $defaultPeriod;
        if (!in_array($filters['status'], OrderWorkflow::statuses(), true)) $filters['status'] = '';
        if (!in_array($filters['payment_status'], PaymentService::paymentStatuses(), true)) $filters['payment_status'] = '';

        $where = ['o.store_id = :store'];
        $params = [':store' => StoreContext::current()->id()];
        if ($filters['q'] !== '') {
            $like = '%' . Db::likeLiteral(mb_strtolower($filters['q'])) . '%';
            $where[] = "(LOWER(o.order_number) LIKE :q_number ESCAPE '!' OR LOWER(o.customer_name) LIKE :q_name ESCAPE '!' OR LOWER(o.customer_email) LIKE :q_email ESCAPE '!')";
            $params += [':q_number' => $like, ':q_name' => $like, ':q_email' => $like];
        }
        if ($filters['period'] !== 'all') {
            $where[] = 'o.created_at >= :since';
            $params[':since'] = SalesReport::sinceUtc($filters['period'] === 'today' ? 1 : (int)$filters['period']);
        }
        if ($filters['status'] !== '') {
            $where[] = 'o.status = :status';
            $params[':status'] = $filters['status'];
        }
        if ($filters['payment_status'] !== '') {
            $where[] = 'o.payment_status = :payment_status';
            $params[':payment_status'] = $filters['payment_status'];
        }
        return $filters + ['where' => implode(' AND ', $where), 'params' => $params];
    }

    /** Write the filtered orders of the current store as CSV; returns the number of order rows. */
    public static function writeCsv($out, array $input): int {
        $filters = self::filters($input, 'all');
        // Unbuffered on MySQL: orders stream to the CSV, so memory stays flat however many orders match.
        $stmt = Db::prepareUnbuffered("SELECT o.*, (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_quantity
            FROM orders o WHERE {$filters['where']} ORDER BY o.created_at DESC, o.id DESC");
        $stmt->execute($filters['params']);
        // A failure mid-stream is logged, raised as an incident and thrown (CsvExportTruncated).
        return CsvExport::stream($out, $stmt, self::COLUMNS, static function (array $order): array {
            $address = json_decode((string)($order['shipping_addr'] ?? ''), true);
            $address = is_array($address) ? $address : [];
            $money = static fn(string $key): string => Import::formatCents((int)($order[$key] ?? 0));
            $row = [
                $order['order_number'], $order['created_at'], $order['status'], $order['payment_status'] ?? '', $order['payment_method'] ?? '', $order['shipping_method'] ?? '',
                $order['customer_name'], $order['customer_email'], $order['customer_phone'] ?? '', $address['company'] ?? '', $address['street'] ?? '', $address['zip'] ?? '', $address['city'] ?? '', $address['country'] ?? '',
                strtoupper((string)$order['currency']), $money('subtotal'), $money('shipping_cost'), $money('payment_fee'), $money('tax_amount'), $money('total'),
                (int)$order['item_quantity'], $order['notes'] ?? '',
            ];
            return array_map(static fn($cell): string => Security::csvCell(is_scalar($cell) ? (string)$cell : ''), $row);
        }, 'orders');
    }
}
