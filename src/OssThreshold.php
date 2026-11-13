<?php
/**
 * BareBones Shop — the 10 000 EUR OSS threshold (art. 59c of the VAT Directive).
 *
 * With OSS off, a consumer in another member state pays the seller's VAT only while the seller's cross-border B2C
 * sales (goods dispatched there, e-services) stay under 10 000 EUR in the current and the previous calendar year.
 * R34 counted the net of invoices of scenario domestic_subthreshold in EUR only. R35 (low): sales in another currency
 * are converted (eur_exchange_rates of config/vat-rates.json; a currency without a rate is reported, not guessed),
 * and an e-book of a consumer of another member state on an invoice of scenario "domestic" (goods supplied at home,
 * the download billed abroad) counts too. Above the threshold the checkout charges such consumers their own
 * country's VAT, as if OSS were on (CheckoutService::vatContext()); was only a warning.
 */
final class OssThreshold
{
    public const EUR_CENTS = 1000000;

    /** @var array<string, bool> per store, for this request (VatService::reset() clears it) */
    private static array $exceeded = [];

    public static function reset(): void
    {
        self::$exceeded = [];
    }

    /** Whether the current store's seller passed the threshold (current or previous year) with OSS off. */
    public static function exceeded(): bool
    {
        $context = StoreContext::current();
        $storeId = $context->id();
        if (array_key_exists($storeId, self::$exceeded)) return self::$exceeded[$storeId];
        $store = $context->config();
        $exceeded = false;
        if (empty($store['oss_enabled']) && Db::tableExists('invoices')) {
            try {
                $exceeded = self::worstYear(Db::get(), $storeId, $store, (array)(Bootstrap::stores()['stores'] ?? []))['sum'] >= self::EUR_CENTS;
            } catch (\Throwable $e) {
                // The threshold decides a VAT rate: a failed count is reported, the checkout keeps going on the setting.
                IncidentMonitor::report('tax.oss_threshold_failed', 'tax', 'warning', ['exception' => $e]);
            }
        }
        return self::$exceeded[$storeId] = $exceeded;
    }

    /**
     * The seller's (all stores sharing legal.seller) cross-border B2C sales of the worse of the current and the previous
     * year, in EUR cents, and the currencies sold in without a conversion rate.
     * @param array<string, mixed> $stores every store (stores.json)
     * @return array{sum: int, year: int, unconverted: list<string>}
     */
    public static function worstYear(PDO $db, string $storeId, array $store, array $stores): array
    {
        $seller = (string)($store['legal']['seller'] ?? $storeId);
        $sameSeller = [];
        foreach ($stores as $id => $other) {
            if (is_array($other) && (string)($other['legal']['seller'] ?? $id) === $seller) $sameSeller[] = (string)$id;
        }
        $sameSeller = $sameSeller ?: [$storeId];
        $sellerCountry = strtoupper((string)(($store['legal']['country'] ?? null) ?: 'SK'));
        $year = (int)ShopTime::date('Y');
        $worst = ['sum' => 0, 'year' => $year, 'unconverted' => []];
        $unconverted = [];
        foreach ([$year - 1, $year] as $y) {
            $sales = self::yearSales($db, $sameSeller, $sellerCountry, $y);
            $unconverted = array_merge($unconverted, $sales['unconverted']);
            if ($sales['sum'] >= $worst['sum']) $worst = ['sum' => $sales['sum'], 'year' => $y, 'unconverted' => []];
        }
        $worst['unconverted'] = array_values(array_unique($unconverted));
        sort($worst['unconverted']);
        return $worst;
    }

    /**
     * @param list<string> $storeIds
     * @return array{sum: int, unconverted: list<string>}
     */
    private static function yearSales(PDO $db, array $storeIds, string $sellerCountry, int $year): array
    {
        $marks = implode(',', array_fill(0, count($storeIds), '?'));
        $range = [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
        $byCurrency = [];
        // Distance sales at the seller's VAT: the whole invoice (and its credit notes).
        $stmt = $db->prepare("SELECT LOWER(i.currency) AS currency, COALESCE(SUM(i.total_net), 0) AS net FROM invoices i JOIN orders o ON o.id = i.order_id
            WHERE o.store_id IN ({$marks}) AND i.status = 'issued' AND i.vat_scenario = ? AND i.issued_at >= ? AND i.issued_at < ?
            GROUP BY LOWER(i.currency)");
        $stmt->execute([...$storeIds, VatService::SCENARIO_DOMESTIC_SUBTHRESH, ...$range]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byCurrency[(string)$row['currency']] = ($byCurrency[(string)$row['currency']] ?? 0) + (int)$row['net'];
        // Goods supplied at home (scenario domestic), the e-book billed to a consumer of another member state: its lines.
        if (Db::hasColumn('order_items', 'digital', $db)) {
            $stmt = $db->prepare("SELECT i.id, LOWER(i.currency) AS currency, o.id AS order_id, o.customer_name, o.shipping_addr, o.billing_addr FROM invoices i JOIN orders o ON o.id = i.order_id
                WHERE o.store_id IN ({$marks}) AND i.status = 'issued' AND i.type <> 'credit_note' AND i.vat_scenario = ? AND i.issued_at >= ? AND i.issued_at < ?
                  AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.digital = 1)");
            $stmt->execute([...$storeIds, VatService::SCENARIO_DOMESTIC, ...$range]);
            $lines = $db->prepare("SELECT COALESCE(SUM(oi.net_amount), 0) - COALESCE((SELECT SUM(cni.net) FROM credit_note_items cni JOIN invoices cn ON cn.id = cni.credit_note_id
                    WHERE cn.status = 'issued' AND cni.order_item_id IN (SELECT id FROM order_items WHERE order_id = :order2 AND digital = 1)), 0)
                FROM order_items oi WHERE oi.order_id = :order AND oi.digital = 1");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $billing = OrderAddress::billing($row);
                $country = strtoupper(trim((string)($billing['country'] ?? '')));
                $business = trim((string)($billing['ic_dph'] ?? '')) !== '' && empty($billing['ic_dph_unverified']);
                if ($country === '' || $country === $sellerCountry || $business || !VatService::isEuCountry($country)) continue;
                $lines->execute([':order' => (int)$row['order_id'], ':order2' => (int)$row['order_id']]);
                $byCurrency[(string)$row['currency']] = ($byCurrency[(string)$row['currency']] ?? 0) + max(0, (int)$lines->fetchColumn());
            }
        }
        $rates = array_change_key_case((array)(VatService::config()['eur_exchange_rates'] ?? []), CASE_LOWER);
        $sum = 0;
        $unconverted = [];
        foreach ($byCurrency as $currency => $net) {
            if ($currency === 'eur') { $sum += $net; continue; }
            $rate = $rates[$currency] ?? null;
            if (!is_numeric($rate) || (float)$rate <= 0) { if ($net !== 0) $unconverted[] = strtoupper($currency); continue; }
            $sum += (int)round($net * (float)$rate);
        }
        return ['sum' => $sum, 'unconverted' => $unconverted];
    }
}
