<?php
/**
 * BareBones Shop — option lists referenced by BBF form definitions (options_from).
 * One source for the public api.php?action=bbf_* endpoints and the server-rendered admin forms.
 */
class BbfOptions {
    /** Options for an api.php action such as "bbf_categories"; null when the action is unknown. */
    public static function forAction(string $action): ?array {
        return match ($action) {
            'bbf_categories' => self::categories(),
            'bbf_product_types' => self::productTypes(),
            'bbf_attr_types' => [
                ['value' => 'enum', 'label' => 'Enum (zoznam hodnôt)'],
                ['value' => 'number', 'label' => 'Číslo'],
                ['value' => 'boolean', 'label' => 'Áno/Nie'],
            ],
            'bbf_page_statuses' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'draft', 'label' => 'Draft'],
            ],
            'bbf_product_statuses' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'archived', 'label' => 'Archived'],
            ],
            'bbf_payment_methods' => [
                ['value' => 'cod', 'label' => 'Dobierka'],
                ['value' => 'transfer', 'label' => 'Bankový prevod'],
                ['value' => 'card', 'label' => 'Kartou online'],
            ],
            default => null,
        };
    }

    /** Resolve an options_from URL like "/api.php?action=bbf_categories". */
    public static function fromSource(string $source): ?array {
        parse_str((string)parse_url($source, PHP_URL_QUERY), $query);
        return self::forAction((string)($query['action'] ?? ''));
    }

    private static function categories(): array {
        $rows = Db::get()->query("SELECT id, name, path FROM categories ORDER BY path, name")->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r): array => ['value' => (string)$r['id'], 'label' => $r['path'] ?: $r['name']], $rows);
    }

    private static function productTypes(): array {
        $catalogFile = dirname(__DIR__) . '/catalog.json';
        $types = file_exists($catalogFile)
            ? array_keys(json_decode((string)file_get_contents($catalogFile), true)['product_types'] ?? [])
            : [];
        return array_map(static fn(string $t): array => ['value' => $t, 'label' => ucfirst($t)], array_values(array_unique(array_merge(['simple'], $types))));
    }
}
