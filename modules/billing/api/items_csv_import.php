<?php
/** Billing products and services CSV import. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/billing.php';

use Core\CsvImportService;

CsvImportService::registerSchema('billing_items', [
    'fields' => [
        'item_id' => ['label' => 'Item ID', 'type' => 'integer'],
        'code' => ['label' => 'Code', 'required' => true],
        'name' => ['label' => 'Name', 'required' => true],
        'item_type' => [
            'label' => 'Item type',
            'enum' => ['labor', 'expense', 'materials', 'fixed_fee', 'milestone', 'discount',
                       'subscription', 'mileage', 'per_diem', 'reimbursement', 'other'],
        ],
        'description' => ['label' => 'Description'],
        'default_unit' => ['label' => 'Default unit'],
        'default_unit_price' => ['label' => 'Default unit price', 'type' => 'number'],
        'gl_revenue_account_code' => ['label' => 'Revenue account code'],
        'taxable' => ['label' => 'Taxable', 'type' => 'boolean'],
        'active' => ['label' => 'Active', 'type' => 'boolean'],
        'external_id' => ['label' => 'External ID (audit / integration)'],
        'source_system' => [
            'label' => 'Source system',
            'enum' => ['manual', 'jobdiva', 'qbo', 'mercury', 'plaid', 'jaz', 'zoho', 'airtable', 'gusto', 'other'],
        ],
    ],
    'unique_within_batch' => ['item_id', 'code'],
]);

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$requireManage = static function () use ($user): void {
    rbac_legacy_require($user, 'billing.invoice.draft');
};

if ($method === 'GET' && in_array($action, ['template', 'sample'], true)) {
    $requireManage();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products_services_' . $action . '.csv"');
    header('Cache-Control: no-store');
    if ($action === 'template') {
        echo CsvImportService::buildTemplate('billing_items');
    } else {
        $samples = require __DIR__ . '/../../../core/csv_samples.php';
        echo CsvImportService::buildSample('billing_items', $samples['billing_items'] ?? []);
    }
    exit;
}

if ($method === 'POST' && $action === 'inspect') {
    $requireManage();
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('billing_items', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    $requireManage();
    require_once __DIR__ . '/../../../core/ai_csv_mapper.php';
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream) ?: [];
    $samples = [];
    for ($i = 0; $i < 3; $i++) {
        $sample = fgetcsv($stream);
        if ($sample === false) break;
        $samples[] = $sample;
    }
    fclose($stream);

    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $alreadyMapped = is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [];
    $inspection = CsvImportService::inspect('billing_items', $csv);
    try {
        api_ok(aiSuggestColumnMap([
            'feature_key' => 'csv.mapping.billing_items',
            'entity_label' => 'Products and services',
            'schema_fields' => $inspection['fields'],
            'headers' => $headers,
            'sample_rows' => $samples,
            'already_mapped' => $alreadyMapped,
        ]));
    } catch (AIDisabledException $e) {
        api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503);
    } catch (Throwable $e) {
        api_error('AI suggestion failed: ' . $e->getMessage(), 502);
    }
}

$validateReferences = static function (array &$result) use ($tenantId): void {
    $pdo = getDB();
    $itemById = $pdo->prepare('SELECT id FROM billing_items WHERE tenant_id = :tenant_id AND id = :id');
    $revenueAccount = $pdo->prepare(
        'SELECT id FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND code = :code
            AND account_type = "revenue" AND active = 1 AND is_postable = 1
          LIMIT 1'
    );
    foreach ($result['rows'] as $rowNumber => $row) {
        if (!empty($row['item_id'])) {
            $itemById->execute(['tenant_id' => $tenantId, 'id' => (int) $row['item_id']]);
            if (!$itemById->fetchColumn()) {
                $result['errors'][$rowNumber][] = "item_id: item {$row['item_id']} was not found in this workspace";
            }
        }
        $accountCode = trim((string) ($row['gl_revenue_account_code'] ?? ''));
        if ($accountCode !== '') {
            $revenueAccount->execute(['tenant_id' => $tenantId, 'code' => $accountCode]);
            if (!$revenueAccount->fetchColumn()) {
                $result['errors'][$rowNumber][] = "gl_revenue_account_code: {$accountCode} is not an active postable revenue account";
            }
        }
    }
    $result['error_count'] = count($result['errors']);
};

if ($method === 'POST' && $action === 'dry_run') {
    $requireManage();
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $result = CsvImportService::dryRun('billing_items', $csv, CsvImportService::readRequestColumnMap());
    $validateReferences($result);
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    $requireManage();
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $preview = CsvImportService::dryRun('billing_items', $csv, $columnMap);
    $validateReferences($preview);
    if (!$skipInvalid && $preview['error_count'] > 0) {
        api_ok([
            'imported_count' => 0,
            'skipped_count' => $preview['row_count'],
            'errors' => $preview['errors'],
            'ids' => [],
            'message' => 'Validation errors present; import was not committed.',
        ]);
    }

    $invalidRows = array_fill_keys(array_keys($preview['errors']), true);
    $result = CsvImportService::commit('billing_items', $csv, function (array $row) use ($tenantId, $user, $updateExisting): int {
        $pdo = getDB();
        $existing = null;

        if (!empty($row['item_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM billing_items WHERE tenant_id = :tenant_id AND id = :id');
            $stmt->execute(['tenant_id' => $tenantId, 'id' => (int) $row['item_id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$existing) throw new RuntimeException("Item {$row['item_id']} was not found in this workspace");
        } elseif (!empty($row['external_id'])) {
            $stmt = $pdo->prepare(
                'SELECT * FROM billing_items
                  WHERE tenant_id = :tenant_id AND source_system = :source_system
                    AND source_external_id = :external_id LIMIT 1'
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'source_system' => (string) ($row['source_system'] ?? 'manual'),
                'external_id' => (string) $row['external_id'],
            ]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$existing) {
            $stmt = $pdo->prepare('SELECT * FROM billing_items WHERE tenant_id = :tenant_id AND code = :code LIMIT 1');
            $stmt->execute(['tenant_id' => $tenantId, 'code' => trim((string) $row['code'])]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($existing && !$updateExisting) {
            throw new RuntimeException("Code {$row['code']} already exists; enable Update existing rows to replace it");
        }

        $accountCode = trim((string) ($row['gl_revenue_account_code'] ?? ($existing['gl_revenue_account_code'] ?? '')));
        if ($accountCode !== '') {
            $account = $pdo->prepare(
                'SELECT id FROM accounting_accounts
                  WHERE tenant_id = :tenant_id AND code = :code
                    AND account_type = "revenue" AND active = 1 AND is_postable = 1 LIMIT 1'
            );
            $account->execute(['tenant_id' => $tenantId, 'code' => $accountCode]);
            if (!$account->fetchColumn()) throw new RuntimeException("Revenue account {$accountCode} is not active and postable");
        }

        $payload = [
            'code' => trim((string) $row['code']),
            'name' => trim((string) $row['name']),
            'item_type' => (string) ($row['item_type'] ?? ($existing['item_type'] ?? 'other')),
            'description' => array_key_exists('description', $row)
                ? (trim((string) $row['description']) ?: null)
                : ($existing['description'] ?? null),
            'default_unit' => trim((string) ($row['default_unit'] ?? ($existing['default_unit'] ?? 'each'))) ?: 'each',
            'default_unit_price' => array_key_exists('default_unit_price', $row) && $row['default_unit_price'] !== ''
                ? round((float) $row['default_unit_price'], 4)
                : ($existing['default_unit_price'] ?? null),
            'gl_revenue_account_code' => $accountCode ?: null,
            'taxable' => array_key_exists('taxable', $row) ? (int) $row['taxable'] : (int) ($existing['taxable'] ?? 0),
            'active' => array_key_exists('active', $row) ? (int) $row['active'] : (int) ($existing['active'] ?? 1),
            'source_system' => (string) ($row['source_system'] ?? ($existing['source_system'] ?? 'manual')),
            'source_external_id' => array_key_exists('external_id', $row)
                ? (trim((string) $row['external_id']) ?: null)
                : ($existing['source_external_id'] ?? null),
        ];

        if ($existing) {
            $duplicate = $pdo->prepare(
                'SELECT id FROM billing_items WHERE tenant_id = :tenant_id AND code = :code AND id <> :id LIMIT 1'
            );
            $duplicate->execute(['tenant_id' => $tenantId, 'code' => $payload['code'], 'id' => (int) $existing['id']]);
            if ($duplicate->fetchColumn()) throw new RuntimeException("Code {$payload['code']} belongs to another item");
            scopedUpdate('billing_items', (int) $existing['id'], $payload);
            return (int) $existing['id'];
        }

        return scopedInsert('billing_items', array_merge($payload, [
            'tenant_id' => $tenantId,
            'created_by_user_id' => $user['id'] ?? null,
        ]));
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    if ($invalidRows && $skipInvalid) {
        $result['errors'] = array_replace($preview['errors'], $result['errors']);
    }
    billingAudit('billing.item.csv_imported', [
        'imported' => $result['imported_count'],
        'skipped' => $result['skipped_count'],
        'errors' => count($result['errors']),
        'update_existing' => $updateExisting,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|sample|inspect|ai_suggest_map|dry_run|commit', 400);
