<?php
/**
 * R23: the payment QR code the payer's banking app reads, chosen by currency and the payer's country:
 * - CZK: the Czech SPAYD (Short Payment Descriptor, Czech Banking Association) that every Czech banking app reads;
 * - EUR from Slovakia: PAY by square, the Slovak banks' standard;
 * - EUR from elsewhere: the EPC069-12 SEPA credit transfer code (GiroCode) read across the euro area; a German or
 *   Austrian payer's app does not read PAY by square, and that was all a German order got.
 * Other currencies have no common standard and keep the text instructions only.
 */
final class PaymentQr {
    public const PAY_BY_SQUARE = 'pay_by_square';
    public const EPC = 'epc';
    public const SPAYD = 'spayd';

    /**
     * @param array{iban:string,bic?:string,amount:int,currency:string,variable_symbol?:string,due_date?:string,
     *              note?:string,beneficiary?:string} $payment amount in minor units, due_date as YYYYMMDD
     * @param string $payerCountry ISO 3166-1 alpha-2 of the payer (the order's address), '' when unknown
     * @return array{format:string,payload:string} format and payload '' when the payment has no QR standard
     */
    public static function for(array $payment, string $payerCountry): array {
        $currency = strtolower((string)$payment['currency']);
        $amount = (int)$payment['amount'];
        if ($amount <= 0) return ['format' => '', 'payload' => ''];
        if ($currency === 'czk') return ['format' => self::SPAYD, 'payload' => self::spayd($payment)];
        if ($currency !== 'eur') return ['format' => '', 'payload' => ''];
        if (strtoupper($payerCountry) === 'SK') return ['format' => self::PAY_BY_SQUARE, 'payload' => PayBySquare::encode($payment)];
        $epc = self::epc($payment);
        return ['format' => $epc === '' ? '' : self::EPC, 'payload' => $epc];
    }

    /** The I18n key suffix of the QR's label and hint ('' for PAY by square, the original keys). */
    public static function labelSuffix(string $format): string {
        return $format === self::PAY_BY_SQUARE || $format === '' ? '' : '_' . $format;
    }

    /**
     * EPC069-12 version 002 (BIC optional inside the EEA), UTF-8, SEPA credit transfer. The beneficiary name is
     * mandatory: without one there is no code. The remittance carries the reference the merchant matches the
     * transfer by (the variable symbol) and the order number.
     */
    private static function epc(array $payment): string {
        $name = mb_substr(PayBySquare::text((string)($payment['beneficiary'] ?? '')), 0, 70);
        $amount = (int)$payment['amount'];
        if ($name === '' || $amount > 99999999999) return '';
        $remittance = trim((string)($payment['variable_symbol'] ?? '') . ' ' . PayBySquare::text((string)($payment['note'] ?? '')));
        return implode("\n", ['BCD', '002', '1', 'SCT', strtoupper(trim((string)($payment['bic'] ?? ''))), $name,
            strtoupper(preg_replace('/\s+/', '', (string)$payment['iban']) ?? ''), 'EUR' . self::decimal($amount), '', '',
            mb_substr($remittance, 0, 140)]);
    }

    /** SPAYD 1.0: ACC as IBAN(+BIC), the amount, currency, due date, variable symbol (X-VS), message and recipient name. */
    private static function spayd(array $payment): string {
        $account = strtoupper(preg_replace('/\s+/', '', (string)$payment['iban']) ?? '');
        $bic = strtoupper(trim((string)($payment['bic'] ?? '')));
        $fields = ['SPD', '1.0', 'ACC:' . $account . ($bic !== '' ? '+' . $bic : ''), 'AM:' . self::decimal((int)$payment['amount']), 'CC:CZK'];
        $due = (string)($payment['due_date'] ?? '');
        if (preg_match('/^\d{8}$/', $due)) $fields[] = 'DT:' . $due;
        $vs = (string)($payment['variable_symbol'] ?? '');
        if (preg_match('/^\d{1,10}$/', $vs)) $fields[] = 'X-VS:' . $vs;
        // '*' separates the fields; the texts are plain ASCII without it.
        $message = mb_substr(str_replace('*', '', PayBySquare::text((string)($payment['note'] ?? ''))), 0, 60);
        if ($message !== '') $fields[] = 'MSG:' . $message;
        $recipient = mb_substr(str_replace('*', '', PayBySquare::text((string)($payment['beneficiary'] ?? ''))), 0, 35);
        if ($recipient !== '') $fields[] = 'RN:' . $recipient;
        return implode('*', $fields);
    }

    private static function decimal(int $minor): string {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }
}
