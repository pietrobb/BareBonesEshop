<?php
/**
 * VatIdValidator adapter interface.
 *
 * Implementations:
 *   - VatIdValidator/Mock — deterministic, used in tests and dev.
 *   - VatIdValidator/Vies — calls EU VIES SOAP service.
 *
 * Result shape:
 *   ['status' => 'valid'|'invalid'|'unverified',
 *    'country' => string|null,    // ISO2 (echoed)
 *    'trader_name' => string|null,
 *    'trader_addr' => string|null,
 *    'raw' => string|null,        // raw response, kept as evidence (vat_id_checks)
 *    'request_identifier' => string|null]  // optional: registry consultation number (VIES requestIdentifier)
 *
 * Status semantics:
 *   - valid       → VAT ID confirmed by registry; cache 24h
 *   - invalid     → VAT ID definitively rejected; cache 24h
 *   - unverified  → service unreachable / timeout; cache 1h, retry-friendly
 */
interface VatIdValidator
{
    /**
     * @param string $vatId Full VAT ID with country prefix (e.g. "DE123456789")
     * @return array{status:string, country:string|null, trader_name:string|null, trader_addr:string|null, raw:string|null, request_identifier?:string|null}
     */
    public function check(string $vatId): array;
}
