<?php
/** Synthetic CoreOne bill intake through the canonical AP approval and ledger. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../core/accounting/coreone_v1.php';

function qaCoreOneBillRequest(string $method, string $path, string $token, ?array $body = null): array
{
    $curl = curl_init(QA_BASE_URL . $path);
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
        'X-CoreFlux-Tenant-Id: ' . QA_TENANT,
    ];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) throw new RuntimeException("{$method} {$path}: transport error {$error}");
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("{$method} {$path}: HTTP {$status}, non-JSON response");
    }
    return ['status' => $status, 'data' => $data];
}

$entity = qaOne($pdo, 'SELECT id, base_currency FROM accounting_entities
    WHERE tenant_id = :t AND code = :code AND active = 1',
    ['t' => QA_TENANT, 'code' => QA_ENTITY_CODE]);
$entityId = (int) ($entity['id'] ?? 0);
if ($entityId <= 0 || $entity['base_currency'] !== 'USD') {
    throw new RuntimeException('Synthetic USD lifecycle entity must be set up first.');
}
$expense = qaOne($pdo, 'SELECT id FROM accounting_accounts
    WHERE tenant_id = :t AND code = "5000" AND active = 1 AND is_postable = 1',
    ['t' => QA_TENANT]);
if (!$expense) throw new RuntimeException('Synthetic expense account 5000 is unavailable.');

$maker = null;
$reviewer = null;
$credentialId = null;
$cookies = [];
try {
    $maker = qaEnsureActor($pdo, 'maker');
    $reviewer = qaEnsureActor($pdo, 'reviewer');
    $makerCookie = tempnam(sys_get_temp_dir(), 'cf-maker-');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-review-');
    $cookies = array_values(array_filter([$makerCookie, $reviewerCookie], 'is_string'));
    if ($makerCookie === false || $reviewerCookie === false) {
        throw new RuntimeException('Could not create synthetic cookie jars.');
    }
    qaLogin($maker, $makerCookie);
    qaLogin($reviewer, $reviewerCookie);

    $run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
    $sourceId = 'stage-ap-e2e:' . $run;
    $path = '/api/coreone/v1/bills.php?source_record_id=' . rawurlencode($sourceId);
    $body = [
        'schema_version' => 1,
        'source_record_id' => $sourceId,
        'vendor_name' => 'Synthetic CoreOne AP Vendor ' . $run,
        'vendor_type' => 'w9_business',
        'bill_number' => 'COREONE-AP-' . $run,
        'received_at' => '2026-10-07',
        'bill_date' => '2026-10-07',
        'due_date' => '2026-11-06',
        'currency' => 'USD',
        'tax_rate_pct' => '0',
        'notes_internal' => 'Synthetic CoreOne AP lifecycle ' . $run,
        'lines' => [[
            'item_type' => 'expense',
            'description' => 'Invented consulting service',
            'quantity' => '1',
            'unit' => 'each',
            'unit_price' => '41.25',
            'gl_expense_account_code' => '5000',
            'is_1099_eligible' => false,
        ]],
    ];
    $before = qaBalances($pdo, $entityId);
    $reportsBefore = qaReports($entityId, $reviewerCookie);
    $issued = coreoneV1IssueCredential(QA_TENANT, $entityId,
        'Synthetic AP lifecycle ' . $run, 1, (int) $maker['id'], ['bills:prepare']);
    $credentialId = (int) $issued['id'];
    $token = (string) $issued['token'];
    echo "Synthetic CoreOne AP run {$run}\n";

    $missing = qaCoreOneBillRequest('GET', $path, $token);
    qaExpect($missing['status'] === 404, 'unknown source bill is not found');
    $first = qaCoreOneBillRequest('POST', '/api/coreone/v1/bills.php', $token, $body);
    $bill = $first['data']['bill'] ?? [];
    $billId = (int) ($bill['id'] ?? 0);
    qaExpect($first['status'] === 201 && $billId > 0
        && empty($first['data']['idempotent_replay'])
        && $bill['status'] === 'pending_approval'
        && (int) $bill['entity_id'] === $entityId
        && abs((float) $bill['total'] - 41.25) < 0.005
        && $bill['journal_entry_id'] === null,
        'CoreOne prepares one unposted canonical AP bill');
    $source = qaOne($pdo, 'SELECT target_id FROM coreone_document_requests
        WHERE tenant_id = :t AND entity_id = :e AND source_type = "ap.bill"
            AND source_record_id = :source_id',
        ['t' => QA_TENANT, 'e' => $entityId, 'source_id' => $sourceId]);
    $apDetail = qaRequest('/modules/ap/api/bills.php?id=' . $billId, 'GET', null, $reviewerCookie);
    qaExpect((int) ($source['target_id'] ?? 0) === $billId
        && (int) ($apDetail['bill']['id'] ?? 0) === $billId
        && $apDetail['bill']['status'] === 'pending_approval'
        && (int) $apDetail['bill']['created_by_user_id'] === (int) $maker['id']
        && count($apDetail['lines'] ?? []) === 1,
        'same bill is visible in ERP AP with source mapping and key issuer');
    $read = qaCoreOneBillRequest('GET', $path, $token);
    qaExpect($read['status'] === 200 && (int) ($read['data']['id'] ?? 0) === $billId,
        'CoreOne reads the same pending bill by source ID');
    $replay = qaCoreOneBillRequest('POST', '/api/coreone/v1/bills.php', $token, $body);
    qaExpect($replay['status'] === 200
        && !empty($replay['data']['idempotent_replay'])
        && (int) ($replay['data']['bill']['id'] ?? 0) === $billId,
        'exact machine retry reuses the AP bill');
    $changed = $body;
    $changed['lines'][0]['unit_price'] = '42.00';
    $conflict = qaCoreOneBillRequest('POST', '/api/coreone/v1/bills.php', $token, $changed);
    qaExpect($conflict['status'] === 409 && qaBalances($pdo, $entityId) === $before,
        'changed intent conflicts and preparation does not move the ledger');
    $machinePost = qaCoreOneBillRequest('POST',
        '/api/coreone/v1/bills.php?action=post&id=' . $billId, $token, []);
    qaExpect($machinePost['status'] === 422 && qaBalances($pdo, $entityId) === $before,
        'bill-only machine credential cannot post through the intake endpoint');

    try {
        qaRequest('/modules/ap/api/bills.php?action=approve&id=' . $billId,
            'POST', [], $makerCookie);
        $selfApprovalBlocked = false;
    } catch (RuntimeException $error) {
        $selfApprovalBlocked = str_contains($error->getMessage(), 'HTTP 403')
            && str_contains($error->getMessage(), 'Two-eye control');
    }
    qaExpect($selfApprovalBlocked && qaBalances($pdo, $entityId) === $before,
        'service-key issuer cannot approve their own API-prepared bill');

    $approved = qaRequest('/modules/ap/api/bills.php?action=approve&id=' . $billId,
        'POST', [], $reviewerCookie);
    qaExpect(($approved['workflow_status'] ?? '') === 'approved'
        && qaBalances($pdo, $entityId) === $before,
        'independent AP reviewer approves without a journal');
    $posted = qaRequest('/modules/ap/api/bills.php?action=post&id=' . $billId,
        'POST', [], $reviewerCookie);
    $journalId = (int) ($posted['journal_entry_id'] ?? 0);
    $journal = qaOne($pdo, 'SELECT id, entity_id, status, source_module
            FROM accounting_journal_entries
        WHERE tenant_id = :t AND id = :id', ['t' => QA_TENANT, 'id' => $journalId]);
    $sourceLink = qaOne($pdo, 'SELECT journal_entry_id, link_kind
        FROM accounting_subledger_links
        WHERE tenant_id = :t AND source_module = "ap" AND source_record_id = :source_id',
        ['t' => QA_TENANT, 'source_id' => 'ap_bill:' . $billId]);
    $event = qaOne($pdo, 'SELECT status, journal_entry_id
        FROM accounting_events
        WHERE tenant_id = :t AND source_module = "ap" AND source_record_id = :source_id',
        ['t' => QA_TENANT, 'source_id' => 'ap_bill:' . $billId]);
    qaExpect($journalId > 0 && (int) ($journal['entity_id'] ?? 0) === $entityId
        && $journal['status'] === 'posted' && $journal['source_module'] === 'ap'
        && (int) ($sourceLink['journal_entry_id'] ?? 0) === $journalId
        && $sourceLink['link_kind'] === 'primary'
        && ($event['status'] ?? '') === 'posted'
        && (int) $event['journal_entry_id'] === $journalId,
        'human-reviewed bill posts through the shared AP event and journal');
    $after = qaBalances($pdo, $entityId);
    qaExpect(qaDelta($before, $after, '5000', 41.25)
        && qaDelta($before, $after, '2000', -41.25)
        && abs(array_sum($after)) < 0.005,
        'expense and payable move once and entity GL remains balanced');
    $reportsAfter = qaReports($entityId, $reviewerCookie);
    qaExpect(qaDelta($reportsBefore['income_statement'], $reportsAfter['income_statement'],
            'total_expense', 41.25)
        && qaDelta($reportsBefore['income_statement'], $reportsAfter['income_statement'],
            'net_income', -41.25)
        && qaDelta($reportsBefore['balance_sheet'], $reportsAfter['balance_sheet'],
            'total_liabilities', 41.25)
        && !empty($reportsAfter['balance_sheet']['balanced'])
        && qaDelta($reportsBefore['cash_flow_indirect'], $reportsAfter['cash_flow_indirect'],
            'net_change_in_cash', 0)
        && !empty($reportsAfter['cash_flow_indirect']['balanced']),
        'income, balance sheet and cash flow agree before any payment');
    $final = qaCoreOneBillRequest('GET', $path, $token);
    $postReplay = qaCoreOneBillRequest('POST', '/api/coreone/v1/bills.php', $token, $body);
    $journalCount = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM accounting_subledger_links
        WHERE tenant_id = :t AND source_module = "ap" AND source_record_id = :source_id
            AND journal_entry_id = :journal_id',
        ['t' => QA_TENANT, 'source_id' => 'ap_bill:' . $billId,
            'journal_id' => $journalId])['n'];
    qaExpect($final['status'] === 200
        && (int) ($final['data']['journal_entry_id'] ?? 0) === $journalId
        && $postReplay['status'] === 200
        && !empty($postReplay['data']['idempotent_replay'])
        && (int) ($postReplay['data']['bill']['journal_entry_id'] ?? 0) === $journalId
        && $journalCount === 1 && qaBalances($pdo, $entityId) === $after,
        'machine sees posted state and replay cannot duplicate the journal');
    echo json_encode(['run' => $run, 'entity_id' => $entityId,
        'bill_id' => $billId, 'journal_entry_id' => $journalId], JSON_PRETTY_PRINT), "\n";
} finally {
    if ($credentialId !== null) {
        $pdo->prepare('UPDATE coreone_accounting_credentials SET revoked_at = NOW()
            WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $credentialId]);
        if (isset($token, $path)) {
            $revoked = qaCoreOneBillRequest('GET', $path, $token);
            qaExpect($revoked['status'] === 401, 'temporary CoreOne credential is revoked');
        }
    }
    foreach ($cookies as $cookie) {
        if (is_string($cookie) && file_exists($cookie)) unlink($cookie);
    }
    foreach ([$maker, $reviewer] as $actor) {
        if ($actor && isset($actor['id'])) {
            $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
                WHERE id = :id AND tenant_id = :t')
                ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
        }
    }
}
