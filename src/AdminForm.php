<?php
/**
 * BareBones Shop — admin forms rendered by BBF (bbf_render_html, embedded mode) from forms/<id>.json.
 *
 * The form posts to admin.php, so admin auth, RBAC, CSRF and the existing save handlers apply. Stored values are
 * shown on edit, and options_from lists come from BbfOptions, the same lists api.php serves.
 */
require_once dirname(__DIR__) . '/vendor/bbf/bbf_render.php';

class AdminForm {
    public static function definition(string $formId): array {
        return bbf_load_form($formId, dirname(__DIR__) . '/forms');
    }

    /**
     * The complete <form>. $values are the record's stored values (from the database, never the posted input);
     * $beforeSubmit is trusted HTML of the admin page placed right before the submit button.
     */
    public static function render(string $formId, string $action, array $values = [], string $beforeSubmit = ''): string {
        return bbf_render_html(self::definition($formId), [
            'action' => $action,
            'values' => $values,
            'hidden' => ['csrf_token' => Security::csrfToken()],
            'lang' => 'sk',
            'options_resolver' => static fn(string $source): ?array => BbfOptions::fromSource($source),
            'id_prefix' => 'field-',
            'form_attrs' => ['id' => 'form-' . $formId, 'class' => 'admin-form'],
            'before_submit' => $beforeSubmit,
            'show_title' => false,
        ]);
    }
}
