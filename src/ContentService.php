<?php
/** Store-scoped menus, banners, landing pages, and audited redirects. */
class ContentService {
    public static function menu(string $code = 'primary', ?string $locale = null): array {
        $code = self::code($code, 'menu code');
        $locale = self::locale($locale ?? I18n::locale());
        try {
            $stmt = Db::get()->prepare("SELECT id, menu_code, locale, label, url, parent_id, sort_order
                FROM content_menu_items
                WHERE store_id=:store AND menu_code=:code AND active=1 AND locale IN ('*', :locale)
                ORDER BY CASE WHEN locale=:locale_exact THEN 0 ELSE 1 END, sort_order, id");
            $stmt->execute([
                ':store' => StoreContext::current()->id(), ':code' => $code,
                ':locale' => $locale, ':locale_exact' => $locale,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (self::missingTable($e)) return [];
            throw $e;
        }
    }

    public static function banners(string $slot = 'homepage', ?string $locale = null, ?string $now = null): array {
        $slot = self::code($slot, 'banner slot');
        $locale = self::locale($locale ?? I18n::locale());
        $now = $now ?? gmdate('Y-m-d H:i:s');
        try {
            $stmt = Db::get()->prepare("SELECT id, slot, locale, title, body, image_url, link_url, link_label, sort_order
                FROM content_banners
                WHERE store_id=:store AND slot=:slot AND active=1 AND locale IN ('*', :locale)
                  AND (starts_at IS NULL OR starts_at<=:now_start) AND (ends_at IS NULL OR ends_at>=:now_end)
                ORDER BY CASE WHEN locale=:locale_exact THEN 0 ELSE 1 END, sort_order, id");
            $stmt->execute([
                ':store' => StoreContext::current()->id(), ':slot' => $slot,
                ':locale' => $locale, ':locale_exact' => $locale,
                ':now_start' => $now, ':now_end' => $now,
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (self::missingTable($e)) return [];
            throw $e;
        }
    }

    public static function page(string $slug, ?string $locale = null): ?array {
        $slug = Security::slug($slug);
        if ($slug === '') return null;
        $stmt = Db::get()->prepare("SELECT * FROM pages WHERE store_id=:store AND slug=:slug AND status='active'");
        $stmt->execute([':store' => StoreContext::current()->id(), ':slug' => $slug]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$page) return null;
        $locale = self::locale($locale ?? I18n::locale());
        $translations = json_decode((string)($page['translations'] ?? '{}'), true);
        $translation = is_array($translations) ? ($translations[$locale] ?? []) : [];
        foreach (['title', 'body', 'seo_title', 'meta_description'] as $field) {
            if (is_array($translation) && isset($translation[$field]) && $translation[$field] !== '') {
                $page[$field] = (string)$translation[$field];
            }
        }
        return $page;
    }

    /** Validate data-only CMS sections; file paths and executable content are never accepted. */
    public static function sanitizeSections(mixed $value): string {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') return '[]';
            $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($value) || count($value) > 100) throw new InvalidArgumentException('Invalid CMS sections');
        $sections = [];
        foreach (array_values($value) as $index => $section) {
            if (!is_array($section)) throw new InvalidArgumentException('Invalid CMS section');
            $type = self::code((string)($section['type'] ?? ''), 'section type');
            $sections[] = [
                'id' => self::code((string)($section['id'] ?? 'section-' . $index), 'section id'),
                'type' => $type,
                'variant' => self::code((string)($section['variant'] ?? 'default'), 'section variant'),
                'heading' => self::optionalText((string)($section['heading'] ?? ''), 500),
                'body_html' => Security::sanitizeRichHtml((string)($section['body_html'] ?? '')),
                'media' => self::sectionData((array)($section['media'] ?? [])),
                'cta' => self::sectionData((array)($section['cta'] ?? [])),
                'items' => self::safeSectionData((array)($section['items'] ?? [])),
                'props' => self::safeSectionData((array)($section['props'] ?? [])),
            ];
        }
        return json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function redirect(string $sourcePath): ?array {
        $sourcePath = self::path($sourcePath, 'source path');
        try {
            $stmt = Db::get()->prepare("SELECT id, source_path, target_url, http_status FROM content_redirects
                WHERE store_id=:store AND source_path=:source AND active=1 LIMIT 1");
            $stmt->execute([':store' => StoreContext::current()->id(), ':source' => $sourcePath]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['target_url'] === $sourcePath) return null;
            return $row;
        } catch (PDOException $e) {
            if (self::missingTable($e)) return null;
            throw $e;
        }
    }

    public static function all(string $type): array {
        [$table, $order] = match($type) {
            'menu' => ['content_menu_items', 'menu_code, locale, sort_order, id'],
            'banner' => ['content_banners', 'slot, locale, sort_order, id'],
            'redirect' => ['content_redirects', 'source_path, id'],
            default => throw new InvalidArgumentException('Unknown content type'),
        };
        $stmt = Db::get()->prepare("SELECT * FROM {$table} WHERE store_id=:store ORDER BY {$order}");
        $stmt->execute([':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function saveMenu(array $input): int {
        $data = [
            'menu_code' => self::code((string)($input['menu_code'] ?? 'primary'), 'menu code'),
            'locale' => self::locale((string)($input['locale'] ?? '*')),
            'label' => self::text((string)($input['label'] ?? ''), 191, 'label'),
            'url' => self::url((string)($input['url'] ?? ''), true),
            'parent_id' => self::nullablePositiveInt($input['parent_id'] ?? null),
            'sort_order' => max(-10000, min(10000, (int)($input['sort_order'] ?? 0))),
            'active' => !empty($input['active']) ? 1 : 0,
        ];
        return self::saveRow('content_menu_items', (int)($input['id'] ?? 0), $data, 'content_menu');
    }

    public static function saveBanner(array $input): int {
        $data = [
            'slot' => self::code((string)($input['slot'] ?? 'homepage'), 'banner slot'),
            'locale' => self::locale((string)($input['locale'] ?? '*')),
            'title' => self::text((string)($input['title'] ?? ''), 191, 'title'),
            'body' => trim((string)($input['body'] ?? '')),
            'image_url' => self::url((string)($input['image_url'] ?? ''), true, true),
            'link_url' => self::url((string)($input['link_url'] ?? ''), true, true),
            'link_label' => self::optionalText((string)($input['link_label'] ?? ''), 191),
            'starts_at' => self::dateOrNull($input['starts_at'] ?? null),
            'ends_at' => self::dateOrNull($input['ends_at'] ?? null),
            'sort_order' => max(-10000, min(10000, (int)($input['sort_order'] ?? 0))),
            'active' => !empty($input['active']) ? 1 : 0,
        ];
        if ($data['starts_at'] !== null && $data['ends_at'] !== null && $data['ends_at'] < $data['starts_at']) {
            throw new InvalidArgumentException('Banner end must not precede start');
        }
        return self::saveRow('content_banners', (int)($input['id'] ?? 0), $data, 'content_banner');
    }

    public static function saveRedirect(array $input): int {
        $status = (int)($input['http_status'] ?? 301);
        if (!in_array($status, [301, 302, 307, 308], true)) throw new InvalidArgumentException('Invalid redirect status');
        $source = self::path((string)($input['source_path'] ?? ''), 'source path');
        $target = self::url((string)($input['target_url'] ?? ''), false);
        if ($source === $target) throw new InvalidArgumentException('Redirect cannot target itself');
        return self::saveRow('content_redirects', (int)($input['id'] ?? 0), [
            'source_path' => $source, 'target_url' => $target, 'http_status' => $status,
            'active' => !empty($input['active']) ? 1 : 0,
        ], 'content_redirect');
    }

    public static function saveLanding(array $input): int {
        $id = (int)($input['id'] ?? 0);
        $slug = Security::slug((string)($input['slug'] ?? ''));
        $title = self::text((string)($input['title'] ?? ''), 191, 'title');
        if ($slug === '') throw new InvalidArgumentException('Invalid landing page slug');
        $data = [
            'slug' => $slug,
            'title' => $title,
            'body' => Security::sanitizeRichHtml((string)($input['body'] ?? '')),
            'status' => in_array(($input['status'] ?? ''), ['active', 'draft'], true) ? (string)$input['status'] : 'draft',
            'translations' => '{}',
            'page_type' => 'landing',
            'template' => self::code((string)($input['template'] ?? 'cms'), 'page template'),
            'seo_title' => self::optionalText((string)($input['seo_title'] ?? ''), 191),
            'meta_description' => self::optionalText((string)($input['meta_description'] ?? ''), 320),
            'sections' => self::sanitizeSections($input['sections'] ?? []),
        ];
        return self::saveRow('pages', $id, $data, 'content_landing_page');
    }

    public static function delete(string $type, int $id): void {
        [$table, $entity] = match($type) {
            'menu' => ['content_menu_items', 'content_menu'],
            'banner' => ['content_banners', 'content_banner'],
            'redirect' => ['content_redirects', 'content_redirect'],
            'landing' => ['pages', 'content_landing_page'],
            default => throw new InvalidArgumentException('Unknown content type'),
        };
        $old = self::row($table, $id);
        if (!$old) return;
        $stmt = Db::get()->prepare("DELETE FROM {$table} WHERE id=:id AND store_id=:store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->id()]);
        if ($stmt->rowCount() > 0) AuditLog::log($entity, $id, $old, null, 'Content deleted');
    }

    private static function saveRow(string $table, int $id, array $data, string $entity): int {
        $old = $id > 0 ? self::row($table, $id) : null;
        if ($id > 0 && !$old) throw new DomainException('Content record not found in active store');
        $data['store_id'] = StoreContext::current()->id();
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        if ($id > 0) {
            $sets = [];
            $params = [':id' => $id, ':scope_store' => $data['store_id']];
            foreach ($data as $key => $value) { $sets[] = "{$key}=:{$key}"; $params[":{$key}"] = $value; }
            Db::get()->prepare("UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE id=:id AND store_id=:scope_store')->execute($params);
        } else {
            $data['created_at'] = $data['updated_at'];
            $columns = array_keys($data);
            $params = [];
            foreach ($data as $key => $value) $params[":{$key}"] = $value;
            Db::get()->prepare("INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_keys($params)) . ')')->execute($params);
            $id = (int)Db::get()->lastInsertId();
        }
        AuditLog::log($entity, $id, $old, self::row($table, $id), $old ? 'Content updated' : 'Content created');
        return $id;
    }

    private static function row(string $table, int $id): ?array {
        $stmt = Db::get()->prepare("SELECT * FROM {$table} WHERE id=:id AND store_id=:store");
        $stmt->execute([':id' => $id, ':store' => StoreContext::current()->id()]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function code(string $value, string $label): string {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/', $value)) throw new InvalidArgumentException("Invalid {$label}");
        return $value;
    }

    private static function locale(string $value): string {
        $value = strtolower(trim($value));
        if ($value !== '*' && !preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $value)) throw new InvalidArgumentException('Invalid locale');
        return $value;
    }

    private static function text(string $value, int $max, string $label): string {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) throw new InvalidArgumentException("Invalid {$label}");
        return $value;
    }

    private static function optionalText(string $value, int $max): string {
        $value = trim($value);
        if (mb_strlen($value) > $max) throw new InvalidArgumentException('Text is too long');
        return $value;
    }

    private static function path(string $value, string $label): string {
        $path = parse_url(trim($value), PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException("Invalid {$label}");
        }
        return $path;
    }

    private static function url(string $value, bool $allowEmpty, bool $allowAbsolute = false): string {
        $value = trim($value);
        if ($value === '' && $allowEmpty) return '';
        if (str_starts_with($value, '/') && !str_starts_with($value, '//') && !preg_match('/[\x00-\x1F\x7F]/', $value)) return $value;
        if ($allowAbsolute && filter_var($value, FILTER_VALIDATE_URL) && strtolower((string)parse_url($value, PHP_URL_SCHEME)) === 'https') return $value;
        throw new InvalidArgumentException('URL must be a local path' . ($allowAbsolute ? ' or HTTPS URL' : ''));
    }

    private static function sectionData(array $data): array {
        $result = self::safeSectionData($data);
        foreach ($result as $key => $item) {
            if (!is_string($item) || !str_ends_with((string)$key, 'url') || $item === '') continue;
            $result[$key] = self::url($item, true, true);
        }
        return $result;
    }

    private static function safeSectionData(array $data): array {
        $result = [];
        foreach ($data as $key => $value) {
            if (!is_int($key) && (!is_string($key) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,79}$/', $key))) continue;
            if (is_string($key) && str_ends_with($key, '_html')) continue;
            if (is_array($value)) $result[$key] = self::safeSectionData($value);
            elseif (is_scalar($value) || $value === null) $result[$key] = is_string($value) ? trim($value) : $value;
        }
        return $result;
    }

    private static function missingTable(PDOException $e): bool {
        return $e->getCode() === '42S02' || str_contains(strtolower($e->getMessage()), 'no such table');
    }

    private static function nullablePositiveInt($value): ?int {
        if ($value === null || $value === '') return null;
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    private static function dateOrNull($value): ?string {
        $value = trim((string)$value);
        if ($value === '') return null;
        $timestamp = strtotime($value);
        if ($timestamp === false) throw new InvalidArgumentException('Invalid date');
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
