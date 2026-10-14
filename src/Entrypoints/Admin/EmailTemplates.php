<?php
/** Admin e-mail template editor: the active store's per-locale overrides of templates/emails; validation, storage and audit live in EmailTemplates. */

/** Draft posted by a failed save or a preview request: kept in the form instead of the stored template.
 *  Only a valid draft posted for preview is rendered; otherwise the preview shows the stored template. */
$GLOBALS['adminEmailDraft'] = null;
$GLOBALS['adminEmailPreviewDraft'] = false;

function pageEmailTemplates(): void {
    $template = Security::str($_GET['template'] ?? '');
    $locale = Security::str($_GET['locale'] ?? '');
    if ($template !== '' && EmailTemplates::editable($template, $locale)) {
        emailTemplateEditor($template, $locale);
        return;
    }
    $locales = EmailTemplates::locales();
    ?>
    <h1><?= esc(I18n::t('admin.title.email_templates')) ?></h1>
    <p class="muted"><?= esc(I18n::t('admin.email.intro')) ?></p>
    <div class="card table-scroll">
        <table>
            <thead><tr><th><?= esc(I18n::t('admin.email.template')) ?></th><th><?= esc(I18n::t('admin.email.purpose')) ?></th>
                <?php foreach ($locales as $loc): ?><th><?= esc(strtoupper($loc)) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach (EmailTemplates::templates() as $name): ?>
            <tr>
                <td><code><?= esc($name) ?></code></td>
                <td><?= esc(adminCodeLabel('admin.email.tpl.', $name)) ?></td>
                <?php foreach ($locales as $loc): $custom = EmailTemplates::override($name, $loc) !== null; ?>
                <td><a href="admin.php?p=email_templates&amp;template=<?= esc(rawurlencode($name)) ?>&amp;locale=<?= esc(rawurlencode($loc)) ?>">
                    <span class="badge badge-<?= $custom ? 'active' : 'draft' ?>"><?= esc(I18n::t($custom ? 'admin.email.state.custom' : 'admin.email.state.default')) ?></span></a></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function emailTemplateEditor(string $template, string $locale): void {
    $override = EmailTemplates::override($template, $locale);
    $draft = $GLOBALS['adminEmailDraft'];
    $source = $draft ?? ($override ?? EmailTemplates::fileSource($template, $locale));
    $previewDraft = $draft !== null && $GLOBALS['adminEmailPreviewDraft'];
    $preview = EmailTemplates::preview($template, $locale, $previewDraft ? $draft : null);
    $action = 'admin.php?p=email_templates&template=' . rawurlencode($template) . '&locale=' . rawurlencode($locale);
    ?>
    <h1><?= esc(I18n::format('admin.email.edit_title', ['template' => $template, 'locale' => strtoupper($locale)])) ?></h1>
    <p><a href="admin.php?p=email_templates"><?= esc(I18n::t('admin.btn.back')) ?></a> · <?= esc(adminCodeLabel('admin.email.tpl.', $template)) ?> ·
        <?= esc($override ? I18n::format('admin.email.custom_since', ['at' => $override['updated_at'], 'by' => $override['updated_by']]) : I18n::t('admin.email.uses_default')) ?></p>
    <div class="card">
        <form method="post" action="<?= esc($action . '&action=save') ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="email-subject"><?= esc(I18n::t('admin.email.subject')) ?></label>
                <input id="email-subject" name="subject" value="<?= esc((string)($source['subject'] ?? '')) ?>" required maxlength="<?= EmailTemplates::MAX_SUBJECT ?>" style="width:100%"></div>
            <div class="form-row"><label for="email-html"><?= esc(I18n::t('admin.email.html')) ?></label>
                <textarea id="email-html" name="html" rows="16" required style="width:100%;font-family:monospace"><?= esc((string)($source['html'] ?? '')) ?></textarea></div>
            <div class="form-row"><label for="email-text"><?= esc(I18n::t('admin.email.text')) ?></label>
                <textarea id="email-text" name="text" rows="8" style="width:100%;font-family:monospace"><?= esc((string)($source['text'] ?? '')) ?></textarea></div>
            <p class="muted"><?= esc(I18n::t('admin.email.vars')) ?>: <?php foreach (EmailTemplates::allowedVariables($template) as $var): ?><code>{{<?= esc($var) ?>}}</code> <?php endforeach; ?></p>
            <p class="muted"><?= esc(I18n::t('admin.email.vars_help')) ?></p>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
            <button class="btn" type="submit" formaction="<?= esc($action . '&action=preview') ?>"><?= esc(I18n::t('admin.email.preview_draft')) ?></button>
        </form>
        <?php if ($override): ?>
        <form method="post" action="<?= esc($action . '&action=reset') ?>" style="margin-top:.75rem" data-confirm="<?= esc(I18n::t('admin.email.reset_confirm')) ?>">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <button class="btn btn-danger" type="submit"><?= esc(I18n::t('admin.email.reset')) ?></button>
        </form>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3><?= esc(I18n::t($previewDraft ? 'admin.email.preview_of_draft' : 'admin.email.preview')) ?></h3>
        <p><strong><?= esc(I18n::t('admin.email.subject')) ?>:</strong> <?= esc($preview['subject']) ?></p>
        <iframe class="email-preview" title="<?= esc(I18n::t('admin.email.preview')) ?>" sandbox="" referrerpolicy="no-referrer" srcdoc="<?= esc($preview['html']) ?>" style="width:100%;height:28rem;border:1px solid #e2e8f0;background:#fff"></iframe>
        <details><summary><?= esc(I18n::t('admin.email.text')) ?></summary><pre style="white-space:pre-wrap"><?= esc($preview['text']) ?></pre></details>
    </div>
    <?php
}

function emailTemplateTarget(): array {
    return [Security::str($_GET['template'] ?? ''), Security::str($_GET['locale'] ?? '')];
}

function emailTemplateKeepDraft(): void {
    $GLOBALS['adminEmailDraft'] = [];
    foreach (['subject', 'html', 'text'] as $field) {
        $value = $_POST[$field] ?? '';
        $GLOBALS['adminEmailDraft'][$field] = is_scalar($value) ? str_replace("\r\n", "\n", (string)$value) : '';
    }
}

function handleEmailTemplateSave(): void {
    [$template, $locale] = emailTemplateTarget();
    $result = EmailTemplates::save($template, $locale, $_POST);
    setFlash($result['ok'] ? 'ok' : 'err', $result['message']);
    if (!$result['ok'] && EmailTemplates::editable($template, $locale)) {
        emailTemplateKeepDraft();
        return;
    }
    header('Location: admin.php?p=email_templates' . (EmailTemplates::editable($template, $locale) ? '&template=' . rawurlencode($template) . '&locale=' . rawurlencode($locale) : ''));
    exit;
}

/** Preview of the posted draft without saving it; a draft that would not save shows why. */
function handleEmailTemplatePreview(): void {
    [$template, $locale] = emailTemplateTarget();
    if (!EmailTemplates::editable($template, $locale)) return;
    emailTemplateKeepDraft();
    $draft = $GLOBALS['adminEmailDraft'];
    $errors = EmailTemplates::validate($template, $draft['subject'], $draft['html'], $draft['text']);
    if ($errors) setFlash('err', implode(' ', $errors));
    $GLOBALS['adminEmailPreviewDraft'] = $errors === [];
}

function handleEmailTemplateReset(): void {
    [$template, $locale] = emailTemplateTarget();
    $result = EmailTemplates::reset($template, $locale);
    setFlash($result['ok'] ? 'ok' : 'err', $result['message']);
    header('Location: admin.php?p=email_templates' . (EmailTemplates::editable($template, $locale) ? '&template=' . rawurlencode($template) . '&locale=' . rawurlencode($locale) : ''));
    exit;
}
