<?php
/**
 * Merchant-editable part of stores.json: each store's storefront name, time zone, seller identity (legal block) and VAT
 * switches. Currency, URL prefix, domains, scopes and invoice prefixes stay file-only: changing them reprices the
 * store or breaks invoice numbering. The updater keeps the installed stores.json, so admin edits survive updates.
 */
final class StoreSettings {
    public const LEGAL_TEXT = ['name', 'street', 'city', 'zip', 'country', 'ico', 'dic', 'ic_dph', 'iban', 'bic', 'bank', 'email', 'phone', 'register_note'];
    private const REQUIRED = ['name', 'street', 'city', 'zip', 'country', 'email'];
    private const MAX_LENGTH = 200;

    private static ?string $path = null;

    /** Test hook: another stores.json (null = the application's). */
    public static function setPathForTesting(?string $path): void {
        self::$path = $path;
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
        return $result + ['warning' => $gap['count'] === 0 ? '' : I18n::format('admin.stores.unpriced', [
            'currency' => strtoupper((string)($store['currency'] ?? '')), 'count' => (string)$gap['count'], 'skus' => implode(', ', $gap['skus']),
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
            $legal['vat_registered'] = ($input['vat_registered'] ?? '') === '1';
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
            if ($legal['vat_registered'] && $legal['ic_dph'] === '') $errors[] = I18n::t('admin.stores.err.vat_id');
        }
        if ($storeName === '') $errors[] = I18n::format('admin.stores.err.required', ['fields' => I18n::t('admin.stores.field.store_name')]);
        // A form without the field (an older admin page) keeps the store's zone.
        $timezone = array_key_exists('timezone', $input) ? $text('timezone') : (string)($store['timezone'] ?? '');
        if (array_key_exists('timezone', $input) && StoreContext::configuredTimezone(['timezone' => $timezone]) === null) $errors[] = I18n::t('admin.stores.err.timezone');
        // The default store is always public. A hidden store goes public only once its own seller identity is entered.
        $isDefault = $storeId === (string)($data['default_store'] ?? '');
        $public = $isDefault || (isset($input['public']) ? $input['public'] === '1' : StoreContext::isPublicConfig($store));
        if ($public && !StoreContext::isPublicConfig($store) && !$sharesSeller && Setup::legalIdentityProblems($legal) !== []) $errors[] = I18n::t('admin.stores.err.publish_example');
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $before = $store;
        // The legal pages print the seller's identity and contact: changing them changes those pages' "last changed" date.
        $printed = array_diff(self::LEGAL_TEXT, ['iban', 'bic', 'bank']);
        if (!$sharesSeller && array_filter($printed, static fn(string $f): bool => (string)($before['legal'][$f] ?? '') !== (string)$legal[$f])) $legal['updated_at'] = date('Y-m-d');
        $store['name'] = $storeName;
        $store['oss_enabled'] = ($input['oss_enabled'] ?? '') === '1';
        if ($timezone !== '') $store['timezone'] = $timezone;
        $store['legal'] = $legal;
        if ($public) unset($store['public']); else $store['public'] = false;
        $data['stores'][$storeId] = $store;
        if (!self::write($data)) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.write')]];
        AuditLog::log('store_settings', 0, ['store' => $storeId, 'name' => $before['name'] ?? null, 'timezone' => $before['timezone'] ?? null, 'oss_enabled' => $before['oss_enabled'] ?? null, 'public' => StoreContext::isPublicConfig($before), 'legal' => $before['legal'] ?? null],
            ['store' => $storeId, 'name' => $store['name'], 'timezone' => $store['timezone'] ?? null, 'oss_enabled' => $store['oss_enabled'], 'public' => $public, 'legal' => $store['legal']]);
        return ['ok' => true, 'errors' => []];
    }

    /** Atomic replace: readers see the old or the new file, never a half-written one. */
    private static function write(array $data): bool {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $target = self::target();
        return is_string($json) && $target !== null && DataOverrides::atomicWrite($target, $json . "\n");
    }
}
