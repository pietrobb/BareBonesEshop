<?php
/**
 * A15.8: e-mail templates — the file defaults in templates/emails/<locale>/ plus per store + locale overrides
 * edited in the admin. An override may use only the variables the template's default files use, and its HTML
 * may not carry scripts, event handlers, javascript: links, forms or embedded frames.
 * Write methods return ['ok' => bool, 'id' => 0, 'message' => flash text] in the admin language.
 */
class EmailTemplates {
    public const FALLBACK_LOCALE = 'en';
    public const MAX_SUBJECT = 500;
    public const MAX_BODY = 100000;

    /** Sample order data for the admin preview: every variable any default template uses. */
    public const SAMPLE = [
        'customer_name' => 'Jana Nováková', 'customer_email' => 'jana.novakova@example.com', 'shop_name' => 'BareBones Shop',
        'order_number' => 'SK-2026-000123', 'order_status' => 'pending', 'order_total' => '59,90 €',
        'items' => [
            ['name' => 'Tričko Basic', 'sku' => 'TS-001', 'quantity' => 2, 'total' => '39,80 €'],
            ['name' => 'Šiltovka Classic', 'sku' => 'CAP-002', 'quantity' => 1, 'total' => '20,10 €'],
        ],
        'bank_transfer' => true, 'bank_beneficiary' => 'BareBones s.r.o.', 'bank_iban' => 'SK31 1200 0000 1987 4263 7541',
        'bank_amount' => '59,90 €', 'bank_vs' => '2026000123', 'bank_message' => 'SK-2026-000123', 'bank_due_date' => '2026-10-27',
        'carrier' => 'Packeta', 'tracking_number' => 'Z 123 4567 890', 'tracking_url' => 'https://tracking.example.com/Z1234567890',
        'invoice_number' => '2026000045', 'issued_at' => '2026-10-13', 'due_date' => '2026-10-27', 'total_gross_formatted' => '59,90 €',
        'invoice_download_url' => 'https://shop.example.com/account.php?p=invoice&id=45', 'seller_name' => 'BareBones s.r.o.',
        'verify_url' => 'https://shop.example.com/account.php?p=verify&token=sample', 'reset_url' => 'https://shop.example.com/account.php?p=reset&token=sample',
        'expires_in' => '60', 'cart_total' => '59,90 €', 'cart_url' => 'https://shop.example.com/cart.php?restore=sample',
        'unsubscribe_url' => 'https://shop.example.com/account.php?p=unsubscribe&token=sample',
        'download_links' => [['url' => 'https://shop.example.com/download.php?token=sample', 'label' => 'E-kniha (PDF)', 'validity' => '2026-11-12']],
        'payment_method' => 'Bankový prevod', 'payment_status' => 'pending', 'payment_id' => 'pi_sample_123', 'awaiting_payment' => true,
        'admin_url' => 'https://shop.example.com/admin.php?p=order&id=123',
        'host' => 'shop.example.com', 'count' => 1, 'files' => [['kind' => 'modified', 'path' => 'src/Cart.php']], 'recorded_at' => '2026-10-13 08:00:00',
        'alert' => false, 'digest' => true,
        'out_items' => [['name' => 'Tričko Basic', 'sku' => 'TS-001', 'available' => 0, 'reserved' => 2, 'threshold' => 3, 'admin_url' => 'https://shop.example.com/admin.php?p=product&id=1']],
        'critical_items' => [['name' => 'Šiltovka Classic', 'sku' => 'CAP-002', 'available' => 1, 'reserved' => 0, 'threshold' => 2, 'admin_url' => 'https://shop.example.com/admin.php?p=product&id=2']],
        'low_items' => [], 'worst_out' => true, 'worst_critical' => false, 'worst_low' => false,
        'first_name' => 'Tričko Basic', 'first_available' => 0, 'more_count' => 1,
        'received_at' => '2026-10-14 08:30:00 UTC', 'reason' => 'Nesedí veľkosť', 'seller_email' => 'info@barebones.example',
        'complaint_number' => 'REK-45-A1B2C3', 'item_name' => 'Tričko Basic', 'defect' => 'Po prvom praní sa rozpáral šev.', 'remedy' => 'Výmena za nový tovar',
        'decide_by' => '2026-11-13', 'status' => 'Uznaná', 'decision_note' => 'Vadu uznávame, nový kus sme odoslali.',
    ];

    /** Template names that have a default HTML file in any locale. */
    public static function templates(): array {
        $names = [];
        foreach (glob(self::dir() . '/*/*.html') ?: [] as $file) $names[basename($file, '.html')] = true;
        $names = array_keys($names);
        sort($names);
        return $names;
    }

    /** Locales an override can be written for: the active store's languages. */
    public static function locales(): array {
        $context = StoreContext::current();
        $languages = $context->config()['languages'] ?? [];
        $languages = is_array($languages) ? array_values(array_filter($languages, 'is_string')) : [];
        return $languages ?: [$context->defaultLocale()];
    }

    /** Variable names allowed in a template: every name its default files use, in any locale and part (incl. {{#each}} row fields). */
    public static function allowedVariables(string $template): array {
        $names = [];
        foreach (glob(self::dir() . '/*/' . $template . '.{html,subject.txt,txt}', GLOB_BRACE) ?: [] as $file) {
            foreach (self::tokens((string)file_get_contents($file)) as $token) {
                if (preg_match('~^(?:#(?:if|each) )?(\w+)$~', $token, $m)) $names[$m[1]] = true;
            }
        }
        $names = array_keys($names);
        sort($names);
        return $names;
    }

    /** The template parts from the files (locale, then the fallback locale, per part); null for a missing part. */
    public static function fileSource(string $template, string $locale): array {
        $parts = ['subject' => null, 'html' => null, 'text' => null];
        if (!self::validName($template)) return $parts;
        foreach (array_unique([$locale, self::FALLBACK_LOCALE]) as $loc) {
            if (!preg_match('/^[a-z]{2}(?:[_-][A-Za-z]{2})?$/', $loc)) continue;
            foreach (['subject' => '.subject.txt', 'html' => '.html', 'text' => '.txt'] as $part => $suffix) {
                $file = self::dir() . "/{$loc}/{$template}{$suffix}";
                if ($parts[$part] === null && is_file($file)) $parts[$part] = (string)file_get_contents($file);
            }
        }
        if ($parts['subject'] !== null) $parts['subject'] = trim($parts['subject']);
        return $parts;
    }

    /** The active store's override for exactly this template + locale, or null. */
    public static function override(string $template, string $locale): ?array {
        if (!StoreContext::hasCurrent()) return null;
        $stmt = Db::get()->prepare('SELECT subject, html, body_text, updated_by, updated_at FROM email_template_overrides
            WHERE store_id = :store AND template = :template AND locale = :locale');
        $stmt->execute([':store' => StoreContext::current()->id(), ':template' => $template, ':locale' => $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['subject' => (string)$row['subject'], 'html' => (string)$row['html'], 'text' => (string)$row['body_text'],
            'updated_by' => (string)$row['updated_by'], 'updated_at' => (string)$row['updated_at']] : null;
    }

    /**
     * Security notices to the merchant (core file drift, operational incidents): only the file default is sent, so an
     * admin allowed to edit templates cannot rewrite an alert into an "all is fine" message.
     */
    public const LOCKED_TEMPLATES = ['core_drift_alert', 'merchant_incident'];

    /**
     * Variables an override's HTML must keep: the order confirmation carries the pre-contractual information on a
     * durable medium (seller, withdrawal function, terms) — a v2.0.0 customisation predates them.
     */
    public const REQUIRED_VARIABLES = ['order_confirmation' => ['seller_name', 'seller_address', 'withdrawal_url', 'terms_url']];

    /** What MailService renders: the store override (an empty text part is derived from its HTML), else the files. */
    public static function source(string $template, string $locale): array {
        $override = in_array($template, self::LOCKED_TEMPLATES, true) ? null : self::override($template, $locale);
        if ($override !== null) return ['subject' => $override['subject'], 'html' => $override['html'], 'text' => $override['text'] === '' ? null : $override['text']];
        return self::fileSource($template, $locale);
    }

    /** Problems of a draft (empty = valid), in the admin language. */
    public static function validate(string $template, string $subject, string $html, string $text): array {
        $errors = [];
        $subject = trim($subject);
        if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT || preg_match('/[\x00-\x1F\x7F]/', $subject)) {
            $errors[] = I18n::format('admin.email.msg.subject', ['max' => self::MAX_SUBJECT]);
        }
        if (trim($html) === '' || strlen($html) > self::MAX_BODY || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $html)) {
            $errors[] = I18n::format('admin.email.msg.html', ['max' => self::MAX_BODY]);
        }
        if (strlen($text) > self::MAX_BODY || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
            $errors[] = I18n::format('admin.email.msg.text', ['max' => self::MAX_BODY]);
        }
        $allowed = array_flip(self::allowedVariables($template));
        $unknown = $malformed = [];
        foreach (['subject' => $subject, 'html' => $html, 'text' => $text] as $part) {
            $depth = ['if' => 0, 'each' => 0];
            foreach (self::tokens($part) as $token) {
                if (preg_match('~^/(if|each)$~', $token, $m)) {
                    if (--$depth[$m[1]] < 0) $malformed['{{' . $token . '}}'] = true;
                } elseif (preg_match('~^(?:#(if|each) )?(\w+)$~', $token, $m)) {
                    if ($m[1] !== '') $depth[$m[1]]++;
                    if (!isset($allowed[$m[2]])) $unknown[$m[2]] = true;
                } else {
                    $malformed['{{' . $token . '}}'] = true;
                }
            }
            if ($depth['if'] > 0 || $depth['each'] > 0) $errors[] = I18n::t('admin.email.msg.unbalanced');
        }
        // Only where the customer sees them: not in a comment or a hidden element, and in a custom text part as well.
        $names = static fn(string $source): array => array_flip(array_map(static fn(string $token): string => preg_replace('~^#(?:if|each) ~', '', $token), self::tokens($source)));
        $usedHtml = $names(self::visibleHtml($html));
        $usedText = trim($text) === '' ? null : $names($text);
        $missing = array_values(array_filter(self::REQUIRED_VARIABLES[$template] ?? [],
            static fn(string $name): bool => !isset($usedHtml[$name]) || ($usedText !== null && !isset($usedText[$name]))));
        if ($missing) $errors[] = I18n::format('admin.email.msg.required_vars', ['vars' => implode(', ', $missing)]);
        if ($unknown) $errors[] = I18n::format('admin.email.msg.unknown_vars', ['vars' => implode(', ', array_keys($unknown)), 'allowed' => implode(', ', array_keys($allowed))]);
        if ($malformed) $errors[] = I18n::format('admin.email.msg.malformed', ['tags' => implode(' ', array_keys($malformed))]);
        $unsafe = self::unsafeHtml($html);
        if ($unsafe) $errors[] = I18n::format('admin.email.msg.unsafe', ['what' => implode(', ', $unsafe)]);
        $leaks = self::secretLeaks($subject, $html, $text);
        if ($leaks) $errors[] = I18n::format('admin.email.msg.secret_link', ['vars' => implode(', ', $leaks)]);
        if (self::hasLiteralIban($html) || self::hasLiteralIban($text)) $errors[] = I18n::t('admin.email.msg.iban_literal');
        return array_values(array_unique($errors));
    }

    /**
     * Variables carrying a one-time token (password reset, e-mail verification, unsubscribe, cart restore,
     * download link, the invoice link with its token): they may only be a whole link (href="{{x}}") or stand alone
     * in the text, never part of another URL or attribute (an image or link that would send the token to a foreign server).
     */
    public const SECRET_VARIABLES = ['reset_url', 'verify_url', 'unsubscribe_url', 'cart_url', 'url', 'invoice_download_url'];

    /** Secret variables used anywhere else than as a whole href or a stand-alone text value. */
    public static function secretLeaks(string $subject, string $html, string $text): array {
        $leaks = [];
        $tagSpans = self::tagSpans($html);
        // A style sheet loads url(...) images: a token there reaches a foreign server like an <img src>.
        preg_match_all('~<style\b.*?(?:</style\s*>|$)~is', $html, $styleMatches, PREG_OFFSET_CAPTURE);
        $styleSpans = array_map(static fn(array $m): array => [$m[1], $m[1] + strlen($m[0])], $styleMatches[0]);
        foreach (self::SECRET_VARIABLES as $name) {
            $tag = '{{' . $name . '}}';
            if (str_contains($subject, $tag)) $leaks[$name] = true;
            foreach (self::offsets($text, $tag) as $at) {
                if (!self::standsAlone($text, $at, strlen($tag))) $leaks[$name] = true;
            }
            foreach (self::offsets($html, $tag) as $at) {
                // Tags are read with their quoted attribute values, so a ">" inside alt=">" does not end the tag.
                $open = self::spanStart($tagSpans, $at);
                $ok = $open !== null
                    ? preg_match('~\shref\s*=\s*(["\'])$~i', substr($html, $open, $at - $open), $quote) === 1 && substr($html, $at + strlen($tag), 1) === $quote[1]
                    : self::spanStart($styleSpans, $at) === null && self::standsAlone($html, $at, strlen($tag));
                if (!$ok) $leaks[$name] = true;
            }
        }
        return array_keys($leaks);
    }

    /**
     * A hard-coded bank account (bank details must come from {{bank_iban}}, the store's configured account): an IBAN
     * in any letter case and grouping (spaces, hyphens, dots, underscores, slashes), or a Czech/Slovak domestic number "prefix-number/bank".
     * Candidates must pass the IBAN (mod 97) or domestic (mod 11) check digits, so dates and order numbers do not count.
     */
    public static function hasLiteralIban(string $source): bool {
        $visible = preg_replace('~\{\{.*?\}\}~s', ' ', $source);
        $visible = html_entity_decode(strip_tags((string)$visible), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $visible = strtoupper((string)preg_replace('/\p{Cf}+/u', '', $visible));
        // Any grouping, also inside the country code and check digits ("S K 3 1", "SK31_1200/0000").
        $gap = '[\s\x{00A0}._\/\\\\|,:;\-]*';
        preg_match_all("/(?<![A-Z0-9])[A-Z]{$gap}[A-Z]{$gap}\\d{$gap}\\d(?:{$gap}[A-Z0-9]){11,64}/u", $visible, $ibans);
        foreach ($ibans[0] as $candidate) {
            $compact = (string)preg_replace('/[^A-Z0-9]/', '', $candidate);
            // Every start, so a decoy group in front ("XX00 SK31 …") does not hide the account behind it.
            for ($start = 0; $start + 15 <= strlen($compact); $start++) {
                if (!preg_match('/^[A-Z]{2}\d{2}/', substr($compact, $start, 4))) continue;
                $length = self::IBAN_LENGTHS[substr($compact, $start, 2)] ?? 0;
                if ($length > 0 && $start + $length <= strlen($compact) && self::ibanChecksumOk(substr($compact, $start, $length))) return true;
            }
        }
        preg_match_all('~(?<![\d/\-])(?:(\d{1,6})\s*-\s*)?(\d{2,10})\s*/\s*(\d{4})(?![\d/])~', $visible, $domestic, PREG_SET_ORDER);
        foreach ($domestic as $m) {
            if (self::domesticChecksumOk($m[1], $m[2]) && (int)ltrim($m[2], '0') > 0 && ($m[1] !== '' || strlen(ltrim($m[2], '0')) >= 6)) return true;
        }
        return false;
    }

    /** IBAN length per country (SWIFT IBAN registry). */
    private const IBAN_LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22, 'BR' => 29, 'BY' => 28,
        'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28, 'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18,
        'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22,
        'IL' => 23, 'IQ' => 23, 'IS' => 26, 'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32, 'LI' => 21, 'LT' => 20,
        'LU' => 20, 'LV' => 21, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18, 'NO' => 15,
        'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22, 'SA' => 24, 'SC' => 31, 'SE' => 24, 'SI' => 19,
        'SK' => 24, 'SM' => 27, 'ST' => 25, 'SV' => 28, 'TL' => 23, 'TN' => 24, 'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    private static function ibanChecksumOk(string $iban): bool {
        $digits = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) $digits .= ctype_digit($char) ? $char : (string)(ord($char) - 55);
        $rest = 0;
        foreach (str_split($digits, 7) as $chunk) $rest = (int)(($rest . $chunk)) % 97;
        return $rest === 1;
    }

    /** Czech/Slovak domestic account check digits: weighted digit sums of prefix and number divisible by 11. */
    private static function domesticChecksumOk(string $prefix, string $number): bool {
        $weights = [6, 3, 7, 9, 10, 5, 8, 4, 2, 1];
        foreach ([[str_pad($prefix, 10, '0', STR_PAD_LEFT), $weights], [str_pad($number, 10, '0', STR_PAD_LEFT), $weights]] as [$digits, $w]) {
            $sum = 0;
            foreach (str_split($digits) as $i => $digit) $sum += (int)$digit * $w[$i];
            if ($sum % 11 !== 0) return false;
        }
        return true;
    }

    /**
     * Byte spans of the HTML tags, read as a browser reads them: a quote opens an attribute value only right
     * after "=" (alt=a' is a plain apostrophe, so ">" inside alt=">" does not end the tag and a stray "'" does
     * not hide the rest of the tag). An unclosed tag or value runs to the end.
     * @return list<array{0:int,1:int}>
     */
    private static function tagSpans(string $html): array {
        $spans = [];
        $length = strlen($html);
        for ($at = 0; $at < $length; $at++) {
            if ($html[$at] !== '<' || $at + 1 >= $length || !preg_match('~[a-zA-Z/!?]~', $html[$at + 1])) continue;
            $start = $at;
            $lastSignificant = '';
            for ($at++; $at < $length && $html[$at] !== '>'; $at++) {
                $char = $html[$at];
                if (($char === '"' || $char === "'") && $lastSignificant === '=') {
                    $close = strpos($html, $char, $at + 1);
                    $at = $close === false ? $length - 1 : $close;
                    $lastSignificant = $char;
                    continue;
                }
                if (!ctype_space($char)) $lastSignificant = $char;
            }
            $spans[] = [$start, min($at + 1, $length)];
        }
        return $spans;
    }

    /** @param list<array{0:int,1:int}> $spans @return int|null start of the span containing $at */
    private static function spanStart(array $spans, int $at): ?int {
        foreach ($spans as [$start, $end]) {
            if ($at > $start && $at < $end) return $start;
        }
        return null;
    }

    /** @return list<int> byte offsets of every occurrence */
    private static function offsets(string $haystack, string $needle): array {
        $offsets = [];
        for ($at = strpos($haystack, $needle); $at !== false; $at = strpos($haystack, $needle, $at + 1)) $offsets[] = $at;
        return $offsets;
    }

    /** Not glued to URL characters on either side (a mail client would otherwise linkify a foreign URL with the token). */
    private static function standsAlone(string $source, int $at, int $length): bool {
        $before = $at > 0 ? $source[$at - 1] : ' ';
        return preg_match('~[\s>]~', $before) === 1
            && preg_match('~^[.,;!?)]?(?:\s|<|$)~', substr($source, $at + $length, 2)) === 1;
    }

    public static function save(string $template, string $locale, mixed $post): array {
        if (!self::editable($template, $locale)) return self::result(false, I18n::t('admin.email.msg.unknown'));
        $fields = self::scalars($post, ['subject', 'html', 'text']);
        if ($fields === null) return self::result(false, I18n::t('admin.msg.invalid_form'));
        $fields = array_map(static fn(string $v): string => str_replace("\r\n", "\n", $v), $fields);
        $errors = self::validate($template, $fields['subject'], $fields['html'], $fields['text']);
        if ($errors) return self::result(false, implode(' ', $errors));
        $old = self::override($template, $locale);
        $row = [':store' => StoreContext::current()->id(), ':template' => $template, ':locale' => $locale, ':subject' => trim($fields['subject']),
            ':html' => $fields['html'], ':text' => trim($fields['text']) === '' ? '' : $fields['text'],
            ':by' => mb_substr(Security::adminUser(), 0, 191), ':at' => gmdate('Y-m-d H:i:s')];
        $db = Db::get();
        $db->beginTransaction();
        try {
            $update = $db->prepare('UPDATE email_template_overrides SET subject = :subject, html = :html, body_text = :text, updated_by = :by, updated_at = :at
                WHERE store_id = :store AND template = :template AND locale = :locale');
            $update->execute($row);
            if ($update->rowCount() === 0 && $old === null) {
                $db->prepare('INSERT INTO email_template_overrides (store_id, template, locale, subject, html, body_text, updated_by, updated_at)
                    VALUES (:store, :template, :locale, :subject, :html, :text, :by, :at)')->execute($row);
            }
            $new = ['subject' => $row[':subject'], 'html' => $row[':html'], 'text' => $row[':text']];
            AuditLog::log('email_template', 0, $old === null ? null : self::auditRow($template, $locale, $old),
                self::auditRow($template, $locale, $new) + self::auditDiff($old ?? self::fileSource($template, $locale), $new), 'Admin e-mail template override');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            Logger::error('email template save failed', ['template' => $template, 'locale' => $locale, 'error' => $e->getMessage()]);
            return self::result(false, I18n::t('admin.email.msg.save_failed'));
        }
        return self::result(true, I18n::format('admin.email.msg.saved', ['template' => $template, 'locale' => $locale]));
    }

    /**
     * Upgrade from v2.0.0, where a shop customised templates/emails/ files in place: every part in $customDir that
     * differs from the same file in $baseDir (the untouched release the shop ran) becomes the active store's override;
     * parts the shop did not change take the current default. Overrides pass validate() like an admin save, so a
     * customised order confirmation without the consumer information is refused until it is merged by hand.
     * Returns one row per changed template + locale: status imported | would_import | refused | skipped.
     */
    public static function importCustomised(string $customDir, string $baseDir, bool $dryRun): array {
        $suffixes = ['subject' => '.subject.txt', 'html' => '.html', 'text' => '.txt'];
        $read = static fn(string $file): ?string => is_file($file) ? str_replace("\r\n", "\n", (string)file_get_contents($file)) : null;
        $changed = [];
        foreach (glob(rtrim($customDir, '/\\') . '/*/*') ?: [] as $file) {
            $locale = basename(dirname($file));
            foreach ($suffixes as $part => $suffix) {
                if (!str_ends_with(basename($file), $suffix)) continue;
                $template = substr(basename($file), 0, -strlen($suffix));
                $custom = $read($file);
                if ($custom !== null && $custom !== $read(rtrim($baseDir, '/\\') . "/{$locale}/" . basename($file))) {
                    $changed[$template][$locale][$part] = $custom;
                }
                break; // '.subject.txt' is tested before '.txt'
            }
        }
        ksort($changed);
        $rows = [];
        foreach ($changed as $template => $locales) {
            ksort($locales);
            foreach ($locales as $locale => $parts) {
                $row = ['template' => (string)$template, 'locale' => (string)$locale, 'parts' => array_keys($parts)];
                if (!self::editable((string)$template, (string)$locale)) {
                    $rows[] = $row + ['status' => 'skipped', 'message' => in_array($template, self::LOCKED_TEMPLATES, true)
                        ? 'locked template: only the file default is sent' : 'unknown template or a language the store does not use'];
                    continue;
                }
                if (self::override((string)$template, (string)$locale) !== null) {
                    $rows[] = $row + ['status' => 'skipped', 'message' => 'the store already has an override; merge by hand in the admin'];
                    continue;
                }
                $draft = array_map(static fn(?string $value): string => (string)$value, self::fileSource((string)$template, (string)$locale));
                $draft = array_replace($draft, $parts);
                $errors = self::validate((string)$template, $draft['subject'], $draft['html'], $draft['text']);
                if ($errors) {
                    $rows[] = $row + ['status' => 'refused', 'message' => implode(' ', $errors)];
                } elseif ($dryRun) {
                    $rows[] = $row + ['status' => 'would_import', 'message' => ''];
                } else {
                    $saved = self::save((string)$template, (string)$locale, $draft);
                    $rows[] = $row + ['status' => $saved['ok'] ? 'imported' : 'refused', 'message' => $saved['message']];
                }
            }
        }
        return $rows;
    }

    /** Drop the active store's override: the file default applies again. */
    public static function reset(string $template, string $locale): array {
        if (!self::editable($template, $locale)) return self::result(false, I18n::t('admin.email.msg.unknown'));
        $old = self::override($template, $locale);
        if ($old === null) return self::result(true, I18n::format('admin.email.msg.reset', ['template' => $template, 'locale' => $locale]));
        $db = Db::get();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM email_template_overrides WHERE store_id = :store AND template = :template AND locale = :locale')
                ->execute([':store' => StoreContext::current()->id(), ':template' => $template, ':locale' => $locale]);
            // The diff (override → restored file default) rides on the old row: a delete has no new row.
            AuditLog::log('email_template', 0, self::auditRow($template, $locale, $old) + self::auditDiff($old, self::fileSource($template, $locale)),
                null, 'Admin e-mail template reset');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            Logger::error('email template reset failed', ['template' => $template, 'locale' => $locale, 'error' => $e->getMessage()]);
            return self::result(false, I18n::t('admin.email.msg.save_failed'));
        }
        return self::result(true, I18n::format('admin.email.msg.reset', ['template' => $template, 'locale' => $locale]));
    }

    /** Rendered sample message for the preview; null parts take the stored template (override or file). */
    public static function preview(string $template, string $locale, ?array $draft = null): array {
        $source = $draft ?? self::source($template, $locale);
        $html = MailService::renderString((string)($source['html'] ?? "<p>{$template}</p>"), self::SAMPLE);
        $subject = trim(str_replace(["\r", "\n"], ' ', MailService::renderString((string)($source['subject'] ?? $template), self::SAMPLE, false)));
        $text = (string)($source['text'] ?? '') !== '' ? MailService::renderString((string)$source['text'], self::SAMPLE, false) : MailService::textFromHtml($html);
        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    public static function editable(string $template, string $locale): bool {
        return !in_array($template, self::LOCKED_TEMPLATES, true) && in_array($template, self::templates(), true) && in_array($locale, self::locales(), true);
    }

    /** Names of HTML constructs that may not appear in a template override (empty = safe). */
    public static function unsafeHtml(string $html): array {
        $found = [];
        if (preg_match_all('~<\s*/?\s*(script|iframe|frame|frameset|object|embed|applet|form|input|button|textarea|select|meta|link|base|svg|math|template|portal)\b~i', $html, $m)) {
            foreach ($m[1] as $tag) $found['<' . strtolower($tag) . '>'] = true;
        }
        if (preg_match('~<[^>]*?[\s/"\']on[a-z]+\s*=~i', $html)) $found['on*='] = true;
        if (preg_match('~<[^>]*?\ssrcdoc\s*=~i', $html)) $found['srcdoc'] = true;
        // Entity-encoded or whitespace-split schemes ("jav&#x61;script:", "java\tscript:") count as well.
        $decoded = preg_replace('/[\x00-\x20]+/', '', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach (['javascript:', 'vbscript:', 'data:text/html', 'expression(', '@import'] as $needle) {
            if (stripos((string)$decoded, $needle) !== false) $found[$needle] = true;
        }
        return array_keys($found);
    }

    /**
     * The HTML a reader sees: without comments, <head>/<title>/<style>, and elements hidden by the hidden attribute,
     * an inline style or a class/id a <style> rule hides (display:none, visibility:hidden, zero size or opacity…).
     * Visible tags are kept with their attributes, so a link's href="{{x}}" counts.
     */
    private static function visibleHtml(string $html): string {
        $html = (string)preg_replace('~<!--.*?(?:-->|$)~s', '', $html);
        $hiddenNames = [];
        if (preg_match_all('~<style\b[^>]*>(.*?)(?:</style\s*>|$)~is', $html, $styles)) {
            foreach ($styles[1] as $css) {
                preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);
                foreach ($rules as [, $selectors, $declarations]) {
                    if (!self::hidesContent($declarations)) continue;
                    preg_match_all('~[.#]([\w-]+)~', $selectors, $m);
                    foreach ($m[1] as $name) $hiddenNames[strtolower($name)] = true;
                }
            }
        }
        $void = array_flip(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);
        $neverShown = array_flip(['head', 'title', 'style', 'script', 'template']);
        preg_match_all('~<(/?)([a-zA-Z][\w-]*)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>~', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $visible = '';
        $stack = [];
        $hidden = 0;
        $pos = 0;
        foreach ($tags as $tag) {
            [$whole, $at] = $tag[0];
            if ($hidden === 0) $visible .= substr($html, $pos, $at - $pos);
            $pos = $at + strlen($whole);
            $name = strtolower($tag[2][0]);
            $attrs = $tag[3][0];
            if ($tag[1][0] === '/') {
                $i = count($stack) - 1;
                while ($i >= 0 && $stack[$i][0] !== $name) $i--;
                if ($i < 0) continue;
                while (count($stack) > $i) $hidden -= array_pop($stack)[1] ? 1 : 0;
                if ($hidden === 0) $visible .= $whole;
                continue;
            }
            $hides = isset($neverShown[$name]) || preg_match('~(?:^|\s)hidden(?:\s|=|/|$)~i', $attrs)
                || (preg_match('~\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $attrs, $style) && self::hidesContent($style[1] . ($style[2] ?? '')));
            if (!$hides && $hiddenNames && preg_match_all('~\s(?:class|id)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $attrs, $refs, PREG_SET_ORDER)) {
                foreach ($refs as $ref) {
                    foreach (preg_split('/\s+/', strtolower($ref[1] . ($ref[2] ?? ''))) ?: [] as $ref1) if (isset($hiddenNames[$ref1])) $hides = true;
                }
            }
            if ($hidden === 0 && !$hides) $visible .= $whole;
            if (isset($void[$name]) || str_ends_with(rtrim($attrs), '/')) continue;
            $stack[] = [$name, (bool)$hides];
            if ($hides) $hidden++;
        }
        if ($hidden === 0) $visible .= substr($html, $pos);
        return $visible;
    }

    private static function hidesContent(string $css): bool {
        return preg_match('~(?:^|[;\s{])(?:display\s*:\s*none|visibility\s*:\s*hidden|mso-hide\s*:\s*all|color\s*:\s*transparent'
            . '|(?:opacity|font-size|max-height)\s*:\s*(?:0+(?:\.0*)?|\.0+)(?:px|pt|em|rem|%)?\s*(?:!important\s*)?(?:;|$))~i', trim($css)) === 1;
    }

    /** Contents of every {{…}} placeholder. */
    private static function tokens(string $source): array {
        preg_match_all('~\{\{(.*?)\}\}~s', $source, $m);
        return $m[1];
    }

    private static function auditRow(string $template, string $locale, array $parts): array {
        return ['template' => $template, 'locale' => $locale, 'subject' => $parts['subject'], 'html_sha256' => hash('sha256', $parts['html']),
            'text_sha256' => hash('sha256', (string)$parts['text'])];
    }

    /** What changed, readable in the audit log: subject before/after and a line diff of the HTML and text parts. */
    private static function auditDiff(array $before, array $after): array {
        return [
            'subject_before' => (string)($before['subject'] ?? ''),
            'html_diff' => self::lineDiff((string)($before['html'] ?? ''), (string)($after['html'] ?? '')),
            'text_diff' => self::lineDiff((string)($before['text'] ?? ''), (string)($after['text'] ?? '')),
        ];
    }

    /** Lines between the common head and tail: "-" removed, "+" added (not minimal, but exact and linear). */
    public static function lineDiff(string $before, string $after): string {
        $a = explode("\n", str_replace("\r\n", "\n", $before));
        $b = explode("\n", str_replace("\r\n", "\n", $after));
        if ($a === $b) return '';
        $head = 0;
        while ($head < count($a) && $head < count($b) && $a[$head] === $b[$head]) $head++;
        $tail = 0;
        while ($tail < count($a) - $head && $tail < count($b) - $head && $a[count($a) - 1 - $tail] === $b[count($b) - 1 - $tail]) $tail++;
        $lines = ['@@ line ' . ($head + 1)];
        foreach (array_slice($a, $head, count($a) - $head - $tail) as $line) $lines[] = '-' . $line;
        foreach (array_slice($b, $head, count($b) - $head - $tail) as $line) $lines[] = '+' . $line;
        return implode("\n", $lines);
    }

    private static function validName(string $template): bool {
        return preg_match('/^[a-z0-9_-]{1,80}$/i', $template) === 1;
    }

    private static function dir(): string {
        return dirname(__DIR__) . '/templates/emails';
    }

    private static function result(bool $ok, string $message): array {
        return ['ok' => $ok, 'id' => 0, 'message' => $message];
    }

    /** The posted fields as strings (missing = ''), or null when the post or a field is not scalar. */
    private static function scalars(mixed $post, array $names): ?array {
        if (!is_array($post)) return null;
        $fields = [];
        foreach ($names as $name) {
            $value = $post[$name] ?? '';
            if (!is_scalar($value)) return null;
            $fields[$name] = (string)$value;
        }
        return $fields;
    }
}
