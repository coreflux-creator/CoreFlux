<?php
/**
 * AP module — CSV bulk vendor import.
 *
 *   GET  /api/ap/csv_import?action=template
 *   POST /api/ap/csv_import?action=dry_run
 *   POST /api/ap/csv_import?action=commit (+ optional skip_invalid=1 or update_existing=1)
 *
 * Built on Core\CsvImportService primitive per HARD_RULES (2026-02-XX):
 * every primary-entity module MUST expose a CSV import flow so tenants
 * can self-serve initial + ongoing data loads.
 *
 * Scope: imports the ap_vendors_index row with name/type/category/terms/
 * remit + 1099 flag. Encrypted PII (tax_id_full, payment_account_full) is
 * NOT importable via CSV — those must be entered through the secure
 * VendorQuickCreate / VendorsList edit flow.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/ap.php';

use Core\CsvImportService;

CsvImportService::registerSchema('ap_vendors', [
    'fields' => [
        // Stable ap_vendors_index.id from the Vendors list or CSV export.
        'vendor_id'        => ['label' => 'Vendor ID',           'type' => 'integer'],
        'vendor_name'      => ['label' => 'Vendor name',         'required' => true],
        // external_id + source_system: per-row correlation back to the
        // system of record (JobDiva, QBO, Mercury, …). When supplied,
        // becomes the upsert key so re-imports don't duplicate rows.
        // Optional — manual one-off imports still work without it.
        'external_id'      => ['label' => 'External ID (audit / integration)'],
        'source_system'    => ['label' => 'Source system',
                               'enum'  => ['manual','jobdiva','qbo','mercury','plaid','jaz','zoho','airtable','gusto','other']],
        'vendor_type'      => ['label' => 'Vendor type',
                               'enum'  => ['1099_individual','c2c_corp','w9_business','utility','other']],
        'vendor_category'  => ['label' => 'Vendor category',
                               'enum'  => ['hourly_labor','service_provider']],
        'default_terms'    => ['label' => 'Default terms'],
        'remit_to_email'   => ['label' => 'Remit-to email',      'type' => 'email'],
        'remit_to_phone'   => ['label' => 'Remit-to phone'],
        'payment_method'   => ['label' => 'Payment method',
                               'enum'  => ['ach','wire','check','card','cash','plaid','mercury','other']],
        'tax_id_last4'     => ['label' => 'Tax ID last 4'],
        'requires_1099'    => ['label' => 'Requires 1099',       'type' => 'boolean'],
    ],
    'unique_within_batch' => ['vendor_id', 'external_id', 'vendor_name'],
]);

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    rbac_legacy_require($user, 'ap.bill.create');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vendors_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('ap_vendors');
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    rbac_legacy_require($user, 'ap.bill.create');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vendors_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('ap_vendors', $samples['ap_vendors'] ?? []);
    exit;
}


if ($method === 'POST' && $action === 'inspect') {
    rbac_legacy_require($user, 'ap.bill.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('ap_vendors', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    rbac_legacy_require($user, 'ap.bill.create');
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

    $ins = CsvImportService::inspect('ap_vendors', $csv);
    try {
        $result = aiSuggestColumnMap([
            'feature_key'    => 'csv.mapping.ap_vendors',
            'entity_label'   => 'Vendors',
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
    rbac_legacy_require($user, 'ap.bill.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $result = CsvImportService::dryRun('ap_vendors', $csv, $columnMap);

    if ($result['rows']) {
        $updateExisting = !empty($_GET['update_existing']);
        $names = array_unique(array_filter(array_column($result['rows'], 'vendor_name')));
        $ids = array_unique(array_filter(array_map('intval', array_column($result['rows'], 'vendor_id'))));
        $externalIds = array_unique(array_filter(array_column($result['rows'], 'external_id')));
        $pdo = getDB();
        $byName = [];
        $byId = [];
        $sourceById = [];
        $byExternal = [];
        if ($names) {
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, vendor_name, source_system FROM ap_vendors_index
                  WHERE tenant_id = ? AND vendor_name IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$tid], $names));
            foreach ($stmt as $r) {
                $byName[$r['vendor_name']] = (int) $r['id'];
                $sourceById[(int) $r['id']] = $r['source_system'];
            }
        }
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, source_system FROM ap_vendors_index WHERE tenant_id = ? AND id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$tid], $ids));
            foreach ($stmt as $r) {
                $byId[(int) $r['id']] = true;
                $sourceById[(int) $r['id']] = $r['source_system'];
            }
        }
        if ($externalIds) {
            $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, source_system, external_id FROM ap_vendors_index
                  WHERE tenant_id = ? AND external_id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$tid], $externalIds));
            foreach ($stmt as $r) $byExternal[$r['source_system'] . ':' . $r['external_id']] = (int) $r['id'];
        }
        foreach ($result['rows'] as $rn => $row) {
            $vendorId = (int) ($row['vendor_id'] ?? 0);
            $name = (string) ($row['vendor_name'] ?? '');
            $nameId = $byName[$name] ?? 0;
            $externalId = (string) ($row['external_id'] ?? '');
            $sourceSystem = (string) (($row['source_system'] ?? '')
                ?: ($sourceById[$vendorId ?: $nameId] ?? 'manual'));
            $externalMatchId = $externalId === '' ? 0 : ($byExternal[$sourceSystem . ':' . $externalId] ?? 0);
            $problem = null;
            if (($row['vendor_id'] ?? '') !== '' && $vendorId <= 0) {
                $problem = 'vendor_id: enter a positive Vendor ID or leave it blank';
            } elseif ($vendorId > 0 && !isset($byId[$vendorId])) {
                $problem = "vendor_id: {$vendorId} does not belong to this company";
            } elseif ($vendorId > 0 && $nameId > 0 && $nameId !== $vendorId) {
                $problem = "vendor_name: '{$name}' belongs to a different vendor";
            } elseif ($vendorId > 0 && $externalMatchId > 0 && $externalMatchId !== $vendorId) {
                $problem = "external_id: '{$externalId}' belongs to a different vendor";
            } elseif ($nameId > 0 && $externalMatchId > 0 && $nameId !== $externalMatchId) {
                $problem = 'Vendor name and external ID identify different vendors';
            } elseif (!$updateExisting && ($vendorId > 0 || $nameId > 0 || $externalMatchId > 0)) {
                $problem = 'Vendor already exists; enable Update matching editable records to update it';
            }
            if ($problem !== null) $result['errors'][$rn][] = $problem;
        }
        $result['error_count'] = count($result['errors']);
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'ap.bill.create');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    require_once __DIR__ . '/../../../core/accounting/csv_import_gate.php';
    coreAccountingClaimCsvImport('ap_vendors', $csv, (int) $ctx['tenant_id'], (int) $user['id']);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);
    $opts = ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap, 'atomic' => !$skipInvalid];

    $result = CsvImportService::commit('ap_vendors', $csv, function (array $row) use ($tid, $updateExisting) {
        $vendorId = (int) ($row['vendor_id'] ?? 0);
        if (($row['vendor_id'] ?? '') !== '' && $vendorId <= 0) {
            throw new RuntimeException('Vendor ID must be positive or blank.');
        }
        $provided = static fn(string $field): bool => array_key_exists($field, $row)
            && $row[$field] !== null && $row[$field] !== '';
        $vendorType = (string) ($row['vendor_type'] ?? 'other');
        $vendorCat  = (string) ($row['vendor_category']
            ?? (in_array($vendorType, ['1099_individual','c2c_corp'], true) ? 'hourly_labor' : 'service_provider'));
        $externalId   = isset($row['external_id'])   && $row['external_id']   !== '' ? (string) $row['external_id']   : null;
        $sourceSystem = isset($row['source_system']) && $row['source_system'] !== '' ? (string) $row['source_system'] : 'manual';

        $pdo = getDB();
        $values = [
            'v'      => (string) $row['vendor_name'],
            'ext'    => $externalId,
            'src'    => $sourceSystem,
            'vt'     => $vendorType,
            'cat'    => $vendorCat,
            'pm'     => $row['payment_method'] ?? null,
            'rmail'  => $row['remit_to_email'] ?? null,
            'rphone' => $row['remit_to_phone'] ?? null,
            'terms'  => (string) ($row['default_terms'] ?? 'NET30'),
            'last4'  => $row['tax_id_last4'] ?? null,
            'r'      => isset($row['requires_1099']) ? (int) $row['requires_1099'] : 0,
        ];
        $optional = [
            'external_id' => ['external_id', 'ext'],
            'source_system' => ['source_system', 'src'],
            'vendor_type' => ['vendor_type', 'vt'],
            'vendor_category' => ['vendor_category', 'cat'],
            'payment_method' => ['payment_method', 'pm'],
            'remit_to_email' => ['remit_to_email', 'rmail'],
            'remit_to_phone' => ['remit_to_phone', 'rphone'],
            'default_terms' => ['default_terms', 'terms'],
            'tax_id_last4' => ['tax_id_last4', 'last4'],
            'requires_1099' => ['requires_1099', 'r'],
        ];

        if ($vendorId > 0) {
            if (!$updateExisting) throw new RuntimeException('Enable update mode to change an existing vendor.');
            $find = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND id = :id');
            $find->execute(['t' => $tid, 'id' => $vendorId]);
            if (!$find->fetchColumn()) throw new RuntimeException('Vendor ID does not belong to this company.');
            $sets = ['vendor_name = :v'];
            $params = ['t' => $tid, 'id' => $vendorId, 'v' => $values['v']];
            foreach ($optional as $field => [$column, $param]) {
                if (!$provided($field)) continue;
                $sets[] = "{$column} = :{$param}";
                $params[$param] = $values[$param];
            }
            $pdo->prepare('UPDATE ap_vendors_index SET ' . implode(', ', $sets)
                . ' WHERE tenant_id = :t AND id = :id')->execute($params);
            return $vendorId;
        }

        $sql =
            'INSERT INTO ap_vendors_index
               (tenant_id, vendor_name, external_id, source_system,
                vendor_type, vendor_category,
                payment_method, remit_to_email, remit_to_phone,
                default_terms, tax_id_last4, requires_1099)
             VALUES (:t, :v, :ext, :src, :vt, :cat, :pm, :rmail, :rphone, :terms, :last4, :r)';
        if ($updateExisting) {
            $assignments = ['vendor_name = VALUES(vendor_name)'];
            foreach ($optional as $field => [$column]) {
                if ($provided($field)) $assignments[] = "{$column} = VALUES({$column})";
            }
            $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
        }
        $pdo->prepare($sql)->execute(['t' => $tid] + $values);
        $findStmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :v');
        $findStmt->execute(['t' => $tid, 'v' => $values['v']]);
        $id = (int) $findStmt->fetchColumn();
        if ($id <= 0) throw new RuntimeException('Vendor was not found after import.');
        return $id;
    }, $opts);

    apAudit('ap.vendor.csv_imported', [
        'imported' => $result['imported_count'],
        'skipped'  => $result['skipped_count'],
        'errors'   => count($result['errors']),
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
