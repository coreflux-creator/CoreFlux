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

if ($method === 'POST' && $action === 'dry_run') {
    RBAC::requirePermission($user, 'placements.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $result = CsvImportService::dryRun('placements', $csv);

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
    $skipInvalid = !empty($_GET['skip_invalid']);

    $result = CsvImportService::commit('placements', $csv, function (array $row) use ($user) {
        // Resolve person_id by tenant + email
        $person = scopedFind(
            'SELECT id FROM people WHERE tenant_id = :tenant_id AND LOWER(email_primary) = LOWER(:email) AND deleted_at IS NULL',
            ['email' => $row['person_email']]
        );
        if (!$person) throw new \RuntimeException("person_email not found: {$row['person_email']}");

        $pid = scopedInsert('placements', [
            'person_id'        => (int) $person['id'],
            'external_id'      => $row['external_id']     ?? null,
            'status'           => 'draft',
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
            'created_by_user_id' => $user['id'] ?? null,
        ]);

        // First rate row (drafted, not approved — approval is a deliberate human step)
        if (!empty($row['bill_rate']) && !empty($row['pay_rate'])) {
            scopedInsert('placement_rates', [
                'placement_id'        => $pid,
                'effective_from'      => $row['start_date'],
                'bill_rate'           => (float) $row['bill_rate'],
                'pay_rate'            => (float) $row['pay_rate'],
                'currency'            => 'USD',
                'created_by_user_id'  => $user['id'] ?? null,
            ]);
        }

        // Chain[0] = end client (string)
        if (!empty($row['end_client_name'])) {
            scopedInsert('placement_client_chain', [
                'placement_id' => $pid,
                'position'     => 0,
                'party_name'   => $row['end_client_name'],
                'party_role'   => 'end_client',
            ]);
        }

        return $pid;
    }, ['skip_invalid' => $skipInvalid]);

    placementsAudit('placement.csv_imported', [
        'imported' => $result['imported_count'],
        'skipped'  => $result['skipped_count'],
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
