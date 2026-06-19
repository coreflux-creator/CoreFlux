<?php
/**
 * People module — CSV import schema registration + endpoint.
 *
 * Routes:
 *   GET  /api/people/csv_import?action=template   → downloads CSV template
 *   POST /api/people/csv_import?action=dry_run    → returns parsed rows + errors
 *                                                   body: multipart file=... OR JSON {csv: "..."}
 *   POST /api/people/csv_import?action=commit     → actually imports
 *                                                   body: same as dry_run, plus optional ?skip_invalid=1
 *
 * Per HARD_RULES (2026-02-XX): every primary-entity module must expose this.
 * SPEC: /app/modules/people/SPEC.md (table mapping derived from §3.1)
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';

use Core\CsvImportService;

CsvImportService::registerSchema('people', [
    'fields' => [
        // person_id wins over email_primary for update-existing rows.
        // Leave blank for new people (insert path resolves on
        // email_primary + external_id uniqueness).
        'person_id'            => ['label' => 'Person ID',            'type' => 'integer'],
        'first_name'           => ['label' => 'First name',           'required' => true],
        'middle_name'          => ['label' => 'Middle name'],
        'last_name'            => ['label' => 'Last name',            'required' => true],
        'preferred_name'       => ['label' => 'Preferred name'],
        'email_primary'        => ['label' => 'Primary email',        'required' => true, 'type' => 'email'],
        'email_secondary'      => ['label' => 'Secondary email',      'type' => 'email'],
        'phone_primary'        => ['label' => 'Primary phone'],
        'phone_secondary'      => ['label' => 'Secondary phone'],
        'classification'       => ['label' => 'Classification',       'required' => true,
                                   'enum'  => ['w2','1099','c2c','temp','perm','candidate','alumni']],
        'status'               => ['label' => 'Status',
                                   'enum'  => ['active','bench','inactive','do_not_rehire']],
        'work_auth_status'     => ['label' => 'Work auth status',
                                   'enum'  => ['citizen','green_card','h1b','opt','cpt','tn','other','unknown']],
        'work_auth_expiry'     => ['label' => 'Work auth expiry',     'type' => 'date'],
        'requires_sponsorship' => ['label' => 'Requires sponsorship', 'type' => 'boolean'],
        'linkedin_url'         => ['label' => 'LinkedIn URL'],
        'source'               => ['label' => 'Source'],
        'external_id'          => ['label' => 'External ID'],
        'recruiter_notes'      => ['label' => 'Recruiter notes'],
    ],
    'unique_within_batch' => ['email_primary', 'external_id'],
]);

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    rbac_legacy_require($user, 'people.manage');
    $csv = CsvImportService::buildTemplate('people');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="people_template.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    rbac_legacy_require($user, 'people.manage');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="people_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('people', $samples['people'] ?? []);
    exit;
}

if ($method === 'POST' && $action === 'inspect') {
    rbac_legacy_require($user, 'people.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('people', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    rbac_legacy_require($user, 'people.manage');
    rbac_legacy_require($user, 'ai.use');
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

    $ins = CsvImportService::inspect('people', $csv);
    try {
        $result = aiSuggestColumnMap([
            'feature_key'    => 'csv.mapping.people',
            'entity_label'   => 'People',
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
    rbac_legacy_require($user, 'people.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $result = CsvImportService::dryRun('people', $csv, $columnMap);

    // Also flag email collisions with EXISTING records in the tenant.
    $existingEmails = [];
    if ($result['rows']) {
        $emails = [];
        foreach ($result['rows'] as $row) if (!empty($row['email_primary'])) $emails[] = $row['email_primary'];
        if ($emails) {
            $placeholders = implode(',', array_fill(0, count($emails), '?'));
            $pdo = getDB();
            $stmt = $pdo->prepare(
                "SELECT LOWER(email_primary) AS e FROM people
                 WHERE tenant_id = ? AND deleted_at IS NULL AND LOWER(email_primary) IN ({$placeholders})"
            );
            // Email-collision dedupe — uses the *people* module scope
            // (shared → parent for sub-tenants) so a sub-tenant doesn't
            // silently re-import a person that already exists under the
            // master tenant. Falls back to raw session tenant when
            // sub_tenants config isn't deployed yet.
            $peopleTid = effectiveTenantIdForModule('people') ?? currentTenantId();
            $stmt->execute(array_merge([$peopleTid], array_map('strtolower', $emails)));
            foreach ($stmt as $r) $existingEmails[$r['e']] = true;
        }
    }
    foreach ($result['rows'] as $rowNum => $row) {
        $em = strtolower($row['email_primary'] ?? '');
        if ($em && isset($existingEmails[$em])) {
            $result['errors'][$rowNum] = $result['errors'][$rowNum] ?? [];
            $result['errors'][$rowNum][] = "email_primary: '{$em}' already exists in tenant";
        }
    }
    $result['error_count'] = count($result['errors']);
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'people.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $skipInvalid    = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $result = CsvImportService::commit('people', $csv, function (array $row) use ($user, $updateExisting) {
        // Update-existing precedence: person_id beats email lookup so
        // an email change in the CSV doesn't accidentally insert a
        // duplicate row. Same id-first pattern as placements/recipients.
        $existing = null;
        $pid = isset($row['person_id']) && $row['person_id'] !== '' ? (int) $row['person_id'] : 0;
        if ($pid > 0) {
            $existing = scopedFind(
                'SELECT id FROM people WHERE tenant_id = :tenant_id AND id = :pid AND deleted_at IS NULL',
                ['pid' => $pid]
            );
            if (!$existing) {
                throw new \RuntimeException("person_id not found: {$pid}");
            }
        } else {
            // Tenant uniqueness check (against existing records by email)
            $existing = scopedFind(
                'SELECT id FROM people WHERE tenant_id = :tenant_id AND LOWER(email_primary) = LOWER(:email) AND deleted_at IS NULL',
                ['email' => $row['email_primary']]
            );
            if ($existing && !$updateExisting) {
                throw new \RuntimeException("email_primary already exists for this tenant (id={$existing['id']})");
            }
        }

        $payload = [
            'first_name'           => $row['first_name'],
            'middle_name'          => $row['middle_name']     ?? null,
            'last_name'            => $row['last_name'],
            'preferred_name'       => $row['preferred_name']  ?? null,
            'email_primary'        => $row['email_primary'],
            'email_secondary'      => $row['email_secondary'] ?? null,
            'phone_primary'        => $row['phone_primary']   ?? null,
            'phone_secondary'      => $row['phone_secondary'] ?? null,
            'classification'       => $row['classification'],
            'status'               => $row['status']           ?? 'active',
            'work_auth_status'     => $row['work_auth_status'] ?? 'unknown',
            'work_auth_expiry'     => $row['work_auth_expiry'] ?? null,
            'requires_sponsorship' => isset($row['requires_sponsorship']) ? (int) $row['requires_sponsorship'] : 0,
            'linkedin_url'         => $row['linkedin_url']    ?? null,
            'source'               => $row['source']          ?? null,
            'external_id'          => $row['external_id']     ?? null,
            'recruiter_notes'      => $row['recruiter_notes'] ?? null,
        ];
        if ($existing && $updateExisting) {
            scopedUpdate('people', (int) $existing['id'], $payload);
            return (int) $existing['id'];
        }
        $payload['created_by_user_id'] = $user['id'] ?? null;
        return scopedInsert('people', $payload);
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    peopleAudit('people.csv_imported', [
        'imported'        => $result['imported_count'],
        'skipped'         => $result['skipped_count'],
        'errors'          => count($result['errors']),
        'update_existing' => $updateExisting,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
