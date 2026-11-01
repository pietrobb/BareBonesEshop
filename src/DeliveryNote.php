<?php
/**
 * Delivery notes (packing slips) for orders of the current store, as printable HTML or PDF.
 * A delivery note travels with the parcel, so it lists goods and quantities but never prices.
 */
final class DeliveryNote {
    public const MAX_ORDERS = 200;

    /**
     * Delivery notes of orders of the current store, in the requested order; foreign or unknown ids are skipped.
     * A note goes into one parcel: an order with shipments gets one note per (not cancelled) shipment listing that
     * shipment's goods, an order without shipments one note with all its items. $shipmentId picks one shipment.
     *
     * @param int[] $orderIds
     * @return list<array{order: array, items: array, address: array, pickup: ?array, shipment: ?array{number:int,count:int,id:int}}>
     */
    public static function forOrders(array $orderIds, ?int $shipmentId = null): array {
        $notes = [];
        foreach (array_slice(array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0))), 0, self::MAX_ORDERS) as $orderId) {
            $order = Orders::byId($orderId);
            if (!$order) continue;
            $address = json_decode((string)($order['shipping_addr'] ?? ''), true);
            $pickup = json_decode((string)($order['shipping_rate_snapshot'] ?? ''), true)['pickup_point'] ?? null;
            $base = ['order' => $order, 'address' => is_array($address) ? $address : [], 'pickup' => is_array($pickup) ? $pickup : null];
            $items = Orders::items($orderId);
            $shipments = array_values(array_filter(Fulfillment::shipments($orderId), static fn(array $s): bool => ($s['status'] ?? '') !== 'cancelled'));
            if ($shipments === []) {
                if ($shipmentId === null) $notes[] = $base + ['items' => $items, 'shipment' => null];
                continue;
            }
            $byId = array_column($items, null, 'id');
            $shipmentItems = Fulfillment::shipmentItems(array_column($shipments, 'id'));
            foreach ($shipments as $i => $shipment) {
                if ($shipmentId !== null && (int)$shipment['id'] !== $shipmentId) continue;
                $lines = [];
                foreach ($shipmentItems[(int)$shipment['id']] ?? [] as $line) {
                    if (($line['status'] ?? '') === 'cancelled') continue;
                    $lines[] = ['sku' => (string)$line['sku'], 'name' => (string)($byId[(int)$line['order_item_id']]['name'] ?? $line['sku']), 'quantity' => (int)$line['quantity']];
                }
                $notes[] = $base + ['items' => $lines, 'shipment' => ['number' => $i + 1, 'count' => count($shipments), 'id' => (int)$shipment['id']]];
            }
        }
        return $notes;
    }

    /** Header of the note: the order number, plus "parcel n of m" when the order ships in several parcels. */
    private static function title(array $note): string {
        $title = I18n::t('admin.delivery_note.title') . ' ' . $note['order']['order_number'];
        $shipment = $note['shipment'] ?? null;
        return $shipment && $shipment['count'] > 1
            ? $title . ' – ' . I18n::format('admin.delivery_note.parcel', ['number' => $shipment['number'], 'count' => $shipment['count']])
            : $title;
    }

    /** @return list<string> recipient block: name, company lines, street, zip city, country, phone */
    public static function recipientLines(array $note): array {
        $address = $note['address'];
        $lines = [(string)$note['order']['customer_name']];
        foreach (CheckoutService::companyLines($address) as $line) $lines[] = $line;
        $lines[] = (string)($address['street'] ?? $address['address'] ?? '');
        $lines[] = trim(($address['zip'] ?? '') . ' ' . ($address['city'] ?? ''));
        $lines[] = strtoupper((string)($address['country'] ?? ''));
        $lines[] = (string)($note['order']['customer_phone'] ?? '');
        return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
    }

    /** One print-ready HTML document, one page per order. */
    public static function html(array $notes): string {
        $seller = (string)(StoreContext::current()->legal()['name'] ?? '');
        $out = '<!DOCTYPE html><html lang="' . esc(I18n::locale()) . '"><head><meta charset="UTF-8"><title>' . esc(I18n::t('admin.delivery_note.title')) . '</title>'
            . '<style>body{font:14px/1.4 system-ui,sans-serif;color:#000;margin:0}.note{padding:24px;page-break-after:always}.note:last-child{page-break-after:auto}'
            . 'table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border-bottom:1px solid #999;padding:6px;text-align:left}td.qty,th.qty{text-align:right}'
            . '.sign{margin-top:48px;border-top:1px solid #000;width:240px;padding-top:4px}@media print{.note{padding:0}}</style></head><body>';
        self::eachInOrderLocale($notes, static function (array $note) use (&$out, $seller): void {
            $order = $note['order'];
            $out .= '<section class="note" lang="' . esc(I18n::locale()) . '" data-delivery-note="' . esc((string)$order['order_number']) . '"'
                . (!empty($note['shipment']) ? ' data-shipment="' . (int)$note['shipment']['id'] . '"' : '') . '>'
                . '<h1>' . esc(self::title($note)) . '</h1>'
                . ($seller !== '' ? '<p>' . esc($seller) . '</p>' : '')
                . '<p>' . esc(I18n::t('admin.th.date')) . ': ' . esc(substr((string)$order['created_at'], 0, 10)) . '</p>'
                . '<h2>' . esc(I18n::t('admin.delivery_note.ship_to')) . '</h2><p>' . implode('<br>', array_map('esc', self::recipientLines($note))) . '</p>';
            if (!empty($order['shipping_method'])) $out .= '<p>' . esc(I18n::t('admin.delivery_note.shipping_method')) . ': ' . esc((string)$order['shipping_method']) . '</p>';
            if ($note['pickup']) $out .= '<p>' . esc(I18n::t('pickup_point')) . ': ' . esc((string)($note['pickup']['name'] ?? '')) . ' (ID ' . esc((string)($note['pickup']['id'] ?? '')) . ')</p>';
            if (($customerNote = Orders::customerNote($order)) !== '') $out .= '<p style="white-space:pre-wrap">' . esc(I18n::t('admin.order.note')) . ': ' . esc($customerNote) . '</p>';
            $out .= '<table><thead><tr><th>' . esc(I18n::t('admin.th.sku')) . '</th><th>' . esc(I18n::t('admin.th.name')) . '</th><th class="qty">' . esc(I18n::t('admin.th.qty')) . '</th></tr></thead><tbody>';
            $quantity = 0;
            foreach ($note['items'] as $item) {
                $quantity += (int)$item['quantity'];
                $out .= '<tr><td>' . esc((string)$item['sku']) . '</td><td>' . esc((string)$item['name']) . '</td><td class="qty">' . (int)$item['quantity'] . '</td></tr>';
            }
            $out .= '</tbody><tfoot><tr><th colspan="2">' . esc(I18n::t('admin.delivery_note.total_quantity')) . '</th><th class="qty">' . $quantity . '</th></tr></tfoot></table>'
                . '<p class="sign">' . esc(I18n::t('admin.delivery_note.received_by')) . '</p></section>';
        });
        return $out . '</body></html>';
    }

    /** One A4 PDF, each order starting on a new page; long orders continue on further pages. */
    public static function pdf(array $notes): string {
        $pdf = new MinimalPdf();
        $seller = (string)(StoreContext::current()->legal()['name'] ?? '');
        self::eachInOrderLocale($notes, static function (array $note) use ($pdf, $seller): void {
            $order = $note['order'];
            $pdf->addPage();
            $pdf->text(20, 20, mb_substr(self::title($note), 0, 60), ['size' => 16, 'bold' => true]);
            $pdf->text(130, 20, I18n::t('admin.th.date') . ': ' . substr((string)$order['created_at'], 0, 10), ['size' => 9]);
            if ($seller !== '') $pdf->text(130, 25, $seller, ['size' => 9]);
            $y = 34.0;
            $pdf->text(20, $y, I18n::t('admin.delivery_note.ship_to'), ['size' => 10, 'bold' => true]);
            foreach (self::recipientLines($note) as $line) { $y += 5; $pdf->text(20, $y, mb_substr($line, 0, 80), ['size' => 9]); }
            if (!empty($order['shipping_method'])) { $y += 6; $pdf->text(20, $y, I18n::t('admin.delivery_note.shipping_method') . ': ' . $order['shipping_method'], ['size' => 9]); }
            if ($note['pickup']) { $y += 5; $pdf->text(20, $y, mb_substr(I18n::t('pickup_point') . ': ' . ($note['pickup']['name'] ?? '') . ' (ID ' . ($note['pickup']['id'] ?? '') . ')', 0, 100), ['size' => 9]); }
            if (($customerNote = Orders::customerNote($order)) !== '') { $y += 5; $pdf->text(20, $y, mb_substr(I18n::t('admin.order.note') . ': ' . preg_replace('/\s+/', ' ', $customerNote), 0, 110), ['size' => 9]); }
            $header = static function () use ($pdf, &$y): void {
                $pdf->text(20, $y, I18n::t('admin.th.sku'), ['size' => 9, 'bold' => true]);
                $pdf->text(60, $y, I18n::t('admin.th.name'), ['size' => 9, 'bold' => true]);
                $pdf->text(170, $y, I18n::t('admin.th.qty'), ['size' => 9, 'bold' => true, 'align' => 'right', 'width' => 20]);
                $y += 3;
                $pdf->line(20, $y, 190, $y);
                $y += 5;
            };
            $y += 10;
            $header();
            $quantity = 0;
            foreach ($note['items'] as $item) {
                if ($y > 270) { $pdf->addPage(); $y = 20.0; $header(); }
                $quantity += (int)$item['quantity'];
                $pdf->text(20, $y, mb_substr((string)$item['sku'], 0, 22), ['size' => 9]);
                $pdf->text(60, $y, mb_substr((string)$item['name'], 0, 60), ['size' => 9]);
                $pdf->text(170, $y, (string)(int)$item['quantity'], ['size' => 9, 'align' => 'right', 'width' => 20]);
                $y += 5;
            }
            $pdf->line(20, $y - 2, 190, $y - 2);
            $pdf->text(60, $y + 3, I18n::t('admin.delivery_note.total_quantity'), ['size' => 9, 'bold' => true]);
            $pdf->text(170, $y + 3, (string)$quantity, ['size' => 9, 'bold' => true, 'align' => 'right', 'width' => 20]);
            $pdf->text(20, min($y + 25, 285), I18n::t('admin.delivery_note.received_by') . ': ______________________', ['size' => 9]);
        });
        return $pdf->render();
    }

    /** The note travels to the customer, so each one is written in the language the order was placed in, not the admin's. */
    private static function eachInOrderLocale(array $notes, callable $render): void {
        $current = I18n::locale();
        try {
            foreach ($notes as $note) {
                $locale = (string)($note['order']['locale'] ?? '');
                I18n::setLocale(preg_match('/^[a-z]{2}$/', $locale) && is_file(__DIR__ . '/../lang/' . $locale . '.php') ? $locale : $current);
                $render($note);
            }
        } finally {
            I18n::setLocale($current);
        }
    }
}
