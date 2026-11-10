<?php
/**
 * Core\CsvExportService — single platform-wide CSV export primitive.
 *
 * Companion to Core\CsvImportService. Per HARD_RULES (2026-02-XX): every
 * primary-entity module must expose CSV export so tenants can move data
 * in and out of the platform without engineering involvement.
 *
 * ## How modules use it
 *
 *   $svc = new CsvExportService([
 *     'first_name'  => 'First name',
 *     'last_name'   => 'Last name',
 *     'email'       => 'Primary email',
 *   ]);
 *   $svc->stream($rowsIterable, 'people_export.csv');
 *
 * Or to return as string:
 *
 *   $csv = CsvExportService::toString($columns, $rows);
 *
 * Notes:
 *   - Streams via php://output so memory stays O(1) on huge exports.
 *   - Caller decides headers/permissions; this is core plumbing only.
 *   - Columns is an ordered map: field_key => label.
 *   - If a row is missing a column, the cell is empty string (no error).
 *   - Booleans serialised as 0/1; arrays as JSON; null as ''.
 */

namespace Core;

class CsvExportService
{
    /** @var array<string,string> field_key => label */
    private array $columns;

    public function __construct(array $columns)
    {
        if (!$columns) throw new \InvalidArgumentException('CsvExportService requires at least one column');
        $this->columns = $columns;
    }

    /**
     * Convert rows to a CSV string. Use for small/medium exports.
     * @param iterable<array<string,mixed>> $rows
     */
    public static function toString(array $columns, iterable $rows): string
    {
        $svc = new self($columns);
        $fp  = fopen('php://temp', 'w+');
        $svc->writeToStream($fp, $rows);
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }

    /**
     * Stream rows to php://output with CSV download headers.
     * Caller MUST NOT have already emitted output. Exits after streaming.
     * @param iterable<array<string,mixed>> $rows
     */
    public function stream(iterable $rows, string $filename = 'export.csv'): void
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename);
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$safe}\"");
        header('Cache-Control: no-store');
        $fp = fopen('php://output', 'w');
        $this->writeToStream($fp, $rows);
        fclose($fp);
        exit;
    }

    /** Write once to a caller-owned stream, without sending headers or exiting. */
    public function writeToStream($fp, iterable $rows): int
    {
        if (!is_resource($fp)) throw new \InvalidArgumentException('CSV output stream required');
        $this->writeHeader($fp);
        $count = 0;
        foreach ($rows as $row) {
            $this->writeRow($fp, $row);
            $count++;
        }
        return $count;
    }

    private function writeHeader($fp): void
    {
        if (fputcsv($fp, array_values($this->columns), ',', '"', '') === false) {
            throw new \RuntimeException('Could not write CSV header');
        }
    }

    private function writeRow($fp, array $row): void
    {
        $cells = [];
        foreach (array_keys($this->columns) as $field) {
            $v = $row[$field] ?? '';
            if (is_bool($v))          $v = $v ? 1 : 0;
            elseif (is_array($v))     $v = json_encode($v);
            elseif ($v === null)      $v = '';
            $cells[] = $v;
        }
        if (fputcsv($fp, $cells, ',', '"', '') === false) {
            throw new \RuntimeException('Could not write CSV row');
        }
    }
}
