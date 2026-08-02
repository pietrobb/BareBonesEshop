<?php
/**
 * BackupStorage — abstrakcia pre úložisko záloh.
 *
 * Adaptér riešia iba IO; integritu (sha256, integrity_check) overuje BackupService.
 */
interface BackupStorage
{
    /** Skopíruje lokálny súbor do úložiska pod $remotePath. Meta JSON sa zapisuje vedľa súboru. */
    public function put(string $localPath, string $remotePath, array $meta): void;

    /** Stiahne $remotePath do lokálneho $localPath. */
    public function get(string $remotePath, string $localPath): void;

    /** Vymaže súbor + jeho meta JSON (a .gz ak existuje). */
    public function delete(string $remotePath): void;

    /**
     * Zoznam záloh pod prefixom; každý prvok = ['path' => 'YYYY/MM/DD/eshop-...db', 'meta' => array, 'mtime' => int].
     * Zoradené od najnovšej po najstaršiu.
     */
    public function list(string $prefix = ''): array;

}
