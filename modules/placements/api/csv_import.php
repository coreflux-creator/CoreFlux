<?php
/**
 * Placements CSV import — uses Core\CsvImportService primitive.
 * Per HARD_RULES (2026-02-XX): every primary-entity module MUST expose CSV import.
 *
 *   GET  /api/placements/csv_import?action=template
 *   POST /api/placements/csv_import?action=dry_run
 *   POST /api/placements/csv_import?action=commit (+ optional ?skip_invalid=1)
 *
 * NOTE: Phase A scope imports the placement record + first rate row + chain[0]
 * (end client) only. Multi-tier chain, commissions, referrals, corp details
 * remain manual via UI. Bulk import of those is Phase B.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/placements.php';

use Core\CsvImportService;

CsvImportService::registerSchema('placements', [
    'fields' => [
        'person_email'      => ['label' => 'Person email',     'required' => true, 'type' => 'email'],
        'title'             => ['label' => 'Title',            'required' => true],
        'engagement_type'   => ['label' => 'Engagement type',  'required' => true,
                                'enum' => ['w2','1099','c2c','temp_to_perm','direct_hire']],
        'start_date'        => ['label' => 'Start date',       'required' => true, 'type' => 'date'],
        'end_date'          => ['label' => 'End date',         'type' => 'date'],
        'due_date'          => ['label' => 'Due date',         'type' => 'date'],
        'end_client_name'   => ['label' => 'End client name'],
        'worksite_state'    => ['label' => 'Worksite state'],
        'worksite_country'  => ['label' => 'Worksite country (2-letter)'],
        'remote_policy'     => ['label' => 'Remote policy',    'enum' => ['onsite','hybrid','remote']],
        'bill_rate'         => ['label' => 'Bill rate ($/hr)', 'type' => 'number'],
        'pay_rate'          => ['label' => 'Pay rate ($/hr)',  'type' => 'number'],
        'external_id'       => ['label' => 'External ID'],
        'notes'             => ['label' => 'Notes'],
    ],
    'unique_within_batch' => ['external_id'],
]);

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    RBAC::requirePermission($user, 'placements.manage');
    $csv = CsvImportService::buildTemplate('placements');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="placements_template.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    RBAC::requirePermission($user, 'placements.manage');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="placements_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('placements', $samples['placements'] ?? []);
    exit;
}


if ($method === 'POST' && $action === 'inspect') {
    RBAC::requirePermission($user, 'placements.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('placements', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    RBAC::requirePermission($user, 'placements.manage');
    require_once __DIR__ . '/../../../core/ai_csv_mapper.php';
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);

    // Read up to 3 sample rows alongside the header.
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream) ?: [];
    $samples = [];
    for ($i = 0; $i < 3; $i++) {
        $row = fgetcsv($stream);
        if ($row === false) break;
        $samples[] = $row;
    }
    fclose($stream);

    $body         = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $alreadyMap   = is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [];

    $ins = CsvImportService::inspect('placements', $csv);
    try {
        $result = aiSuggestColumnMap([
            'feature_key'    => 'csv.mapping.placements',
            'entity_label'   => 'Placements',
            'schema_fields'  => $ins['fields'],
            'headers'        => $headers,
            'sample_rows'    => $samples,
            'already_mapped' => $alreadyMap,
        ]);
    } catch (AIDisabledException $e) {
        api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503);
    } catch (\Throwable $e) {
        api_error('AI suggestion failed: ' . $e->getMessage(), 502);
    }
    api_ok($result);
}
if ($method === 'POST' && $action === 'dry_run') {
    RBAC::requirePermission($user, 'placements.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $result = CsvImportService::dryRun('placements', $csv, $columnMap);

    // Validate person_email exists in tenant
    if ($result['rows']) {
        $emails = [];
        foreach ($result['rows'] as $r) if (!empty($r['person_email'])) $emails[] = strtolower($r['person_email']);
        if ($emails) {
            $placeholders = implode(',', array_fill(0, count($emails), '?'));
            $pdo  = getDB();
            $stmt = $pdo->prepare(
                "SELECT LOWER(email_primary) AS e, id FROM people
                 WHERE tenant_id = ? AND deleted_at IS NULL AND LOWER(email_primary) IN ({$placeholders})"
            );
            $stmt->execute(array_merge([currentTenantId()], $emails));
            $found = [];
            foreach ($stmt as $r) $found[$r['e']] = (int) $r['id'];
            foreach ($result['rows'] as $rn => $row) {
                $em = strtolower($row['person_email'] ?? '');
                if ($em && !isset($found[$em])) {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = "person_email: '{$em}' not found in this tenant's People";
                }
            }
            $result['error_count'] = count($result['errors']);
        }
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    RBAC::requirePermission($user, 'placements.manage');
    RBAC::requirePermission($user, 'placements.financials.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap      = CsvImportService::readRequestColumnMap();
    $skipInvalid    = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $result = CsvImportService::commit('placements', $csv, function (array $row) use ($user, $updateExisting) {
        // Resolve person_id by tenant + email
        $person = scopedFind(
            'SELECT id FROM people WHERE tenant_id = :tenant_id AND LOWER(email_primary) = LOWER(:email) AND deleted_at IS NULL',
            ['email' => $row['person_email']]
        );
        if (!$person) throw new \RuntimeException("person_email not found: {$row['person_email']}");

        // Update-existing mode: match by external_id (tenant-unique identifier
        // when present), then by (person_id + title + start_date).
        $existing = null;
        if ($updateExisting) {
            if (!empty($row['external_id'])) {
                $existing = scopedFind(
                    'SELECT id FROM placements WHERE tenant_id = :tenant_id AND external_id = :x AND deleted_at IS NULL',
                    ['x' => $row['external_id']]
                );
            }
            if (!$existing) {
                $existing = scopedFind(
                    'SELECT id FROM placements
                      WHERE tenant_id = :tenant_id AND person_id = :p AND title = :t AND start_date = :s
                            AND deleted_at IS NULL',
                    ['p' => (int) $person['id'], 't' => $row['title'], 's' => $row['start_date']]
                );
            }
        }

        $payload = [
            'person_id'        => (int) $person['id'],
            'external_id'      => $row['external_id']     ?? null,
            'start_date'       => $row['start_date'],
            'end_date'         => $row['end_date']        ?? null,
            'due_date'         => $row['due_date']        ?? null,
            'engagement_type'  => $row['engagement_type'],
            'worksite_state'   => $row['worksite_state']  ?? null,
            'worksite_country' => $row['worksite_country']?? null,
            'remote_policy'    => placementsNormalizeRemotePolicy($row['remote_policy'] ?? null),
            'title'            => $row['title'],
            'end_client_name'  => $row['end_client_name'] ?? null,
            'notes'            => $row['notes']           ?? null,
        ];

        if ($existing) {
            scopedUpdate('placements', (int) $existing['id'], $payload);
            $pid = (int) $existing['id'];
        } else {
            $payload['status']             = 'draft';
            $payload['created_by_user_id'] = $user['id'] ?? null;
            $pid = scopedInsert('placements', $payload);
        }

        // First rate row (drafted, not approved — approval is a deliberate human step).
        // In update-existing mode, only insert if no rate has been recorded yet.
        if (!empty($row['bill_rate']) && !empty($row['pay_rate'])) {
            $hasRate = $existing ? scopedFind('SELECT id FROM placement_rates WHERE placement_id = :p LIMIT 1', ['p' => $pid]) : null;
            if (!$hasRate) {
                scopedInsert('placement_rates', [
                    'placement_id'        => $pid,
                    'effective_from'      => $row['start_date'],
                    'bill_rate'           => (float) $row['bill_rate'],
                    'pay_rate'            => (float) $row['pay_rate'],
                    'currency'            => 'USD',
                    'created_by_user_id'  => $user['id'] ?? null,
                ]);
            }
        }

        // Chain[0] = end client (string). Only insert for new placements.
        if (!$existing && !empty($row['end_client_name'])) {
            scopedInsert('placement_client_chain', [
                'placement_id' => $pid,
                'position'     => 0,
                'party_name'   => $row['end_client_name'],
                'party_role'   => 'end_client',
            ]);
        }

        return $pid;
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    placementsAudit('placement.csv_imported', [
        'imported'        => $result['imported_count'],
        'skipped'         => $result['skipped_count'],
        'update_existing' => $updateExisting,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
