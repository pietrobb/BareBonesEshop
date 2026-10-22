<?php
/**
 * Round 8: pre-contractual and confirmation information for consumers (Directive 2011/83/EU art. 6 and 8(7)):
 * the seller's identity, the withdrawal period and how to withdraw, the 24-month legal guarantee, the out-of-court
 * dispute body (ADR) and the terms on a durable medium. One source for the legal pages and the order confirmation.
 * The texts are a technical baseline for a lawyer to review; the ADR body defaults by the seller's country and
 * stores.json legal.adr_name / legal.adr_url override it.
 */
final class ConsumerNotice {
    public const WITHDRAWAL_DAYS = 14;
    public const LIABILITY_MONTHS = 24;
    public const LEGAL_PAGES = ['privacy', 'terms', 'cookies'];

    /** Consumer ADR body per seller country (the EU ODR platform closed on 20 July 2025). */
    private const ADR = [
        'SK' => ['Slovenská obchodná inšpekcia', 'https://www.soi.sk'],
        'CZ' => ['Česká obchodní inspekce', 'https://adr.coi.cz'],
        'PL' => ['Inspekcja Handlowa', 'https://polubowne.uokik.gov.pl'],
        'HU' => ['Budapesti Békéltető Testület', 'https://bekeltet.bkik.hu'],
        'DE' => ['Universalschlichtungsstelle des Bundes', 'https://www.universalschlichtungsstelle.de'],
        'AT' => ['Schlichtung für Verbrauchergeschäfte', 'https://www.verbraucherschlichtung.at'],
    ];

    /** ['name' => ..., 'url' => ...] of the seller's ADR body ('' when none is known). */
    public static function adr(array $legal): array {
        $default = self::ADR[strtoupper(trim((string)($legal['country'] ?? '')))] ?? ['', ''];
        return [
            'name' => trim((string)($legal['adr_name'] ?? '')) ?: $default[0],
            'url' => trim((string)($legal['adr_url'] ?? '')) ?: $default[1],
        ];
    }

    /** Placeholders of the legal templates: the legal block plus the ADR body. */
    public static function legalVars(array $legal): array {
        $adr = self::adr($legal);
        return array_map('strval', array_filter($legal, 'is_scalar')) + ['adr_name' => $adr['name'], 'adr_url' => $adr['url']];
    }

    /** A legal page (privacy / terms / cookies) of $locale (else English) filled with the seller's data, as HTML. */
    public static function renderLegal(string $page, string $locale, array $legal): string {
        $dir = dirname(__DIR__) . '/templates/legal/';
        $tpl = $dir . $locale . '/' . $page . '.html';
        if (!preg_match('/^[a-z]{2}$/', $locale) || !is_file($tpl)) $tpl = $dir . 'en/' . $page . '.html';
        $html = (string)file_get_contents($tpl);
        $vars = [];
        foreach (self::legalVars($legal) as $key => $value) $vars['{{' . $key . '}}'] = esc($value);
        // The date the text last changed: the store's legal.updated_at, else the template's own
        // <!-- legal-updated: YYYY-MM-DD --> marker, else the template file's mtime — never "today".
        $updated = (string)($legal['updated_at'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $updated)) $updated = preg_match('/<!--\s*legal-updated:\s*(\d{4}-\d{2}-\d{2})\s*-->/', $html, $m) ? $m[1] : date('Y-m-d', (int)filemtime($tpl));
        $vars['{{updated_at}}'] = esc($updated);
        // A key the store's legal block does not set is left out, never printed as a placeholder.
        return (string)preg_replace('/\{\{[a-z_]+\}\}/', '', strtr($html, $vars));
    }

    /** The seller of $order: its identity from the invoicing seller, ADR and other legal keys from the store. */
    public static function sellerLegal(array $order): array {
        return InvoiceService::sellerIdentity($order) + (StoreContext::hasCurrent() ? StoreContext::current()->legal() : []);
    }

    /** Variables of the order confirmation e-mail. */
    public static function mailVars(array $order): array {
        $legal = self::sellerLegal($order);
        $adr = self::adr($legal);
        $address = trim(implode(', ', array_filter([
            (string)($legal['street'] ?? ''), trim((string)($legal['zip'] ?? '') . ' ' . (string)($legal['city'] ?? '')), (string)($legal['country'] ?? ''),
        ])));
        return [
            'seller_name' => (string)($legal['name'] ?? ''), 'seller_address' => $address,
            'seller_ico' => (string)($legal['ico'] ?? ''), 'seller_dic' => (string)($legal['dic'] ?? ''), 'seller_ic_dph' => (string)($legal['ic_dph'] ?? ''),
            'seller_email' => (string)($legal['email'] ?? ''), 'seller_phone' => (string)($legal['phone'] ?? ''),
            'seller_register_note' => (string)($legal['register_note'] ?? ''),
            'adr_name' => $adr['name'], 'adr_url' => $adr['url'],
            'withdrawal_days' => (string)self::WITHDRAWAL_DAYS, 'liability_months' => (string)self::LIABILITY_MONTHS,
            'withdrawal_url' => self::absoluteUrl('/?page=withdrawal&order=' . rawurlencode((string)($order['order_number'] ?? ''))),
            'terms_url' => self::absoluteUrl('/?page=terms'),
        ];
    }

    /** The terms valid when the order was confirmed, attached to the confirmation (durable medium). */
    public static function termsAttachment(array $order, string $locale): array {
        $html = '<!doctype html><html lang="' . esc($locale) . '"><head><meta charset="utf-8"></head><body>'
            . self::renderLegal('terms', $locale, self::sellerLegal($order)) . '</body></html>';
        return ['filename' => 'terms-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($order['order_number'] ?? '')) . '.html', 'content_type' => 'text/html; charset=utf-8', 'data' => $html];
    }

    /** $path of the active store as an absolute URL (the configured app_url, else the store-relative path). */
    private static function absoluteUrl(string $path): string {
        $link = Template::storeUrl($path);
        $appUrl = Security::configuredAppUrl();
        return $appUrl === null ? $link : Security::storeAppUrl($appUrl, StoreContext::current()->config()) . Template::seoPath($link);
    }
}
