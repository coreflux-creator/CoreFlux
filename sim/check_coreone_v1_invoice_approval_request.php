<?php
/** Rollback-only approval-request check on an isolated simulation tenant. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/accounting/coreone_invoices_v1.php';

$tenantId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) $tenantId = (int) substr($arg, 9);
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a disposable test tenant.\n");
    exit(2);
}
setRequestTenantId($tenantId);
setRequestModuleScope('billing');
$pdo = getDB();
$tenant = $pdo->prepare('SELECT name FROM tenants WHERE id = :id');
$tenant->execute(['id' => $tenantId]);
$tenantName = (string) $tenant->fetchColumn();
$databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$localQa = in_array('--local-qa', $argv, true)
    && preg_match('/^coreaccounting_blank_test[0-9]+$/D', $databaseName)
    && str_starts_with($tenantName, 'CoreAccounting ');
if (!in_array($tenantName, ['CoreFlux CI Simulation', "CoreFlux CI Simulation {$tenantId}"], true)
    && !$localQa) {
    fwrite(STDERR, "This check requires a disposable simulation tenant or explicit local QA database.\n");
    exit(2);
}
$entity = $pdo->prepare('SELECT id, base_currency FROM accounting_entities WHERE tenant_id = :t AND active = 1 ORDER BY id LIMIT 1');
$entity->execute(['t' => $tenantId]);
$entity = $entity->fetch(PDO::FETCH_ASSOC);
if (!$entity) {
    fwrite(STDERR, "Seed an active synthetic entity first.\n");
    exit(2);
}

$checks = [];
$rejects = static function (callable $action, string $class): bool {
    try { $action(); return false; }
    catch (Throwable $e) { return $e instanceof $class; }
};
$sourceId = 'stage-approval:' . bin2hex(random_bytes(8));
$body = [
    'schema_version' => 1,
    'source_record_id' => $sourceId,
    'client_name' => 'Rollback-only approval client ' . bin2hex(random_bytes(4)),
    'issue_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+30 days')),
    'currency' => (string) $entity['base_currency'],
    'tax_rate_pct' => '0',
    'lines' => [['description' => 'Approval fixture', 'quantity' => '1',
        'unit' => 'each', 'unit_price' => '25.00', 'taxable' => false]],
];
$request = ['schema_version' => 1, 'source_record_id' => $sourceId];

try {
    $pdo->beginTransaction();
    $draftToken = coreoneV1IssueCredential($tenantId, (int) $entity['id'],
        'Approval draft fixture', 1, null, ['invoices:draft']);
    $requestToken = coreoneV1IssueCredential($tenantId, (int) $entity['id'],
        'Approval request fixture', 1, null, ['invoices:request_approval']);
    $draftCredential = coreoneV1Authenticate('Bearer ' . $draftToken['token']);
    $requestCredential = coreoneV1Authenticate('Bearer ' . $requestToken['token']);
    if (!$draftCredential || !$requestCredential) throw new RuntimeException('Fixture credential did not authenticate.');
    $checks['request_scope_is_separate_from_draft_scope'] =
        !coreoneV1HasScope($draftCredential, 'invoices:request_approval')
        && !coreoneV1HasScope($requestCredential, 'invoices:draft');

    $draft = coreoneV1CreateInvoiceDraft($draftCredential, $body)['invoice'];
    $invoiceId = (int) $draft['id'];
    $checks['draft_starts_without_workflow'] = $draft['status'] === 'draft'
        && $draft['workflow_instance_id'] === null;
    $routing = billingInvoiceApprovalRouting($tenantId, billingInvoiceWorkflowRow($tenantId, $invoiceId));
    if (empty($routing['workflow_required'])) {
        $checks['no_policy_refuses_machine_request'] = $rejects(
            static fn() => coreoneV1RequestInvoiceApproval($requestCredential, $request),
            CoreOneDocumentConflictException::class
        );
    }

    $pdo->prepare('INSERT INTO users (name, email, role, is_active)
        VALUES (:name, :email, "tenant_admin", 1)')->execute([
        'name' => 'Rollback-only Invoice Approver',
        'email' => 'approval-' . bin2hex(random_bytes(8)) . '@coreflux.test',
    ]);
    $approverId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_tenants (user_id, tenant_id, role, status)
        VALUES (:user_id, :tenant_id, "tenant_admin", "active")')->execute([
        'user_id' => $approverId, 'tenant_id' => $tenantId,
    ]);
    $policy = peopleGraphCreateApprovalPolicy($tenantId, [
        'policy_key' => 'coreone-approval-' . bin2hex(random_bytes(8)),
        'name' => 'Rollback-only invoice approval',
        'resource_module' => 'billing',
        'resource_type' => 'invoice',
    ]);
    peopleGraphCreateApprovalRule($tenantId, [
        'policy_id' => (int) $policy['id'],
        'approver_strategy' => 'named_actor',
        'approver_actor_type' => 'user',
        'approver_actor_id' => $approverId,
        'conditions' => ['invoice_id' => $invoiceId],
        'separation_of_duties_required' => true,
    ]);
    $started = coreoneV1RequestInvoiceApproval($requestCredential, $request);
    $pendingId = (int) ($started['invoice']['workflow_instance_id'] ?? 0);
    $checks['request_starts_one_pending_canonical_workflow'] = !$started['idempotent_replay']
        && $started['approval_requested'] && $pendingId > 0
        && $started['invoice']['status'] === 'draft'
        && $started['invoice']['workflow_status'] === 'pending';
    $again = coreoneV1RequestInvoiceApproval($requestCredential, $request);
    $checks['request_retry_reuses_pending_workflow'] = $again['idempotent_replay']
        && !$again['approval_requested']
        && (int) $again['invoice']['workflow_instance_id'] === $pendingId;

    $pdo->prepare('INSERT INTO accounting_entities (tenant_id, code, legal_name, base_currency, active)
        VALUES (:tenant_id, :code, "Rollback-only second approval entity", :currency, 1)')->execute([
        'tenant_id' => $tenantId, 'code' => 'SIM-APR-' . bin2hex(random_bytes(4)),
        'currency' => (string) $entity['base_currency'],
    ]);
    $foreignEntity = (int) $pdo->lastInsertId();
    $otherToken = coreoneV1IssueCredential($tenantId, $foreignEntity,
        'Other entity approval fixture', 1, null, ['invoices:request_approval']);
    $otherCredential = coreoneV1Authenticate('Bearer ' . $otherToken['token']);
    $checks['another_entity_cannot_request_or_read_approval'] = $otherCredential !== null
        && $rejects(static fn() => coreoneV1RequestInvoiceApproval($otherCredential, $request),
            OutOfBoundsException::class)
        && coreoneV1GetInvoiceDraft($otherCredential, $sourceId) === null;

    $checks['invoice_workflow_skip_is_not_approval'] = $rejects(
        static fn() => workflowAct($tenantId, $pendingId, $approverId, 'skip'),
        InvalidArgumentException::class
    ) && billingInvoiceWorkflowRow($tenantId, $invoiceId)['status'] === 'draft';
    $checks['nonmember_cannot_act_as_invoice_reviewer'] = $rejects(
        static fn() => billingInvoiceWorkflowAct($tenantId, $invoiceId, PHP_INT_MAX, 'approve'),
        RuntimeException::class
    ) && billingInvoiceWorkflowRow($tenantId, $invoiceId)['status'] === 'draft';

    $acted = billingInvoiceWorkflowAct($tenantId, $invoiceId, $approverId, 'approve');
    $approved = coreoneV1GetInvoiceDraft($requestCredential, $sourceId);
    $checks['human_action_approves_same_billing_invoice'] = !empty($acted['approved'])
        && $approved['status'] === 'approved'
        && $approved['workflow_status'] === 'approved'
        && (int) $approved['workflow_instance_id'] === $pendingId;
    $after = coreoneV1RequestInvoiceApproval($requestCredential, $request);
    $checks['approved_invoice_is_not_routed_again'] = $after['idempotent_replay']
        && !$after['approval_requested'] && $after['invoice']['status'] === 'approved';

    $reviewSource = 'stage-approval-review:' . bin2hex(random_bytes(8));
    $reviewBody = $body;
    $reviewBody['source_record_id'] = $reviewSource;
    $review = coreoneV1CreateInvoiceDraft($draftCredential, $reviewBody)['invoice'];
    peopleGraphCreateApprovalRule($tenantId, [
        'policy_id' => (int) $policy['id'],
        'approver_strategy' => 'named_actor',
        'approver_actor_type' => 'user',
        'approver_actor_id' => $approverId,
        'conditions' => ['invoice_id' => (int) $review['id']],
        'separation_of_duties_required' => true,
    ]);
    $reviewRequest = ['schema_version' => 1, 'source_record_id' => $reviewSource];
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute(['id' => $approverId]);
    $checks['inactive_approver_cannot_receive_machine_request'] = $rejects(
        static fn() => coreoneV1RequestInvoiceApproval($requestCredential, $reviewRequest),
        CoreOneDocumentConflictException::class
    ) && coreoneV1GetInvoiceDraft($requestCredential, $reviewSource)['workflow_instance_id'] === null;
    $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')->execute(['id' => $approverId]);
    $pdo->prepare('UPDATE user_tenants SET role = "employee" WHERE tenant_id = :tenant_id AND user_id = :user_id')
        ->execute(['tenant_id' => $tenantId, 'user_id' => $approverId]);
    $checks['approver_without_billing_permission_cannot_receive_request'] = $rejects(
        static fn() => coreoneV1RequestInvoiceApproval($requestCredential, $reviewRequest),
        CoreOneDocumentConflictException::class
    ) && coreoneV1GetInvoiceDraft($requestCredential, $reviewSource)['workflow_instance_id'] === null;
    $pdo->prepare('UPDATE user_tenants SET role = "tenant_admin" WHERE tenant_id = :tenant_id AND user_id = :user_id')
        ->execute(['tenant_id' => $tenantId, 'user_id' => $approverId]);
    coreoneV1RequestInvoiceApproval($requestCredential, $reviewRequest);
    $rejected = billingInvoiceWorkflowAct($tenantId, (int) $review['id'], $approverId,
        'reject', 'Fixture review rejection');
    $checks['rejected_workflow_cannot_be_restarted_by_machine_retry'] =
        ($rejected['instance']['status'] ?? null) === 'rejected'
        && $rejects(static fn() => coreoneV1RequestInvoiceApproval($requestCredential, $reviewRequest),
            CoreOneDocumentConflictException::class);

    $voidSource = 'stage-approval-void:' . bin2hex(random_bytes(8));
    $voidBody = $body;
    $voidBody['source_record_id'] = $voidSource;
    $void = coreoneV1CreateInvoiceDraft($draftCredential, $voidBody)['invoice'];
    $pdo->prepare('UPDATE billing_invoices SET status = "void" WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => (int) $void['id']]);
    $checks['void_invoice_refuses_approval_request'] = $rejects(
        static fn() => coreoneV1RequestInvoiceApproval($requestCredential,
            ['schema_version' => 1, 'source_record_id' => $voidSource]),
        CoreOneDocumentConflictException::class
    );
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    $checks['fixture_completed_without_exception'] = false;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$checks['rollback_removed_invoice_and_workflow'] = coreoneV1GetInvoiceDraft(
    ['tenant_id' => $tenantId, 'entity_id' => (int) $entity['id']], $sourceId
) === null;
$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $passed) echo ($passed ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
