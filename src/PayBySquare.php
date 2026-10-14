<?php
/**
 * PAY by square (Slovak Banking Association standard) payment-order QR text.
 *
 * Layout: 2-byte header (type 0 = PAY, version 1 = 1.1.0, document 0, reserved 0),
 * 2-byte little-endian length of CRC32 + payload, then the LZMA1 body (lc=3, lp=0, pb=2,
 * 128 KiB dictionary, no 13-byte stream header, no end marker), all as base32hex without
 * padding. The payload is the tab-separated data model with one payment and one account.
 *
 * The LZMA body is written by a literal-only encoder: a valid stream the standard decoder
 * reads, only without the size savings of match search, which this short payload does not need.
 */
final class PayBySquare {
    private const VERSION = 0x01;

    /**
     * @param array{iban:string,bic?:string,amount:int,currency:string,variable_symbol?:string,
     *              due_date?:string,note?:string,beneficiary?:string} $payment amount in minor units,
     *              due_date as YYYYMMDD
     */
    public static function encode(array $payment): string {
        $amount = (int)$payment['amount'];
        $fields = [
            '',                                         // invoice id
            '1',                                        // payment count
            '1',                                        // payment order
            self::amount($amount),
            strtoupper((string)$payment['currency']),
            (string)($payment['due_date'] ?? ''),
            (string)($payment['variable_symbol'] ?? ''),
            '',                                         // constant symbol
            '',                                         // specific symbol
            '',                                         // originator's reference
            self::text((string)($payment['note'] ?? '')),
            '1',                                        // bank account count
            strtoupper(preg_replace('/\s+/', '', (string)$payment['iban'])),
            strtoupper(trim((string)($payment['bic'] ?? ''))),
            '0',                                        // standing order extension
            '0',                                        // direct debit extension
            self::text((string)($payment['beneficiary'] ?? '')),
            '',                                         // beneficiary street
            '',                                         // beneficiary city
        ];
        $payload = implode("\t", $fields);
        $checked = pack('V', crc32($payload)) . $payload;
        $binary = chr(self::VERSION) . chr(0x00) . pack('v', strlen($checked)) . self::lzmaLiterals($checked);
        return self::base32Hex($binary);
    }

    private static function amount(int $minor): string {
        $text = sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
        return rtrim(rtrim($text, '0'), '.');
    }

    /** Banking apps expect plain ASCII text without tabs. */
    private static function text(string $value): string {
        static $from = ['á','ä','à','â','ã','å','ą','č','ć','ç','ď','é','ě','ë','è','ê','ę','í','ì','î','ï','ĺ','ľ','ł','ň','ń','ñ','ó','ô','ö','ő','ò','õ','ŕ','ř','š','ś','ß','ť','ú','ů','ü','ű','ù','û','ý','ÿ','ž','ź','ż'];
        static $to = ['a','a','a','a','a','a','a','c','c','c','d','e','e','e','e','e','e','i','i','i','i','l','l','l','n','n','n','o','o','o','o','o','o','r','r','s','s','ss','t','u','u','u','u','u','u','y','y','z','z','z'];
        $upperFrom = array_map(static fn(string $c): string => mb_strtoupper($c, 'UTF-8'), $from);
        $upperTo = array_map('strtoupper', $to);
        $plain = str_replace(array_merge($from, $upperFrom), array_merge($to, $upperTo), $value);
        $plain = preg_replace('/[^\x20-\x7E]/', '', $plain) ?? '';
        return trim(mb_substr($plain, 0, 140));
    }

    private static function lzmaLiterals(string $data): string {
        $out = '';
        $low = 0;
        $range = 0xFFFFFFFF;
        $cache = 0;
        $cacheSize = 1;
        $isMatch = array_fill(0, 4, 1024);   // state stays 0 (literal after literal)
        $literal = array_fill(0, 0x300 * 8, 1024);
        $shiftLow = static function () use (&$low, &$cache, &$cacheSize, &$out): void {
            if ($low < 0xFF000000 || $low > 0xFFFFFFFF) {
                $carry = $low >> 32;
                $temp = $cache;
                do {
                    $out .= chr(($temp + $carry) & 0xFF);
                    $temp = 0xFF;
                } while (--$cacheSize !== 0);
                $cache = ($low >> 24) & 0xFF;
            }
            $cacheSize++;
            $low = ($low & 0x00FFFFFF) << 8;
        };
        $bit = static function (array &$probs, int $index, int $value) use (&$low, &$range, $shiftLow): void {
            $bound = ($range >> 11) * $probs[$index];
            if ($value === 0) {
                $range = $bound;
                $probs[$index] += (2048 - $probs[$index]) >> 5;
            } else {
                $low += $bound;
                $range -= $bound;
                $probs[$index] -= $probs[$index] >> 5;
            }
            while ($range < 0x01000000) {
                $range = ($range << 8) & 0xFFFFFFFF;
                $shiftLow();
            }
        };

        $previous = 0;
        for ($pos = 0, $n = strlen($data); $pos < $n; $pos++) {
            $byte = ord($data[$pos]);
            $bit($isMatch, $pos & 3, 0);
            $base = 0x300 * ($previous >> 5);
            $symbol = 1;
            for ($i = 7; $i >= 0; $i--) {
                $b = ($byte >> $i) & 1;
                $bit($literal, $base + $symbol, $b);
                $symbol = ($symbol << 1) | $b;
            }
            $previous = $byte;
        }
        for ($i = 0; $i < 5; $i++) $shiftLow();
        return $out;
    }

    private static function base32Hex(string $binary): string {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUV';
        $bits = '';
        foreach (str_split($binary) as $char) $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        $bits = str_pad($bits, (int)ceil(strlen($bits) / 5) * 5, '0');
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec($chunk)];
        return $out;
    }
}
