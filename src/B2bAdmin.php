<?php
/**
 * A12.6: admin CRUD for the active store's B2B companies and customer groups and the assignment of a customer to them.
 * Rows belong to the store (store_id = StoreContext id); a customer can only join a group or company of its own store.
 * Group discount is entered in % and stored as basis points (Pricing::applyPriceListContext applies it when no price list
 * matches). Purchase on invoice is offered only to an approved, active company with invoice_purchase and payment terms
 * (Customers::canInvoicePurchase); credit_limit 0 means no limit. Changing a VAT ID clears its validation timestamp.
 * Every write is audited. Write methods return ['ok' => bool, 'id' => int, 'message' => flash text].
 */
final class B2bAdmin {
    private const MAX_TEXT = 191;
    private const MAX_INT = 2000000000;
    public const APPROVAL = ['pending' => 'čaká na schválenie', 'approved' => 'schválená', 'rejected' => 'zamietnutá'];

    public static function groups(): array {
        return self::storeRows('SELECT cg.*, (SELECT COUNT(*) FROM customers c WHERE c.customer_group_id = cg.id AND c.store_id = cg.store_id) AS customer_count
            FROM customer_groups cg WHERE cg.store_id = :store ORDER BY cg.name, cg.code');
    }

    public static function group(int $id): ?array {
        return self::storeRows('SELECT * FROM customer_groups WHERE id = :id AND store_id = :store', [':id' => $id])[0] ?? null;
    }

    public static function saveGroup(int $id, mixed $post): array {
        $old = $id > 0 ? self::group($id) : null;
        if ($id > 0 && !$old) return self::result(false, 0, 'Zákaznícka skupina neexistuje v tomto obchode.');
        $fields = self::scalars($post, ['code', 'name', 'discount', 'active']);
        if ($fields === null) return self::result(false, $id, 'Neplatný formulár.');
        try {
            $name = self::text($fields['name'], true, 'Názov');
            $code = self::code($fields['code'], $name);
            if (self::storeRows('SELECT 1 AS taken FROM customer_groups WHERE store_id = :store AND code = :code AND id != :id', [':code' => $code, ':id' => $id ?: -1])) {
                self::fail("Kód skupiny \"{$code}\" už v tomto obchode existuje.");
            }
            $row = ['code' => $code, 'name' => $name, 'discount_bps' => self::percentBps($fields['discount']), 'active' => self::flag($fields['active'])];
        } catch (DomainException $e) {
            return self::result(false, $id, $e->getMessage());
        }
        $id = self::write('customer_groups', 'customer_group', $old, $id, $row, $old ? 'Skupina uložená.' : 'Skupina vytvorená.', "Kód skupiny \"{$row['code']}\" už v tomto obchode existuje.");
        return is_array($id) ? $id : self::result(true, $id, $old ? 'Skupina uložená.' : 'Skupina vytvorená.');
    }

    public static function deleteGroup(int $id): array {
        $group = $id > 0 ? self::group($id) : null;
        if (!$group) return self::result(false, 0, 'Zákaznícka skupina neexistuje v tomto obchode.');
        $blockers = [];
        if ((int)(self::storeRows('SELECT COUNT(*) AS n FROM customers WHERE store_id = :store AND customer_group_id = :id', [':id' => $id])[0]['n'] ?? 0) > 0) $blockers[] = 'zákazníci';
        $lists = array_column(self::storeRows('SELECT code FROM price_lists WHERE store_id = :store AND customer_group_id = :id', [':id' => $id]), 'code');
        if ($lists) $blockers[] = 'cenníky ' . implode(', ', $lists);
        $promotions = [];
        foreach (self::storeRows('SELECT code, conditions FROM promotions WHERE store_id = :store') as $promotion) {
            $conditions = json_decode((string)$promotion['conditions'], true);
            if (is_array($conditions['customer_group_ids'] ?? null) && in_array($id, array_map('intval', $conditions['customer_group_ids']), true)) $promotions[] = $promotion['code'];
        }
        if ($promotions) $blockers[] = 'promo akcie ' . implode(', ', $promotions);
        if ($blockers) return self::result(false, $id, "Skupinu {$group['code']} nemožno zmazať, používajú ju: " . implode('; ', $blockers) . '. Deaktivujte ju.');
        return self::remove('customer_groups', 'customer_group', $group, 'Skupina zmazaná.');
    }

    /** Companies of the store with the number of members and their unpaid (open) order total. */
    public static function companies(): array {
        return array_map(static fn(array $row): array => $row + ['open_total' => Customers::companyOpenTotal((int)$row['id'])],
            self::storeRows('SELECT co.*, (SELECT COUNT(*) FROM customers c WHERE c.company_id = co.id AND c.store_id = co.store_id) AS customer_count
                FROM companies co WHERE co.store_id = :store ORDER BY co.legal_name, co.code'));
    }

    public static function company(int $id): ?array {
        return self::storeRows('SELECT * FROM companies WHERE id = :id AND store_id = :store', [':id' => $id])[0] ?? null;
    }

    public static function saveCompany(int $id, mixed $post): array {
        $old = $id > 0 ? self::company($id) : null;
        if ($id > 0 && !$old) return self::result(false, 0, 'Firma neexistuje v tomto obchode.');
        $fields = self::scalars($post, ['code', 'legal_name', 'registration_number', 'vat_id', 'approval_status', 'credit_limit', 'payment_terms_days', 'invoice_purchase', 'active']);
        if ($fields === null) return self::result(false, $id, 'Neplatný formulár.');
        try {
            $name = self::text($fields['legal_name'], true, 'Obchodné meno');
            $code = self::code($fields['code'], $name);
            if (self::storeRows('SELECT 1 AS taken FROM companies WHERE store_id = :store AND code = :code AND id != :id', [':code' => $code, ':id' => $id ?: -1])) {
                self::fail("Kód firmy \"{$code}\" už v tomto obchode existuje.");
            }
            $registration = trim($fields['registration_number']);
            if ($registration !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/-]{1,31}$/', $registration)) self::fail('IČO / registračné číslo: 2 až 32 znakov (písmená, číslice, medzera, . / -).');
            $vatId = strtoupper(preg_replace('/[\s.-]+/', '', $fields['vat_id']));
            if ($vatId !== '' && !preg_match('/^[A-Z]{2}[A-Z0-9+*]{2,13}$/', $vatId)) self::fail('IČ DPH musí začínať kódom krajiny (napr. SK2020123456).');
            $approval = $fields['approval_status'] === '' ? 'pending' : $fields['approval_status'];
            if (!isset(self::APPROVAL[$approval])) self::fail('Stav schválenia musí byť ' . implode(', ', array_keys(self::APPROVAL)) . '.');
            $terms = self::int($fields['payment_terms_days'] === '' ? '0' : $fields['payment_terms_days'], 0, 365, 'Splatnosť musí byť 0 až 365 dní.');
            $invoice = self::flag($fields['invoice_purchase']);
            if ($invoice && $terms < 1) self::fail('Nákup na faktúru vyžaduje splatnosť aspoň 1 deň.');
            $row = [
                'code' => $code, 'legal_name' => $name, 'registration_number' => $registration === '' ? null : $registration,
                'vat_id' => $vatId === '' ? null : $vatId, 'vat_country' => $vatId === '' ? null : substr($vatId, 0, 2),
                // A different VAT ID has not been validated yet.
                'vat_validated_at' => $old && $vatId !== '' && $vatId === (string)$old['vat_id'] ? $old['vat_validated_at'] : null,
                'approval_status' => $approval,
                'credit_limit' => self::int($fields['credit_limit'] === '' ? '0' : $fields['credit_limit'], 0, self::MAX_INT, 'Kreditný limit musí byť nezáporná suma v centoch (0 = bez limitu).'),
                'payment_terms_days' => $terms, 'invoice_purchase' => $invoice, 'active' => self::flag($fields['active']),
            ];
        } catch (DomainException $e) {
            return self::result(false, $id, $e->getMessage());
        }
        $message = $old ? 'Firma uložená.' : 'Firma vytvorená.';
        if ($invoice && $approval !== 'approved') $message .= ' Nákup na faktúru sa ponúkne až po schválení.';
        $id = self::write('companies', 'company', $old, $id, $row, $message, "Kód firmy \"{$row['code']}\" už v tomto obchode existuje.");
        return is_array($id) ? $id : self::result(true, $id, $message);
    }

    public static function deleteCompany(int $id): array {
        $company = $id > 0 ? self::company($id) : null;
        if (!$company) return self::result(false, 0, 'Firma neexistuje v tomto obchode.');
        $members = (int)(self::storeRows('SELECT COUNT(*) AS n FROM customers WHERE store_id = :store AND company_id = :id', [':id' => $id])[0]['n'] ?? 0);
        if ($members > 0) return self::result(false, $id, "Firmu {$company['code']} nemožno zmazať: má priradených zákazníkov ({$members}). Najprv ich odpojte alebo firmu deaktivujte.");
        return self::remove('companies', 'company', $company, 'Firma zmazaná.');
    }

    /** Current group and company of a customer of this store (null when the customer is foreign). */
    public static function customerAssignment(int $customerId): ?array {
        $row = self::storeRows('SELECT customer_group_id, company_id FROM customers WHERE id = :id AND store_id = :store', [':id' => $customerId])[0] ?? null;
        return $row === null ? null : ['customer_group_id' => $row['customer_group_id'] === null ? null : (int)$row['customer_group_id'],
            'company_id' => $row['company_id'] === null ? null : (int)$row['company_id']];
    }

    public static function assignCustomer(int $customerId, mixed $post): array {
        $old = $customerId > 0 ? self::customerAssignment($customerId) : null;
        if ($old === null) return self::result(false, 0, 'Zákazník neexistuje v tomto obchode.');
        $fields = self::scalars($post, ['customer_group_id', 'company_id']);
        if ($fields === null) return self::result(false, $customerId, 'Neplatný formulár.');
        $new = [];
        foreach (['customer_group_id' => ['group', 'Zákaznícka skupina'], 'company_id' => ['company', 'Firma']] as $column => [$kind, $label]) {
            $value = trim($fields[$column]);
            if ($value === '' || $value === '0') { $new[$column] = null; continue; }
            $row = ctype_digit($value) ? ($kind === 'group' ? self::group((int)$value) : self::company((int)$value)) : null;
            if ($row === null) return self::result(false, $customerId, "{$label} #{$value} nepatrí do tohto obchodu.");
            $new[$column] = (int)$row['id'];
        }
        if ($new === $old) return self::result(true, $customerId, 'Bez zmeny.');
        $db = Db::get();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE customers SET customer_group_id = :group, company_id = :company WHERE id = :id AND store_id = :store')
                ->execute([':group' => $new['customer_group_id'], ':company' => $new['company_id'], ':id' => $customerId, ':store' => StoreContext::current()->id()]);
            AuditLog::log('customer', $customerId, $old, $new, 'Admin B2B assignment');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::result(true, $customerId, 'Priradenie k skupine a firme uložené.');
    }

    /** Insert or update one store-owned row and audit it; returns the id, or a failed result on a unique-key race. */
    private static function write(string $table, string $entity, ?array $old, int $id, array $row, string $note, string $duplicate): int|array {
        $params = array_combine(array_map(static fn(string $k): string => ":{$k}", array_keys($row)), $row) + [':store' => StoreContext::current()->id()];
        $db = Db::get();
        $db->beginTransaction();
        try {
            if ($old) {
                $set = implode(', ', array_map(static fn(string $k): string => "{$k} = :{$k}", array_keys($row)));
                $db->prepare("UPDATE {$table} SET {$set}" . ($table === 'companies' ? ', updated_at = ' . Db::now() : '') . ' WHERE id = :id AND store_id = :store')->execute($params + [':id' => $id]);
            } else {
                $db->prepare("INSERT INTO {$table} (store_id, " . implode(', ', array_keys($row)) . ') VALUES (:store, ' . implode(', ', array_keys(array_slice($params, 0, count($row)))) . ')')->execute($params);
                $id = (int)$db->lastInsertId();
            }
            AuditLog::log($entity, $id, $old, $row, "Admin {$entity}: {$note}");
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            if ($e instanceof PDOException && str_starts_with((string)$e->getCode(), '23')) return self::result(false, $old ? $id : 0, $duplicate);
            throw $e;
        }
        return $id;
    }

    private static function remove(string $table, string $entity, array $row, string $message): array {
        $db = Db::get();
        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM {$table} WHERE id = :id AND store_id = :store")->execute([':id' => (int)$row['id'], ':store' => StoreContext::current()->id()]);
            AuditLog::log($entity, (int)$row['id'], $row, null, "Admin {$entity} delete");
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return self::result(true, 0, $message);
    }

    private static function storeRows(string $sql, array $params = []): array {
        $stmt = Db::get()->prepare($sql);
        $stmt->execute($params + [':store' => StoreContext::current()->id()]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** "12,5" → 1250 basis points; empty = 0; 0 to 100 % with at most two decimals. @throws DomainException */
    private static function percentBps(string $value): int {
        $value = trim($value);
        if ($value === '') return 0;
        if (!preg_match('/^(\d{1,3})(?:[.,](\d{1,2}))?$/', $value, $m)) self::fail('Zľava skupiny v % musí byť číslo od 0 do 100 (najviac dve desatinné miesta).');
        $bps = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if ($bps > 10000) self::fail('Zľava skupiny v % musí byť číslo od 0 do 100 (najviac dve desatinné miesta).');
        return $bps;
    }

    public static function bpsToPercent(int $bps): string {
        $fraction = rtrim(sprintf('%02d', $bps % 100), '0');
        return intdiv($bps, 100) . ($fraction === '' ? '' : ',' . $fraction);
    }

    /** @throws DomainException */
    private static function int(string $value, int $min, int $max, string $message): int {
        $value = trim($value);
        if (!preg_match('/^-?\d{1,10}$/', $value) || (int)$value < $min || (int)$value > $max) self::fail($message);
        return (int)$value;
    }

    /** @throws DomainException */
    private static function text(string $value, bool $required, string $label): string {
        $value = trim($value);
        if (($value === '' && $required) || mb_strlen($value) > self::MAX_TEXT || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            self::fail("{$label} je povinné (najviac " . self::MAX_TEXT . ' znakov, bez riadiacich znakov).');
        }
        return $value;
    }

    /** @throws DomainException */
    private static function code(string $code, string $name): string {
        $code = Str::slug(trim($code) !== '' ? $code : $name);
        if ($code === '' || strlen($code) > self::MAX_TEXT) self::fail('Kód musí obsahovať písmená alebo číslice (najviac ' . self::MAX_TEXT . ' znakov).');
        return $code;
    }

    private static function flag(string $value): int {
        return $value !== '' && $value !== '0' ? 1 : 0;
    }

    /** @throws DomainException */
    private static function fail(string $message): never {
        throw new DomainException($message);
    }

    private static function result(bool $ok, int $id, string $message): array {
        return ['ok' => $ok, 'id' => $id, 'message' => $message];
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
