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
 *
 * R36 (medium): the count is every distance sale, whatever VAT it was charged with. Only invoices taxed at the seller's
 * VAT (domestic_subthreshold) counted, so a shop that sold past the threshold last year under OSS (or crossed it this
 * year, its later sales being OSS invoices) counted 0, or fell back under it with one credit note, and charged
 * Austrian consumers Slovak VAT again. Each invoice and credit note now stores its distance-sale net (invoices.oss_net,
 * documentNet()): its goods when dispatched to a consumer of another member state (scenario oss or
 * domestic_subthreshold), its e-books when the consumer lives in another member state (also on an export or a
 * domestic invoice; an e-book of a consumer at home no longer counts with the goods), credit notes by their lines or,
 * credited by amount, in proportion. R36 (low): orders placed and not yet invoiced count too; the supply that crosses
 * the threshold is charged the consumer's VAT already (exceeded($saleNet)); and an order is placed under a per-seller
 * lock that checks the decision again (guardOrder()), so concurrent orders at the threshold cannot all pass at home VAT.
 */
final class OssThreshold
{
    public const EUR_CENTS = 1000000;

    /** @var array<string, array{previous: int, current: int}> per store, for this request (VatService::reset() clears it) */
    private static array $years = [];

    public static function reset(): void
    {
        self::$years = [];
    }

    /**
     * Whether a consumer of another member state pays their own country's VAT in the current store with OSS off: the
     * seller passed the threshold last year, or passes it this year with the sales so far plus $saleNet (EUR cents,
     * saleNet()) — the supply that exceeds it is the first one taxed in the consumer's state (art. 59c).
     */
    public static function exceeded(int $saleNet = 0): bool
    {
        $context = StoreContext::current();
        $storeId = $context->id();
        $store = $context->config();
        if (!empty($store['oss_enabled']) || !Db::tableExists('invoices')) return false;
        try {
            $years = self::$years[$storeId] ??= self::years(Db::get(), $storeId, $store, (array)(Bootstrap::stores()['stores'] ?? []));
        } catch (\Throwable $e) {
            // The threshold decides a VAT rate: a failed count is reported, the checkout keeps going on the setting.
            IncidentMonitor::report('tax.oss_threshold_failed', 'tax', 'warning', ['exception' => $e]);
            return false;
        }
        return $years['previous'] > self::EUR_CENTS || $years['current'] + max(0, $saleNet) > self::EUR_CENTS;
    }

    /**
     * The seller's (all stores sharing legal.seller) cross-border B2C sales of the worse of the current and the previous
     * year, in EUR cents, and the currencies sold in without a conversion rate.
     * @param array<string, mixed> $stores every store (stores.json)
     * @return array{sum: int, year: int, unconverted: list<string>}
     */
    public static function worstYear(PDO $db, string $storeId, array $store, array $stores): array
    {
        $year = (int)ShopTime::date('Y');
        $worst = ['sum' => 0, 'year' => $year, 'unconverted' => []];
        $unconverted = [];
        foreach ([$year - 1, $year] as $y) {
            $sales = self::yearSales($db, self::sellerStores($storeId, $store, $stores), self::sellerCountry($store), $y, $y === $year);
            $unconverted = array_merge($unconverted, $sales['unconverted']);
            if ($sales['sum'] >= $worst['sum']) $worst = ['sum' => $sales['sum'], 'year' => $y, 'unconverted' => []];
        }
        $worst['unconverted'] = array_values(array_unique($unconverted));
        sort($worst['unconverted']);
        return $worst;
    }

    /**
     * Distance-sale net (cents of the document's currency, negative for a credit note) that an issued invoice or credit
     * note adds to the threshold. $doc is its invoices row; its order is read for the buyer's addresses.
     */
    public static function documentNet(PDO $db, array $doc): int
    {
        $order = self::orderRow($db, (int)($doc['order_id'] ?? 0));
        if ($order === null) return 0;
        $sellerCountry = self::sellerCountry(self::storeConfig((string)($doc['store_id'] ?? $order['store_id'] ?? 'default')));
        if ((string)($doc['type'] ?? 'invoice') !== 'credit_note') {
            [$goods, $digital] = self::distance($order, (string)($doc['vat_scenario'] ?? ''), $sellerCountry);
            if (!$goods && !$digital) return 0;
            $total = (int)($doc['total_net'] ?? 0);
            $digitalNet = min($total, self::digitalNet($db, $order, (string)($doc['financial_snapshot'] ?? '')));
            return ($goods ? $total - $digitalNet : 0) + ($digital ? $digitalNet : 0);
        }
        $credit = json_decode((string)($doc['credit_snapshot'] ?? ''), true);
        $credit = is_array($credit) ? $credit : [];
        // A reversal undoes the credit note it reverses, exactly.
        if (($credit['kind'] ?? '') === 'reversal') return -self::storedNet($db, (int)($credit['reverses_credit_note_id'] ?? 0));
        $source = self::invoiceRow($db, (int)($doc['refers_to_invoice_id'] ?? 0));
        if ($source === null) return 0;
        [$goods, $digital] = self::distance($order, (string)($source['vat_scenario'] ?? ''), $sellerCountry);
        if (!$goods && !$digital) return 0;
        $credited = abs((int)($doc['total_net'] ?? 0));
        $itemLines = array_values(array_filter((array)($credit['lines'] ?? []), static fn($line): bool => is_array($line) && isset($line['order_item_id'])));
        if ($itemLines !== []) {
            $digitalItems = self::digitalItemIds($db, (int)$order['id']);
            $digitalNet = 0;
            foreach ($itemLines as $line) if (isset($digitalItems[(int)$line['order_item_id']])) $digitalNet += abs((int)($line['net'] ?? 0));
            $digitalNet = min($credited, $digitalNet);
            return -(($goods ? $credited - $digitalNet : 0) + ($digital ? $digitalNet : 0));
        }
        // Credited by amount (a refund, a cancellation): the invoice's distance share of what it credits.
        $sourceNet = (int)($source['total_net'] ?? 0);
        if ($sourceNet <= 0) return 0;
        return -(int)round($credited * self::storedNet($db, (int)$source['id']) / $sourceNet);
    }

    /** Store an issued document's distance-sale net (InvoiceService, after it inserted the row); skipped before the migration. */
    public static function record(PDO $db, int $invoiceId): void
    {
        if (!Db::hasColumn('invoices', 'oss_net', $db)) return;
        $doc = self::invoiceRow($db, $invoiceId);
        if ($doc === null) return;
        $db->prepare('UPDATE invoices SET oss_net = :net WHERE id = :id')->execute([':net' => self::documentNet($db, $doc), ':id' => $invoiceId]);
    }

    /**
     * Distance-sale net in EUR cents that a checkout of $items adds (exceeded($saleNet)): the goods when $goods (they go
     * to a consumer of another member state), the downloads when $digital (the consumer lives in one), at the gross the
     * cart shows less the seller's VAT of each line's class — the net they are invoiced at under the threshold. The same
     * inputs at checkout (cart lines) and when the order is placed (snapshot lines) give the same amount.
     * @param list<array{gross: int, vat_class: string, digital: bool}> $items
     */
    public static function saleNet(array $items, bool $goods, bool $digital, string $currency, string $sellerCountry): int
    {
        $net = 0;
        foreach ($items as $item) {
            if (!($item['digital'] ? $digital : $goods)) continue;
            $rate = VatService::rate($sellerCountry, VatService::resolveClass((string)$item['vat_class']));
            $net += (int)round((int)$item['gross'] / (1 + $rate / 100));
        }
        if ($net === 0 || strtolower($currency) === 'eur') return $net;
        $rate = array_change_key_case((array)(VatService::config()['eur_exchange_rates'] ?? []), CASE_LOWER)[strtolower($currency)] ?? null;
        // No rate: the checkout cannot tell how much it adds; the doctor reports the currency.
        return is_numeric($rate) && (float)$rate > 0 ? (int)round($net * (float)$rate) : 0;
    }

    /**
     * R36 (low): Orders::create() calls this before its transaction writes the order. When the order is a
     * distance sale of a store with OSS off, it takes the seller's threshold lock (MySQL GET_LOCK; SQLite's checkout lock
     * is held already), counts again (other orders committed meanwhile) and refuses the order when that decides another
     * VAT than the snapshot was priced with. Returns the lock to release after the commit (releaseOrderLock()).
     * @param array<string, mixed> $snapshot the order's financial snapshot
     * @param array<string, mixed> $customer Orders::create()'s $customer (address, billing_address)
     * @param list<array<string, mixed>> $cartItems the order's cart lines, in snapshot order (their product type)
     * @throws VatChangedException
     */
    public static function guardOrder(PDO $db, array $snapshot, array $customer, array $cartItems): ?string
    {
        $context = StoreContext::current();
        $store = $context->config();
        if (!empty($store['oss_enabled']) || !Db::tableExists('invoices', $db)) return null;
        $sellerCountry = self::sellerCountry($store);
        $order = [
            'shipping_addr' => json_encode($customer['address'] ?? [], JSON_UNESCAPED_UNICODE),
            'billing_addr' => !empty($customer['billing_address']) ? json_encode($customer['billing_address'], JSON_UNESCAPED_UNICODE) : null,
            'customer_name' => (string)($customer['name'] ?? ''),
        ];
        $scenario = (string)($snapshot['scenario'] ?? '');
        $components = array_values(array_filter((array)($snapshot['components'] ?? []), static fn($c): bool => is_array($c) && ($c['type'] ?? '') === 'product'));
        $chargedAbroad = $scenario === VatService::SCENARIO_OSS;
        foreach ($components as $component) if (($component['supply'] ?? '') === VatService::SCENARIO_OSS) $chargedAbroad = true;
        [$goods, $digital] = self::distance($order, $scenario, $sellerCountry);
        if (!$goods && !$digital) return null;
        $lock = null;
        if (Db::isMysql()) {
            $lock = 'bbe_oss_' . substr(hash('sha256', self::sellerKey($context->id(), $store)), 0, 40);
            $stmt = $db->prepare('SELECT GET_LOCK(?, 10)');
            $stmt->execute([$lock]);
            if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Could not take the OSS threshold lock');
        }
        try {
            $items = [];
            foreach ($components as $position => $component) {
                $items[] = ['gross' => (int)($component['gross_before_discount'] ?? 0), 'vat_class' => (string)($component['vat_class'] ?? 'standard'),
                    'digital' => !empty($cartItems[$position]['digital']) || VariantAvailability::isDigitalType((string)($cartItems[$position]['product_type'] ?? ''))];
            }
            unset(self::$years[$context->id()]);
            $abroad = self::exceeded(self::saleNet($items, $goods, $digital, (string)($snapshot['currency'] ?? 'eur'), $sellerCountry));
            // Only the home VAT can turn wrong meanwhile: the sales only grow while the lock is held (an order priced
            // abroad by the caller — a manual order, a test — is left to it).
            if ($abroad && !$chargedAbroad) throw new VatChangedException('The OSS threshold decides another VAT than the order was priced with');
        } catch (\Throwable $e) {
            self::releaseOrderLock($db, $lock);
            throw $e;
        }
        return $lock;
    }

    public static function releaseOrderLock(PDO $db, ?string $lock): void
    {
        if ($lock === null) return;
        try {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        } catch (\Throwable $e) {
            // Released with the connection anyway; logged, the order it guarded is committed or rolled back already.
            Logger::exception('tax.oss_threshold_unlock_failed', $e);
        }
    }

    /**
     * [goods count, e-books count] of an order of scenario $scenario: goods dispatched to a consumer of another member
     * state (the scenarios a checkout gives them), e-books when a consumer — no IČ DPH, or one VIES did not verify — lives
     * (is billed) in another member state.
     * @return array{0: bool, 1: bool}
     */
    public static function distance(array $order, string $scenario, string $sellerCountry): array
    {
        $billing = OrderAddress::billing($order);
        $residence = strtoupper(trim((string)($billing['country'] ?? '')));
        $business = trim((string)($billing['ic_dph'] ?? '')) !== '' && empty($billing['ic_dph_unverified']);
        $goods = in_array($scenario, [VatService::SCENARIO_OSS, VatService::SCENARIO_DOMESTIC_SUBTHRESH], true);
        $digital = !$business && $residence !== '' && $residence !== strtoupper($sellerCountry) && VatService::isEuCountry($residence);
        return [$goods, $digital];
    }

    /** @return array{previous: int, current: int} */
    private static function years(PDO $db, string $storeId, array $store, array $stores): array
    {
        $year = (int)ShopTime::date('Y');
        $sameSeller = self::sellerStores($storeId, $store, $stores);
        $sellerCountry = self::sellerCountry($store);
        return ['previous' => self::yearSales($db, $sameSeller, $sellerCountry, $year - 1, false)['sum'],
            'current' => self::yearSales($db, $sameSeller, $sellerCountry, $year, true)['sum']];
    }

    /**
     * @param list<string> $storeIds
     * @return array{sum: int, unconverted: list<string>}
     */
    private static function yearSales(PDO $db, array $storeIds, string $sellerCountry, int $year, bool $withOpenOrders): array
    {
        $marks = implode(',', array_fill(0, count($storeIds), '?'));
        $range = [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
        $byCurrency = [];
        $stored = Db::hasColumn('invoices', 'oss_net', $db);
        $documents = "FROM invoices i JOIN orders o ON o.id = i.order_id
            WHERE o.store_id IN ({$marks}) AND i.status = 'issued' AND i.type IN ('invoice', 'credit_note') AND i.issued_at >= ? AND i.issued_at < ?";
        if ($stored) {
            $stmt = $db->prepare("SELECT LOWER(i.currency) AS currency, COALESCE(SUM(i.oss_net), 0) AS net {$documents} AND i.oss_net <> 0 GROUP BY LOWER(i.currency)");
            $stmt->execute([...$storeIds, ...$range]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $byCurrency[(string)$row['currency']] = ($byCurrency[(string)$row['currency']] ?? 0) + (int)$row['net'];
        }
        // A document without its stored net (written before the migration, or by another writer) is counted from its lines.
        $stmt = $db->prepare('SELECT i.* ' . $documents . ($stored ? ' AND i.oss_net IS NULL' : '') . ' ORDER BY i.id');
        $stmt->execute([...$storeIds, ...$range]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
            $currency = strtolower((string)($doc['currency'] ?? 'eur'));
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + self::documentNet($db, $doc);
        }
        // Placed, not cancelled and not invoiced yet (an unpaid bank transfer, a cash-on-delivery parcel on its way).
        if ($withOpenOrders) {
            $stmt = $db->prepare("SELECT o.* FROM orders o WHERE o.store_id IN ({$marks}) AND o.created_at >= ? AND o.created_at < ?
                  AND o.status NOT IN ('cancelled', 'refunded', 'failed') AND o.vat_scenario IN (?, ?, ?, ?)
                  AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = o.id AND i.type = 'invoice' AND i.status = 'issued')");
            $stmt->execute([...$storeIds, ...$range, VatService::SCENARIO_OSS, VatService::SCENARIO_DOMESTIC_SUBTHRESH, VatService::SCENARIO_DOMESTIC, VatService::SCENARIO_EXPORT]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
                [$goods, $digital] = self::distance($order, (string)$order['vat_scenario'], $sellerCountry);
                if (!$goods && !$digital) continue;
                $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true);
                $total = is_array($snapshot) ? (int)($snapshot['totals']['net'] ?? 0) : (int)($order['net_total'] ?? 0);
                $digitalNet = min($total, self::digitalNet($db, $order, (string)($order['financial_snapshot'] ?? '')));
                $currency = strtolower((string)($order['currency'] ?? 'eur'));
                $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + ($goods ? $total - $digitalNet : 0) + ($digital ? $digitalNet : 0);
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

    /** Net of the order's downloads: their snapshot components (lines in order), else the lines' own net. */
    private static function digitalNet(PDO $db, array $order, string $snapshotJson): int
    {
        if (!Db::hasColumn('order_items', 'digital', $db)) return 0;
        $stmt = $db->prepare('SELECT id, digital, net_amount FROM order_items WHERE order_id = ? ORDER BY id');
        $stmt->execute([(int)$order['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $snapshot = json_decode($snapshotJson, true);
        $components = is_array($snapshot) ? array_values(array_filter((array)($snapshot['components'] ?? []), static fn($c): bool => is_array($c) && ($c['type'] ?? '') === 'product')) : [];
        $net = 0;
        foreach ($items as $position => $item) {
            if ((int)$item['digital'] !== 1) continue;
            $net += count($components) === count($items) ? (int)($components[$position]['net'] ?? 0) : (int)($item['net_amount'] ?? 0);
        }
        return max(0, $net);
    }

    /** @return array<int, true> */
    private static function digitalItemIds(PDO $db, int $orderId): array
    {
        if (!Db::hasColumn('order_items', 'digital', $db)) return [];
        $stmt = $db->prepare('SELECT id FROM order_items WHERE order_id = ? AND digital = 1');
        $stmt->execute([$orderId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** The stored net of an issued document, computed when the column is not filled (yet). */
    private static function storedNet(PDO $db, int $invoiceId): int
    {
        $doc = self::invoiceRow($db, $invoiceId);
        if ($doc === null || (string)($doc['status'] ?? '') !== 'issued') return 0;
        return array_key_exists('oss_net', $doc) && $doc['oss_net'] !== null ? (int)$doc['oss_net'] : self::documentNet($db, $doc);
    }

    private static function invoiceRow(PDO $db, int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $db->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function orderRow(PDO $db, int $id): ?array
    {
        if ($id <= 0) return null;
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<string> */
    private static function sellerStores(string $storeId, array $store, array $stores): array
    {
        $seller = self::sellerKey($storeId, $store);
        $sameSeller = [];
        foreach ($stores as $id => $other) {
            if (is_array($other) && (string)($other['legal']['seller'] ?? $id) === $seller) $sameSeller[] = (string)$id;
        }
        return $sameSeller ?: [$storeId];
    }

    private static function sellerKey(string $storeId, array $store): string
    {
        return (string)($store['legal']['seller'] ?? $storeId);
    }

    private static function sellerCountry(array $store): string
    {
        return strtoupper((string)(($store['legal']['country'] ?? null) ?: 'SK'));
    }

    /** The store's stores.json entry; [] (seller country SK) when unknown, e.g. in a migration without a store context. */
    private static function storeConfig(string $storeId): array
    {
        $store = Bootstrap::stores()['stores'][$storeId] ?? null;
        return is_array($store) ? $store : [];
    }
}
