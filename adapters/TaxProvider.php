<?php
/**
 * Canonical tax provider contract used to freeze checkout financial snapshots.
 */
interface TaxProvider
{
    public static function rate(string $country, string $class = 'standard', ?string $atDate = null): float;

    public static function resolveScenario(array $ctx): array;

    public static function computeLine(int $unitGrossCents, int $qty, float $rate, bool $reverse): array;

    public static function buildSnapshot(array $items, array $context, array $amounts = []): array;

    public static function validateSnapshot(array $snapshot): void;
}
