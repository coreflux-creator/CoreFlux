<?php
/**
 * Core\CsvImportService — single platform-wide CSV import primitive.
 *
 * Per HARD_RULES (2026-02-XX): every module that owns a primary entity
 * (people, placements, clients, vendors, time entries, etc.) MUST expose
 * a CSV import flow built on this service. Modules MUST NOT roll their own.
 *
 * ## How modules use it
 *
 *   1. Module declares an import schema (column → field, types, validators)
 *      via CsvImportService::registerSchema('people', [...]).
 *   2. Module's API endpoint (POST /api/<module>/csv_import) calls:
 *        a. CsvImportService::buildTemplate('people') → CSV string
 *        b. CsvImportService::dryRun('people', $rawCsv)  → preview rows + errors
 *        c. CsvImportService::commit('people', $rawCsv, $onRowCallback) → real insert
 *   3. The $onRowCallback is module-owned: takes a validated row array,
 *      writes to the module's DB tables, returns id (or throws).
 *
 * ## Design constraints
 *
 *   - Stateless. No DB tables of its own. Modules own persistence.
 *   - Streaming-safe for large files (uses fgetcsv, not file_get_contents).
 *   - Tenant scoping is the caller's responsibility (this is core plumbing).
 *   - Always dry-run first; commit re-runs validation row-by-row.
 *   - First row of CSV is always the header. Column order matches template.
 *
 * ## Schema shape
 *
 *   [
 *     'fields' => [
 *       'first_name'    => ['label' => 'First name', 'required' => true],
 *       'classification'=> ['label' => 'Classification', 'required' => true,
 *                           'enum'  => ['w2','1099','c2c','temp','perm','candidate','alumni']],
 *       'email_primary' => ['label' => 'Primary email', 'required' => true,
 *                           'type'  => 'email'],
 *       'work_auth_expiry' => ['label' => 'Work auth expiry', 'type' => 'date'],
 *     ],
 *     'unique_within_batch' => ['email_primary'],
 *   ]
 */

namespace Core;

class CsvImportService
{
    /** @var array<string, array> module => schema */
    private static array $schemas = [];

    public static function registerSchema(string $module, array $schema): void
    {
        self::$schemas[$module] = $schema;
    }

    public static function getSchema(string $module): ?array
    {
        return self::$schemas[$module] ?? null;
    }

    /**
     * Generate an empty CSV template (header row only) for a module.
     * Each header is the field's `label` if set, else the field key.
     */
    public static function buildTemplate(string $module): string
    {
        $schema = self::getSchema($module);
        if (!$schema) throw new \InvalidArgumentException("No CSV schema registered for module '{$module}'");
        $headers = [];
        foreach ($schema['fields'] as $key => $def) {
            $headers[] = $def['label'] ?? $key;
        }
        $fp = fopen('php://temp', 'w+');
        fputcsv($fp, $headers);
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return $csv;
    }

    /**
     * Parse CSV into structured rows. Returns:
     *   ['rows' => array<int, array<field => value>>, 'errors' => array<int, list<string>>,
     *    'header_map' => array, 'row_count' => int]
     *
     * Validation runs:
     *   - required fields present
     *   - enum membership
     *   - email / date / number coercion
     *   - cross-row uniqueness (within the file) for declared keys
     */
    public static function dryRun(string $module, string $rawCsv): array
    {
        $schema = self::getSchema($module);
        if (!$schema) throw new \InvalidArgumentException("No CSV schema registered for module '{$module}'");

        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $rawCsv);
        rewind($stream);

        $headers = fgetcsv($stream);
        if (!$headers) {
            return ['rows' => [], 'errors' => [0 => ['CSV is empty or unreadable']], 'header_map' => [], 'row_count' => 0];
        }
        // Map header label → field_key (case-insensitive).
        $labelToKey = [];
        foreach ($schema['fields'] as $key => $def) {
            $labelToKey[strtolower(trim($def['label'] ?? $key))] = $key;
            $labelToKey[strtolower($key)] = $key; // tolerate raw key as header
        }
        $headerMap = [];
        foreach ($headers as $i => $h) {
            $hk = strtolower(trim((string) $h));
            if (isset($labelToKey[$hk])) $headerMap[$i] = $labelToKey[$hk];
        }

        $rows   = [];
        $errors = [];
        $seenForUnique = [];
        foreach (($schema['unique_within_batch'] ?? []) as $k) $seenForUnique[$k] = [];

        $rowNum = 1; // 1-indexed (header is row 1; data rows start at 2)
        while (($cells = fgetcsv($stream)) !== false) {
            $rowNum++;
            // Skip totally blank rows
            if (count(array_filter($cells, fn($c) => $c !== null && $c !== '')) === 0) continue;

            $row = [];
            $rowErrors = [];
            foreach ($cells as $i => $value) {
                $field = $headerMap[$i] ?? null;
                if (!$field) continue;
                $row[$field] = trim((string) $value);
            }

            // Required + type checks
            foreach ($schema['fields'] as $field => $def) {
                $val = $row[$field] ?? '';
                if (!empty($def['required']) && $val === '') {
                    $rowErrors[] = "{$field}: required";
                    continue;
                }
                if ($val === '') continue;

                if (!empty($def['enum']) && !in_array($val, $def['enum'], true)) {
                    $rowErrors[] = "{$field}: invalid value '{$val}', expected one of: " . implode(',', $def['enum']);
                }
                $type = $def['type'] ?? 'text';
                if ($type === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $rowErrors[] = "{$field}: invalid email '{$val}'";
                }
                if ($type === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                    // tolerate mm/dd/yyyy
                    $ts = strtotime($val);
                    if (!$ts) $rowErrors[] = "{$field}: invalid date '{$val}', expected YYYY-MM-DD";
                    else      $row[$field] = date('Y-m-d', $ts);
                }
                if ($type === 'number' && !is_numeric($val)) {
                    $rowErrors[] = "{$field}: not numeric '{$val}'";
                }
                if ($type === 'boolean') {
                    $lc = strtolower($val);
                    if (in_array($lc, ['1','true','yes','y','t'], true))      $row[$field] = 1;
                    else if (in_array($lc, ['0','false','no','n','f',''], true)) $row[$field] = 0;
                    else $rowErrors[] = "{$field}: invalid boolean '{$val}'";
                }
            }

            // Uniqueness within the batch
            foreach (($schema['unique_within_batch'] ?? []) as $k) {
                $v = $row[$k] ?? null;
                if ($v === null || $v === '') continue;
                $vKey = is_string($v) ? strtolower($v) : $v;
                if (isset($seenForUnique[$k][$vKey])) {
                    $rowErrors[] = "{$k}: duplicate within file (also row {$seenForUnique[$k][$vKey]})";
                } else {
                    $seenForUnique[$k][$vKey] = $rowNum;
                }
            }

            $rows[$rowNum]   = $row;
            if ($rowErrors) $errors[$rowNum] = $rowErrors;
        }
        fclose($stream);

        return [
            'rows'        => $rows,
            'errors'      => $errors,
            'header_map'  => $headerMap,
            'row_count'   => count($rows),
            'error_count' => count($errors),
        ];
    }

    /**
     * Commit imports row-by-row. Re-runs dryRun first; aborts if any row has errors
     * UNLESS $opts['skip_invalid'] is true (then invalid rows are skipped, valid ones inserted).
     *
     * @param callable $onRow  fn(array $row): int  — module's writer; returns inserted id, or throws
     * @return array {imported_count, skipped_count, errors, ids}
     */
    public static function commit(string $module, string $rawCsv, callable $onRow, array $opts = []): array
    {
        $dry = self::dryRun($module, $rawCsv);

        $skipInvalid = !empty($opts['skip_invalid']);
        if (!$skipInvalid && $dry['error_count'] > 0) {
            return [
                'imported_count' => 0,
                'skipped_count'  => $dry['row_count'],
                'errors'         => $dry['errors'],
                'ids'            => [],
                'message'        => 'Validation errors present; commit aborted. Pass skip_invalid=1 to import valid rows only.',
            ];
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = $dry['errors'];
        $ids      = [];
        foreach ($dry['rows'] as $rowNum => $row) {
            if (isset($dry['errors'][$rowNum])) { $skipped++; continue; }
            try {
                $id = (int) $onRow($row);
                $ids[$rowNum] = $id;
                $imported++;
            } catch (\Throwable $e) {
                $errors[$rowNum] = $errors[$rowNum] ?? [];
                $errors[$rowNum][] = 'persist failed: ' . $e->getMessage();
                $skipped++;
            }
        }

        return [
            'imported_count' => $imported,
            'skipped_count'  => $skipped,
            'errors'         => $errors,
            'ids'            => $ids,
        ];
    }

    /**
     * Helper for endpoints: extract raw CSV from $_FILES['file'] OR JSON {csv: "..."}.
     */
    public static function readRequestCsv(): ?string
    {
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            return (string) file_get_contents($_FILES['file']['tmp_name']);
        }
        $raw = file_get_contents('php://input');
        if (!$raw) return null;
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['csv'])) return (string) $data['csv'];
        return $raw; // raw text/csv body
    }
}
