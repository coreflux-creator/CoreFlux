<?php
/**
 * Staffing module — CSV bulk client import.
 *
 *   GET  /api/staffing/csv_import?action=template
 *   POST /api/staffing/csv_import?action=dry_run
 *   POST /api/staffing/csv_import?action=commit (+ optional ?skip_invalid=1)
 *
 * Built on Core\CsvImportService primitive per HARD_RULES (2026-02-XX):
 * every primary-entity module MUST expose a CSV import flow.
 *
 * Scope: imports staffing_clients rows. MSA dates and back-billing must
 * be entered via the Clients UI (out of scope for bulk).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';

use Core\CsvImportService;

CsvImportService::registerSchema('staffing_clients', [
    'fields' => [
        // client_id (companies.id) wins over fuzzy name match for
        // update-existing rows.
        'client_id'             => ['label' => 'Client ID',             'type' => 'integer'],
        'name'                  => ['label' => 'Client name',           'required' => true],
        // external_id + source_system: stable per-row id from the
        // source-of-truth ATS/CRM (JobDiva client id, Bullhorn id,
        // HubSpot company id, etc.). When supplied, becomes the upsert
        // key so re-uploading the same export doesn't duplicate firms.
        'external_id'           => ['label' => 'External ID (audit / integration)'],
        'source_system'         => ['label' => 'Source system',
                                    'enum'  => ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other']],
        'legal_name'            => ['label' => 'Legal name'],
        'industry'              => ['label' => 'Industry'],
        'primary_contact_name'  => ['label' => 'Primary contact name'],
        'primary_contact_email' => ['label' => 'Primary contact email', 'type' => 'email'],
        'primary_contact_phone' => ['label' => 'Primary contact phone'],
        'billing_address_line1' => ['label' => 'Billing address line 1'],
        'billing_address_line2' => ['label' => 'Billing address line 2'],
        'billing_city'          => ['label' => 'Billing city'],
        'billing_state'         => ['label' => 'Billing state'],
        'billing_postal_code'   => ['label' => 'Billing postal code'],
        'billing_country'       => ['label' => 'Billing country (2-letter)'],
        'payment_terms_days'    => ['label' => 'Payment terms (days)',  'type' => 'number'],
        'status'                => ['label' => 'Status',
                                    'enum'  => ['active','prospect','on_hold','inactive','closed']],
        'notes'                 => ['label' => 'Notes'],
    ],
    'unique_within_batch' => ['external_id', 'name'],
]);

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    rbac_legacy_require($user, 'staffing.view');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clients_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('staffing_clients');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    rbac_legacy_require($user, 'staffing.view');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clients_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('staffing_clients', $samples['staffing_clients'] ?? []);
    exit;
}


if ($method === 'POST' && $action === 'inspect') {
    rbac_legacy_require($user, 'staffing.view');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('staffing_clients', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    rbac_legacy_require($user, 'staffing.view');
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

    $ins = CsvImportService::inspect('staffing_clients', $csv);
    try {
        $result = aiSuggestColumnMap([
            'feature_key'    => 'csv.mapping.staffing_clients',
            'entity_label'   => 'Clients',
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
    rbac_legacy_require($user, 'staffing.view');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $result = CsvImportService::dryRun('staffing_clients', $csv, $columnMap);

    // Flag collisions with existing client name rows in this tenant.
    if ($result['rows']) {
        $names = array_unique(array_filter(array_column($result['rows'], 'name')));
        if ($names) {
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $pdo = getDB();
            $stmt = $pdo->prepare(
                "SELECT name FROM staffing_clients
                  WHERE tenant_id = ? AND name IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$tid], $names));
            $existing = [];
            foreach ($stmt as $r) $existing[$r['name']] = true;
            foreach ($result['rows'] as $rn => $row) {
                if (!empty($row['name']) && isset($existing[$row['name']])) {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = "name: '{$row['name']}' already exists in tenant";
                }
            }
            $result['error_count'] = count($result['errors']);
        }
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'staffing.view');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid    = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $result = CsvImportService::commit('staffing_clients', $csv, function (array $row) use ($updateExisting) {
        $externalId   = isset($row['external_id'])   && $row['external_id']   !== '' ? (string) $row['external_id']   : null;
        $sourceSystem = isset($row['source_system']) && $row['source_system'] !== '' ? (string) $row['source_system'] : 'manual';

        // Existing-row resolution: prefer (source_system, external_id)
        // when supplied; fall back to fuzzy (tenant, name) for legacy
        // / manual rows without an external_id.
        $existing = null;
        if ($externalId !== null) {
            $existing = scopedFind(
                'SELECT id FROM staffing_clients
                  WHERE tenant_id = :tenant_id AND source_system = :s AND external_id = :e',
                ['s' => $sourceSystem, 'e' => $externalId]
            );
        }
        if (!$existing) {
            $existing = scopedFind(
                'SELECT id FROM staffing_clients WHERE tenant_id = :tenant_id AND name = :n',
                ['n' => $row['name']]
            );
        }
        if ($existing && !$updateExisting && $externalId === null) {
            // Manual import where we'd otherwise duplicate a same-named
            // firm — surface a row error instead of silent upsert. With
            // an external_id present we always upsert (idempotent feed).
            throw new \RuntimeException("Client '{$row['name']}' already exists (id={$existing['id']}) — skipped");
        }
        $payload = [
            'name'                  => $row['name'],
            'external_id'           => $externalId,
            'source_system'         => $sourceSystem,
            'legal_name'            => $row['legal_name']            ?? null,
            'industry'              => $row['industry']              ?? null,
            'primary_contact_name'  => $row['primary_contact_name']  ?? null,
            'primary_contact_email' => $row['primary_contact_email'] ?? null,
            'primary_contact_phone' => $row['primary_contact_phone'] ?? null,
            'billing_address_line1' => $row['billing_address_line1'] ?? null,
            'billing_address_line2' => $row['billing_address_line2'] ?? null,
            'billing_city'          => $row['billing_city']          ?? null,
            'billing_state'         => $row['billing_state']         ?? null,
            'billing_postal_code'   => $row['billing_postal_code']   ?? null,
            'billing_country'       => $row['billing_country']       ?? 'US',
            'payment_terms_days'    => isset($row['payment_terms_days']) ? (int) $row['payment_terms_days'] : 30,
            'status'                => $row['status']                ?? 'active',
            'notes'                 => $row['notes']                 ?? null,
        ];
        if ($existing) {
            scopedUpdate('staffing_clients', (int) $existing['id'], $payload);
            return (int) $existing['id'];
        }
        return scopedInsert('staffing_clients', $payload);
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
