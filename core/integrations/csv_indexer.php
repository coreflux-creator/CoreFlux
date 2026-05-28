<?php
/**
 * /app/core/integrations/csv_indexer.php
 *
 * Generic CSV → integration_payload_field_index ingestor.
 *
 * Operator workflow:
 *   1. Export a JobDiva Job / Candidate / Customer / Contact list to CSV
 *      (or any integration whose REST endpoints we can't reach).
 *   2. Drop the CSV into the Field Mapping Studio's "Upload CSV" surface.
 *   3. We treat the header row as field names and every data row as an
 *      "indexable record" — exactly the same shape `mappingUpsert()`
 *      would have produced from a successful API sync.
 *   4. The Studio's entity-type dropdown immediately surfaces every
 *      column as a mappable path, with sample values from the real
 *      data, and the Auto-map suggester proposes targets.
 *
 * Stream-parses with fgetcsv() so memory stays bounded even on 100k-row
 * exports. UTF-8 BOM stripped automatically. Malformed rows skipped +
 * counted, not fatal — operators get a per-row error report at the end.
 */
declare(strict_types=1);

require_once __DIR__ . '/payload_field_index.php';

/**
 * Parse + index a CSV file as records for (tenant, integration,
 * entity_type). Returns a summary the API endpoint can hand back to
 * the UI.
 *
 * @return array{
 *   rows_seen:int, rows_indexed:int, rows_skipped:int,
 *   field_count:int, errors:array<int, string>, sample_headers:array<int, string>
 * }
 */
function csvIndexerIngest(
    int $tenantId,
    string $integration,
    string $entityType,
    string $filePath,
    int $maxRows = 100000
): array {
    $summary = [
        'rows_seen'      => 0,
        'rows_indexed'   => 0,
        'rows_skipped'   => 0,
        'field_count'    => 0,
        'errors'         => [],
        'sample_headers' => [],
    ];
    if ($tenantId <= 0 || $integration === '' || $entityType === '') {
        $summary['errors'][] = 'tenant + integration + entity_type are required';
        return $summary;
    }
    if (!is_readable($filePath)) {
        $summary['errors'][] = 'csv file not readable';
        return $summary;
    }

    $h = fopen($filePath, 'rb');
    if (!$h) {
        $summary['errors'][] = 'fopen failed';
        return $summary;
    }

    // 1) Header row — strip BOM, normalise whitespace.
    $headers = fgetcsv($h);
    if (!is_array($headers) || empty($headers)) {
        fclose($h);
        $summary['errors'][] = 'no header row found';
        return $summary;
    }
    $headers = array_map(function ($cell) {
        $cell = (string) $cell;
        // Strip UTF-8 BOM on the very first cell.
        if (str_starts_with($cell, "\xEF\xBB\xBF")) $cell = substr($cell, 3);
        return trim($cell);
    }, $headers);
    // Drop trailing empty header columns (common from Excel exports).
    while (count($headers) > 0 && end($headers) === '') array_pop($headers);
    if (count($headers) === 0) {
        fclose($h);
        $summary['errors'][] = 'header row was entirely empty';
        return $summary;
    }
    $summary['field_count']    = count($headers);
    $summary['sample_headers'] = array_slice($headers, 0, 20);

    // 2) Data rows — stream parse, index each as an associative record.
    $rowNum = 1; // header was line 1
    while (($row = fgetcsv($h)) !== false) {
        $rowNum++;
        $summary['rows_seen']++;
        if ($summary['rows_indexed'] >= $maxRows) {
            $summary['errors'][] = "stopped at row {$rowNum} — maxRows={$maxRows} reached";
            break;
        }
        if ($row === [null] || $row === false) { $summary['rows_skipped']++; continue; }
        // Skip rows that are entirely empty (Excel trailing newlines).
        $allEmpty = true;
        foreach ($row as $v) { if (trim((string) $v) !== '') { $allEmpty = false; break; } }
        if ($allEmpty) { $summary['rows_skipped']++; continue; }

        // Pad / truncate row to match header count so combine doesn't crash.
        if (count($row) < count($headers)) {
            $row = array_pad($row, count($headers), null);
        } elseif (count($row) > count($headers)) {
            $row = array_slice($row, 0, count($headers));
        }

        $record = [];
        foreach ($headers as $i => $colName) {
            if ($colName === '') continue; // unnamed columns skipped
            $v = $row[$i] ?? null;
            if (is_string($v)) $v = trim($v);
            if ($v === '') $v = null;
            $record[$colName] = $v;
        }
        if (empty($record)) { $summary['rows_skipped']++; continue; }

        try {
            integrationPayloadFieldIndexRecord($tenantId, $integration, $entityType, $record);
            $summary['rows_indexed']++;
        } catch (\Throwable $e) {
            $summary['rows_skipped']++;
            if (count($summary['errors']) < 20) {
                $summary['errors'][] = "row {$rowNum}: " . $e->getMessage();
            }
        }
    }
    fclose($h);
    return $summary;
}
