<?php
/** A streamed CSV export failed after its header row (see CsvExport). */
final class CsvExportTruncated extends RuntimeException
{
    public function __construct(public readonly string $export, public readonly int $rowsWritten, Throwable $previous)
    {
        parent::__construct("Export {$export} truncated after {$rowsWritten} rows", 0, $previous);
    }
}
