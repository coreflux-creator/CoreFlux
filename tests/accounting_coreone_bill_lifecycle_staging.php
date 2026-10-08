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
require_once __DIR__ . '/../core/accounting/coreone_bills_v1.php';

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
$credentials = [];
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
    $credentials[] = $issued;
    $token = (string) $issued['token'];
    $reviewIssued = coreoneV1IssueCredential(QA_TENANT, $entityId,
        'Synthetic AP review ' . $run, 1, (int) $maker['id'], ['bills:request_approval']);
    $credentials[] = $reviewIssued;
    $reviewToken = (string) $reviewIssued['token'];
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
    $reviewRead = qaCoreOneBillRequest('GET', $path, $reviewToken);
    qaExpect($reviewRead['status'] === 200
        && (int) ($reviewRead['data']['id'] ?? 0) === $billId
        && ($reviewRead['data']['approval_status'] ?? '') === 'not_requested',
        'request-only credential can poll the unsubmitted bill');
    $reviewBody = ['schema_version' => 1, 'source_record_id' => $sourceId];
    $requestPath = '/api/coreone/v1/bills.php?action=request_approval';
    $otherEntity = qaOne($pdo, 'SELECT id FROM accounting_entities
        WHERE tenant_id = :t AND code = "SIM-CUTOVER-QA" AND active = 1',
        ['t' => QA_TENANT]);
    if (!$otherEntity) throw new RuntimeException('Synthetic isolation entity is unavailable.');
    $otherIssued = coreoneV1IssueCredential(QA_TENANT, (int) $otherEntity['id'],
        'Synthetic AP isolation ' . $run, 1, (int) $maker['id'], ['bills:request_approval']);
    $credentials[] = $otherIssued;
    $otherToken = (string) $otherIssued['token'];
    $crossEntityRead = qaCoreOneBillRequest('GET', $path, $otherToken);
    $crossEntityRequest = qaCoreOneBillRequest('POST', $requestPath, $otherToken, $reviewBody);
    qaExpect($crossEntityRead['status'] === 404 && $crossEntityRequest['status'] === 404,
        'another entity cannot read or request review of the source bill');
    $wrongScope = qaCoreOneBillRequest('POST', $requestPath, $token, $reviewBody);
    $cannotPrepare = qaCoreOneBillRequest('POST', '/api/coreone/v1/bills.php', $reviewToken, $body);
    qaExpect($wrongScope['status'] === 403 && $cannotPrepare['status'] === 403,
        'preparation and review-request scopes do not imply each other');
    $badReview = qaCoreOneBillRequest('POST', $requestPath, $reviewToken,
        $reviewBody + ['approver_user_id' => (int) $maker['id']]);
    $missingReview = qaCoreOneBillRequest('POST', $requestPath, $reviewToken,
        ['schema_version' => 1, 'source_record_id' => 'stage-ap-e2e:missing-' . $run]);
    qaExpect($badReview['status'] === 422 && $missingReview['status'] === 404
        && qaBalances($pdo, $entityId) === $before,
        'review request accepts only its source key and creates no missing bill');
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

    require_once __DIR__ . '/../modules/ap/lib/approval_router.php';
    $reviewCredential = coreoneV1Authenticate('Bearer ' . $reviewToken);
    $unroutedBill = qaOne($pdo, 'SELECT * FROM ap_bills
        WHERE tenant_id = :t AND entity_id = :e AND id = :bill_id',
        ['t' => QA_TENANT, 'e' => $entityId, 'bill_id' => $billId]);
    if (!$reviewCredential || !$unroutedBill) {
        throw new RuntimeException('Could not prepare reviewer failure proof.');
    }
    $evaluation = apEvaluateApprovalPolicy(QA_TENANT, $unroutedBill);
    $policyId = (int) ($evaluation['policy_id'] ?? 0);
    if ($policyId <= 0 || empty($evaluation['chain'])) {
        throw new RuntimeException('Synthetic reviewer policy is unavailable.');
    }
    $pdo->beginTransaction();
    try {
        $makerOnlyChain = json_encode([['step' => 1,
            'approver_user_ids' => [(int) $maker['id']], 'quorum' => 1,
            'label' => 'Self approval is prohibited']], JSON_THROW_ON_ERROR);
        $pdo->prepare('UPDATE ap_approval_policies SET chain_json = :chain
            WHERE tenant_id = :t AND id = :id')
            ->execute(['chain' => $makerOnlyChain, 't' => QA_TENANT, 'id' => $policyId]);
        $routingBlocked = false;
        try {
            coreoneV1RequestBillApproval($reviewCredential, $reviewBody);
        } catch (CoreOneDocumentConflictException $error) {
            $routingBlocked = str_contains($error->getMessage(), 'creator cannot approve');
        }
        $orphanWorkflow = qaOne($pdo, 'SELECT COUNT(*) AS n FROM workflow_instances
            WHERE tenant_id = :t AND subject_type = "ap_bill" AND subject_id = :bill_id',
            ['t' => QA_TENANT, 'bill_id' => $billId]);
        $orphanReview = qaOne($pdo, 'SELECT COUNT(*) AS n FROM ap_bill_approvals
            WHERE tenant_id = :t AND bill_id = :bill_id',
            ['t' => QA_TENANT, 'bill_id' => $billId]);
        qaExpect($routingBlocked && (int) $orphanWorkflow['n'] === 0
            && (int) $orphanReview['n'] === 0
            && qaBalances($pdo, $entityId) === $before,
            'no independent reviewer leaves no workflow, approval rows or GL movement');
    } finally {
        $pdo->rollBack();
    }

    $requested = qaCoreOneBillRequest('POST', $requestPath, $reviewToken, $reviewBody);
    $workflowId = (int) ($requested['data']['bill']['approval_workflow_id'] ?? 0);
    $workflow = qaOne($pdo, 'SELECT status, started_by_user_id FROM workflow_instances
        WHERE tenant_id = :t AND id = :id AND subject_type = "ap_bill" AND subject_id = :bill_id',
        ['t' => QA_TENANT, 'id' => $workflowId, 'bill_id' => $billId]);
    $approvalRows = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM ap_bill_approvals
        WHERE tenant_id = :t AND bill_id = :bill_id AND state = "pending"',
        ['t' => QA_TENANT, 'bill_id' => $billId])['n'];
    qaExpect($requested['status'] === 202 && $workflowId > 0
        && !empty($requested['data']['approval_requested'])
        && ($requested['data']['bill']['approval_status'] ?? '') === 'pending'
        && ($workflow['status'] ?? '') === 'pending'
        && (int) $workflow['started_by_user_id'] === (int) $maker['id']
        && $approvalRows > 0 && qaBalances($pdo, $entityId) === $before,
        'machine starts the existing AP human review without posting');
    $requestReplay = qaCoreOneBillRequest('POST', $requestPath, $reviewToken, $reviewBody);
    $workflowCount = (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM workflow_instances
        WHERE tenant_id = :t AND subject_type = "ap_bill" AND subject_id = :bill_id',
        ['t' => QA_TENANT, 'bill_id' => $billId])['n'];
    qaExpect($requestReplay['status'] === 200
        && !empty($requestReplay['data']['idempotent_replay'])
        && (int) ($requestReplay['data']['bill']['approval_workflow_id'] ?? 0) === $workflowId
        && $workflowCount === 1,
        'review-request retry reuses one human workflow');

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
        && ($final['data']['approval_status'] ?? '') === 'approved'
        && $postReplay['status'] === 200
        && !empty($postReplay['data']['idempotent_replay'])
        && (int) ($postReplay['data']['bill']['journal_entry_id'] ?? 0) === $journalId
        && $journalCount === 1 && qaBalances($pdo, $entityId) === $after,
        'machine sees posted state and replay cannot duplicate the journal');
    $reviewAfterPost = qaCoreOneBillRequest('POST', $requestPath, $reviewToken, $reviewBody);
    qaExpect($reviewAfterPost['status'] === 200
        && !empty($reviewAfterPost['data']['idempotent_replay'])
        && (int) ($reviewAfterPost['data']['bill']['journal_entry_id'] ?? 0) === $journalId
        && $workflowCount === (int) qaOne($pdo, 'SELECT COUNT(*) AS n FROM workflow_instances
            WHERE tenant_id = :t AND subject_type = "ap_bill" AND subject_id = :bill_id',
            ['t' => QA_TENANT, 'bill_id' => $billId])['n'],
        'post-approval machine retry remains read-only');
    echo json_encode(['run' => $run, 'entity_id' => $entityId,
        'bill_id' => $billId, 'journal_entry_id' => $journalId], JSON_PRETTY_PRINT), "\n";
} finally {
    foreach ($credentials as $credential) {
        $pdo->prepare('UPDATE coreone_accounting_credentials SET revoked_at = NOW()
            WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => (int) $credential['id']]);
    }
    if ($credentials && isset($token, $reviewToken, $otherToken, $path)) {
        $revoked = qaCoreOneBillRequest('GET', $path, $token);
        $reviewRevoked = qaCoreOneBillRequest('GET', $path, $reviewToken);
        $otherRevoked = qaCoreOneBillRequest('GET', $path, $otherToken);
        qaExpect($revoked['status'] === 401 && $reviewRevoked['status'] === 401
            && $otherRevoked['status'] === 401,
            'temporary preparation, review and isolation credentials are revoked');
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
