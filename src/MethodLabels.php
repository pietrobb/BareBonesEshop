<?php
/**
 * R8: customer-facing names of the shipping and payment methods in the shopper's language. The methods the
 * migrations seed carry one Slovak name for every store; while a method still has that seeded name (and
 * description), the storefront shows the locale's lang/<locale>.php text (methods.<kind>.<code>[_desc]).
 * A name the merchant changed is the merchant's and is shown as entered.
 */
final class MethodLabels {
    /** Seeded [name, description] per kind and code (migrations 20260425064000, 20260425071000 and 20260801190000). */
    public const SEEDED = [
        'shipping' => [
            'pickup' => ['Osobný odber', null],
            'packeta' => ['Packeta / Zásielkovňa', null],
            'gls' => ['GLS kuriér', null],
        ],
        'payment' => [
            'card' => ['Kartou online', 'Online card payment via configured gateway.'],
            'bank_transfer' => ['Bankový prevod', 'Manual bank transfer after order creation.'],
            'cod' => ['Dobierka', 'Cash on delivery where the selected carrier supports COD.'],
            // Migration 20260801190000 seeds the B2B method with an English name.
            'invoice' => ['Invoice purchase', 'Approved B2B account with credit limit and payment terms.'],
        ],
    ];

    /** Codes of the built-in shipping adapters (Adapters::shipping) that are stored as a shipment's carrier. */
    private const CARRIERS = ['pickup', 'packeta', 'gls'];

    /** $locale null = the current locale; a document (invoice, e-mail) passes its own language. */
    public static function name(string $kind, string $code, string $name, ?string $locale = null): string {
        return self::translated($kind, $code, $name, 0, '', $locale);
    }

    public static function description(string $kind, string $code, string $description, ?string $locale = null): string {
        return self::translated($kind, $code, $description, 1, '_desc', $locale);
    }

    /**
     * The customer-facing name of a carrier on a shipment (e-mail, account, order status page): a built-in carrier
     * code (packeta, gls, pickup) reads as its name in $locale (carrier_name.<code>); a carrier the merchant typed
     * in ("DPD") is shown as entered.
     */
    public static function carrier(string $carrier, ?string $locale = null): string {
        $code = strtolower(trim($carrier));
        if (!in_array($code, self::CARRIERS, true)) return $carrier;
        $key = 'carrier_name.' . $code;
        $text = $locale === null || $locale === '' ? I18n::t($key) : I18n::tIn($key, $locale);
        return $text === $key || $text === '' ? $carrier : $text;
    }

    /**
     * R20: the names migration 20261022230000 seeds into the CZ, HU and PL stores — an English page of the HU store
     * showed "GLS futár" next to English text. Seeded name => its languages (space-separated); in them the name stays as seeded ("Platba kartou" is Czech and Slovak).
     */
    private const SEEDED_STORE_NAMES = [
        'shipping' => [
            'pickup' => ['Osobní odběr' => 'cs', 'Személyes átvétel' => 'hu', 'Odbiór osobisty' => 'pl'],
            'packeta' => ['Zásilkovna' => 'cs', 'Packeta' => null],
            'gls' => ['GLS kurýr' => 'cs', 'GLS futár' => 'hu', 'Kurier GLS' => 'pl'],
        ],
        'payment' => [
            'card' => ['Platba kartou' => 'cs sk', 'Bankkártyás fizetés' => 'hu', 'Karta płatnicza' => 'pl'],
            'bank_transfer' => ['Bankovní převod' => 'cs', 'Banki átutalás' => 'hu', 'Przelew bankowy' => 'pl'],
            'cod' => ['Dobírka' => 'cs', 'Utánvét' => 'hu', 'Pobranie' => 'pl'],
        ],
    ];

    /** @var array<string, array<string, array<string, array<string, string>>>> provider store => kind => code => translations */
    private static array $merchant = [];

    /** Forget the merchant translations read in this request (after a method is saved). */
    public static function forget(): void { self::$merchant = []; }

    /**
     * R50 (low): the translations the merchant entered for a method ({"cs": {"name": "…", "description": "…"}}) as JSON
     * for the methods' translations column: only the given store languages, only non-empty texts; null when none.
     * R51: the saved translations of languages the form did not offer ($existing) are kept, not dropped.
     */
    public static function encodeTranslations(mixed $posted, array $languages, ?string $existing = null): ?string {
        $out = [];
        $kept = json_decode((string)$existing, true);
        foreach (is_array($kept) ? $kept : [] as $locale => $texts) {
            if (is_string($locale) && !in_array($locale, $languages, true) && is_array($texts)) $out[$locale] = $texts;
        }
        foreach (is_array($posted) ? $posted : [] as $locale => $texts) {
            if (!is_string($locale) || !in_array($locale, $languages, true) || !is_array($texts)) continue;
            foreach (['name', 'description'] as $field) {
                $text = trim(Security::str($texts[$field] ?? ''));
                if ($text !== '') $out[$locale][$field] = mb_substr($text, 0, 191);
            }
        }
        ksort($out);
        return $out === [] ? null : json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string, string>> locale => [name, description] of the method in the active store */
    public static function translations(string $kind, string $code): array {
        if (!StoreContext::hasCurrent() || !Db::connected()) return [];
        $db = Db::get();
        $store = spl_object_id($db) . ':' . StoreContext::current()->providerId();
        if (!isset(self::$merchant[$store][$kind])) {
            self::$merchant[$store][$kind] = [];
            $table = $kind === 'payment' ? 'payment_methods' : 'shipping_methods';
            // Before migration 20261120120400 (doctor, upgrade) there is no translations column yet.
            if (!Db::hasColumn($table, 'translations', $db)) return [];
            $stmt = $db->prepare("SELECT code, translations FROM {$table} WHERE store_id = ? AND translations IS NOT NULL AND translations <> ''");
            $stmt->execute([StoreContext::current()->providerId()]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $decoded = json_decode((string)$row['translations'], true);
                if (!is_array($decoded)) continue;
                foreach ($decoded as $locale => $texts) {
                    if (!is_array($texts)) continue;
                    foreach (['name', 'description'] as $field) {
                        if (is_string($texts[$field] ?? null)) self::$merchant[$store][$kind][(string)$row['code']][(string)$locale][$field] = $texts[$field];
                    }
                }
            }
        }
        return self::$merchant[$store][$kind][$code] ?? [];
    }

    /**
     * The languages in which a method may carry the merchant's own name and description: those of every store sharing
     * the active store's methods (provider_scope), less the language of the methods' own name (the default language of
     * the store owning them). R51: only the active store's were offered, so a German store sharing a Slovak store's
     * methods could not enter German at all, and saving from one store dropped the other stores' translations.
     */
    public static function translationLanguages(): array {
        $store = StoreContext::current();
        $provider = $store->providerId();
        $stores = (array)(Bootstrap::stores()['stores'] ?? []);
        $languages = [];
        foreach ([$store->id() => $store->config()] + $stores as $id => $config) {
            if (!is_array($config) || (string)($config['provider_scope'] ?? $id) !== $provider) continue;
            foreach ([(string)($config['default_locale'] ?? 'en'), ...array_map('strval', (array)($config['languages'] ?? []))] as $locale) $languages[$locale] = true;
        }
        unset($languages[self::baseLanguage()]);
        return array_keys($languages);
    }

    /**
     * The language of a method's own name and description: the default language of the store owning the active store's
     * methods. R53 (low): the admin marked the translations' languages but not this one.
     */
    public static function baseLanguage(): string {
        $store = StoreContext::current();
        $provider = $store->providerId();
        $stores = (array)(Bootstrap::stores()['stores'] ?? []);
        return is_array($stores[$provider] ?? null) ? (string)($stores[$provider]['default_locale'] ?? 'en') : $store->defaultLocale();
    }

    /** The admin label of a method's own name/description field ($key), with its language when translations are offered too. */
    public static function fieldLabel(string $key): string {
        return I18n::t($key) . (self::translationLanguages() !== [] ? ' (' . self::baseLanguage() . ')' : '');
    }

    private static function translated(string $kind, string $code, string $value, int $field, string $suffix, ?string $locale): string {
        $own = self::translations($kind, $code)[$locale === null || $locale === '' ? I18n::locale() : $locale][$field === 0 ? 'name' : 'description'] ?? '';
        if (trim($own) !== '') return $own;
        $seeded = self::SEEDED[$kind][$code][$field] ?? null;
        $names = self::SEEDED_STORE_NAMES[$kind][$code] ?? [];
        if ($field === 0 && array_key_exists($value, $names)) {
            // "Packeta" is the brand in every language; it needs no translation.
            if ($names[$value] === null || in_array($locale === null || $locale === '' ? I18n::locale() : $locale, explode(' ', $names[$value]), true)) return $value;
        } elseif ($seeded === null || $value !== $seeded) {
            return $value;
        }
        $key = 'methods.' . $kind . '.' . $code . $suffix;
        $text = $locale === null || $locale === '' ? I18n::t($key) : I18n::tIn($key, $locale);
        return $text === $key || $text === '' ? $value : $text;
    }
}
