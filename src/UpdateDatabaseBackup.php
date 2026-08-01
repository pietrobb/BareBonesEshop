<?php

interface UpdateDatabaseBackup
{
    public function probe(string $workingDirectory): array;

    public function create(string $snapshotDirectory): array;

    public function restore(string $snapshotDirectory, array $metadata): void;
}
