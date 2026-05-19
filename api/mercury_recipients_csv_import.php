<?php
/**
 * /api/mercury_recipients_csv_import.php — Slice 2 polish.
 *
 *   GET  ?action=template     → empty CSV header for download
 *   POST ?action=dry_run      → validate without persisting (returns rows + errors)
 *   POST ?action=commit[&skip_invalid=1]  → persist via mercuryRecipientCreate
 *
 * Vendor-recipient import only. Funding_source recipients stay UI-only
 * because they require pasting the Mercury external_account id (per
 * Slice 2 note in core/mercury_adapter.php) — bulk-importing them would
 * skip that operator step.
 *
 * Built on Core\CsvImportService per HARD_RULES (2026-02): every
 * primary-entity module MUST expose a CSV flow using the shared primitive.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/CsvImportService.php';
require_once __DIR__ . '/../core/mercury_recipients.php';

use Core\CsvImportService;

CsvImportService::registerSchema('mercury_vendor_recipients', [
    'fields' => [
        'name'           => ['label' => 'Vendor name',      'required' => true],
        'email'          => ['label' => 'Email',            'type'     => 'email'],
        'payment_method' => ['label' => 'Payment method',
                             'enum'  => ['ach', 'wire', 'check']],
        'routing_number' => ['label' => 'Routing number (9 digits)', 'required' => true],
        'account_number' => ['label' => 'Account number',            'required' => true],
        'account_type'   => ['label' => 'Account type',
                             'enum'  => ['checking', 'savings']],
        'nickname'       => ['label' => 'Bank account nickname'],
        'notes'          => ['label' => 'Notes'],
    ],
    'unique_within_batch' => ['name'],
]);

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method   = api_method();
$action   = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && $action === 'template') {
    if (!rbac_legacy_can($user, 'accounting.bank.view')
        && !rbac_legacy_can($user, 'accounting.bank.manage')) {
        api_error('Permission denied', 403);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mercury_vendor_recipients_template.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildTemplate('mercury_vendor_recipients');
    exit;
}

if ($method === 'POST' && $action === 'dry_run') {
    rbac_legacy_require($user, 'accounting.bank.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $result = CsvImportService::dryRun('mercury_vendor_recipients', $csv, $columnMap);

    // Flag rows whose names already exist as active recipients in this tenant.
    if ($result['rows']) {
        $names = array_unique(array_filter(array_column($result['rows'], 'name')));
        if ($names) {
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            $pdo = getDB();
            $stmt = $pdo->prepare(
                "SELECT LOWER(name) AS lname FROM mercury_recipients
                  WHERE tenant_id = ? AND deleted_at IS NULL
                    AND LOWER(name) IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$tenantId], array_map('strtolower', $names)));
            $existing = [];
            foreach ($stmt as $r) $existing[$r['lname']] = true;
            foreach ($result['rows'] as $rn => $row) {
                $lname = strtolower((string) ($row['name'] ?? ''));
                if ($lname !== '' && isset($existing[$lname])) {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = "name: '{$row['name']}' already exists as a recipient";
                }
            }
            $result['error_count'] = count($result['errors']);
        }
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'accounting.bank.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap   = CsvImportService::readRequestColumnMap();
    $skipInvalid = !empty($_GET['skip_invalid']);
    $userId      = $user['id'] ?? null;

    $result = CsvImportService::commit('mercury_vendor_recipients', $csv,
        function (array $row) use ($tenantId, $userId): int {
            $rec = mercuryRecipientCreate($tenantId, [
                'kind'           => 'vendor',
                'name'           => trim((string) $row['name']),
                'email'          => $row['email']          ?? null,
                'payment_method' => $row['payment_method'] ?? 'ach',
                'notes'          => $row['notes']          ?? null,
                'bank' => [
                    'routing_number' => $row['routing_number'],
                    'account_number' => $row['account_number'],
                    'account_type'   => $row['account_type'] ?? 'checking',
                    'nickname'       => $row['nickname']     ?? null,
                ],
            ], $userId);
            return (int) ($rec['id'] ?? 0);
        }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
