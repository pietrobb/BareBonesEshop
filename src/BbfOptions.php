<?php
/**
 * BareBones Shop — option lists referenced by BBF form definitions (options_from).
 * One source for the public /api/v1/options/<name> endpoints and the server-rendered admin forms.
 */
class BbfOptions {
    /** Options for an API action such as "bbf_categories"; null when the action is unknown. */
    public static function forAction(string $action): ?array {
        return match ($action) {
            'bbf_categories' => self::categories(),
            'bbf_brands' => array_map(static fn(array $r): array => ['value' => (string)$r['id'], 'label' => $r['name']],
                CatalogScope::rows('SELECT id, name FROM brands WHERE store_id = :catalog AND active = 1 ORDER BY name')),
            'bbf_product_types' => self::productTypes(),
            // R23 UX: labels in the admin's language (the attribute types and payment methods were Slovak in every admin).
            'bbf_attr_types' => self::labelled(['enum', 'number', 'boolean'], 'admin.attr.type.'),
            'bbf_page_statuses' => self::labelled(['active', 'draft'], 'admin.product.status.'),
            'bbf_product_statuses' => self::labelled(['active', 'draft', 'archived'], 'admin.product.status.'),
            'bbf_vat_classes' => self::labelled(VatService::productClasses(), 'admin.product.vat_class.'),
            'bbf_payment_methods' => [
                ['value' => 'cod', 'label' => I18n::t('methods.payment.cod')],
                ['value' => 'transfer', 'label' => I18n::t('methods.payment.bank_transfer')],
                ['value' => 'card', 'label' => I18n::t('methods.payment.card')],
            ],
            default => null,
        };
    }

    /** @return list<array{value: string, label: string}> */
    private static function labelled(array $values, string $prefix): array {
        return array_map(static fn(string $value): array => ['value' => $value, 'label' => mb_strtoupper(mb_substr(I18n::t($prefix . $value), 0, 1)) . mb_substr(I18n::t($prefix . $value), 1)], $values);
    }

    /** Resolve an options_from URL like "/api/v1/options/categories". */
    public static function fromSource(string $source): ?array {
        $route = ApiRoute::resolve($source, '');
        return $route !== null && str_starts_with($route['action'], 'bbf_') ? self::forAction($route['action']) : null;
    }

    private static function categories(): array {
        return array_map(static fn(array $r): array => ['value' => (string)$r['id'], 'label' => $r['path'] ?: $r['name']], CatalogScope::categories());
    }

    /** R58 (review round 23, low): each type by its catalog.json name in the admin's language ("Digital Download"), not "Downloadable". */
    private static function productTypes(): array {
        $types = array_map('strval', array_keys(ImportTemplates::types()));
        $locale = I18n::locale();
        return array_map(static fn(string $t): array => ['value' => $t, 'label' => ImportTemplates::label($t, $locale)], array_values(array_unique(array_merge(['simple'], $types))));
    }
}
