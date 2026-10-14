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
        $created = strtotime((string)($order['created_at'] ?? '') . ' UTC') ?: time();
        $due = $created + self::payWithinSeconds();
        $beneficiary = (string)($legal['name'] ?? '');
        // PAY by square is the Slovak banks' standard and they accept it for EUR only; other currencies get text instructions.
        $payBySquare = $currency !== 'eur' ? '' : PayBySquare::encode([
            'iban' => $iban, 'bic' => $bic, 'amount' => $amount, 'currency' => $currency,
            'variable_symbol' => $variableSymbol, 'due_date' => gmdate('Ymd', $due),
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
            'due_date' => gmdate('Y-m-d', $due),
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

    /** Unpaid transfers are released by PendingOrderExpiry; the payment is due before that. */
    private static function payWithinSeconds(): int {
        $cfg = require __DIR__ . '/../config.php';
        return PendingOrderExpiry::offlineTtls(is_array($cfg) ? $cfg : [])['bank_transfer'] ?? 14 * 86400;
    }

    private static function validIban(string $iban): bool {
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
