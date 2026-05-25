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

require_once __DIR__ . '/../lib/csv_helpers.php';

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
    rbac_legacy_require($user, 'placements.manage');
    $csv = CsvImportService::buildTemplate('placements');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="placements_template.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    rbac_legacy_require($user, 'placements.manage');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="placements_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('placements', $samples['placements'] ?? []);
    exit;
}


if ($method === 'POST' && $action === 'inspect') {
    rbac_legacy_require($user, 'placements.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('placements', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    rbac_legacy_require($user, 'placements.manage');
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
    rbac_legacy_require($user, 'placements.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $result = CsvImportService::dryRun('placements', $csv, $columnMap);

    // Validate person_email exists in tenant
    if ($result['rows']) {
        $emails = [];
        foreach ($result['rows'] as $r) {
            $em = placementsCsvNormaliseEmail((string) ($r['person_email'] ?? ''));
            if ($em !== '') $emails[] = $em;
        }
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

            // "Did you mean?" — when a CSV email misses, hand the operator
            // up to 3 closest emails from the tenant's directory so a
            // single-character typo or hidden-Unicode bug is fixable
            // in two clicks instead of a database safari. Loaded lazily
            // (only when there's at least one miss) to keep dry-run fast
            // on clean batches.
            $directoryCache = null;
            $loadDirectory = static function () use (&$directoryCache, $pdo) {
                if ($directoryCache !== null) return $directoryCache;
                $st = $pdo->prepare(
                    'SELECT LOWER(email_primary) AS e FROM people
                      WHERE tenant_id = ? AND deleted_at IS NULL AND email_primary IS NOT NULL
                      LIMIT 5000'
                );
                $st->execute([currentTenantId()]);
                $directoryCache = array_column($st->fetchAll(\PDO::FETCH_ASSOC), 'e');
                return $directoryCache;
            };
            $suggestFor = static function (string $needle) use ($loadDirectory): array {
                if ($needle === '') return [];
                $candidates = $loadDirectory();
                if (!$candidates) return [];
                $scored = [];
                foreach ($candidates as $cand) {
                    // levenshtein blows up beyond 255 chars — bail safely.
                    if (strlen($cand) > 255 || strlen($needle) > 255) continue;
                    $d = levenshtein($needle, $cand);
                    if ($d <= 3) $scored[$cand] = $d;
                }
                asort($scored);
                return array_slice(array_keys($scored), 0, 3);
            };

            foreach ($result['rows'] as $rn => $row) {
                $rawEm = (string) ($row['person_email'] ?? '');
                $em    = placementsCsvNormaliseEmail($rawEm);
                if ($em && !isset($found[$em])) {
                    $msg = "person_email: '{$rawEm}' not found in this tenant's People";
                    $suggestions = $suggestFor($em);
                    if ($suggestions) {
                        $msg .= ' — did you mean: ' . implode(', ', $suggestions) . '?';
                    }
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = $msg;
                }
            }
            $result['error_count'] = count($result['errors']);
        }
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'placements.manage');
    rbac_legacy_require($user, 'placements.financials.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap      = CsvImportService::readRequestColumnMap();
    $skipInvalid    = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $result = CsvImportService::commit('placements', $csv, function (array $row) use ($user, $updateExisting) {
        // Resolve person_id by tenant + email. Same Unicode-defensive
        // normalisation as dry_run so a row that passed validation
        // doesn't fail the commit with a stale hidden-whitespace error.
        $emClean = placementsCsvNormaliseEmail((string) ($row['person_email'] ?? ''));
        $person = scopedFind(
            'SELECT id FROM people WHERE tenant_id = :tenant_id AND LOWER(email_primary) = :email AND deleted_at IS NULL',
            ['email' => $emClean]
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
