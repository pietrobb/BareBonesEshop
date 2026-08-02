<?php
final class QueryCountingPDO extends PDO {
    private int $queryCount = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queryCount++;
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->queryCount++;
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false {
        $this->queryCount++;
        return parent::exec($statement);
    }

    public function resetQueryCount(): void {
        $this->queryCount = 0;
    }

    public function queryCount(): int {
        return $this->queryCount;
    }
}
