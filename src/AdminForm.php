<?php
/**
 * BareBones Shop — server-rendered admin forms from BBF JSON definitions (forms/<id>.json).
 *
 * The field list, labels and constraints stay in the BBF definition; the form is plain HTML
 * posting to admin.php, so admin auth, RBAC, CSRF and the existing save handlers apply,
 * stored values are shown on edit, and nothing depends on bbf.js or vendor/bbf/submit.php.
 */
class AdminForm {
    private const FIELD_TYPES = ['text', 'number', 'textarea', 'select', 'file'];

    public static function definition(string $formId): array {
        if (!preg_match('/^[a-z0-9-]+$/', $formId)) throw new InvalidArgumentException('Invalid form id');
        $file = dirname(__DIR__) . "/forms/{$formId}.json";
        $form = json_decode((string)@file_get_contents($file), true);
        if (!is_array($form) || !is_array($form['fields'] ?? null)) throw new RuntimeException("Form definition {$formId} is missing or invalid");
        foreach ($form['fields'] as $field) {
            if (!in_array($field['type'] ?? '', self::FIELD_TYPES, true)) {
                throw new RuntimeException("Form {$formId}: unsupported field type " . ($field['type'] ?? '?'));
            }
        }
        return $form;
    }

    /** Opening <form> tag with the CSRF token; multipart when the definition has a file field. */
    public static function open(string $formId, string $action): string {
        $form = self::definition($formId);
        $multipart = in_array('file', array_column($form['fields'], 'type'), true);
        return '<form method="post" action="' . esc($action) . '" id="form-' . esc($formId) . '" class="admin-form"'
            . ($multipart ? ' enctype="multipart/form-data"' : '') . '>'
            . '<input type="hidden" name="csrf_token" value="' . esc(Security::csrfToken()) . '">'
            . (!empty($form['description']) ? '<p class="muted">' . esc((string)$form['description']) . '</p>' : '');
    }

    /** All fields of the definition, filled with $values (field name => stored value). */
    public static function fields(string $formId, array $values): string {
        $html = '';
        foreach (self::definition($formId)['fields'] as $field) $html .= self::field($field, $values);
        return $html;
    }

    public static function close(string $formId): string {
        $label = (string)(self::definition($formId)['submit_label'] ?? 'Uložiť');
        return '<button class="btn btn-primary" type="submit">' . esc($label) . '</button></form>';
    }

    private static function field(array $field, array $values): string {
        $name = (string)$field['name'];
        $id = 'field-' . $name;
        $value = array_key_exists($name, $values) && $values[$name] !== null ? (string)$values[$name] : '';
        $attrs = ' id="' . esc($id) . '" name="' . esc($name) . '"' . (!empty($field['required']) ? ' required' : '');
        if (!empty($field['placeholder'])) $attrs .= ' placeholder="' . esc((string)$field['placeholder']) . '"';

        switch ($field['type']) {
            case 'textarea':
                $control = '<textarea' . $attrs . ' rows="' . max(1, (int)($field['rows'] ?? 4)) . '" style="width:100%">' . esc($value) . '</textarea>';
                break;
            case 'select':
                $options = isset($field['options_from']) ? (BbfOptions::fromSource((string)$field['options_from']) ?? []) : ($field['options'] ?? []);
                $options = array_map(static fn($o): array => is_array($o) ? ['value' => (string)($o['value'] ?? ''), 'label' => (string)($o['label'] ?? $o['value'] ?? '')] : ['value' => (string)$o, 'label' => (string)$o], $options);
                // A stored value outside the current list stays selectable, so saving never silently changes it.
                if ($value !== '' && !in_array($value, array_column($options, 'value'), true)) array_unshift($options, ['value' => $value, 'label' => $value]);
                if (empty($field['required']) || $value === '') array_unshift($options, ['value' => '', 'label' => '—']);
                $control = '<select' . $attrs . '>';
                foreach ($options as $o) {
                    $control .= '<option value="' . esc($o['value']) . '"' . ($o['value'] === $value ? ' selected' : '') . '>' . esc($o['label']) . '</option>';
                }
                $control .= '</select>';
                break;
            case 'file':
                $control = '<input type="file"' . $attrs . (!empty($field['accept']) ? ' accept="' . esc((string)$field['accept']) . '"' : '') . '>';
                break;
            case 'number':
                $control = '<input type="number"' . $attrs . (isset($field['min']) ? ' min="' . (int)$field['min'] . '"' : '') . ' value="' . esc($value) . '">';
                break;
            default:
                $control = '<input type="text"' . $attrs . ' value="' . esc($value) . '" style="width:100%">';
        }
        return '<div class="form-row"><label for="' . esc($id) . '">' . esc((string)($field['label'] ?? $name)) . '</label>' . $control . '</div>';
    }
}
