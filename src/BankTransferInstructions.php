<?php
/**
 * What a customer needs to pay an order by bank transfer: the store's account
 * (stores.json legal.iban / legal.bic / legal.name), the exact amount and currency,
 * the variable symbol the merchant matches the transfer by (the order ID) and a
 * PAY by square QR code with the same data.
 *
 * Shown only while a bank-transfer order still waits for money and the store has a
 * valid IBAN; paid, cancelled or expired orders get no payment instructions.
 */
final class BankTransferInstructions {
    private const CLOSED_STATUSES = ['cancelled', 'refunded', 'returned'];

    public static function forOrder(array $order, ?array $legal = null): ?array {
        if (!self::isBankTransfer($order) || (string)($order['payment_status'] ?? '') !== 'pending'
            || in_array((string)($order['status'] ?? ''), self::CLOSED_STATUSES, true)) {
            return null;
        }
        $legal ??= StoreContext::current()->legal();
        $iban = strtoupper(preg_replace('/\s+/', '', (string)($legal['iban'] ?? '')) ?? '');
        if (!self::validIban($iban)) return null;
        $bic = strtoupper(trim((string)($legal['bic'] ?? '')));
        if (!preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) $bic = '';

        $amount = (int)$order['total'];
        $currency = strtolower((string)($order['currency'] ?? '') ?: 'eur');
        $variableSymbol = self::variableSymbol($order);
        $due = self::dueAt($order);
        $storeId = self::storeOf($order);
        $beneficiary = (string)($legal['name'] ?? '');
        // PAY by square is the Slovak banks' standard and they accept it for EUR only; other currencies get text instructions.
        $payBySquare = $currency !== 'eur' ? '' : PayBySquare::encode([
            'iban' => $iban, 'bic' => $bic, 'amount' => $amount, 'currency' => $currency,
            'variable_symbol' => $variableSymbol, 'due_date' => ShopTime::date('Ymd', $due, $storeId),
            'note' => (string)$order['order_number'], 'beneficiary' => $beneficiary,
        ]);
        return [
            'beneficiary' => $beneficiary,
            'bank' => (string)($legal['bank'] ?? ''),
            'iban' => $iban,
            'iban_display' => trim(chunk_split($iban, 4, ' ')),
            'bic' => $bic,
            'amount' => $amount,
            'amount_display' => I18n::price($amount, $currency),
            'currency' => strtoupper($currency),
            'variable_symbol' => $variableSymbol,
            'message' => (string)$order['order_number'],
            'due_date' => ShopTime::date('Y-m-d', $due, $storeId),
            'pay_by_square' => $payBySquare,
            'qr_svg' => $payBySquare === '' ? '' : QrCode::svg($payBySquare, I18n::t('bank_transfer.qr_label')),
        ];
    }

    /** Rendered instructions block for storefront pages, '' when there is nothing to pay by transfer. */
    public static function html(array $order): string {
        $instructions = self::forOrder($order);
        if ($instructions === null) return '';
        $labels = [];
        foreach (['title', 'intro', 'beneficiary', 'iban', 'bic', 'amount', 'variable_symbol', 'message', 'due_date', 'qr_hint'] as $key) {
            $labels[$key] = I18n::t('bank_transfer.' . $key);
        }
        return Template::render('storefront/bank-transfer', ['bt' => $instructions, 't' => $labels]);
    }

    /** Same instructions without the rendered QR image, for JSON and e-mail. */
    public static function forApi(array $order, ?array $legal = null): ?array {
        $instructions = self::forOrder($order, $legal);
        if ($instructions === null) return null;
        unset($instructions['qr_svg']);
        return $instructions;
    }

    /** Flat variables for mail templates: {{#if bank_transfer}} … {{bank_iban}} {{bank_amount}} {{bank_vs}}. */
    public static function mailVars(array $order): array {
        $bank = self::forApi($order);
        $vars = ['bank_transfer' => $bank !== null];
        $fields = ['beneficiary' => 'beneficiary', 'iban' => 'iban_display', 'bic' => 'bic', 'amount' => 'amount_display',
            'vs' => 'variable_symbol', 'message' => 'message', 'due_date' => 'due_date'];
        foreach ($fields as $name => $field) $vars['bank_' . $name] = (string)($bank[$field] ?? '');
        return $vars;
    }

    /** Up to 10 digits the merchant finds the transfer by; the order ID is unique across stores. */
    public static function variableSymbol(array $order): string {
        return (string)(int)$order['id'];
    }

    private static function isBankTransfer(array $order): bool {
        $snapshot = json_decode((string)($order['payment_method_snapshot'] ?? ''), true);
        return OfflinePaymentQueue::isOfflineTransfer($order, is_array($snapshot) ? $snapshot : []);
    }

    /** Days after the due date a transfer may still be on its way (bank processing) before the order is cancelled. */
    public const DEFAULT_GRACE_DAYS = 3;
    /** Days before the due date an unpaid transfer order is reminded (once). */
    public const DEFAULT_REMINDER_DAYS = 2;

    /** End (23:59:59 on the shop's calendar) of the day the transfer is due: $ttl (default: the configured term) after the order. */
    public static function dueAt(array $order, ?int $ttl = null): int {
        $created = strtotime((string)($order['created_at'] ?? '') . ' UTC') ?: ShopTime::now();
        return ShopTime::endOfDay($created + ($ttl ?? self::payWithinSeconds()), 0, self::storeOf($order));
    }

    /**
     * When an unpaid transfer order may be cancelled: after the whole due day and bank_transfer_grace_days more
     * (default 3) on the shop's calendar, so money sent on the due day is credited before the order is released.
     */
    public static function cancelAfter(array $order, ?int $ttl = null): int {
        $grace = max(0, (int)(Security::config()['bank_transfer_grace_days'] ?? self::DEFAULT_GRACE_DAYS));
        return ShopTime::endOfDay(self::dueAt($order, $ttl), $grace, self::storeOf($order));
    }

    /** Start (00:00 local) of the day bank_transfer_reminder_days (default 2) before the due date. */
    public static function remindFrom(array $order, ?int $ttl = null): int {
        $lead = max(0, (int)(Security::config()['bank_transfer_reminder_days'] ?? self::DEFAULT_REMINDER_DAYS));
        return ShopTime::endOfDay(self::dueAt($order, $ttl), -$lead - 1, self::storeOf($order)) + 1;
    }

    /**
     * Queue one bank_transfer_reminder e-mail for each unpaid transfer order of the active store whose reminder day has
     * come and which is not yet due to be cancelled. The order row is claimed first, so a reminder is sent at most once.
     */
    public static function remindDue(?int $ttl = null, int $limit = 50): int {
        $db = Db::get();
        // Migration window: the code is deployed before 20261101120000_order_payment_reminder has run.
        if (!Db::hasColumn('orders', 'payment_reminder_sent_at', $db)) return 0;
        $stmt = $db->prepare("SELECT * FROM orders WHERE store_id = :store AND status = 'pending' AND payment_status = 'pending'
            AND payment_reminder_sent_at IS NULL AND (payment_id IS NULL OR payment_id = '') ORDER BY id ASC LIMIT 500");
        $stmt->execute([':store' => StoreContext::current()->id()]);
        $claim = $db->prepare("UPDATE orders SET payment_reminder_sent_at = :now WHERE id = :id AND payment_reminder_sent_at IS NULL
            AND status = 'pending' AND payment_status = 'pending'");
        $now = ShopTime::now();
        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
            if ($sent >= $limit) break;
            if (!self::isBankTransfer($order) || trim((string)($order['customer_email'] ?? '')) === '') continue;
            if ($now < self::remindFrom($order, $ttl) || $now > self::dueAt($order, $ttl)) continue;
            $claim->execute([':now' => gmdate('Y-m-d H:i:s', $now), ':id' => (int)$order['id']]);
            if ($claim->rowCount() !== 1) continue;
            JobQueue::push('email_send', ['template' => 'bank_transfer_reminder', 'to' => (string)$order['customer_email'],
                'locale' => (string)($order['locale'] ?? ''), 'order_id' => (int)$order['id']], 5);
            $sent++;
        }
        return $sent;
    }

    private static function storeOf(array $order): ?string {
        return isset($order['store_id']) ? (string)$order['store_id'] : null;
    }

    /** Unpaid transfers are released by PendingOrderExpiry; the payment is due before that. */
    private static function payWithinSeconds(): int {
        $cfg = require __DIR__ . '/../config.php';
        return PendingOrderExpiry::offlineTtls(is_array($cfg) ? $cfg : [])['bank_transfer'] ?? 14 * 86400;
    }

    /** ISO 13616 check digits of an IBAN without spaces, upper case. */
    public static function validIban(string $iban): bool {
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) return false;
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $remainder = 0;
        foreach (str_split($rearranged) as $char) {
            $digits = ctype_digit($char) ? $char : (string)(ord($char) - 55);
            foreach (str_split($digits) as $digit) $remainder = ($remainder * 10 + (int)$digit) % 97;
        }
        return $remainder === 1;
    }
}
