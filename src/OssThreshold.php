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
    /**
     * R39 (medium 1): why exceeded() charged the consumer's VAT, stored in the order's snapshot (oss_threshold): the
     * current year passed the threshold (a crossing of its own, it activates the year) or the previous year did (it
     * activates nothing: the seller returns to its own VAT after two years under 10 000 EUR).
     */
    public const REASON_CURRENT = 'current_year';
    public const REASON_PREVIOUS = 'previous_year';

    /** @var array<string, array{previous: int, current: int}> per store, for this request (VatService::reset() clears it) */
    private static array $years = [];
    private static ?string $reason = null;

    public static function reset(): void
    {
        self::$years = [];
        self::$netsStored = false;
        EurRates::reset();
    }

    /** Why the last exceeded() call returned true (REASON_*), null when it returned false. */
    public static function lastReason(): ?string
    {
        return self::$reason;
    }

    /**
     * R39 (low): OSS is the taxable person's registration (art. 369b), not a storefront's: a store of an IČ DPH whose
     * other store has OSS on is in the scheme too. With it off in one of them, that store charged Slovak VAT to Austrian
     * consumers (and the doctor said PASS).
     * @param array<string, mixed> $store
     */
    public static function sellerOssEnabled(string $storeId, array $store, ?array $stores = null): bool
    {
        if (!empty($store['oss_enabled'])) return true;
        $stores ??= (array)(Bootstrap::stores()['stores'] ?? []);
        $seller = self::sellerKey($storeId, $store, $stores);
        foreach ($stores as $id => $other) {
            if ((string)$id !== $storeId && is_array($other) && !empty($other['oss_enabled']) && self::sellerKey((string)$id, $other, $stores) === $seller) return true;
        }
        return false;
    }

    /**
     * Whether a consumer of another member state pays their own country's VAT in the current store with OSS off: the
     * seller passed the threshold last year, or passes it this year with the sales so far plus $saleNet (currency cents,
     * saleNet()) — the supply that exceeds it is the first one taxed in the consumer's state (art. 59c).
     */
    public static function exceeded(int $saleNet = 0, string $currency = 'eur'): bool
    {
        $context = StoreContext::current();
        $storeId = $context->id();
        $store = $context->config();
        self::$reason = null;
        if (self::sellerOssEnabled($storeId, $store) || !Db::tableExists('invoices')) return false;
        $db = Db::get();
        $year = (int)ShopTime::date('Y');
        try {
            if (self::activated($db, $storeId, $store, $year, false)) return (bool)(self::$reason = self::REASON_CURRENT);
            if (self::activated($db, $storeId, $store, $year - 1, false)) return (bool)(self::$reason = self::REASON_PREVIOUS);
            $years = self::$years[$storeId] ??= self::years($db, $storeId, $store, (array)(Bootstrap::stores()['stores'] ?? []));
            if ($years['previous'] > self::EUR_CENTS) return (bool)(self::$reason = self::REASON_PREVIOUS);
            if ($saleNet > 0 && strtolower($currency) !== 'eur') {
                $stores = self::sellerStores($storeId, $store, (array)(Bootstrap::stores()['stores'] ?? []));
                $over = self::consistently($db, static fn(): int => self::yearSales($db, $stores, $year, true, $saleNet, $currency)['sum']) > self::EUR_CENTS;
            } else {
                $over = $years['current'] + max(0, $saleNet) > self::EUR_CENTS;
            }
            if ($over) self::$reason = self::REASON_CURRENT;
            return $over;
        } catch (\Throwable $e) {
            // R38 (low): the threshold decides the VAT rate. A failed count charged the seller's VAT without a word (a
            // warning incident); now no order is placed on an unknown VAT (the checkout refuses, CheckoutService::vatContext()
            // shows the page at home VAT until then) and the merchant is alerted.
            IncidentMonitor::report('tax.oss_threshold_failed', 'tax', 'critical', ['store_id' => $storeId, 'exception' => $e]);
            throw new OssThresholdUnavailableException('The OSS threshold could not be counted: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * The seller's (all stores of its IČ DPH, else sharing legal.seller — sellerKey()) cross-border B2C sales of the worse of the current and the previous
     * year, in EUR cents, the currencies sold in without a conversion rate and those converted (eur_exchange_rates).
     * @param array<string, mixed> $stores every store (stores.json)
     * @return array{sum: int, year: int, unconverted: list<string>, converted: list<string>}
     */
    public static function worstYear(PDO $db, string $storeId, array $store, array $stores): array
    {
        $year = (int)ShopTime::date('Y');
        $worst = ['sum' => 0, 'year' => $year];
        $unconverted = $converted = [];
        $both = self::consistently($db, static fn(): array => array_map(static fn(int $y): array => self::yearSales($db, self::sellerStores($storeId, $store, $stores), $y, $y === $year), [$year - 1 => $year - 1, $year => $year]));
        foreach ($both as $y => $sales) {
            $unconverted = array_merge($unconverted, $sales['unconverted']);
            $converted = array_merge($converted, $sales['converted'], self::convertedAtIssue($db, self::sellerStores($storeId, $store, $stores), $y));
            if ($sales['sum'] >= $worst['sum']) $worst = ['sum' => $sales['sum'], 'year' => $y];
        }
        foreach (['unconverted' => $unconverted, 'converted' => $converted] as $key => $codes) {
            $codes = array_values(array_unique($codes));
            sort($codes);
            $worst[$key] = $codes;
        }
        return $worst;
    }

    /**
     * R40: currencies of the year's documents converted to EUR at their issue (record()): counted in the EUR total, they
     * were converted at the rates of then, so the doctor still judges those rates (Setup::ossThresholdCheck()).
     * @param list<string> $storeIds
     * @return list<string>
     */
    private static function convertedAtIssue(PDO $db, array $storeIds, int $year): array
    {
        if ($storeIds === [] || !self::hasColumn('invoices', 'oss_net_eur', $db)) return [];
        $marks = implode(',', array_fill(0, count($storeIds), '?'));
        $stmt = $db->prepare("SELECT DISTINCT UPPER(i.currency) FROM invoices i JOIN orders o ON o.id = i.order_id
            WHERE o.store_id IN ({$marks}) AND i.status = 'issued' AND i.oss_net_eur IS NOT NULL AND i.oss_net <> 0 AND i.issued_at >= ? AND i.issued_at < ?");
        $stmt->execute([...$storeIds, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * R38 (low): the conversion rates are fixed values of config/vat-rates.json; their date (eur_exchange_rates_as_of,
     * Y-m-d) tells how old they are. Null when it is missing or not a date. R39: or the ECB rates cli/eur-rates.php
     * stored (EurRates), when newer.
     */
    public static function ratesAge(): ?int
    {
        $asOf = EurRates::current()['as_of']; // R39: the stored ECB rates' date when they are newer
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? DateTimeImmutable::createFromFormat('!Y-m-d', $asOf, new DateTimeZone('UTC')) : false;
        if ($date === false) return null;
        return (int)floor((ShopTime::now() - $date->getTimestamp()) / 86400);
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

    /**
     * Store an issued document's distance-sale net (InvoiceService, after it inserted the row, in its transaction) and
     * add it to its store's year total (oss_threshold_totals, R36 medium 1); skipped before the migrations. Once only.
     */
    public static function record(PDO $db, int $invoiceId): void
    {
        if (!Db::hasColumn('invoices', 'oss_net', $db)) return;
        $doc = self::invoiceRow($db, $invoiceId);
        if ($doc === null) return;
        $net = self::documentNet($db, $doc);
        // R40 (low): in another currency, also in EUR at the rate of the day it is issued (art. 91(2)); counted so.
        $eur = self::hasColumn('invoices', 'oss_net_eur', $db) ? self::issueEur($db, $doc, $net) : null;
        $stmt = $db->prepare('UPDATE invoices SET oss_net = :net' . ($eur !== null ? ', oss_net_eur = :eur' : '') . ' WHERE id = :id AND oss_net IS NULL');
        $stmt->execute([':net' => $net, ':id' => $invoiceId] + ($eur !== null ? [':eur' => $eur] : []));
        if ($stmt->rowCount() === 1 && (string)($doc['status'] ?? '') === 'issued') self::addToTotal($db, $doc, $net, $eur);
        if (($doc['type'] ?? '') === 'invoice' && ($doc['status'] ?? '') === 'issued' && Db::hasColumn('orders', 'oss_net', $db)) $db->prepare('UPDATE orders SET oss_net = 0 WHERE id = ?')->execute([(int)$doc['order_id']]);
        if ($net > 0 && ($doc['status'] ?? '') === 'issued') self::rememberCrossing($db, (string)(self::orderRow($db, (int)$doc['order_id'])['store_id'] ?? $doc['store_id']), self::documentYear($db, $doc));
    }
    /** An issued document was cancelled (InvoiceService::cancel()): its stored net leaves its year total. */
    public static function forget(PDO $db, int $invoiceId): void
    {
        $doc = self::invoiceRow($db, $invoiceId);
        if ($doc === null || !array_key_exists('oss_net', $doc) || $doc['oss_net'] === null) return;
        self::addToTotal($db, $doc, -(int)$doc['oss_net'], isset($doc['oss_net_eur']) ? -(int)$doc['oss_net_eur'] : null);
        if (($doc['type'] ?? '') === 'invoice') self::recordOrder($db, (int)$doc['order_id']);
    }
    /**
     * Store the distance-sale net of a placed order (Orders::create(), in its transaction): what it adds to the threshold
     * until it is invoiced (orders.oss_net, in the order's currency; 0 for any other sale). Skipped before the migration.
     */
    public static function recordOrder(PDO $db, int $orderId): void
    {
        if (!Db::hasColumn('orders', 'oss_net', $db)) return;
        $order = self::orderRow($db, $orderId);
        if ($order === null) return;
        $net = self::orderNet($db, $order);
        $db->prepare("UPDATE orders SET oss_net = CASE WHEN status IN ('cancelled', 'refunded', 'failed') OR EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = orders.id AND i.type = 'invoice' AND i.status = 'issued') THEN 0 ELSE :net END WHERE id = :id")->execute([':net' => $net, ':id' => $orderId]);
        $year = self::orderYear((string)$order['store_id'], (string)$order['created_at']);
        $reason = self::snapshotReason($order);
        // R51 (high 2): decided only when an activation can still be recorded; evaluated eagerly it summed the year's open
        // orders for every order, and migration 20261113190000 (every open order, table not created yet) went quadratic.
        self::rememberCrossing($db, (string)$order['store_id'], $year, static fn(): bool => $net > 0
            && !in_array((string)$order['status'], ['cancelled', 'refunded', 'failed'], true) && self::chargedAbroad($order)
            && ($reason === null ? self::crossesAtHome($db, $order, $year, $net) : $reason === self::REASON_CURRENT));
    }

    /**
     * Capability review (R39 run 513): an order placed before R39 (no reason in its snapshot) is a crossing of its year
     * by the rule the migration decided it with (rebuildActivations()): the year's other sales plus it at the seller's
     * VAT (homeNet()) pass 10 000 EUR. Was "the previous year did not decide" — read from the activations table the
     * migration repaired: cancelling the invoice of a sale the migration had judged carried recreated its year's row.
     */
    private static function crossesAtHome(PDO $db, array $order, int $year, int $net): bool
    {
        $storeId = (string)$order['store_id'];
        $stores = self::sellerStores($storeId, self::storeConfig($storeId), (array)(Bootstrap::stores()['stores'] ?? []));
        $currency = strtolower((string)($order['currency'] ?? 'eur'));
        // With open orders in any year: this order is counted (its invoice or its stored oss_net), as the rebuild counts it.
        $others = self::yearSales($db, $stores, $year, true)['sum'] - self::inEur($net, $currency);
        return $others + self::inEur(self::homeNet($order, $net), $currency) > self::EUR_CENTS;
    }

    /** The REASON_* an order's snapshot records for its consumer VAT (exceeded()), null for an order placed before R39. */
    private static function snapshotReason(array $order): ?string
    {
        $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true);
        $reason = is_array($snapshot) ? ($snapshot['oss_threshold'] ?? null) : null;
        return in_array($reason, [self::REASON_CURRENT, self::REASON_PREVIOUS], true) ? $reason : null;
    }

    /**
     * R37 (low, HU27): a sale of a store with OSS off charged the consumer's VAT — the threshold was passed by it at the
     * seller's own VAT (CheckoutService::thresholdVat()). Charged as a gross that keeps its price (cross_border_pricing
     * "gross"), its net at 27 % HU VAT can stay under the threshold (9 990 + 9,84 €) while at 23 % it passed it
     * (9 990 + 10,16 €): the next sale was charged Slovak VAT again. That decision activates OSS for the year.
     */
    private static function chargedAbroad(array $order): bool
    {
        $storeId = (string)($order['store_id'] ?? 'default');
        if (self::sellerOssEnabled($storeId, self::storeConfig($storeId))) return false;
        if ((string)($order['vat_scenario'] ?? '') === VatService::SCENARIO_OSS) return true;
        $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true);
        foreach (is_array($snapshot) ? (array)($snapshot['components'] ?? []) : [] as $component) {
            if (is_array($component) && ($component['supply'] ?? '') === VatService::SCENARIO_OSS) return true;
        }
        return false;
    }

    /** Scenarios whose open orders count toward the threshold (orderNet()). */
    private const OPEN_ORDER_SCENARIOS = [VatService::SCENARIO_OSS, VatService::SCENARIO_DOMESTIC_SUBTHRESH, VatService::SCENARIO_DOMESTIC, VatService::SCENARIO_EXPORT];

    /**
     * R50 (review round 20, medium): orders placed before orders.oss_net existed got their net only when migration
     * 20261113190000 ran with this release's Db; a signed update runs the migrations with the old release's Db, so it was
     * skipped and every checkout counted those orders from their lines (6.7-8.8 s at 20 000 open orders of a store
     * invoicing in its ERP, out of memory at 330 000). Stores the net of every order placed since $since (UTC) still
     * without one, 500 per batch (one commit each on MySQL). Uses no Db method a released Db lacks. Returns the orders.
     */
    public static function backfillOrderNets(PDO $db, string $since, ?float $deadline = null): int
    {
        if (!self::hasColumn('orders', 'oss_net', $db)) return 0;
        $marks = implode(', ', array_fill(0, count(self::OPEN_ORDER_SCENARIOS), '?'));
        $settled = $db->prepare("UPDATE orders SET oss_net = 0 WHERE oss_net IS NULL AND created_at >= ? AND (status IN ('cancelled', 'refunded', 'failed')
            OR vat_scenario IS NULL OR vat_scenario NOT IN ({$marks})
            OR EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = orders.id AND i.type = 'invoice' AND i.status = 'issued'))");
        $settled->execute([$since, ...self::OPEN_ORDER_SCENARIOS]);
        $count = $settled->rowCount();
        $next = $db->prepare('SELECT * FROM orders WHERE oss_net IS NULL AND created_at >= ? AND id > ? ORDER BY id LIMIT 500');
        $store = $db->prepare('UPDATE orders SET oss_net = ? WHERE id = ? AND oss_net IS NULL');
        // R52: one commit per batch on SQLite too (500 commits of one row each outlasted the 10 s budget on a busy disk).
        $own = !$db->inTransaction();
        $after = 0;
        do {
            $next->execute([$since, $after]);
            $rows = $next->fetchAll(PDO::FETCH_ASSOC);
            $opened = $own && $rows !== [] && $db->beginTransaction();
            try {
                foreach ($rows as $order) {
                    $store->execute([self::orderNet($db, $order), (int)$order['id']]);
                    $count += $store->rowCount();
                    $after = (int)$order['id'];
                }
                if ($opened) $db->commit();
                $opened = false;
            } catch (\Throwable $e) {
                if ($opened) $db->rollBack();
                throw $e;
            }
        } while (count($rows) === 500 && ($deadline === null || microtime(true) < $deadline));
        return $count;
    }

    /** Distance-sale net of an order not invoiced yet, from its snapshot and lines. */
    public static function orderNet(PDO $db, array $order): int
    {
        $scenario = (string)($order['vat_scenario'] ?? '');
        if (!in_array($scenario, [VatService::SCENARIO_OSS, VatService::SCENARIO_DOMESTIC_SUBTHRESH, VatService::SCENARIO_DOMESTIC, VatService::SCENARIO_EXPORT], true)) return 0;
        $store = self::storeConfig((string)($order['store_id'] ?? 'default'));
        [$goods, $digital] = self::distance($order, $scenario, self::sellerCountry($store));
        if (!$goods && !$digital) return 0;
        $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true);
        $total = is_array($snapshot) ? (int)($snapshot['totals']['net'] ?? 0) : (int)($order['net_total'] ?? 0);
        $digitalNet = min($total, self::digitalNet($db, $order, (string)($order['financial_snapshot'] ?? '')));
        return ($goods ? $total - $digitalNet : 0) + ($digital ? $digitalNet : 0);
    }

    /**
     * R38 (medium 4): the year an issued document counts in. An invoice its own; a credit note (a reversal too) the year
     * of the invoice it corrects: a January credit note of a December sale lowered the new year, which fell back under
     * the threshold by it, and Austrian consumers were charged Slovak VAT again.
     * @param array<string, mixed> $doc an invoices row
     */
    public static function documentYear(PDO $db, array $doc): int
    {
        $source = (string)($doc['type'] ?? 'invoice') === 'credit_note' ? self::invoiceRow($db, (int)($doc['refers_to_invoice_id'] ?? 0)) : null;
        $date = static fn(array $row): string => (string)(($row['delivery_date'] ?? '') ?: ($row['issued_at'] ?? ''));
        return (int)substr($source !== null && (string)($source['issued_at'] ?? '') !== '' ? $date($source) : $date($doc), 0, 4);
    }

    /**
     * SQL date documentYear() reads, for invoices row $doc LEFT JOINed with its corrected invoice $source (refers_to_invoice_id).
     * R38 (low): the date of supply (delivery_date, dátum dodania), else the issue date: goods supplied on 30 December and
     * invoiced on 2 January are a sale of the old year (art. 59c counts supplies; the invoice is never earlier).
     */
    public static function documentDateSql(string $doc, string $source): string
    {
        return "CASE WHEN {$doc}.type = 'credit_note' AND {$source}.issued_at IS NOT NULL THEN COALESCE(NULLIF({$source}.delivery_date, ''), {$source}.issued_at)"
            . " ELSE COALESCE(NULLIF({$doc}.delivery_date, ''), {$doc}.issued_at) END";
    }

    /**
     * R40: SQL [currency, net] a stored document of alias $alias counts with: EUR at its issue (oss_net_eur) when converted
     * then, else its own currency (record(), addToTotal(); OssThresholdCache rebuilds the totals by the same).
     * @return array{0: string, 1: string}
     */
    public static function storedNetSql(PDO $db, string $alias): array
    {
        if (!self::hasColumn('invoices', 'oss_net_eur', $db)) return ["LOWER({$alias}.currency)", "{$alias}.oss_net"];
        return ["CASE WHEN {$alias}.oss_net_eur IS NOT NULL THEN 'eur' ELSE LOWER({$alias}.currency) END", "COALESCE({$alias}.oss_net_eur, {$alias}.oss_net)"];
    }

    /**
     * R40 (low): EUR cents of a document's distance-sale net $net in another currency, at the rate it is issued at: a credit
     * note (a reversal) at the rate of the document it corrects, else the current ECB rate (EurRates). Null in EUR, or
     * without a rate (counted in its currency then, at the current rate).
     * @param array<string, mixed> $doc an invoices row
     */
    private static function issueEur(PDO $db, array $doc, int $net): ?int
    {
        $currency = strtolower((string)($doc['currency'] ?? 'eur'));
        if ($net === 0 || $currency === 'eur') return null;
        if ((string)($doc['type'] ?? 'invoice') === 'credit_note') {
            $credit = json_decode((string)($doc['credit_snapshot'] ?? ''), true);
            $sourceId = is_array($credit) && ($credit['kind'] ?? '') === 'reversal' ? (int)($credit['reverses_credit_note_id'] ?? 0) : (int)($doc['refers_to_invoice_id'] ?? 0);
            $source = self::invoiceRow($db, $sourceId);
            if ($source !== null && isset($source['oss_net_eur']) && (int)($source['oss_net'] ?? 0) !== 0) {
                return (int)round($net * (int)$source['oss_net_eur'] / (int)$source['oss_net']);
            }
            // R41: the document it corrects still counts in its currency; so does the correction, until both convert.
            if ($source !== null && (int)($source['oss_net'] ?? 0) !== 0 && strtolower((string)($source['currency'] ?? 'eur')) !== 'eur') return null;
        }
        // R41 (medium 3): the ECB rate of the day of issue only (EurRates::rateOn()); a stale or configured current rate
        // fixed in oss_net_eur was never corrected. Without one the document waits (convertPending()). R49 (low): of the day
        // of supply (delivery_date), the day it counts in (documentDateSql(); art. 91: the chargeable event), else of issue.
        $day = (string)($doc['delivery_date'] ?? '') !== '' ? (string)$doc['delivery_date'] : (string)($doc['issued_at'] ?? '');
        $rate = EurRates::rateOn($db, $currency, substr($day, 0, 10));
        return $rate !== null ? (int)round($net * $rate) : null;
    }

    /**
     * R41 (medium 3): issued documents in another currency without their EUR at issue yet (no ECB rate of their day was
     * stored then, or they predate oss_net_eur): converted at the rate of their day once cli/eur-rates.php stored it, and
     * moved from their currency's total to the EUR one. Returns how many were converted, and what still waits.
     * @return array{converted: int, pending: int, oldest: ?string, currencies: list<string>}
     */
    public static function convertPending(PDO $db): array
    {
        $done = 0;
        foreach (self::pendingDocuments($db) as $doc) {
            $eur = self::issueEur($db, $doc, (int)$doc['oss_net']);
            if ($eur === null) continue;
            $own = !$db->inTransaction();
            if ($own) $db->beginTransaction();
            try {
                $stmt = $db->prepare('UPDATE invoices SET oss_net_eur = ? WHERE id = ? AND oss_net_eur IS NULL AND oss_net = ?');
                $stmt->execute([$eur, (int)$doc['id'], (int)$doc['oss_net']]);
                if ($stmt->rowCount() === 1) {
                    self::addToTotal($db, $doc, -(int)$doc['oss_net']);
                    self::addToTotal($db, $doc, $eur, $eur);
                    $done++;
                }
                if ($own) $db->commit();
            } catch (Throwable $e) {
                if ($own && $db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
        self::reset();
        return ['converted' => $done] + self::pending($db);
    }

    /**
     * R41: pending() of the seller's stores (worstYear()'s), for the setup doctor.
     * @param array<string, mixed> $stores every store (stores.json)
     * @return array{pending: int, oldest: ?string, currencies: list<string>}
     */
    public static function pendingOfSeller(PDO $db, string $storeId, array $store, array $stores): array
    {
        return self::pending($db, self::sellerStores($storeId, $store, $stores));
    }

    /**
     * R41: the documents convertPending() still waits for (of orders of $storeIds when given): how many, the oldest day of
     * supply or issue (R49: whose rate converts it), their currencies (upper case).
     * @param ?list<string> $storeIds
     * @return array{pending: int, oldest: ?string, currencies: list<string>}
     */
    public static function pending(PDO $db, ?array $storeIds = null): array
    {
        $docs = self::pendingDocuments($db);
        if ($storeIds !== null) {
            $orders = $docs === [] ? [] : array_flip(array_map('intval', array_column(array_filter(
                $db->query('SELECT id, store_id FROM orders WHERE id IN (' . implode(',', array_map(static fn(array $d): int => (int)$d['order_id'], $docs)) . ')')->fetchAll(PDO::FETCH_ASSOC),
                static fn(array $o): bool => in_array((string)$o['store_id'], $storeIds, true)), 'id')));
            $docs = array_values(array_filter($docs, static fn(array $d): bool => isset($orders[(int)$d['order_id']])));
        }
        // R49: the day whose rate converts it (issueEur(): of supply, else of issue), from which the ECB history is fetched.
        $days = array_map(static fn(array $d): string => substr((string)(($d['delivery_date'] ?? '') !== '' ? $d['delivery_date'] : $d['issued_at']), 0, 10), $docs);
        $currencies = array_values(array_unique(array_map(static fn(array $d): string => strtoupper((string)$d['currency']), $docs)));
        sort($currencies);
        return ['pending' => count($docs), 'oldest' => $days === [] ? null : min($days), 'currencies' => $currencies];
    }

    /**
     * @return list<array<string, mixed>> issued documents of this and last year (the years the threshold counts), by id (an
     * invoice before its credit notes), waiting for their EUR at issue
     */
    private static function pendingDocuments(PDO $db): array
    {
        if (!self::hasColumn('invoices', 'oss_net_eur', $db)) return [];
        // R42 (low 4): by the year the document counts in (documentDateSql()): a credit note of an invoice of an older year
        // never converts (its invoice is not converted; issueEur()) and kept the doctor warning and the history download.
        $stmt = $db->prepare("SELECT i.* FROM invoices i LEFT JOIN invoices s ON s.id = i.refers_to_invoice_id
            WHERE i.status = 'issued' AND i.oss_net IS NOT NULL AND i.oss_net <> 0 AND i.oss_net_eur IS NULL
            AND LOWER(i.currency) <> 'eur' AND i.issued_at >= ? AND " . self::documentDateSql('i', 's') . ' >= ? ORDER BY i.id');
        $since = sprintf('%04d-01-01 00:00:00', (int)ShopTime::date('Y') - 1);
        $stmt->execute([$since, substr($since, 0, 10)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $doc an invoices row
     * @param ?int $eur its net in EUR at its issue (issueEur()): counted in the EUR total; else $net in its currency
     */
    private static function addToTotal(PDO $db, array $doc, int $net, ?int $eur = null): void
    {
        if ($eur !== null) $net = $eur;
        if ($net === 0 || !Db::tableExists('oss_threshold_totals', $db)) return;
        $order = self::orderRow($db, (int)($doc['order_id'] ?? 0));
        $key = [(string)($order['store_id'] ?? $doc['store_id'] ?? 'default'), self::documentYear($db, $doc), $eur !== null ? 'eur' : strtolower((string)($doc['currency'] ?? 'eur'))];
        $db->prepare(Db::insertIgnore() . ' INTO oss_threshold_totals (store_id, year, currency, net) VALUES (?, ?, ?, 0)')->execute($key);
        $db->prepare('UPDATE oss_threshold_totals SET net = net + ? WHERE store_id = ? AND year = ? AND currency = ?')->execute([$net, ...$key]);
    }

    /**
     * Estimate in the cart's currency cents of what $items add (exceeded($saleNet, $currency)) before shipping, fee and
     * discounts are known: the goods when $goods (they go to a consumer of another member state), the downloads when
     * $digital (the consumer lives in one), at the gross the cart shows less the seller's VAT of each line's class. Only
     * the JSON previews decide on it; an order and the checkout page decide on snapshotNet() (CheckoutService::thresholdVat(), guardOrder()).
     * @param list<array{gross: int, vat_class: string, digital: bool}> $items
     */
    public static function saleNet(array $items, bool $goods, bool $digital, string $sellerCountry): int
    {
        $net = 0;
        foreach ($items as $item) {
            if (!($item['digital'] ? $digital : $goods)) continue;
            // R51 (low): the category itself (books 5 % in SK; a download its digital category, e-books 27 % in HU), as
            // the snapshot prices it (VatService::buildSnapshot()); resolveClass() first gave the reduced 19 %.
            $class = (string)$item['vat_class'];
            $rate = VatService::rate($sellerCountry, $item['digital'] ? VatService::digitalCategory($class) : $class);
            $net += (int)round((int)$item['gross'] / (1 + $rate / 100));
        }
        return $net;
    }

    /**
     * Distance-sale net in the snapshot's currency cents of an order priced at home VAT — what its invoice
     * adds (documentNet(), orderNet()): its net less the downloads' when $goods (shipping, payment fee and discounts
     * included), its downloads when $digital. $items are its lines in snapshot order (their product type).
     * @param list<array<string, mixed>> $items
     */
    public static function snapshotNet(array $snapshot, array $items, bool $goods, bool $digital): int
    {
        $total = (int)($snapshot['totals']['net'] ?? 0);
        $products = array_values(array_filter((array)($snapshot['components'] ?? []), static fn($c): bool => is_array($c) && ($c['type'] ?? '') === 'product'));
        $digitalNet = 0;
        foreach ($products as $position => $component) {
            $item = $items[$position] ?? [];
            if (!empty($item['digital']) || VariantAvailability::isDigitalType((string)($item['product_type'] ?? ''))) $digitalNet += (int)($component['net'] ?? 0);
        }
        $digitalNet = min($total, $digitalNet);
        return ($goods ? $total - $digitalNet : 0) + ($digital ? $digitalNet : 0);
    }

    private static function inEur(int $net, string $currency): int
    {
        if ($net === 0 || strtolower($currency) === 'eur') return $net;
        $rate = EurRates::current()['rates'][strtolower($currency)] ?? null;
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
        if (self::sellerOssEnabled($context->id(), $store) || !Db::tableExists('invoices', $db)) return null;
        $sellerCountry = self::sellerCountry($store);
        $order = [
            'store_id' => $context->id(),
            'shipping_addr' => json_encode($customer['address'] ?? [], JSON_UNESCAPED_UNICODE),
            'billing_addr' => !empty($customer['billing_address']) ? json_encode($customer['billing_address'], JSON_UNESCAPED_UNICODE) : null,
            'customer_name' => (string)($customer['name'] ?? ''),
            'financial_snapshot' => json_encode(['digital_residence' => $snapshot['digital_residence'] ?? null]),
            'shipping_method' => array_filter($cartItems, static fn(array $item): bool => empty($item['digital']) && !VariantAvailability::isDigitalType((string)($item['product_type'] ?? ''))) ? (string)($customer['shipping_method'] ?? '') : 'digital',
        ];
        $scenario = (string)($snapshot['scenario'] ?? '');
        $components = array_values(array_filter((array)($snapshot['components'] ?? []), static fn($c): bool => is_array($c) && ($c['type'] ?? '') === 'product'));
        $chargedAbroad = $scenario === VatService::SCENARIO_OSS;
        foreach ($components as $component) if (($component['supply'] ?? '') === VatService::SCENARIO_OSS) $chargedAbroad = true;
        [$goods, $digital] = self::distance($order, $scenario, $sellerCountry);
        if ($chargedAbroad || (!$goods && (!$digital || !array_filter($cartItems, static fn(array $item): bool => !empty($item['digital']) || VariantAvailability::isDigitalType((string)($item['product_type'] ?? '')))))) return null;
        $lock = self::lockSeller($db);
        try {
            unset(self::$years[$context->id()]);
            // Capability review (R36): on what the invoice records — shipping, payment fee and discounts included.
            $abroad = self::exceeded(self::snapshotNet($snapshot, $cartItems, $goods, $digital), (string)($snapshot['currency'] ?? 'eur'));
            // Only the home VAT can turn wrong meanwhile: the sales only grow while the lock is held (an order priced
            // abroad by the caller — a manual order, a test — is left to it).
            if ($abroad) throw new VatChangedException('The OSS threshold decides another VAT than the order was priced with');
        } catch (\Throwable $e) {
            self::releaseOrderLock($db, $lock);
            throw $e;
        }
        return $lock;
    }

    /**
     * MySQL GET_LOCK name of a seller's threshold. GET_LOCK is server-wide, so it is named after the database too (as the
     * migration lock is): shops of one MySQL server do not wait on each other.
     * @param array<string, mixed> $store
     */
    public static function sellerLockName(PDO $db, string $storeId, array $store): string
    {
        $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        return 'bbe_oss_' . substr(hash('sha256', $database . '|' . self::sellerKey($storeId, $store)), 0, 40);
    }

    /**
     * The current store's seller threshold lock on MySQL (GET_LOCK; SQLite serializes orders under its checkout lock),
     * taken before the writer's transaction so its reads see every order committed under it. Null when not needed.
     */
    public static function lockSeller(PDO $db): ?string
    {
        $context = StoreContext::current();
        if (!Db::isMysql() || self::sellerOssEnabled($context->id(), $context->config())) return null;
        $lock = self::sellerLockName($db, $context->id(), $context->config());
        $stmt = $db->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$lock]);
        if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Could not take the OSS threshold lock');
        return $lock;
    }

    /**
     * R38 (low): an admin edit of a pending order's lines (Orders::replaceItems()) re-prices it against the threshold
     * like a new order, under the seller's lock (lockSeller()) and without its own lines: they were counted next to the
     * new ones (60 € placed, edited to 90 €: 150 € counted) and the edit refused or decided on a stale count. Called
     * in the edit's transaction before it prices; recordOrder() stores the new net.
     */
    public static function withoutOpenOrder(PDO $db, int $orderId): void
    {
        if (Db::hasColumn('orders', 'oss_net', $db)) $db->prepare('UPDATE orders SET oss_net = 0 WHERE id = ?')->execute([$orderId]);
        self::reset();
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
        // R38 (medium 3): next to goods the e-books are supplied where the goods are: the delivery country, the store's
        // when collected there (CheckoutService::vatContext()).
        $method = (string)($order['shipping_method'] ?? '');
        $delivered = strtoupper(trim((string)(OrderAddress::delivery($order)['country'] ?? '')));
        if ($method !== 'digital' && $delivered !== '') $residence = self::collected($method, (string)($order['store_id'] ?? 'default')) ? strtoupper($sellerCountry) : $delivered;
        // R39 (low): an order placed since records the evidence its e-books were taxed on (CheckoutService::digitalResidence()):
        // the country declared, else the billing one. An Austrian collecting in the Bratislava store was counted at home.
        $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true);
        $evidence = is_array($snapshot) && is_array($snapshot['digital_residence'] ?? null) ? $snapshot['digital_residence'] : null;
        // R40: or the country two items of evidence named ('decided').
        $evidenced = (string)($evidence['declared'] ?? $evidence['decided'] ?? $evidence['billing'] ?? '');
        if ($evidence !== null && $evidenced !== '') $residence = strtoupper($evidenced);
        $business = trim((string)($billing['ic_dph'] ?? '')) !== '' && empty($billing['ic_dph_unverified']);
        $goods = in_array($scenario, [VatService::SCENARIO_OSS, VatService::SCENARIO_DOMESTIC_SUBTHRESH], true);
        $digital = !$business && $residence !== '' && $residence !== strtoupper($sellerCountry) && VatService::isEuCountry($residence);
        return [$goods, $digital];
    }

    /**
     * Whether an order's method is collection in the seller's store (ShippingService::isPickupMethod() without a store
     * context, as in a migration recounting documents): the code "pickup", or a method of that carrier or adapter.
     * R40 (low): of the order's store; another store's pickup method of the same code counted a courier order as collected.
     * R41 (medium 2): of the store's provider scope, whose shipping methods its checkout offered (the sample "de" store
     * ships with "default"'s, so its collection was counted abroad), and service_type "store_pickup" too — the test
     * ShippingService::isPickupMethod() applies at checkout.
     */
    private static function collected(string $method, string $storeId): bool
    {
        if ($method === '' || $method === 'digital') return false;
        if ($method === 'pickup') return true;
        $scope = (string)(self::storeConfig($storeId)['provider_scope'] ?? $storeId);
        return ShippingService::isStorePickupMethod(Db::get(), $method, $scope);
    }

    public static function activated(PDO $db, string $storeId, array $store, int $year, bool $withPrevious = true, ?array $stores = null): bool
    {
        if (!Db::tableExists('oss_threshold_activations', $db)) return false;
        $stmt = $db->prepare('SELECT 1 FROM oss_threshold_activations WHERE seller_key = ? AND year BETWEEN ? AND ?');
        $stmt->execute([self::sellerKey($storeId, $store, $stores), $year - (int)$withPrevious, $year]);
        return $stmt->fetchColumn() !== false;
    }

    // Persist only actual sales, in the writer's transaction. A speculative checkout must never activate OSS.
    private static function rememberCrossing(PDO $db, string $storeId, int $year, bool|\Closure $decided = false): void
    {
        if (!Db::tableExists('oss_threshold_activations', $db)) return;
        $store = self::storeConfig($storeId);
        if (self::activated($db, $storeId, $store, $year, false)) return;
        if ($decided instanceof \Closure) $decided = $decided();
        $stores = self::sellerStores($storeId, $store, (array)(Bootstrap::stores()['stores'] ?? []));
        if (!$decided && self::yearSales($db, $stores, $year, $year === (int)ShopTime::date('Y'))['sum'] <= self::EUR_CENTS) return;
        $db->prepare(Db::insertIgnore() . ' INTO oss_threshold_activations (seller_key, year) VALUES (?, ?)')->execute([self::sellerKey($storeId, $store), $year]);
        self::reset();
    }

    /**
     * Replay issued documents in date order: a later credit reduces turnover, not the year's historical peak.
     * $dropCarried (R38/R39 migrations): main @ c1fe7a9 recorded a year's activation for every sale charged abroad
     * because the previous year was over the threshold; such a row that the year's own sales do not explain is removed.
     *
     * R39 (medium 1): the evidence is the documents and orders alone, never the activations table being repaired. R38
     * deleted oldest first and judged each next year by the table after its own deletions: with 2024 over, 2025 and 2026
     * at 3 000 EUR each, 2025 was dropped and 2026 then looked like a crossing of its own (kept); a lone carried 2025 row
     * was dropped and its 2026 sales recorded a new one — exceeded() stayed true to the end of 2027. A sale charged
     * abroad is a crossing of its year when its snapshot says so (REASON_CURRENT); one placed before R39 when it is the
     * sale that passes 10 000 EUR at the seller's VAT (homeNet()): the year's sales before it plus it at home VAT.
     */
    public static function rebuildActivations(PDO $db, bool $dropCarried = false): void
    {
        $since = sprintf('%04d-01-01', (int)ShopTime::date('Y') - 1);
        // R38 (medium 4): replayed when issued, counted in the year of the invoice a credit note corrects.
        [$currencySql, $netSql] = self::storedNetSql($db, 'i'); // R40: EUR at the issue when converted then
        $stmt = $db->prepare("SELECT o.store_id, i.issued_at AS at_date, " . self::documentDateSql('i', 's') . " AS year_date, i.id, 0 AS kind, i.type AS doc_type, o.id AS order_id, {$currencySql} AS currency, {$netSql} AS net
            FROM invoices i JOIN orders o ON o.id = i.order_id LEFT JOIN invoices s ON s.id = i.refers_to_invoice_id
            WHERE i.status = 'issued' AND i.type IN ('invoice', 'credit_note') AND i.issued_at >= ? AND i.oss_net <> 0
            UNION ALL SELECT o.store_id, o.created_at AS at_date, o.created_at AS year_date, o.id, 1 AS kind, 'order' AS doc_type, o.id AS order_id, o.currency, o.oss_net AS net
            FROM orders o WHERE o.created_at >= ? AND o.oss_net > 0 AND o.status NOT IN ('cancelled', 'refunded', 'failed')
            AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = o.id AND i.type = 'invoice' AND i.status = 'issued')
            ORDER BY at_date, kind, id");
        // Orders by their store's calendar (orderYear()): from a day earlier in UTC, the ones of an earlier year skipped.
        $stmt->execute([$since, ShopTime::addDays($since, -1)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totals = []; $crossed = [];
        foreach ($rows as $row) {
            $storeId = (string)$row['store_id'];
            $seller = self::sellerKey($storeId, self::storeConfig($storeId));
            $year = (int)$row['kind'] === 1 ? self::orderYear($storeId, (string)$row['year_date']) : (int)substr((string)$row['year_date'], 0, 4);
            if ($year < (int)substr($since, 0, 4) || isset($crossed[$seller][$year])) continue;
            $currency = strtolower((string)$row['currency']);
            $before = 0;
            foreach ($totals[$seller][$year] ?? [] as $code => $net) $before += self::inEur($net, $code);
            $totals[$seller][$year][$currency] = ($totals[$seller][$year][$currency] ?? 0) + (int)$row['net'];
            $sum = 0;
            foreach ($totals[$seller][$year] as $code => $net) $sum += self::inEur($net, $code);
            if ($sum > self::EUR_CENTS) { $crossed[$seller][$year] = true; continue; }
            // R37: a sale charged the consumer's VAT with OSS off was the threshold decision (chargedAbroad()).
            if ((int)$row['net'] <= 0 || $row['doc_type'] === 'credit_note') continue;
            $order = self::orderRow($db, (int)$row['order_id']);
            if ($order === null || !self::chargedAbroad($order)) continue;
            $reason = self::snapshotReason($order);
            if ($reason === null && $before + self::inEur(self::homeNet($order, (int)$row['net']), $currency) > self::EUR_CENTS) $reason = self::REASON_CURRENT;
            if ($reason === self::REASON_CURRENT) $crossed[$seller][$year] = true;
        }
        if ($dropCarried) self::dropCarriedActivations($db, (int)substr($since, 0, 4), $crossed);
        // A rebuild adds evidence; it cannot erase a crossing recorded before a later cancellation.
        $insert = $db->prepare(($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO oss_threshold_activations (seller_key, year) VALUES (?, ?)');
        foreach ($crossed as $seller => $years) foreach ($years as $year => $_) $insert->execute([$seller, $year]);
        self::reset();
    }

    /**
     * $net (the distance-sale net stored for an order charged the consumer's VAT) at the seller's own VAT: what it
     * added to the threshold when it was decided (CheckoutService::thresholdVat() prices the crossing at home VAT). Each
     * snapshot component's gross is netted at the seller's rate of its class (shipping and fee at the goods' highest);
     * $net is scaled by that over the snapshot's net. Without a snapshot, $net.
     */
    private static function homeNet(array $order, int $net): int
    {
        $snapshot = json_decode((string)($order['financial_snapshot'] ?? ''), true);
        $snapshotNet = is_array($snapshot) ? (int)($snapshot['totals']['net'] ?? 0) : 0;
        if ($snapshotNet <= 0) return $net;
        $country = self::sellerCountry(self::storeConfig((string)($order['store_id'] ?? 'default')));
        $home = 0;
        $goodsRate = 0.0;
        $ancillary = 0;
        foreach ((array)($snapshot['components'] ?? []) as $component) {
            if (!is_array($component)) continue;
            if (($component['type'] ?? '') !== 'product') { $ancillary += (int)($component['gross'] ?? 0); continue; }
            $rate = VatService::rate($country, VatService::resolveClass((string)($component['vat_class'] ?? 'standard')));
            $goodsRate = max($goodsRate, $rate);
            $home += (int)round((int)($component['gross'] ?? 0) / (1 + $rate / 100));
        }
        $home += (int)round($ancillary / (1 + $goodsRate / 100));
        return (int)round($net * $home / $snapshotNet);
    }

    /**
     * Removes the rows from $since on that the replay ($crossed, from the documents and orders) does not explain, the
     * same whatever the table held: rows of a seller no store maps to any more (an old key) are left alone.
     * @param array<string, array<int, true>> $crossed
     */
    private static function dropCarriedActivations(PDO $db, int $since, array $crossed): void
    {
        $stores = (array)(Bootstrap::stores()['stores'] ?? []);
        $known = [];
        foreach ($stores as $id => $store) if (is_array($store)) $known[self::sellerKey((string)$id, $store, $stores)] = true;
        $stmt = $db->prepare('SELECT seller_key, year FROM oss_threshold_activations WHERE year >= ?');
        $stmt->execute([$since]);
        $delete = $db->prepare('DELETE FROM oss_threshold_activations WHERE seller_key = ? AND year = ?');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            [$seller, $year] = [(string)$row['seller_key'], (int)$row['year']];
            if (!isset($crossed[$seller][$year]) && isset($known[$seller])) $delete->execute([$seller, $year]);
        }
        self::reset();
    }

    /** @return array{previous: int, current: int} */
    private static function years(PDO $db, string $storeId, array $store, array $stores): array
    {
        $year = (int)ShopTime::date('Y');
        $sameSeller = self::sellerStores($storeId, $store, $stores);
        self::storeMissingNets($db, $year);
        return self::consistently($db, static function () use ($db, $sameSeller, $year): array {
            $previous = self::yearSales($db, $sameSeller, $year - 1, false)['sum'];
            // Past the threshold last year: this year does not matter (art. 59c), nothing more to read.
            if ($previous > self::EUR_CENTS) return ['previous' => $previous, 'current' => 0];
            return ['previous' => $previous, 'current' => self::yearSales($db, $sameSeller, $year, true)['sum']];
        });
    }

    private const MISSING_NETS_SECONDS = 10.0;
    private static bool $netsStored = false;

    /**
     * R51 (review round 21, medium): open orders still without orders.oss_net (backfillOrderNets() not run) get it before
     * the count, 500 per committed batch, for at most MISSING_NETS_SECONDS of this request. Counted inside the snapshot and
     * stored only at its end, 200 000 such orders outlasted max_execution_time = 30: each checkout died and the next one
     * started over. With orders still missing after the budget the count is refused (the checkout asks to retry); the
     * next request goes on where this one stopped.
     */
    private static function storeMissingNets(PDO $db, int $year): void
    {
        if (self::$netsStored || $db->inTransaction() || !self::hasColumn('orders', 'oss_net', $db)) return;
        $since = sprintf('%04d-12-31', $year - 2); // a day before the previous year, in any store's time zone
        // R53 (review round 53, low): per configured store (the stores a count reads), so the lookup runs on
        // idx_orders_store_oss_net. Without the store it read every order: 0,1-0,8 s on each checkout at 500 000 orders.
        $storeIds = array_map('strval', array_keys((array)(Bootstrap::stores()['stores'] ?? []))) ?: ['default'];
        $missing = $db->prepare('SELECT 1 FROM orders WHERE store_id IN (' . implode(',', array_fill(0, count($storeIds), '?')) . ') AND oss_net IS NULL AND created_at >= ? LIMIT 1');
        for ($pass = 0; ; $pass++) {
            $missing->execute([...$storeIds, $since]);
            $found = (bool)$missing->fetchColumn();
            $missing->closeCursor();
            if (!$found) break;
            if ($pass > 0) throw new RuntimeException('Open orders without their stored OSS net are being stored; retry shortly');
            self::backfillOrderNets($db, $since, microtime(true) + self::MISSING_NETS_SECONDS);
        }
        self::$netsStored = true;
    }

    /**
     * R38 (low): the totals, the documents and the open orders a count adds up are read in one snapshot. Read one by
     * one, an invoice issued in between (its order leaves the open orders, its net joins the totals) was counted twice
     * or not at all. Inside the caller's transaction that is the snapshot already; else a read-only one: SQLite's
     * deferred BEGIN (WAL: writers go on, this connection keeps its snapshot), MySQL's REPEATABLE READ consistent snapshot.
     * @template T
     * @param callable(): T $read
     * @return T
     */
    private static function consistently(PDO $db, callable $read): mixed
    {
        self::$unstoredNets = [];
        if ($db->inTransaction()) {
            try {
                return $read();
            } finally {
                self::$unstoredNets = []; // the caller's transaction may be read-only: stored by a later read
            }
        }
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec('BEGIN DEFERRED');
        } else {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        }
        try {
            $result = $read();
        } catch (\Throwable $e) {
            self::$unstoredNets = [];
            $db->exec('ROLLBACK');
            throw $e;
        }
        $db->exec('COMMIT');
        self::storeCountedNets($db);
        return $result;
    }

    /** @var array<int, int> order id => net of open orders yearSales() counted from their lines (no orders.oss_net yet) */
    private static array $unstoredNets = [];

    /**
     * R50: stores the nets yearSales() computed for orders without one (backfillOrderNets() not run), after the read-only
     * snapshot, so the next checkout reads them from orders.oss_net. Only a still missing net is written (an order placed,
     * edited or invoiced meanwhile stored its own); 500 per commit.
     */
    private static function storeCountedNets(PDO $db): void
    {
        $pending = self::$unstoredNets;
        self::$unstoredNets = [];
        if ($pending === []) return;
        $store = $db->prepare('UPDATE orders SET oss_net = ? WHERE id = ? AND oss_net IS NULL');
        foreach (array_chunk($pending, 500, true) as $chunk) {
            $db->beginTransaction();
            try {
                foreach ($chunk as $id => $net) $store->execute([$net, $id]);
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
    }

    /**
     * @param list<string> $storeIds
     * @return array{sum: int, unconverted: list<string>, converted: list<string>}
     */
    private static function yearSales(PDO $db, array $storeIds, int $year, bool $withOpenOrders, int $saleNet = 0, string $saleCurrency = 'eur'): array
    {
        $marks = implode(',', array_fill(0, count($storeIds), '?'));
        $range = [sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)];
        $byCurrency = [];
        $add = static function (string $currency, int $net) use (&$byCurrency): void {
            $byCurrency[strtolower($currency)] = ($byCurrency[strtolower($currency)] ?? 0) + $net;
        };
        $stored = Db::hasColumn('invoices', 'oss_net', $db);
        // R38 (medium 4): a credit note in the year of the invoice it corrects (issued no earlier than it).
        $at = self::documentDateSql('i', 's');
        $documents = "FROM invoices i JOIN orders o ON o.id = i.order_id LEFT JOIN invoices s ON s.id = i.refers_to_invoice_id
            WHERE o.store_id IN ({$marks}) AND i.status = 'issued' AND i.type IN ('invoice', 'credit_note') AND i.issued_at >= ? AND {$at} >= ? AND {$at} < ?";
        $documentRange = [$range[0], ...$range];
        // R36 (medium 1): the stored nets are summed per store and year as documents are issued (oss_threshold_totals),
        // so a checkout reads a few rows instead of every invoice of two years (~5 s per request at 200 000 invoices).
        if ($stored && Db::tableExists('oss_threshold_totals', $db)) {
            $stmt = $db->prepare("SELECT currency, SUM(net) AS net FROM oss_threshold_totals WHERE store_id IN ({$marks}) AND year = ? GROUP BY currency");
            $stmt->execute([...$storeIds, $year]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $add((string)$row['currency'], (int)$row['net']);
        } elseif ($stored) {
            // R40: in EUR at its issue when converted then (record()).
            [$currencySql, $netSql] = self::storedNetSql($db, 'i');
            $stmt = $db->prepare("SELECT {$currencySql} AS currency, COALESCE(SUM({$netSql}), 0) AS net {$documents} AND i.oss_net <> 0 GROUP BY {$currencySql}");
            $stmt->execute([...$storeIds, ...$documentRange]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $add((string)$row['currency'], (int)$row['net']);
        }
        // A document without its stored net (written before the migration, or by another writer) is counted from its lines.
        $stmt = $db->prepare('SELECT i.* ' . $documents . ($stored ? ' AND i.oss_net IS NULL' : '') . ' ORDER BY i.id');
        $stmt->execute([...$storeIds, ...$documentRange]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) $add((string)($doc['currency'] ?? 'eur'), self::documentNet($db, $doc));
        // Placed, not cancelled and not invoiced yet (an unpaid bank transfer, a cash-on-delivery parcel on its way): by
        // the net stored when it was placed (orders.oss_net, recordOrder()), else from its lines.
        if ($withOpenOrders) {
            // R38 (low): each store's calendar year (orderYearBounds()), not the UTC one.
            $bounds = [];
            foreach ($storeIds as $id) array_push($bounds, $id, ...self::orderYearBounds($id, $year));
            $inYear = implode(' OR ', array_fill(0, count($storeIds), '(o.store_id = ? AND o.created_at >= ? AND o.created_at < ?)'));
            $open = "FROM orders o WHERE ({$inYear}) AND o.status NOT IN ('cancelled', 'refunded', 'failed')
                  AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.order_id = o.id AND i.type = 'invoice' AND i.status = 'issued')";
            $storedOrders = Db::hasColumn('orders', 'oss_net', $db);
            if ($storedOrders) {
                $stmt = $db->prepare("SELECT LOWER(o.currency) AS currency, SUM(o.oss_net) AS net {$open} AND o.oss_net > 0 GROUP BY LOWER(o.currency)");
                $stmt->execute($bounds);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $add((string)$row['currency'], (int)$row['net']);
                $open .= ' AND o.oss_net IS NULL';
            }
            // R50: an order still without its net (backfillOrderNets() not run yet) is counted from its lines once: by id,
            // 500 rows at a time (not every row of the year in memory), and its net is stored for the next checkout.
            $stmt = $db->prepare("SELECT o.* {$open} AND o.vat_scenario IN (?, ?, ?, ?) AND o.id > ? ORDER BY o.id LIMIT 500");
            $after = 0;
            do {
                $stmt->execute([...$bounds, ...self::OPEN_ORDER_SCENARIOS, $after]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $order) {
                    $net = self::orderNet($db, $order);
                    $add((string)($order['currency'] ?? 'eur'), $net);
                    if ($storedOrders) self::$unstoredNets[(int)$order['id']] = $net;
                    $after = (int)$order['id'];
                }
            } while (count($rows) === 500);
        }
        if ($saleNet > 0) $add($saleCurrency, $saleNet);
        $rates = EurRates::current()['rates'];
        $sum = 0;
        $unconverted = [];
        $converted = [];
        foreach ($byCurrency as $currency => $net) {
            if ($currency === 'eur') { $sum += $net; continue; }
            $rate = $rates[$currency] ?? null;
            if (!is_numeric($rate) || (float)$rate <= 0) { if ($net !== 0) $unconverted[] = strtoupper($currency); continue; }
            if ($net !== 0) $converted[] = strtoupper($currency);
            $sum += (int)round($net * (float)$rate);
        }
        return ['sum' => $sum, 'unconverted' => $unconverted, 'converted' => $converted];
    }

    /** Net of the order's downloads: their snapshot components (lines in order), else the lines' own net. */
    private static function digitalNet(PDO $db, array $order, string $snapshotJson): int
    {
        if (!self::hasColumn('order_items', 'digital', $db)) return 0;
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
        if (!self::hasColumn('order_items', 'digital', $db)) return [];
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
        $seller = self::sellerKey($storeId, $store, $stores);
        $sameSeller = [];
        foreach ($stores as $id => $other) {
            if (is_array($other) && self::sellerKey((string)$id, $other, $stores) === $seller) $sameSeller[] = (string)$id;
        }
        return $sameSeller ?: [$storeId];
    }

    /**
     * R38 (medium 5): the threshold is the taxable person's (art. 59c): stores invoicing under one IČ DPH share it,
     * whatever number series they issue (two storefronts of one company with own prefixes had 10 000 EUR each). Without
     * a VAT ID, the seller whose series the store's documents use (InvoiceService::sellerCode()).
     */
    private static function sellerKey(string $storeId, array $store, ?array $stores = null): string
    {
        $root = ['stores' => [$storeId => $store] + ($stores ?? (Bootstrap::stores()['stores'] ?? []))] + Bootstrap::stores();
        $code = InvoiceService::sellerCode($root, $storeId, (string)($store['currency'] ?? '')) ?? $storeId;
        // A storefront sharing a seller's series is that seller (its own legal fields only change what is printed).
        $vatId = self::vatId((array)($root['stores'][$code] ?? [])) ?: self::vatId($store);
        return $vatId !== '' ? 'vat:' . $vatId : $code;
    }

    private static function vatId(array $store): string
    {
        $legal = $store['legal'] ?? null;
        return is_array($legal) ? strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)($legal['ic_dph'] ?? ''))) : '';
    }

    /** R38 (medium 5, migration): an activation recorded under a store's number-series seller holds for its VAT payer too. */
    public static function rekeyActivations(PDO $db): void
    {
        $stores = (array)(Bootstrap::stores()['stores'] ?? []);
        $rows = $db->query('SELECT seller_key, year FROM oss_threshold_activations')->fetchAll(PDO::FETCH_ASSOC);
        $insert = $db->prepare(($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO oss_threshold_activations (seller_key, year) VALUES (?, ?)');
        foreach ($stores as $id => $store) {
            if (!is_array($store)) continue;
            $old = InvoiceService::sellerCode(['stores' => $stores] + Bootstrap::stores(), (string)$id, (string)($store['currency'] ?? '')) ?? (string)$id;
            $new = self::sellerKey((string)$id, $store, $stores);
            if ($new === $old) continue;
            foreach ($rows as $row) if ((string)$row['seller_key'] === $old) $insert->execute([$new, (int)$row['year']]);
        }
        self::reset();
    }

    /**
     * R38 (low): an order's year is its store's calendar year, not UTC's (orders.created_at is UTC): an order placed at
     * 00:30 on 1 January in Bratislava (23:30 UTC) is a sale of the new year, as its invoice is (InvoiceService).
     */
    private static function orderYear(string $storeId, string $createdAtUtc): int
    {
        $time = strtotime(trim($createdAtUtc) . ' UTC');
        if ($time === false) return (int)substr($createdAtUtc, 0, 4);
        return (int)ShopTime::dateIn('Y', StoreContext::timezoneFor(self::storeConfig($storeId)), $time);
    }

    /** @return array{0: string, 1: string} UTC bounds [start, end) of $year on store $storeId's calendar, as orders.created_at */
    private static function orderYearBounds(string $storeId, int $year): array
    {
        $zone = new DateTimeZone(StoreContext::timezoneFor(self::storeConfig($storeId)));
        $utc = new DateTimeZone('UTC');
        $bound = static fn(int $y): string => (new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $y), $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        return [$bound($year), $bound($year + 1)];
    }

    private static function sellerCountry(array $store): string
    {
        return strtoupper((string)(($store['legal']['country'] ?? null) ?: 'SK'));
    }

    // A signed update runs the migrations under the old release's Db class (v2.0.0 has no hasColumn()): the schema is
    // read here then (R40: oss_net_eur is added after a migration calling rebuildActivations()).
    private static function hasColumn(string $table, string $column, PDO $db): bool
    {
        if (in_array('hasColumn', get_class_methods(Db::class), true)) return Db::hasColumn($table, $column, $db);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return in_array($column, array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name'), true);
        }
        $stmt = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return $stmt->fetchColumn() !== false;
    }

    /** The store's stores.json entry; [] (seller country SK) when unknown, e.g. in a migration without a store context. */
    private static function storeConfig(string $storeId): array
    {
        $store = Bootstrap::stores()['stores'][$storeId] ?? null;
        return is_array($store) ? $store : [];
    }
}
