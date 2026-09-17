<?php
/** Chart-of-accounts CSV round-trip import. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/accounting.php';

use Core\CsvImportService;

CsvImportService::registerSchema('accounting_accounts', [
    'fields' => [
        'account_id'          => ['label' => 'Account ID', 'type' => 'integer'],
        'code'                => ['label' => 'Code', 'required' => true],
        'name'                => ['label' => 'Name', 'required' => true],
        'account_type'        => ['label' => 'Account type', 'required' => true,
                                  'enum' => ['asset', 'liability', 'equity', 'revenue', 'expense']],
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
    $postingCount = $pdo->prepare(
        'SELECT COUNT(*) FROM accounting_journal_entry_lines l
          JOIN accounting_journal_entries je ON je.id = l.je_id
         WHERE je.tenant_id = :tenant_id AND l.account_id = :account_id'
    );
    $codeOwner = $pdo->prepare('SELECT id FROM accounting_accounts WHERE tenant_id = :tenant_id AND code = :code LIMIT 1');

    foreach ($result['rows'] as $rowNumber => $row) {
        $existing = $findAccount($pdo, $row);
        if (!empty($row['account_id']) && !$existing) {
            $result['errors'][$rowNumber][] = "account_id: account {$row['account_id']} was not found in this workspace";
            continue;
        }

        $codeOwner->execute(['tenant_id' => $tenantId, 'code' => trim((string) $row['code'])]);
        $ownerId = (int) ($codeOwner->fetchColumn() ?: 0);
        if ($ownerId > 0 && $existing && $ownerId !== (int) $existing['id']) {
            $result['errors'][$rowNumber][] = "code: {$row['code']} belongs to a different account";
        }

        try {
            $parent = $resolveParent($pdo, $row);
            $parentRequested = !empty($row['parent_account_id']) || trim((string) ($row['parent_account_code'] ?? '')) !== '';
            if ($parentRequested && !$parent) {
                $result['errors'][$rowNumber][] = 'parent: account was not found in this workspace; import parent rows first';
            } elseif ($parent && (string) $parent['account_type'] !== (string) $row['account_type']) {
                $result['errors'][$rowNumber][] = 'parent: parent and child must have the same account type';
            } elseif ($parent && $existing && (int) $parent['id'] === (int) $existing['id']) {
                $result['errors'][$rowNumber][] = 'parent: an account cannot be its own parent';
            }
        } catch (Throwable $e) {
            $result['errors'][$rowNumber][] = 'parent: ' . $e->getMessage();
        }

        if ($existing) {
            $postingCount->execute(['tenant_id' => $tenantId, 'account_id' => (int) $existing['id']]);
            $hasPostings = (int) $postingCount->fetchColumn() > 0;
            if ($hasPostings && ((string) $existing['account_type'] !== (string) $row['account_type']
                || (string) $existing['normal_side'] !== (string) $row['normal_side'])) {
                $result['errors'][$rowNumber][] = 'account_type/normal_side: posted accounts cannot change accounting classification';
            }
            if ($hasPostings && array_key_exists('is_postable', $row) && !(int) $row['is_postable']) {
                $result['errors'][$rowNumber][] = 'is_postable: an account with journal activity must remain postable';
            }
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

        $parent = $resolveParent($pdo, $row);
        $parentId = $parent ? (int) $parent['id'] : null;
        if ($parent && (string) $parent['account_type'] !== (string) $row['account_type']) {
            throw new RuntimeException('Parent and child must have the same account type');
        }
        if ($existing && $parentId === (int) $existing['id']) {
            throw new RuntimeException('An account cannot be its own parent');
        }

        if ($existing && $parentId) {
            $cursor = $parent;
            $guard = 0;
            while ($cursor && !empty($cursor['parent_account_id']) && $guard < 500) {
                if ((int) $cursor['parent_account_id'] === (int) $existing['id']) {
                    throw new RuntimeException('Parent selection would create a cycle');
                }
                $next = $pdo->prepare('SELECT id, parent_account_id FROM accounting_accounts WHERE tenant_id = :tenant_id AND id = :id');
                $next->execute(['tenant_id' => $tenantId, 'id' => (int) $cursor['parent_account_id']]);
                $cursor = $next->fetch(PDO::FETCH_ASSOC) ?: null;
                $guard++;
            }
        }

        $payload = [
            'code' => trim((string) $row['code']),
            'name' => trim((string) $row['name']),
            'account_type' => (string) $row['account_type'],
            'normal_side' => (string) $row['normal_side'],
            'parent_account_id' => $parentId,
            'is_postable' => array_key_exists('is_postable', $row) ? (int) $row['is_postable'] : (int) ($existing['is_postable'] ?? 1),
            'currency' => trim((string) ($row['currency'] ?? '')) ?: null,
            'cash_flow_tag' => trim((string) ($row['cash_flow_tag'] ?? '')) ?: null,
            'description' => trim((string) ($row['description'] ?? '')) ?: null,
            'active' => array_key_exists('active', $row) ? (int) $row['active'] : (int) ($existing['active'] ?? 1),
        ];

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

    // Persist the exact custom-validated preview. CsvImportService::commit()
    // re-runs schema validation, but it cannot see tenant references, posting
    // locks, or hierarchy errors added above. Iterating the preview here makes
    // skip_invalid honest: no custom-invalid row can leak into the ledger.
    $imported = 0;
    $skipped = 0;
    $errors = $preview['errors'];
    $ids = [];
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
    $result = [
        'imported_count' => $imported,
        'skipped_count' => $skipped,
        'errors' => $errors,
        'ids' => $ids,
    ];
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|sample|inspect|ai_suggest_map|dry_run|commit', 400);
