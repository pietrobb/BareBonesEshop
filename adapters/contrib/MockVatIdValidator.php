<?php
/**
 * MockVatIdValidator — deterministic, used in tests and dev.
 *
 * Behaviour:
 *   - VAT IDs in $valid_set        → status='valid'
 *   - VAT IDs in $invalid_set      → status='invalid'
 *   - VAT IDs in $unverified_set   → status='unverified' (simulates service down)
 *   - All others                   → status='valid' if length-shape OK, else 'invalid'
 */
final class MockVatIdValidator implements VatIdValidator
{
    /** @var array<string,array> */
    private array $overrides = [];

    public function __construct(array $overrides = [])
    {
        // Normalize keys to upper, no spaces
        foreach ($overrides as $k => $v) {
            $this->overrides[strtoupper(preg_replace('/\s+/', '', $k))] = $v;
        }
    }

    public function check(string $vatId): array
    {
        $vatId = strtoupper(preg_replace('/\s+/', '', $vatId));
        if (isset($this->overrides[$vatId])) {
            $o = $this->overrides[$vatId];
            return [
                'status'      => $o['status'] ?? 'valid',
                'country'     => $o['country'] ?? substr($vatId, 0, 2),
                'trader_name' => $o['trader_name'] ?? null,
                'trader_addr' => $o['trader_addr'] ?? null,
                'raw'         => $o['raw'] ?? null,
            ];
        }

        // Default heuristic — country prefix + at least 8 digits/letters
        $valid = (bool)preg_match('/^[A-Z]{2}[0-9A-Z]{6,14}$/', $vatId);
        return [
            'status'      => $valid ? 'valid' : 'invalid',
            'country'     => $valid ? substr($vatId, 0, 2) : null,
            'trader_name' => null,
            'trader_addr' => null,
            'raw'         => null,
        ];
    }
}
