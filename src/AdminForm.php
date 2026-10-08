<?php
/**
 * BareBones Shop — admin forms rendered and validated by BBF (embedded mode: bbf_render_html, bbf_validate)
 * from forms/<id>.json.
 *
 * The form posts to admin.php, so admin auth, RBAC and CSRF apply; the save handler validates the post with
 * validate() and stores the normalized data. Stored values are shown on edit, and options_from lists come from
 * BbfOptions, the same lists api.php serves.
 */
require_once dirname(__DIR__) . '/vendor/bbf/bbf_render.php';

class AdminForm {
    private const STATE = 'admin_form_state';

    public static function definition(string $formId): array {
        return bbf_load_form($formId, dirname(__DIR__) . '/forms');
    }

    /**
     * The complete <form>. $values are the record's stored values (from the database, never the posted input);
     * $beforeSubmit is trusted HTML of the admin page placed right before the submit button. After a rejected
     * save the form shows the errors and the input that passed validation.
     */
    public static function render(string $formId, string $action, array $values = [], string $beforeSubmit = ''): string {
        $errors = [];
        $state = $_SESSION[self::STATE] ?? null;
        if (is_array($state) && ($state['form'] ?? null) === $formId) {
            unset($_SESSION[self::STATE]);
            $errors = $state['errors'];
            $values = $state['values'] + $values;
        }
        return bbf_render_html(self::definition($formId), self::options($values) + [
            'action' => $action,
            'errors' => $errors,
            'hidden' => ['csrf_token' => Security::csrfToken()],
            'id_prefix' => 'field-',
            'form_attrs' => ['id' => 'form-' . $formId, 'class' => 'admin-form'],
            'before_submit' => $beforeSubmit,
            'show_title' => false,
        ]);
    }

    /**
     * Validate a post of the form: $stored are the record's values from the database (empty for a new record),
     * $uploads the upload limits (allowed_extensions, max_file_size). Returns the normalized data of the form's
     * fields (a file field gives ['action' => 'keep'|'remove'|'replace', 'files' => [...]]), or null when the post
     * is invalid; the errors are then kept for the next render() of this form.
     */
    public static function validate(string $formId, array $stored = [], array $uploads = [], ?array $input = null, ?array $files = null): ?array {
        $result = bbf_validate(self::definition($formId), $input ?? $_POST, $files ?? $_FILES, self::options($stored) + ['uploads' => $uploads]);
        if (!$result['errors']) return $result['data'];
        $_SESSION[self::STATE] = [
            'form' => $formId,
            'errors' => $result['errors'],
            'values' => array_filter(array_diff_key($result['data'], $result['errors']), 'is_scalar'),
        ];
        return null;
    }

    /** The first error message kept by the last rejected validate(), for a flash message. */
    public static function firstError(): string {
        $errors = $_SESSION[self::STATE]['errors'] ?? [];
        return is_array($errors) && $errors ? (string)reset($errors) : '';
    }

    private static function options(array $values): array {
        return [
            'values' => $values,
            'lang' => 'sk',
            'options_resolver' => static fn(string $source): ?array => BbfOptions::fromSource($source),
        ];
    }
}
