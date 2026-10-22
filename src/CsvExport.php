<?php
/**
 * Streams an unbuffered result set (Db::executeUnbuffered) into a CSV handle.
 *
 * A streamed export can fail half-way (MySQL net_write_timeout on a slow download, a lost
 * connection). A marker row would be read as data by CSV parsers, so a failure after the header
 * row is made visible instead by: a log entry `export.truncated` and an `export.truncated`
 * incident (with the number of rows written), then CsvExportTruncated. The HTTP handler answers
 * 500 when nothing was sent yet; once the response has started its status cannot change, so the
 * incident (admin operations page / alert mail) is the signal that the downloaded file is incomplete.
 */
final class CsvExport
{
    /**
     * @param resource $out
     * @param list<string> $header
     * @param callable(array<string,mixed>): list<mixed> $mapRow database row to CSV cells (already escaped)
     * @return int rows written
     * @throws CsvExportTruncated when the result set fails after the header row was written
     */
    public static function stream($out, PDOStatement $stmt, array $header, callable $mapRow, string $export): int
    {
        fputcsv($out, $header, ',', '"', '');
        $count = 0;
        try {
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                fputcsv($out, $mapRow($row), ',', '"', '');
                $count++;
            }
        } catch (PDOException $e) {
            try { $stmt->closeCursor(); } catch (Throwable) {}
            Logger::safeWrite('error', 'export.truncated', ['export' => $export, 'rows' => $count, 'error' => $e->getMessage()]);
            IncidentMonitor::report('export.truncated', 'export', 'error', ['count' => $count, 'exception' => $e]);
            throw new CsvExportTruncated($export, $count, $e);
        }
        return $count;
    }

    /** For HTTP handlers: a 500 when the response has not started yet; otherwise nothing more can be sent. */
    public static function abortResponse(): void
    {
        if (headers_sent()) return;
        while (ob_get_level() > 0) ob_end_clean();
        header_remove('Content-Disposition');
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Export failed; see the operations incidents.\n";
    }
}
