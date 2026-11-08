<?php
/**
 * R23 UX: Admin → E-mail delivery. The owner enters the SMTP server, its login and the sender here (stored by
 * MailSettings, the password encrypted and never shown again) and sends a test e-mail. Values from the server
 * environment win and are shown read-only.
 */

/** The posted form after a refused save: shown again instead of the saved values (never the password). */
$GLOBALS['adminMailDraft'] = null;

function pageMailDelivery(): void {
    $saved = MailSettings::raw();
    $values = $GLOBALS['adminMailDraft'] ?? $saved;
    $locked = MailSettings::environmentOverrides();
    $provider = MailSettings::providerCode();
    $config = Security::config();
    $configured = strtolower((string)($config['provider_defaults']['mail'] ?? 'log'));
    $mode = (string)($values['mode'] ?? ($provider === 'smtp' ? 'smtp' : 'log'));
    $field = static function (string $name, string $type = 'text', array $attributes = []) use ($values, $locked): string {
        $id = 'mail-' . $name;
        $value = $type === 'password' ? '' : (string)($values[$name] ?? '');
        $html = '<div class="form-row"><label for="' . $id . '">' . esc(I18n::t('admin.mail.' . $name)) . '</label>'
            . '<input id="' . $id . '" name="' . $name . '" type="' . $type . '" value="' . esc($value) . '"';
        foreach ($attributes as $attribute => $attributeValue) $html .= ' ' . $attribute . '="' . esc((string)$attributeValue) . '"';
        if (isset($locked[$name])) $html .= ' disabled';
        $html .= '>';
        if (isset($locked[$name])) {
            $note = I18n::format('admin.mail.env_locked', ['name' => $locked[$name]]);
            $html .= '<p class="muted" data-mail-env="' . esc($locked[$name]) . '">' . esc($note) . '</p>';
        }
        return $html . '</div>';
    };
    ?>
    <h1><?= esc(I18n::t('admin.title.mail')) ?></h1>
    <p class="muted"><?= esc(I18n::t('admin.mail.intro')) ?></p>
    <div class="card" data-mail-status="<?= esc($provider) ?>">
        <p><?= esc(I18n::format('admin.mail.current', ['provider' => adminCodeLabel('admin.mail.provider.', $provider), 'from' => MailService::sender()])) ?></p>
        <?php if ((string)getenv('MAIL_ADAPTER') !== ''): ?><p class="muted"><?= esc(I18n::format('admin.mail.env_locked', ['name' => 'MAIL_ADAPTER'])) ?></p><?php endif; ?>
        <?php if (MailSettings::mode() === null && !in_array($configured, MailSettings::MODES, true)): ?>
        <p class="muted" data-mail-config-provider><?= esc(I18n::format('admin.mail.other_provider', ['provider' => $configured])) ?></p>
        <?php endif; ?>
    </div>
    <?php if (MailSettings::passwordUnreadable()): ?><div class="flash flash-err" role="alert" data-mail-password-unreadable><?= esc(I18n::t('admin.mail.password_unreadable')) ?></div><?php endif; ?>
    <div class="card">
        <form method="post" action="admin.php?p=mail&amp;action=save" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="mail-mode"><?= esc(I18n::t('admin.mail.mode')) ?></label>
                <select id="mail-mode" name="mode"><?php foreach (MailSettings::MODES as $option): ?>
                    <option value="<?= esc($option) ?>"<?= $mode === $option ? ' selected' : '' ?>><?= esc(I18n::t('admin.mail.provider.' . $option)) ?></option>
                <?php endforeach; ?></select></div>
            <?= $field('host', 'text', ['placeholder' => 'smtp.example.com', 'maxlength' => 253]) ?>
            <div class="form-row"><label for="mail-encryption"><?= esc(I18n::t('admin.mail.encryption')) ?></label>
                <select id="mail-encryption" name="encryption"<?= isset($locked['encryption']) ? ' disabled' : '' ?>><?php foreach (MailSettings::ENCRYPTIONS as $option): ?>
                    <option value="<?= esc($option) ?>"<?= (string)($values['encryption'] ?? 'tls') === $option ? ' selected' : '' ?>><?= esc(I18n::t('admin.mail.encryption.' . $option)) ?></option>
                <?php endforeach; ?></select>
                <?php if (isset($locked['encryption'])): ?><p class="muted"><?= esc(I18n::format('admin.mail.env_locked', ['name' => $locked['encryption']])) ?></p><?php endif; ?></div>
            <?= $field('port', 'number', ['min' => 1, 'max' => 65535, 'placeholder' => '587']) ?>
            <?= $field('username', 'text', ['maxlength' => 254, 'autocomplete' => 'off']) ?>
            <?= $field('password', 'password', ['maxlength' => 1024, 'autocomplete' => 'new-password']) ?>
            <?php if ((string)($saved['password'] ?? '') !== '' && !isset($locked['password'])): ?>
            <p class="muted" data-mail-password-saved><?= esc(I18n::t('admin.mail.password_saved')) ?></p>
            <label><input type="checkbox" name="clear_password" value="1"> <?= esc(I18n::t('admin.mail.clear_password')) ?></label>
            <?php endif; ?>
            <?= $field('from', 'email', ['maxlength' => 254, 'placeholder' => 'shop@' . (parse_url((string)($config['app_url'] ?? ''), PHP_URL_HOST) ?: 'example.com')]) ?>
            <p class="muted"><?= esc(I18n::t('admin.mail.from_hint')) ?></p>
            <button class="btn btn-primary" type="submit"><?= esc(I18n::t('admin.btn.save')) ?></button>
        </form>
    </div>
    <div class="card">
        <h3><?= esc(I18n::t('admin.mail.test')) ?></h3>
        <form method="post" action="admin.php?p=mail&amp;action=test">
            <input type="hidden" name="csrf_token" value="<?= Security::csrfToken() ?>">
            <div class="form-row"><label for="mail-test-to"><?= esc(I18n::t('admin.mail.test_to')) ?></label>
                <input id="mail-test-to" name="to" type="email" required value="<?= esc((string)($config['admin_email'] ?? '')) ?>"></div>
            <button class="btn" type="submit"><?= esc(I18n::t('admin.mail.test')) ?></button>
        </form>
    </div>
    <?php
}

function handleMailDeliverySave(): void {
    $input = $_POST;
    $locked = MailSettings::environmentOverrides();
    $saved = MailSettings::raw();
    // A field the environment sets is not posted (disabled): the saved value stays as it was.
    foreach (array_keys($locked) as $name) $input[$name] = $name === 'password' ? '' : ($saved[$name] ?? '');
    [$values, $errors] = MailSettings::validate($input);
    $errors = array_diff_key($errors, $locked);
    if ($errors === []) {
        $error = MailSettings::save($values, null, 'admin:' . Security::adminUser());
        if ($error === null) {
            setFlash('ok', I18n::t('admin.mail.saved'));
            header('Location: admin.php?p=mail');
            exit;
        }
        $errors = ['save' => $error];
    }
    setFlash('err', implode(' ', array_map(static fn(string $code): string => I18n::t('admin.mail.error.' . $code), array_values(array_unique($errors)))));
    $GLOBALS['adminMailDraft'] = array_diff_key($values, ['password' => 1]);
}

function handleMailDeliveryTest(): void {
    $to = Security::email($_POST['to'] ?? '');
    if ($to === null) {
        setFlash('err', I18n::t('admin.mail.error.test_to'));
    } elseif (!Security::rateLimit('admin_mail_test', 10, 3600)) {
        setFlash('err', I18n::t('admin.mail.test_limited'));
    } else {
        $provider = MailSettings::providerCode();
        $shop = trim((string)(Template::branding()['name'] ?? '')) ?: I18n::t('admin.app_name');
        $adapterId = $provider;
        try {
            $adapter = MailService::adapter();
            $adapterId = $adapter->adapterId();
            $result = $adapter->send([
                'to' => $to, 'from' => MailService::sender(), 'from_name' => $shop,
                'subject' => I18n::format('admin.mail.test_subject', ['shop' => $shop]),
                'text' => I18n::format('admin.mail.test_body', ['shop' => $shop, 'provider' => $adapterId]),
                'headers' => ['X-Mailer' => 'BareBonesShop', 'X-Template' => 'admin_mail_test'],
            ]);
        } catch (Throwable $e) {
            $result = ['status' => 'failed', 'message' => $e->getMessage()];
        }
        $sent = ($result['status'] ?? '') === 'sent';
        if ($sent && $adapterId === 'log') {
            setFlash('ok', I18n::format('admin.mail.test_logged', ['to' => $to]));
        } elseif ($sent) {
            setFlash('ok', I18n::format('admin.mail.test_sent', ['to' => $to, 'provider' => adminCodeLabel('admin.mail.provider.', $provider)]));
        } else {
            setFlash('err', I18n::format('admin.mail.test_failed', ['error' => (string)($result['message'] ?? 'failed')]));
        }
    }
    header('Location: admin.php?p=mail');
    exit;
}
