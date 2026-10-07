<?php
/** Chart-of-accounts CSV round-trip import. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../../../core/accounting/account_mutation.php';
require_once __DIR__ . '/../lib/accounting.php';

use Core\CsvImportService;

CsvImportService::registerSchema('accounting_accounts', [
    'fields' => [
        'account_id'          => ['label' => 'Account ID', 'type' => 'integer'],
        'code'                => ['label' => 'Code', 'required' => true],
        'name'                => ['label' => 'Name', 'required' => true],
        'account_type'        => ['label' => 'Account type', 'required' => true,
                                  'enum' => ACCOUNTING_ACCOUNT_TYPES],
        'normal_side'         => ['label' => 'Normal side', 'required' => true, 'enum' => ['debit', 'credit']],
        'parent_account_id'   => ['label' => 'Parent account ID', 'type' => 'integer'],
        'parent_account_code' => ['label' => 'Parent account code'],
        'is_postable'         => ['label' => 'Is postable', 'type' => 'boolean'],
        'currency'            => ['label' => 'Currency'],
        // Cash-flow tags intentionally remain open text. The reporting engine
        // supports granular prefix-based classifications (operating_wc_ar,
        // financing_debt, investing_loans) as well as tenant-defined tags.
        'cash_flow_tag'       => ['label' => 'Cash flow tag'],
        'description'         => ['label' => 'Description'],
        'active'              => ['label' => 'Active', 'type' => 'boolean'],
    ],
    'unique_within_batch' => ['account_id', 'code'],
]);

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$requireManage = static function () use ($user): void {
    rbac_legacy_require($user, 'accounting.coa.manage');
};

if ($method === 'GET' && in_array($action, ['template', 'sample'], true)) {
    $requireManage();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chart_of_accounts_' . $action . '.csv"');
    header('Cache-Control: no-store');
    if ($action === 'template') {
        echo CsvImportService::buildTemplate('accounting_accounts');
    } else {
        $samples = require __DIR__ . '/../../../core/csv_samples.php';
        echo CsvImportService::buildSample('accounting_accounts', $samples['accounting_accounts'] ?? []);
    }
    exit;
}

if ($method === 'POST' && $action === 'inspect') {
    $requireManage();
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('accounting_accounts', $csv));
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
    $inspection = CsvImportService::inspect('accounting_accounts', $csv);
    try {
        api_ok(aiSuggestColumnMap([
            'feature_key' => 'csv.mapping.accounting_accounts',
            'entity_label' => 'Chart of accounts',
            'schema_fields' => $inspection['fields'],
            'headers' => $headers,
            'sample_rows' => $samples,
            'already_mapped' => is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [],
        ]));
    } catch (AIDisabledException $e) {
        api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503);
    } catch (Throwable $e) {
        api_error('AI suggestion failed: ' . $e->getMessage(), 502);
    }
}

$findAccount = static function (PDO $pdo, array $row) use ($tenantId): ?array {
    if (!empty($row['account_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => (int) $row['account_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $stmt = $pdo->prepare('SELECT * FROM accounting_accounts WHERE tenant_id = :tenant_id AND code = :code LIMIT 1');
    $stmt->execute(['tenant_id' => $tenantId, 'code' => trim((string) $row['code'])]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
};

$resolveParent = static function (PDO $pdo, array $row) use ($tenantId): ?array {
    $byId = null;
    $byCode = null;
    if (!empty($row['parent_account_id'])) {
        $stmt = $pdo->prepare('SELECT id, code, account_type, parent_account_id FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute(['tenant_id' => $tenantId, 'id' => (int) $row['parent_account_id']]);
        $byId = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$byId) throw new RuntimeException("Parent account ID {$row['parent_account_id']} was not found in this workspace");
    }
    if (trim((string) ($row['parent_account_code'] ?? '')) !== '') {
        $stmt = $pdo->prepare('SELECT id, code, account_type, parent_account_id FROM accounting_accounts WHERE tenant_id = :tenant_id AND code = :code LIMIT 1');
        $stmt->execute(['tenant_id' => $tenantId, 'code' => trim((string) $row['parent_account_code'])]);
        $byCode = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$byCode) throw new RuntimeException("Parent account code {$row['parent_account_code']} was not found in this workspace");
    }
    if ($byId && $byCode && (int) $byId['id'] !== (int) $byCode['id']) {
        throw new RuntimeException('Parent account ID and code refer to different accounts');
    }
    return $byId ?: $byCode;
};

$validateRows = static function (array &$result) use ($tenantId, $findAccount, $resolveParent): void {
    $pdo = getDB();
    foreach ($result['rows'] as $rowNumber => $row) {
        if (isset($result['errors'][$rowNumber])) continue;
        $existing = $findAccount($pdo, $row);
        if (!empty($row['account_id']) && !$existing) {
            $result['errors'][$rowNumber][] = "account_id: account {$row['account_id']} was not found in this workspace";
            continue;
        }
        try {
            $changes = accountingAccountCsvChanges($row);
            if (!empty($row['parent_account_id']) || trim((string) ($row['parent_account_code'] ?? '')) !== '') {
                $parent = $resolveParent($pdo, $row);
                $changes['parent_account_id'] = (int) $parent['id'];
            }
            accountingReviewAccountChange($tenantId, $existing, $changes);
        } catch (Throwable $e) {
            $result['errors'][$rowNumber][] = $e->getMessage();
        }
    }
    $result['error_count'] = count($result['errors']);
};

if ($method === 'POST' && $action === 'dry_run') {
    $requireManage();
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $result = CsvImportService::dryRun('accounting_accounts', $csv, CsvImportService::readRequestColumnMap());
    $validateRows($result);
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    $requireManage();
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $preview = CsvImportService::dryRun('accounting_accounts', $csv, $columnMap);
    $validateRows($preview);
    if (!$skipInvalid && $preview['error_count'] > 0) {
        api_ok([
            'imported_count' => 0,
            'skipped_count' => $preview['row_count'],
            'errors' => $preview['errors'],
            'ids' => [],
            'message' => 'Validation errors present; import was not committed.',
        ]);
    }

    $writer = function (array $row) use (
        $tenantId, $user, $updateExisting, $findAccount, $resolveParent
    ): int {
        $pdo = getDB();
        $existing = $findAccount($pdo, $row);
        if ($existing && !$updateExisting) {
            throw new RuntimeException("Account {$row['code']} already exists; enable Update matching accounts to change it");
        }
        if (!empty($row['account_id']) && !$existing) {
            throw new RuntimeException("Account ID {$row['account_id']} was not found in this workspace");
        }

        $duplicate = $pdo->prepare(
            'SELECT id FROM accounting_accounts
              WHERE tenant_id = :tenant_id AND code = :code' . ($existing ? ' AND id <> :id' : '') . ' LIMIT 1'
        );
        $params = ['tenant_id' => $tenantId, 'code' => trim((string) $row['code'])];
        if ($existing) $params['id'] = (int) $existing['id'];
        $duplicate->execute($params);
        if ($duplicate->fetchColumn()) throw new RuntimeException("Code {$row['code']} belongs to another account");

        $changes = accountingAccountCsvChanges($row);
        if (!empty($row['parent_account_id']) || trim((string) ($row['parent_account_code'] ?? '')) !== '') {
            $parent = $resolveParent($pdo, $row);
            $changes['parent_account_id'] = (int) $parent['id'];
        }
        $payload = accountingReviewAccountChange($tenantId, $existing, $changes);

        if ($existing) {
            scopedUpdate('accounting_accounts', (int) $existing['id'], $payload);
            accountingAudit('accounting.account.updated', [
                'id' => (int) $existing['id'],
                'source' => 'csv_roundtrip',
                'fields' => array_keys($payload),
            ], (int) $existing['id']);
            return (int) $existing['id'];
        }

        $id = scopedInsert('accounting_accounts', array_merge($payload, ['tenant_id' => $tenantId]));
        accountingAudit('accounting.account.created', [
            'id' => $id,
            'code' => $payload['code'],
            'source' => 'csv_roundtrip',
            'actor_user_id' => $user['id'] ?? null,
        ], $id);
        return $id;
    };

    // Revalidate each row during persistence; the default path rolls back the
    // whole file if any row fails after preview. Partial import is opt-in.
    $imported = 0;
    $skipped = 0;
    $errors = $preview['errors'];
    $ids = [];
    $pdo = getDB();
    $ownsTxn = !$skipInvalid && !$pdo->inTransaction();
    if ($ownsTxn) $pdo->beginTransaction();
    foreach ($preview['rows'] as $rowNumber => $row) {
        if (isset($errors[$rowNumber])) {
            $skipped++;
            continue;
        }
        try {
            $ids[$rowNumber] = $writer($row);
            $imported++;
        } catch (Throwable $e) {
            $errors[$rowNumber] = ['persist failed: ' . $e->getMessage()];
            $skipped++;
        }
    }
    if (!$skipInvalid && $errors) {
        if ($ownsTxn && $pdo->inTransaction()) $pdo->rollBack();
        api_ok([
            'imported_count' => 0, 'skipped_count' => $preview['row_count'],
            'errors' => $errors, 'ids' => [], 'aborted' => true,
            'message' => 'No accounts were imported. Fix all errors and retry.',
        ]);
    }
    if ($ownsTxn && $pdo->inTransaction()) $pdo->commit();
    $result = [
        'imported_count' => $imported,
        'skipped_count' => $skipped,
        'errors' => $errors,
        'ids' => $ids,
    ];
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|sample|inspect|ai_suggest_map|dry_run|commit', 400);
