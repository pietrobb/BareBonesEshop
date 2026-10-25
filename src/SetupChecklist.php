<?php
declare(strict_types=1);

/**
 * The `cli/setup.php doctor` checks for the admin dashboard: what still needs attention, each with the admin page that
 * fixes it (or the doctor's remediation when only config.php or the server can).
 */
final class SetupChecklist
{
    /** Check-name prefix => admin page that fixes it (longest prefix wins). */
    private const FIX_PAGES = [
        'stores.currency_prices.' => 'price_lists',
        'stores.' => 'stores',
        'payment.' => 'payments',
        'admin.' => 'admin_users',
        'provider.shipping' => 'shipping',
    ];

    /**
     * @return array{counts: array{fail: int, warn: int}, items: list<array{status: string, name: string, message: string, action: string, page: ?string}>}
     */
    public static function summary(?array $doctor = null): array
    {
        try {
            $doctor ??= Setup::doctor(Security::config());
        } catch (Throwable $e) {
            Logger::warning('Setup checklist: doctor failed', ['exception' => get_class($e)]);
            $doctor = ['checks' => [['status' => 'fail', 'name' => 'doctor', 'message' => 'The setup doctor could not run.', 'action' => 'Run php cli/setup.php doctor on the server.']]];
        }
        $items = [];
        $counts = ['fail' => 0, 'warn' => 0];
        foreach ((array)($doctor['checks'] ?? []) as $check) {
            $status = (string)($check['status'] ?? '');
            if (!isset($counts[$status])) continue;
            $counts[$status]++;
            $name = (string)($check['name'] ?? '');
            $items[] = ['status' => $status, 'name' => $name, 'message' => (string)($check['message'] ?? ''),
                'action' => (string)($check['action'] ?? ''), 'page' => self::fixPage($name)];
        }
        usort($items, static fn(array $a, array $b): int => [$a['status'] !== 'fail', $a['name']] <=> [$b['status'] !== 'fail', $b['name']]);
        return ['counts' => $counts, 'items' => $items];
    }

    public static function fixPage(string $checkName): ?string
    {
        foreach (self::FIX_PAGES as $prefix => $page) {
            if (str_starts_with($checkName, $prefix)) return $page;
        }
        return null;
    }
}
