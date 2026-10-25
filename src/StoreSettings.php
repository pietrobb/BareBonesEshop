<?php
/**
 * Merchant-editable part of stores.json: each store's storefront name, seller identity (legal block) and VAT
 * switches. Currency, URL prefix, domains, scopes and invoice prefixes stay file-only: changing them reprices the
 * store or breaks invoice numbering. The updater keeps the installed stores.json, so admin edits survive updates.
 */
final class StoreSettings {
    public const LEGAL_TEXT = ['name', 'street', 'city', 'zip', 'country', 'ico', 'dic', 'ic_dph', 'iban', 'bank', 'email', 'phone', 'register_note'];
    private const REQUIRED = ['name', 'street', 'city', 'zip', 'country', 'email'];
    private const MAX_LENGTH = 200;

    private static ?string $path = null;

    /** Test hook: another stores.json (null = the application's). */
    public static function setPathForTesting(?string $path): void {
        self::$path = $path;
    }

    public static function path(): string {
        return self::$path ?? dirname(__DIR__) . '/stores.json';
    }

    public static function writable(): bool {
        return is_file(self::path()) && is_writable(self::path()) && is_writable(dirname(self::path()));
    }

    /** Decoded stores.json, read fresh (not the per-request Bootstrap cache). */
    public static function load(): array {
        $data = json_decode((string)@file_get_contents(self::path()), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Validate the posted form and write it into stores.{$storeId}.
     * @return array{ok: bool, errors: list<string>}  errors are I18n-formatted messages
     */
    public static function save(string $storeId, array $input): array {
        // Two admins saving different stores at once: each re-reads under the lock, so neither edit is lost.
        $lock = @fopen(self::path() . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if ($lock !== false) fclose($lock);
            return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.write')]];
        }
        try {
            return self::saveLocked($storeId, $input);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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
            if ($legal['payment_terms_days'] < 0 || $legal['payment_terms_days'] > 365) $errors[] = I18n::t('admin.stores.err.terms');
            if ($legal['vat_registered'] && $legal['ic_dph'] === '') $errors[] = I18n::t('admin.stores.err.vat_id');
        }
        if ($storeName === '') $errors[] = I18n::format('admin.stores.err.required', ['fields' => I18n::t('admin.stores.field.store_name')]);
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $before = $store;
        $store['name'] = $storeName;
        $store['oss_enabled'] = ($input['oss_enabled'] ?? '') === '1';
        $store['legal'] = $legal;
        $data['stores'][$storeId] = $store;
        if (!self::write($data)) return ['ok' => false, 'errors' => [I18n::t('admin.stores.err.write')]];
        AuditLog::log('store_settings', 0, ['store' => $storeId, 'name' => $before['name'] ?? null, 'oss_enabled' => $before['oss_enabled'] ?? null, 'legal' => $before['legal'] ?? null],
            ['store' => $storeId, 'name' => $store['name'], 'oss_enabled' => $store['oss_enabled'], 'legal' => $store['legal']]);
        return ['ok' => true, 'errors' => []];
    }

    /** Atomic replace: readers see the old or the new file, never a half-written one. */
    private static function write(array $data): bool {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) return false;
        $tmp = self::path() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        $mode = @fileperms(self::path());
        if ($mode !== false) @chmod($tmp, $mode & 0777);
        if (!@rename($tmp, self::path())) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) @opcache_invalidate(self::path(), true);
        return true;
    }
}
