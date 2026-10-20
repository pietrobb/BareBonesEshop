<?php
/**
 * The shop's connection class. On SQLite a transaction takes the write lock when it begins (BEGIN IMMEDIATE):
 * the shop's transactions read, then write, and a deferred one that read while another connection wrote can
 * never upgrade its stale snapshot — it fails with "database is locked" at once, busy_timeout does not wait.
 * An immediate transaction waits for the writer up to busy_timeout instead.
 *
 * PDO does not see a transaction opened with exec() before PHP 8.4 (inTransaction() is false, commit() throws
 * "no active transaction"), so the transaction this class opens is tracked here and closed with exec() too.
 */
class ShopPDO extends PDO {
    private bool $immediate = false;

    public function beginTransaction(): bool {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') return parent::beginTransaction();
        if ($this->inTransaction()) throw new PDOException('There is already an active transaction');
        if ($this->exec('BEGIN IMMEDIATE') === false) return false;
        $this->immediate = true;
        return true;
    }

    public function commit(): bool {
        if (!$this->immediate) return parent::commit();
        // A busy COMMIT leaves the transaction open: it stays ours to retry or roll back.
        if ($this->exec('COMMIT') === false) return false;
        $this->immediate = false;
        return true;
    }

    public function rollBack(): bool {
        if (!$this->immediate) return parent::rollBack();
        $this->immediate = false;
        // SQLite ends a transaction by itself after some errors (e.g. disk full): then there is nothing to undo.
        if (!$this->sqliteInTransaction()) return true;
        return $this->exec('ROLLBACK') !== false;
    }

    public function inTransaction(): bool {
        return $this->immediate || parent::inTransaction();
    }

    private function sqliteInTransaction(): bool {
        if (PHP_VERSION_ID >= 80400) return parent::inTransaction();
        // Before PHP 8.4 PDO cannot ask SQLite; BEGIN is refused only while a transaction is open.
        $mode = $this->getAttribute(PDO::ATTR_ERRMODE);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        try {
            $began = $this->exec('BEGIN') !== false;
        } finally {
            $this->setAttribute(PDO::ATTR_ERRMODE, $mode);
        }
        if ($began) $this->exec('ROLLBACK');
        return !$began;
    }
}
