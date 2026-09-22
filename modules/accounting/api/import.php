<?php
/**
 * Accounting API — CSV ledger imports.
 *
 *   GET  /api/accounting/import?action=template&type=coa|je|periods
 *   POST /api/accounting/import?action=dry_run&type=coa|je|periods   body: {csv: "..."}
 *   POST /api/accounting/import?action=commit&type=coa|je|periods   body: {csv: "...", skip_invalid?: 1}
 *
 * Row writers:
 *   - coa     → INSERT or UPDATE accounting_accounts by (tenant_id, code)
 *   - je      → accountingPostJe() with idempotency_key 'csv:<batch>:<row>'
 *               (prevents double-post on retry)
 *   - periods → UPDATE accounting_periods status by (tenant_id, entity_id, start_date)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/dimensions.php';
require_once __DIR__ . '/../../staffing/lib/dimensions.php';

use Core\CsvImportService;

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');
$type   = (string) ($_GET['type']   ?? '');

rbac_legacy_require($user, 'accounting.coa.manage');

// ── Register accounting import schemas (one per type) ────────────────────
CsvImportService::registerSchema('accounting_coa', [
    'fields' => [
        'code'              => ['label' => 'Code',              'required' => true],
        'name'              => ['label' => 'Name',              'required' => true],
        'account_type'      => ['label' => 'Account type',      'required' => true,
                                 'enum' => ['asset','liability','equity','revenue','expense']],
        'normal_side'       => ['label' => 'Normal side',       'required' => true, 'enum' => ['debit','credit']],
        'parent_account_id' => ['label' => 'Parent account id', 'type' => 'number'],
        'is_postable'       => ['label' => 'Is postable',       'type' => 'boolean'],
        'currency'          => ['label' => 'Currency'],
        'cash_flow_tag'     => ['label' => 'Cash flow tag',
                                 'enum' => ['operating','investing','financing','cash_and_equivalents','']],
        'description'       => ['label' => 'Description'],
        'active'            => ['label' => 'Active',            'type' => 'boolean'],
    ],
    'unique_within_batch' => ['code'],
]);

CsvImportService::registerSchema('accounting_je', [
    'fields' => [
        'batch_ref'    => ['label' => 'Batch ref',    'required' => true],
        'posting_date' => ['label' => 'Posting date', 'required' => true, 'type' => 'date'],
        'memo'         => ['label' => 'Memo'],
        'account_code' => ['label' => 'Account code', 'required' => true],
        'debit'        => ['label' => 'Debit',        'type' => 'number'],
        'credit'       => ['label' => 'Credit',       'type' => 'number'],
        'line_memo'    => ['label' => 'Line memo'],
        'entity_id'    => ['label' => 'Entity id',    'required' => true, 'type' => 'number'],
        'client'       => ['label' => 'Client dimension'],
        'placement'    => ['label' => 'Assignment dimension'],
        'worker'       => ['label' => 'Worker dimension'],
        'job'          => ['label' => 'Job dimension'],
        'recruiter'    => ['label' => 'Recruiter dimension'],
        'account_manager' => ['label' => 'Account manager dimension'],
        'branch'       => ['label' => 'Branch dimension'],
        'service_line' => ['label' => 'Service line dimension'],
        'work_state'   => ['label' => 'Work state dimension'],
        'wc_class'     => ['label' => 'WC class dimension'],
        'department'   => ['label' => 'Department dimension'],
        'cost_center'  => ['label' => 'Cost center dimension'],
        'vendor'       => ['label' => 'Vendor dimension'],
        'counterparty_entity' => ['label' => 'Counterparty entity dimension'],
    ],
    // batch_ref groups rows into a single JE; uniqueness is across (batch_ref + line).
]);

CsvImportService::registerSchema('accounting_periods', [
    'fields' => [
        'entity_id'     => ['label' => 'Entity id',     'required' => true, 'type' => 'number'],
        'period_number' => ['label' => 'Period number', 'required' => true, 'type' => 'number'],
        'start_date'    => ['label' => 'Start date',    'required' => true, 'type' => 'date'],
        'end_date'      => ['label' => 'End date',      'required' => true, 'type' => 'date'],
        'status'        => ['label' => 'Status',        'required' => true,
                             'enum' => ['future','open','soft_closed','closed','reopened']],
    ],
]);

$schemaByType = [
    'coa'     => 'accounting_coa',
    'je'      => 'accounting_je',
    'periods' => 'accounting_periods',
];
$schemaKey = $schemaByType[$type] ?? null;
if (!$schemaKey) api_error('Unknown import type. Use coa|je|periods.', 422);

// ── action=template ──────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'template') {
    $csv = CsvImportService::buildTemplate($schemaKey);
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="accounting-' . $type . '-template.csv"');
    }
    echo $csv;
    exit;
}

// ── action=dry_run / commit (POST) ───────────────────────────────────────
if ($method !== 'POST' || !in_array($action, ['dry_run','commit'], true)) {
    api_error('Method not allowed', 405);
}
$requestBody = [];
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (str_contains($contentType, 'application/json')) {
    $requestBody = api_json_body();
}
$raw = array_key_exists('csv', $requestBody)
    ? (string) $requestBody['csv']
    : CsvImportService::readRequestCsv();
if ($raw === null || $raw === '') api_error('Missing CSV payload', 422);

$defaultBatchRef = trim((string) ($requestBody['default_batch_ref'] ?? ''));
if (strlen($defaultBatchRef) > 120) {
    api_error('Default batch reference must be 120 characters or fewer.', 422);
}
$defaults = $type === 'je' && $defaultBatchRef !== ''
    ? ['batch_ref' => $defaultBatchRef]
    : [];

/**
 * Normalize journal rows into batches and hydrate assignment-owned dimensions.
 * The same preparation runs during preview and commit so an import can never
 * look valid in the UI and then fail for a newly discovered structural issue.
 */
function accountingPrepareJeImport(int $tenantId, array $dry): array
{
    $dimensionKeys = [
        'client','placement','worker','job','recruiter','account_manager',
        'branch','service_line','work_state','wc_class','department',
        'cost_center','vendor','counterparty_entity',
    ];
    $grouped = [];
    $batchErrors = [];
    $assignmentContextCache = [];
    foreach ($dry['rows'] as $rowNum => $row) {
        if (isset($dry['errors'][$rowNum])) continue;
        $batch = (string) $row['batch_ref'];
        $rowEntityId = !empty($row['entity_id']) ? (int) $row['entity_id'] : null;
        if (!isset($grouped[$batch])) {
            $grouped[$batch] = [
                'posting_date' => $row['posting_date'],
                'memo' => $row['memo'] ?? null,
                'entity_id' => $rowEntityId,
                'lines' => [],
            ];
        } else {
            if ((string) $grouped[$batch]['posting_date'] !== (string) $row['posting_date']) {
                $batchErrors[$batch][] = 'all rows must use the same posting date';
            }
            if ($rowEntityId !== null && (int) $grouped[$batch]['entity_id'] !== $rowEntityId) {
                $batchErrors[$batch][] = 'all rows must use the same entity id';
            }
        }

        $dims = [];
        foreach ($dimensionKeys as $dimensionKey) {
            $value = $row[$dimensionKey] ?? null;
            if ($value !== null && trim((string) $value) !== '') $dims[$dimensionKey] = $value;
        }
        if (!empty($dims['placement']) && $rowEntityId) {
            $placementId = (int) preg_replace('/^PL-/i', '', trim((string) $dims['placement']));
            if ($placementId <= 0) {
                $batchErrors[$batch][] = "row {$rowNum}: assignment dimension must be an internal ID such as PL-123";
            } else {
                $cacheKey = $rowEntityId . ':' . (string) $row['posting_date'] . ':' . $placementId;
                try {
                    if (!isset($assignmentContextCache[$cacheKey])) {
                        $assignmentContextCache[$cacheKey] = staffingAssignmentDimensionContext(
                            $tenantId,
                            $placementId,
                            $rowEntityId,
                            (string) $row['posting_date']
                        );
                    }
                    $assignmentContext = $assignmentContextCache[$cacheKey];
                    $resolvedEntityId = (int) ($assignmentContext['event_entity_id'] ?? 0);
                    if ($resolvedEntityId !== $rowEntityId) {
                        $batchErrors[$batch][] = "row {$rowNum}: assignment PL-{$placementId} belongs to entity {$resolvedEntityId}, not {$rowEntityId}";
                    }
                    $requiredDimensions = accountingRequiredDimensionKeysForAccountCodes(
                        $tenantId,
                        [(string) ($row['account_code'] ?? '')]
                    );
                    $blockingMissing = staffingDimensionMissingForRequirements(
                        $assignmentContext,
                        $requiredDimensions,
                        $dims
                    );
                    if ($blockingMissing) {
                        $batchErrors[$batch][] = "row {$rowNum}: assignment PL-{$placementId} is missing "
                            . implode(', ', staffingDimensionMissingLabels($blockingMissing));
                    }
                    $inheritedDimensions = (array) ($assignmentContext['dimensions'] ?? []);
                    if (!empty($assignmentContext['vendor_dimension'])) {
                        $inheritedDimensions['vendor'] = $assignmentContext['vendor_dimension'];
                    }
                    // The assignment master is authoritative for inherited
                    // axes. Explicit CSV values remain available for dimensions
                    // the assignment does not own, such as counterparty entity.
                    $dims = array_replace($dims, $inheritedDimensions, ['placement' => $placementId]);
                } catch (\Throwable $e) {
                    $batchErrors[$batch][] = "row {$rowNum}: could not resolve assignment PL-{$placementId}: " . $e->getMessage();
                }
            }
        }
        $grouped[$batch]['lines'][] = [
            'account_code' => $row['account_code'],
            'debit' => (float) ($row['debit'] ?? 0),
            'credit' => (float) ($row['credit'] ?? 0),
            'memo' => $row['line_memo'] ?? null,
            'dims' => $dims,
        ];
    }
    foreach ($grouped as $batch => $je) {
        if (count($je['lines']) < 2) $batchErrors[$batch][] = 'needs at least 2 lines';
    }
    foreach ($batchErrors as $batch => $messages) {
        $batchErrors[$batch] = array_values(array_unique($messages));
    }
    return ['grouped' => $grouped, 'batch_errors' => $batchErrors];
}

if ($action === 'dry_run') {
    $res = CsvImportService::dryRun($schemaKey, $raw, null, $defaults);
    if ($type === 'je') {
        $prepared = accountingPrepareJeImport($tid, $res);
        foreach ($prepared['batch_errors'] as $batch => $messages) {
            $res['errors']['batch:' . $batch] = $messages;
        }
        $res['error_count'] = count($res['errors']);
    }
    api_ok($res);
}

// ── Commit — per-type writers ────────────────────────────────────────────
$skipInvalid = !empty($_GET['skip_invalid']) || !empty($requestBody['skip_invalid'] ?? null);

if ($type === 'coa') {
    $db = getDB();
    $writer = function (array $row) use ($db, $tid): int {
        $stmt = $db->prepare('SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = :c LIMIT 1');
        $stmt->execute(['t' => $tid, 'c' => $row['code']]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        $fields = [
            'name'         => $row['name'],
            'account_type' => $row['account_type'],
            'normal_side'  => $row['normal_side'],
            'parent_account_id' => !empty($row['parent_account_id']) ? (int) $row['parent_account_id'] : null,
            'is_postable'  => isset($row['is_postable']) ? (int) $row['is_postable'] : 1,
            'currency'     => $row['currency']     ?? null,
            'cash_flow_tag'=> $row['cash_flow_tag']?? null,
            'description'  => $row['description'] ?? null,
            'active'       => isset($row['active']) ? (int) $row['active'] : 1,
        ];
        if ($existing) {
            $sets = []; $params = ['id' => $existing['id'], 't' => $tid];
            foreach ($fields as $k => $v) { $sets[] = "`$k` = :$k"; $params[$k] = $v; }
            $db->prepare("UPDATE accounting_accounts SET " . implode(',', $sets) .
                         " WHERE id = :id AND tenant_id = :t")->execute($params);
            return (int) $existing['id'];
        }
        $cols = array_merge(['tenant_id','code'], array_keys($fields));
        $ph   = array_map(fn($c) => ':' . $c, $cols);
        $vals = array_merge(['tenant_id' => $tid, 'code' => $row['code']], $fields);
        $db->prepare('INSERT INTO accounting_accounts (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', $ph) . ')')
            ->execute($vals);
        return (int) $db->lastInsertId();
    };
    $res = CsvImportService::commit($schemaKey, $raw, $writer, ['skip_invalid' => $skipInvalid]);
    accountingAudit('accounting.ledger.imported', ['type' => 'coa', 'imported' => $res['imported_count'], 'skipped' => $res['skipped_count']]);
    api_ok($res);
}

if ($type === 'je') {
    // Group rows into JEs by batch_ref; post each batch via accountingPostJe
    // with idempotency key 'csv:<sha256(batch_ref)>'.
    $dry = CsvImportService::dryRun($schemaKey, $raw, null, $defaults);
    $prepared = accountingPrepareJeImport($tid, $dry);
    foreach ($prepared['batch_errors'] as $batch => $messages) {
        $dry['errors']['batch:' . $batch] = $messages;
    }
    $dry['error_count'] = count($dry['errors']);
    if (!$skipInvalid && $dry['error_count'] > 0) {
        api_ok([
            'imported_count' => 0,
            'skipped_count'  => $dry['row_count'],
            'errors'         => $dry['errors'],
            'ids'            => [],
            'message'        => 'Validation errors present; commit aborted. Pass skip_invalid=1 to import valid rows only.',
        ]);
    }
    $grouped = $prepared['grouped'];
    $batchErrors = $prepared['batch_errors'];
    $imported = 0; $skipped = 0; $errors = $dry['errors']; $ids = [];
    foreach ($grouped as $batch => $je) {
        if (!empty($batchErrors[$batch])) {
            $skipped++;
            $errors['batch:' . $batch] = array_values(array_unique($batchErrors[$batch]));
            continue;
        }
        try {
            $res = accountingPostJe($tid, $je + [
                'source_module'   => 'manual',
                'idempotency_key' => 'csv:' . hash('sha256', (string) $tid . ':' . $batch),
            ], $user['id'] ?? null, true);
            $ids[$batch] = (int) $res['je_id'];
            $imported++;
        } catch (\Throwable $e) {
            $skipped++;
            $errors['batch:' . $batch] = ['post failed: ' . $e->getMessage()];
        }
    }
    accountingAudit('accounting.ledger.imported', ['type' => 'je', 'imported' => $imported, 'skipped' => $skipped]);
    api_ok(['imported_count' => $imported, 'skipped_count' => $skipped, 'errors' => $errors, 'ids' => $ids]);
}

if ($type === 'periods') {
    $db = getDB();
    $writer = function (array $row) use ($db, $tid): int {
        $stmt = $db->prepare(
            'SELECT id FROM accounting_periods
             WHERE tenant_id = :t AND entity_id = :e AND start_date = :sd LIMIT 1'
        );
        $stmt->execute(['t' => $tid, 'e' => (int) $row['entity_id'], 'sd' => $row['start_date']]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            $db->prepare(
                'UPDATE accounting_periods
                 SET period_number = :pn, end_date = :ed, status = :st
                 WHERE id = :id AND tenant_id = :t'
            )->execute([
                'pn' => (int) $row['period_number'], 'ed' => $row['end_date'],
                'st' => $row['status'], 'id' => $existing['id'], 't' => $tid,
            ]);
            return (int) $existing['id'];
        }
        $db->prepare(
            'INSERT INTO accounting_periods
                (tenant_id, entity_id, period_number, start_date, end_date, status)
             VALUES (:t, :e, :pn, :sd, :ed, :st)'
        )->execute([
            't' => $tid, 'e' => (int) $row['entity_id'],
            'pn' => (int) $row['period_number'], 'sd' => $row['start_date'],
            'ed' => $row['end_date'], 'st' => $row['status'],
        ]);
        return (int) $db->lastInsertId();
    };
    $res = CsvImportService::commit($schemaKey, $raw, $writer, ['skip_invalid' => $skipInvalid]);
    accountingAudit('accounting.ledger.imported', ['type' => 'periods', 'imported' => $res['imported_count'], 'skipped' => $res['skipped_count']]);
    api_ok($res);
}

api_error('Unhandled type: ' . $type, 422);
