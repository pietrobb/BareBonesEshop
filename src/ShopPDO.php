<?php
/**
 * The shop's connection class. On SQLite a transaction takes the write lock when it begins (BEGIN IMMEDIATE):
 * the shop's transactions read, then write, and a deferred one that read while another connection wrote can
 * never upgrade its stale snapshot — it fails with "database is locked" at once, busy_timeout does not wait.
 * An immediate transaction waits for the writer up to busy_timeout instead.
 */
class ShopPDO extends PDO {
    public function beginTransaction(): bool {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return parent::beginTransaction();
        if ($this->inTransaction()) throw new PDOException('There is already an active transaction');
        return $this->exec('BEGIN IMMEDIATE') !== false;
    }
}
