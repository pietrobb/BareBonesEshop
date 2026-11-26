<?php
/**
 * Merchant-editable part of stores.json: each store's storefront name, time zone, seller identity (legal block), VAT
 * switches, domains and URL prefix (saveDomains()). Currency, scopes and invoice prefixes stay file-only: changing them
 * reprices the store or breaks invoice numbering. The updater keeps the installed stores.json, so admin edits survive updates.
 */
final class StoreSettings {
    public const LEGAL_TEXT = ['name', 'street', 'city', 'zip', 'country', 'ico', 'dic', 'ic_dph', 'iban', 'bic', 'bank', 'email', 'phone', 'register_note'];
    private const REQUIRED = ['name', 'street', 'city', 'zip', 'country', 'email'];
    private const MAX_LENGTH = 200;
    /** R56: the domain test's seconds for all the domains of one save (an admin request waited 10 × 4 s). */
    private const PROBE_SECONDS = 8;

    private static ?string $path = null;
    /** @var null|callable(string): list<string> */
    private static $resolver = null;

    /** Test hook: another stores.json (null = the application's). */
    public static function setPathForTesting(?string $path): void {
        self::$path = $path;
    }

    /** Test hook: the addresses a host name resolves to (null = DNS). */
    public static function setResolverForTesting(?callable $resolver): void {
        self::$resolver = $resolver;
    }

    /** Whether a legal block's seller charges VAT: a block without vat_registered is registered (checkout, storefront). */
    public static function vatRegistered(array $legal): bool {
        return !array_key_exists('vat_registered', $legal) || (bool)$legal['vat_registered'];
    }

    /** R59 (review round 24, finding 4): the tax identity fields stores.json sets for a store sharing a seller, which are ignored. */
    public static function identityIgnored(array $settings, string $storeId): array {
        return array_keys(SellerLegal::identityOverrides($settings, $storeId));
    }

    public static function path(): string {
        return self::$path ?? DataOverrides::storesPath();
    }

    /** Saved into the private data directory (or a stores.json in the application root); never into the example. */
    public static function writable(): bool {
        if (self::$path !== null) return is_file(self::$path) && is_writable(self::$path) && is_writable(dirname(self::$path));
        $dir = DataOverrides::dir();
        if ($dir !== null) return is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));
        return DataOverrides::storesWritePath() !== null;
    }

    /** The file a save writes: the data directory copy, or stores.json in the application root. */
    private static function target(): ?string {
        return self::$path ?? DataOverrides::storesWritePath();
    }

    /** Decoded stores.json, read fresh (not the per-request Bootstrap cache). */
    public static function load(): array {
        $data = json_decode((string)@file_get_contents(self::path()), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Validate the posted form and write it into stores.{$storeId}.
     * @return array{ok: bool, errors: list<string>, warning?: string}  errors are I18n-formatted messages; warning: R23, the
     *         saved store is public but has products without a price in its currency (not for sale there)
     */
    public static function save(string $storeId, array $input): array {
        $result = self::locked(static fn(): array => self::saveLocked($storeId, $input));
        if (!$result['ok']) return $result;
        $store = (array)(self::load()['stores'][$storeId] ?? []);
        $gap = StoreContext::isPublicConfig($store) ? StoreCurrencyCoverage::unpriced(Db::get(), $storeId, $store) : ['count' => 0, 'skus' => []];
        // R30: in the plural form of the count ("1 aktívnych produktov" was the only form).
        return $result + ['warning' => $gap['count'] === 0 ? '' : strtr(I18n::plural('admin.stores.unpriced', (int)$gap['count']), [
            '{currency}' => strtoupper((string)($store['currency'] ?? '')), '{skus}' => implode(', ', $gap['skus']),
        ])];
    }

    /** Two admins saving at once: each re-reads under the lock, so neither edit is lost. */
    private static function locked(callable $change): array {
        $target = self::target();
        $lock = $target === null ? false : @fopen($target . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if ($lock !== false) fclose($lock);
            return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.write')]];
        }
        try {
            return $change();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A new storefront under its own URL prefix, selling the shared catalog through the default store's providers
     * and invoicing as the default store's seller until its own seller identity is entered.
     */
    public static function addStore(array $input): array {
        return self::locked(static function () use ($input): array {
            $data = self::load();
            $field = static fn(string $f): string => trim(is_scalar($input[$f] ?? null) ? (string)$input[$f] : '');
            $id = strtolower($field('id'));
            $prefix = '/' . trim(strtolower($field('url_prefix')), '/');
            $locale = strtolower($field('default_locale'));
            $currency = strtolower($field('currency'));
            $name = mb_substr($field('name'), 0, self::MAX_LENGTH);
            $stores = is_array($data['stores'] ?? null) ? $data['stores'] : [];
            $defaultId = (string)($data['default_store'] ?? '');
            $base = $stores[$defaultId] ?? null;
            $errors = [];
            if (!preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $id) || isset($stores[$id])) $errors[] = I18n::t('admin.stores.err.id');
            if (!preg_match('#^/[a-z0-9-]{2,20}$#', $prefix) || StoreContext::isReservedPrefix($prefix) || in_array($prefix, array_map(static fn($s): string => is_array($s) ? (string)($s['url_prefix'] ?? '') : '', $stores), true)) {
                $errors[] = I18n::t('admin.stores.err.prefix');
            }
            if (!preg_match('/^[a-z]{2}$/', $locale) || !is_file(dirname(__DIR__) . "/lang/{$locale}.php")) $errors[] = I18n::t('admin.stores.err.locale');
            if (!isset($data['currencies'][$currency])) $errors[] = I18n::t('admin.stores.err.currency');
            if ($name === '') $errors[] = I18n::format('admin.stores.err.required', ['fields' => I18n::t('admin.stores.field.store_name')]);
            if (!is_array($base)) $errors[] = I18n::t('admin.stores.err.unknown');
            if ($errors) return ['ok' => false, 'errors' => $errors];

            $data['stores'][$id] = [
                'id' => $id, 'name' => $name, 'domains' => [],
                'catalog_scope' => (string)($base['catalog_scope'] ?? $defaultId), 'provider_scope' => (string)($base['provider_scope'] ?? $defaultId),
                'theme' => (string)($base['theme'] ?? 'default'), 'channels' => $base['channels'] ?? ['storefront'], 'permissions' => $base['permissions'] ?? [],
                'default_locale' => $locale, 'currency' => $currency, 'timezone' => StoreContext::timezoneFor($base), 'url_prefix' => $prefix, 'languages' => [$locale],
                'oss_enabled' => false, 'legal' => ['seller' => (string)(isset($base['legal']['seller']) ? $base['legal']['seller'] : $defaultId)],
            ];
            if (!self::write($data)) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.write')]];
            AuditLog::log('store_settings', 0, null, ['store' => $id, 'added' => $data['stores'][$id]]);
            return ['ok' => true, 'errors' => []];
        });
    }

    /** Records a store keeps for its customers and the tax office: while one exists the store stays. */
    private const STORE_RECORDS = ['orders', 'invoices', 'order_refunds', 'payment_transactions', 'customers', 'withdrawal_requests',
        'complaints', 'rma_requests', 'gdpr_events', 'back_in_stock_requests'];

    /**
     * Remove a store without customer or accounting records. The default store, a store another one invoices as and
     * a store whose catalogue or providers another store sells stay. The removed store's admin grants, API clients and
     * legal texts go with it, so a later store under the same id inherits none of them.
     */
    public static function removeStore(string $storeId, PDO $db): array {
        return self::locked(static function () use ($storeId, $db): array {
            $data = self::load();
            $stores = is_array($data['stores'] ?? null) ? $data['stores'] : [];
            if (!isset($stores[$storeId]) || $storeId === (string)($data['default_store'] ?? '')) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.remove_default')]];
            $records = 0;
            foreach (self::STORE_RECORDS as $table) {
                if (!Db::tableExists($table, $db)) continue;
                $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE store_id = ?");
                $stmt->execute([$storeId]);
                $records += (int)$stmt->fetchColumn();
            }
            $dependants = array_filter($stores, static fn($s, $id): bool => $id !== $storeId && is_array($s) && in_array($storeId,
                [(string)($s['legal']['seller'] ?? ''), (string)($s['catalog_scope'] ?? $id), (string)($s['provider_scope'] ?? $id)], true), ARRAY_FILTER_USE_BOTH);
            if ($records > 0 || $dependants) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.remove_used')]];
            $removed = $stores[$storeId];
            unset($data['stores'][$storeId]);
            if (!self::write($data)) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.write')]];
            $db->prepare('DELETE FROM admin_store_permissions WHERE store_id = ?')->execute([$storeId]);
            // R14: nor its API keys or legal texts — a new store under the old id starts without them.
            foreach (['api_clients', 'api_idempotency'] as $table) {
                if (Db::tableExists($table, $db)) $db->prepare("DELETE FROM {$table} WHERE store_id = ?")->execute([$storeId]);
            }
            DataOverrides::removeStoreLegal($storeId);
            AuditLog::log('store_settings', 0, ['store' => $storeId, 'removed' => $removed], null);
            return ['ok' => true, 'errors' => []];
        });
    }

    private static function saveLocked(string $storeId, array $input): array {
        $data = self::load();
        $store = $data['stores'][$storeId] ?? null;
        if (!is_array($store)) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.unknown')]];

        $text = static fn(string $field): string => mb_substr(trim(str_replace(["\r", "\n"], ' ', is_scalar($input[$field] ?? null) ? (string)$input[$field] : '')), 0, self::MAX_LENGTH);
        $errors = [];
        $storeName = $text('store_name');
        $legal = is_array($store['legal'] ?? null) ? $store['legal'] : [];
        $sharesSeller = isset($legal['seller']);
        if (!$sharesSeller) {
            foreach (self::LEGAL_TEXT as $field) $legal[$field] = $text($field);
            $legal['country'] = strtoupper($legal['country']);
            $legal['email'] = strtolower($legal['email']);
            // R50 (high 2): a form without the field keeps the store's VAT status (no key = registered, as checkout reads it).
            $legal['vat_registered'] = array_key_exists('vat_registered', $input) ? $input['vat_registered'] === '1' : self::vatRegistered($legal);
            $terms = $text('payment_terms_days');
            $legal['payment_terms_days'] = ctype_digit($terms) ? (int)$terms : -1;

            $missing = array_values(array_filter(self::REQUIRED, static fn(string $f): bool => $legal[$f] === ''));
            if ($missing) {
                $errors[] = I18n::format('admin.stores.err.required', ['fields' => implode(', ', array_map(static fn(string $f): string => I18n::t('admin.stores.field.' . $f), $missing))]);
            }
            if ($legal['email'] !== '' && !filter_var($legal['email'], FILTER_VALIDATE_EMAIL)) $errors[] = I18n::t('admin.stores.err.email');
            if ($legal['country'] !== '' && !preg_match('/^[A-Z]{2}$/', $legal['country'])) $errors[] = I18n::t('admin.stores.err.country');
            $iban = strtoupper((string)preg_replace('/\s+/', '', $legal['iban']));
            if ($iban !== '' && !BankTransferInstructions::validIban($iban)) $errors[] = I18n::t('admin.stores.err.iban');
            $legal['bic'] = strtoupper((string)preg_replace('/\s+/', '', $legal['bic']));
            if ($legal['bic'] !== '' && !BankTransferInstructions::validBic($legal['bic'])) $errors[] = I18n::t('admin.stores.err.bic');
            if ($legal['payment_terms_days'] < 0 || $legal['payment_terms_days'] > 365) $errors[] = I18n::t('admin.stores.err.terms');
            // R51 (low): an EU VAT ID only for a seller in the EU — the sample US store (sales tax, EIN) could not be saved.
            if ($legal['vat_registered'] && $legal['ic_dph'] === '' && ($legal['country'] === '' || VatService::isEuCountry($legal['country']))) $errors[] = I18n::t('admin.stores.err.vat_id');
        }
        if ($storeName === '') $errors[] = I18n::format('admin.stores.err.required', ['fields' => I18n::t('admin.stores.field.store_name')]);
        // A form without the field (an older admin page) keeps the store's zone.
        $timezone = array_key_exists('timezone', $input) ? $text('timezone') : (string)($store['timezone'] ?? '');
        if (array_key_exists('timezone', $input) && StoreContext::configuredTimezone(['timezone' => $timezone]) === null) $errors[] = I18n::t('admin.stores.err.timezone');
        // R58 (review round 23, medium S3): the storefront's languages (was: stores.json only, while the installer and its
        // hint said Admin → Stores adds them). A form without the field keeps them; the default language always stays.
        $languages = array_key_exists('languages', $input) ? self::parseLanguages($input['languages'], (string)($store['default_locale'] ?? 'en'), $errors) : null;
        // R59 (review round 24, finding 6): the country the store sells to (StoreContext::marketCountryOf(): checkout, store
        // switcher, hreflang de-AT next to the German store's de-DE); empty: its language's, else the seller's. A form
        // without the field keeps it (was: stores.json only).
        $marketCountry = array_key_exists('market_country', $input) ? strtoupper($text('market_country')) : null;
        if ($marketCountry !== null && $marketCountry !== '' && !preg_match('/^[A-Z]{2}$/', $marketCountry)) $errors[] = I18n::t('admin.stores.err.country');
        // The default store is always public. A hidden store goes public only once its own seller identity is entered.
        $isDefault = $storeId === (string)($data['default_store'] ?? '');
        $public = $isDefault || (isset($input['public']) ? $input['public'] === '1' : StoreContext::isPublicConfig($store));
        if ($public && !StoreContext::isPublicConfig($store) && !$sharesSeller && Setup::legalIdentityProblems($legal) !== []) $errors[] = I18n::t('admin.stores.err.publish_example');
        // R54 (review round 19, UX): the store's own domains (StoreContext serves it there, its links use the first one);
        // a form without the field keeps them (was: only by editing stores.json).
        $domains = array_key_exists('domains', $input) ? self::parseDomains($input['domains'], $data['stores'], $storeId, $isDefault, $errors) : null;
        // R56 (review round 21, low): nor through this form a store without a URL prefix and without a domain (saveDomains()).
        if ($domains === [] && !$isDefault && rtrim((string)($store['url_prefix'] ?? ''), '/') === '') $errors[] = I18n::t('admin.stores.err.prefix_needs_domain');
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $before = $store;
        // The legal pages print the seller's identity and contact: changing them changes those pages' "last changed" date.
        $printed = array_diff(self::LEGAL_TEXT, ['iban', 'bic', 'bank']);
        if (!$sharesSeller && array_filter($printed, static fn(string $f): bool => (string)($before['legal'][$f] ?? '') !== (string)$legal[$f])) $legal['updated_at'] = date('Y-m-d');
        $store['name'] = $storeName;
        $store['oss_enabled'] = ($input['oss_enabled'] ?? '') === '1';
        // A form without the field keeps the store's choice (Cart::keepsNetAbroad(), default "net").
        if (in_array($input['cross_border_pricing'] ?? null, ['net', 'gross'], true)) $store['cross_border_pricing'] = $input['cross_border_pricing'];
        if ($timezone !== '') $store['timezone'] = $timezone;
        if ($domains !== null) $store['domains'] = $domains;
        if ($languages !== null) $store['languages'] = $languages;
        if ($marketCountry === '') unset($store['country']); elseif ($marketCountry !== null) $store['country'] = $marketCountry;
        $store['legal'] = $legal;
        if ($public) unset($store['public']); else $store['public'] = false;
        $data['stores'][$storeId] = $store;
        if (!self::write($data)) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.write')]];
        AuditLog::log('store_settings', 0, ['store' => $storeId, 'name' => $before['name'] ?? null, 'timezone' => $before['timezone'] ?? null, 'oss_enabled' => $before['oss_enabled'] ?? null, 'cross_border_pricing' => $before['cross_border_pricing'] ?? null, 'public' => StoreContext::isPublicConfig($before), 'legal' => $before['legal'] ?? null],
            ['store' => $storeId, 'name' => $store['name'], 'timezone' => $store['timezone'] ?? null, 'oss_enabled' => $store['oss_enabled'], 'cross_border_pricing' => $store['cross_border_pricing'] ?? null, 'public' => $public, 'legal' => $store['legal']]
            + ($domains !== null ? ['domains' => $domains, 'domains_before' => $before['domains'] ?? []] : [])
            + ($languages !== null ? ['languages' => $languages, 'languages_before' => $before['languages'] ?? []] : [])
            + ($marketCountry !== null ? ['country' => $store['country'] ?? null, 'country_before' => $before['country'] ?? null] : []));
        return ['ok' => true, 'errors' => []];
    }

    /** R59: the country a store sells to without a "country" of its own: its language's, else its seller's. */
    public static function defaultMarketCountry(array $root, string $storeId): string {
        $store = is_array($root['stores'][$storeId] ?? null) ? $root['stores'][$storeId] : [];
        return StoreContext::marketCountryOf(['country' => ''] + $store, (string)(SellerLegal::forStore($root, $storeId)['country'] ?? ''));
    }

    /**
     * R58 (review round 23, medium S3): the posted storefront languages (languages[]): locales the shop ships texts for
     * (I18n::shippedLocales()), the store's default language first and always included.
     * @param list<string> $errors
     * @return list<string>
     */
    private static function parseLanguages(mixed $posted, string $defaultLocale, array &$errors): array {
        $posted = is_scalar($posted) ? preg_split('/[\s,]+/', (string)$posted, -1, PREG_SPLIT_NO_EMPTY) : $posted;
        if (!is_array($posted) || array_filter($posted, static fn($l): bool => !is_string($l)) !== []) {
            $errors[] = I18n::t('admin.stores.err.languages');
            return [];
        }
        $languages = array_values(array_unique(array_map(static fn(string $l): string => strtolower(trim($l)), $posted)));
        $unknown = array_diff($languages, I18n::shippedLocales());
        if ($unknown !== []) $errors[] = I18n::t('admin.stores.err.languages');
        return array_values(array_unique(array_merge([$defaultLocale], array_diff($languages, $unknown))));
    }

    /**
     * Host names, one per line or separated by commas or spaces: lower case, without scheme, port or path, each with a
     * dot and used by no other store (the host picks the store). At most 10.
     * @param list<string> $errors
     * @return list<string>
     */
    private static function parseDomains(mixed $posted, array $stores, string $storeId, bool $isDefault, array &$errors): array {
        // R56 (review round 21, low): domains[] (an array) was read as an empty field and removed every domain.
        if ($posted !== null && !is_scalar($posted)) {
            $errors[] = I18n::format('admin.stores.err.domains', ['domains' => '[]']);
            return [];
        }
        $list = preg_split('/[\s,]+/', strtolower(trim((string)$posted)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $domains = array_values(array_unique(array_map(static fn(string $d): string => rtrim($d, '.'), $list)));
        $taken = [];
        foreach ($stores as $id => $other) {
            if ((string)$id === $storeId || !is_array($other)) continue;
            foreach ((array)($other['domains'] ?? []) as $d) $taken[strtolower(rtrim(trim((string)$d), '.'))] = (string)$id;
        }
        // R55 (review round 20, medium M1): the host of app_url serves the default store. Another store given it took that
        // host over: one save left the main storefront 404, or showed that store in its place.
        $mainHosts = array_filter([strtolower(rtrim((string)parse_url((string)Security::configuredAppUrl(), PHP_URL_HOST), '.'))]);
        // R56 (review round 21, low): without app_url, the host this admin request came through, unless a store has it
        // as its own domain: that host serves the default store (nothing was reserved).
        $requestHost = strtolower(rtrim((string)preg_replace('/:\d+$/', '', is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : ''), '.'));
        $ownDomains = array_map(static fn($d): string => strtolower(rtrim(trim((string)$d), '.')), (array)($stores[$storeId]['domains'] ?? []));
        if ($mainHosts === [] && $requestHost !== '' && !isset($taken[$requestHost]) && !in_array($requestHost, $ownDomains, true)) $mainHosts[] = $requestHost;
        $mainHost = array_values(array_filter($domains, static fn(string $d): bool => !$isDefault && in_array($d, $mainHosts, true)));
        if ($mainHost !== []) $errors[] = I18n::format('admin.stores.err.domains_app_url', ['domain' => $mainHost[0]]);
        // A Unicode name is entered as punycode (the Host header carries that); the message gives it.
        $unicode = array_values(array_filter($domains, static fn(string $d): bool => preg_match('/[^\x00-\x7F]/', $d) === 1));
        if ($unicode !== []) {
            $ascii = array_map(static fn(string $d): string => function_exists('idn_to_ascii') ? (string)(idn_to_ascii($d, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '') : '', $unicode);
            $errors[] = I18n::format('admin.stores.err.domains_idn', ['domains' => implode(', ', $unicode), 'punycode' => implode(', ', array_filter($ascii)) ?: 'xn--…']);
        }
        // R56 (review round 21, low): not an IP address (127.0.0.1, 0x7f.1): a host name's last label has a letter. The
        // domain test then asked the server's own or the cloud metadata address.
        $bad = array_filter($domains, static fn(string $d): bool => !in_array($d, $mainHost, true) && !in_array($d, $unicode, true) && (!str_contains($d, '.') || strlen($d) > 253
            || filter_var($d, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || isset($taken[$d])
            || preg_match('/(?:^|\.)(?:[0-9]+|0x[0-9a-f]*)$/', $d) === 1
            // R58 (review round 23, low N1): nor a name of the local network (RFC 6761/6762, 8375, ICANN .internal), which
            // the resolver or libcurl answers locally (*.localhost is 127.0.0.1): no public shop is there.
            || preg_match('/(?:^|\.)(?:localhost|localdomain|local|internal|lan|arpa)$/', $d) === 1));
        if ($bad !== [] || count($domains) > 10) $errors[] = I18n::format('admin.stores.err.domains', ['domains' => implode(', ', $bad ?: $domains)]);
        return $domains;
    }

    /**
     * R55 (review round 20, UX): the store's domains alone, from their own form (owner only). They were saved with the
     * seller form, so a sample IBAN or a missing seller field kept a store from moving to its domain. The warning names
     * the domains whose /healthz did not answer as this shop (DNS or the web server not set up yet).
     * @return array{ok: bool, errors: list<string>, warning?: string}
     */
    public static function saveDomains(string $storeId, mixed $posted, mixed $prefixPosted = null): array {
        $result = self::locked(static function () use ($storeId, $posted, $prefixPosted): array {
            $data = self::load();
            $store = $data['stores'][$storeId] ?? null;
            if (!is_array($store)) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.unknown')]];
            $errors = [];
            $isDefault = $storeId === (string)($data['default_store'] ?? '');
            $domains = self::parseDomains($posted, $data['stores'], $storeId, $isDefault, $errors);
            // R55 (review round 20, UX): the URL prefix is set here too (was: only in stores.json). Without one a store is
            // told apart by its own domain only; the form without the field keeps the prefix.
            $prefix = $prefixPosted === null ? rtrim((string)($store['url_prefix'] ?? ''), '/')
                : rtrim('/' . trim(strtolower(trim(is_scalar($prefixPosted) ? (string)$prefixPosted : '')), '/'), '/');
            if ($prefix === '' && !$isDefault && $domains === []) {
                $errors[] = I18n::t('admin.stores.err.prefix_needs_domain');
            } elseif ($prefix !== '' && $prefixPosted !== null && $prefix !== rtrim((string)($store['url_prefix'] ?? ''), '/')
                && (!preg_match('#^/[a-z0-9-]{2,20}$#', $prefix) || StoreContext::isReservedPrefix($prefix)
                    || in_array($prefix, array_map(static fn($s): string => is_array($s) ? rtrim((string)($s['url_prefix'] ?? ''), '/') : '', array_diff_key($data['stores'], [$storeId => true])), true))) {
                $errors[] = I18n::t('admin.stores.err.prefix');
            }
            if ($errors) return ['ok' => false, 'errors' => $errors];
            $before = ['domains' => $store['domains'] ?? [], 'url_prefix' => (string)($store['url_prefix'] ?? '')];
            $data['stores'][$storeId]['domains'] = $domains;
            $data['stores'][$storeId]['url_prefix'] = $prefix;
            if (!self::write($data)) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.write')]];
            AuditLog::log('store_settings', 0, ['store' => $storeId] + $before, ['store' => $storeId, 'domains' => $domains, 'url_prefix' => $prefix]);
            return ['ok' => true, 'errors' => [], 'domains' => $domains,
                'domains_before' => array_map(static fn($d): string => strtolower(rtrim(trim((string)$d), '.')), (array)$before['domains']),
                'untested_before' => array_map('strval', (array)($store['domains_untested'] ?? []))];
        });
        if (!$result['ok']) return $result;
        // R56 (review round 21, low): only the domains this save added (every save probed all ten, 40 s).
        // R57 (review round 22, low): and those an earlier save had no time left for (they were never tested).
        $pending = array_values(array_intersect($result['domains'], $result['untested_before']));
        $probe = self::probeDomains(array_values(array_unique(array_merge(array_diff($result['domains'], $result['domains_before']), $pending))));
        // R60 (review round 25, low): a domain that did not answer as this shop (DNS not set up yet, a probe timing out at
        // the deadline) or resolved to a non-public address is kept too and probed again by the next save, until it
        // answers. Only a domain left untested was; one probed once was never verified again.
        $failed = array_values(array_unique(array_merge($probe['untested'], $probe['unreachable'], $probe['private'])));
        if ($failed !== $pending) {
            self::locked(static function () use ($storeId, $failed): array {
                $data = self::load();
                if (!is_array($data['stores'][$storeId] ?? null)) return ['ok' => false];
                $untested = array_values(array_intersect($failed, (array)($data['stores'][$storeId]['domains'] ?? [])));
                if ($untested === []) unset($data['stores'][$storeId]['domains_untested']); else $data['stores'][$storeId]['domains_untested'] = $untested;
                return ['ok' => self::write($data)];
            });
        }
        $warnings = [];
        foreach (['unreachable' => 'admin.stores.domains_unreachable', 'private' => 'admin.stores.domains_private', 'untested' => 'admin.stores.domains_untested'] as $kind => $key) {
            if ($probe[$kind] !== []) $warnings[] = I18n::format($key, ['domains' => implode(', ', $probe[$kind])]);
        }
        return ['ok' => true, 'errors' => [], 'warning' => implode(' ', $warnings)];
    }

    /**
     * The $domains whose /healthz (under app_url's scheme, port and path) does not answer as this shop. Only probed for
     * an HTTPS app_url (JsonHttpClient: no redirects, no proxies, 4 s). R56: within PROBE_SECONDS for all of them; the
     * domains left when they are up are not probed (nor reported).
     * R57 (review round 22, low): a domain resolving to a loopback, private or other non-public address (localtest.me,
     * 127.0.0.1.nip.io) is not asked (the request reached the server's own or the cloud metadata address); the address
     * checked is the one asked. The domains left at the deadline are 'untested': the next save tests them.
     * @param list<string> $domains
     * @return array{unreachable: list<string>, private: list<string>, untested: list<string>}
     */
    private static function probeDomains(array $domains): array {
        $probe = ['unreachable' => [], 'private' => [], 'untested' => []];
        $appUrl = Security::configuredAppUrl();
        if ($appUrl === null || strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) !== 'https') return $probe;
        $port = (int)(parse_url($appUrl, PHP_URL_PORT) ?: 443);
        $deadline = microtime(true) + self::PROBE_SECONDS;
        foreach ($domains as $domain) {
            $left = (int)floor($deadline - microtime(true));
            if ($left < 1) {
                $probe['untested'][] = $domain;
                continue;
            }
            $addresses = self::resolve($domain);
            // R58 (review round 23, low N1): a name without an address is not asked. The request went out unpinned and
            // libcurl answers *.localhost itself with 127.0.0.1: the probe reached the server's own loopback.
            if ($addresses === []) {
                $probe['unreachable'][] = $domain;
                continue;
            }
            if (array_filter($addresses, static fn(string $ip): bool => !FeedImport::isPublicFeedIp($ip)) !== []) {
                $probe['private'][] = $domain;
                continue;
            }
            try {
                $health = JsonHttpClient::request(Security::storeAppUrl($appUrl, ['domains' => [$domain]]), '/healthz', 'GET', null, [], min(4, $left),
                    ["{$domain}:{$port}:{$addresses[0]}"]);
                if (($health['service'] ?? '') !== 'bareboneseshop') $probe['unreachable'][] = $domain;
            } catch (Throwable $e) {
                Logger::exception('store_settings.domain_probe_failed', $e, ['domain' => $domain]);
                $probe['unreachable'][] = $domain;
            }
        }
        return $probe;
    }

    /** @return list<string> the A and AAAA addresses of $host ([] when it does not resolve) */
    private static function resolve(string $host): array {
        if (self::$resolver !== null) return (self::$resolver)($host);
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) $ips[] = $ip;
        }
        return array_values(array_unique($ips ?: (@gethostbynamel($host) ?: [])));
    }

    /** Atomic replace: readers see the old or the new file, never a half-written one. */
    private static function write(array $data): bool {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $target = self::target();
        return is_string($json) && $target !== null && DataOverrides::atomicWrite($target, $json . "\n");
    }
}
