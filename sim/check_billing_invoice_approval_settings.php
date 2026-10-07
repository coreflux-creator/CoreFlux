<?php
/** Rollback-only Billing reviewer setup check on an isolated tenant. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../modules/billing/lib/approval_settings.php';
require_once __DIR__ . '/../modules/billing/lib/workflow.php';
require_once __DIR__ . '/../modules/billing/lib/invoice_drafts.php';
require_once __DIR__ . '/../modules/billing/lib/approval_assignment.php';

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

$checks = [];
$rejects = static function (callable $action, string $class): bool {
    try { $action(); return false; }
    catch (Throwable $e) { return $e instanceof $class; }
};
$original = billingInvoiceApprovalSettingsRead($tenantId);
if ($original['configured']) {
    fwrite(STDERR, "A default reviewer policy already exists; use a disposable tenant without one.\n");
    exit(2);
}
$fixtureEmail = 'reviewer-' . bin2hex(random_bytes(8)) . '@coreflux.test';

try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO users (name, email, role, is_active)
        VALUES ("Rollback-only Reviewer", :email, "tenant_admin", 1)')
        ->execute(['email' => $fixtureEmail]);
    $reviewerId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_tenants (user_id, tenant_id, role, status)
        VALUES (:user_id, :tenant_id, "tenant_admin", "active")')
        ->execute(['user_id' => $reviewerId, 'tenant_id' => $tenantId]);
    $checks['eligible_active_tenant_admin'] = billingInvoiceReviewerIsEligible($tenantId, $reviewerId);
    $checks['empty_selection_rejected'] = $rejects(
        static fn() => billingInvoiceApprovalSettingsSave($tenantId, [], $reviewerId),
        InvalidArgumentException::class
    );
    $checks['nonmember_selection_rejected'] = $rejects(
        static fn() => billingInvoiceApprovalSettingsSave($tenantId, [PHP_INT_MAX], $reviewerId),
        InvalidArgumentException::class
    );

    $saved = billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId], $reviewerId);
    $checks['default_policy_is_configured'] = $saved['configured']
        && $saved['reviewer_user_ids'] === [$reviewerId];
    $rows = peopleGraphListApprovalPolicies($tenantId, [
        'resource_module' => 'billing', 'resource_type' => 'invoice',
    ]);
    $policy = current(array_filter($rows, static fn(array $row): bool =>
        $row['policy_key'] === BILLING_INVOICE_APPROVAL_POLICY_KEY));
    $rules = $policy ? peopleGraphListApprovalRules($tenantId, ['policy_id' => $policy['id']]) : [];
    $checks['uses_shared_people_graph_policy_and_rule'] = $policy !== false
        && count($rules) === 1
        && $rules[0]['approver_strategy'] === 'named_actor'
        && $rules[0]['separation_of_duties_required'] === true;

    billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId], $reviewerId);
    $sameRules = peopleGraphListApprovalRules($tenantId, ['policy_id' => $policy['id']]);
    $checks['exact_save_does_not_duplicate_rules'] = count($sameRules) === 1;

    $pdo->prepare('INSERT INTO users (name, email, role, is_active)
        VALUES ("Rollback-only Second Reviewer", :email, "tenant_admin", 1)')
        ->execute(['email' => 'reviewer-' . bin2hex(random_bytes(8)) . '@coreflux.test']);
    $secondId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_tenants (user_id, tenant_id, role, status)
        VALUES (:user_id, :tenant_id, "tenant_admin", "active")')
        ->execute(['user_id' => $secondId, 'tenant_id' => $tenantId]);
    $changed = billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId, $secondId], $reviewerId);
    $checks['multiple_reviewers_replace_policy_atomically'] = $changed['reviewer_user_ids'] === [$reviewerId, $secondId]
        && count(peopleGraphListApprovalRules($tenantId, ['policy_id' => $policy['id']])) === 2;

    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute(['id' => $secondId]);
    $stale = billingInvoiceApprovalSettingsRead($tenantId);
    $checks['inactive_reviewer_visible_as_stale'] = $stale['unavailable_reviewer_user_ids'] === [$secondId]
        && !billingInvoiceReviewerIsEligible($tenantId, $secondId);
    $checks['inactive_reviewer_cannot_be_saved'] = $rejects(
        static fn() => billingInvoiceApprovalSettingsSave($tenantId, [$secondId], $reviewerId),
        InvalidArgumentException::class
    );
    $clean = billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId], $reviewerId);
    $checks['stale_reviewer_can_be_replaced'] = $clean['reviewer_user_ids'] === [$reviewerId]
        && $clean['unavailable_reviewer_user_ids'] === [];

    $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')->execute(['id' => $secondId]);
    $pdo->prepare('INSERT INTO users (name, email, role, is_active)
        VALUES ("Rollback-only New Reviewer", :email, "tenant_admin", 1)')
        ->execute(['email' => 'reviewer-' . bin2hex(random_bytes(8)) . '@coreflux.test']);
    $newId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_tenants (user_id, tenant_id, role, status)
        VALUES (:user_id, :tenant_id, "tenant_admin", "active")')
        ->execute(['user_id' => $newId, 'tenant_id' => $tenantId]);
    $entity = $pdo->prepare('SELECT id, base_currency FROM accounting_entities
        WHERE tenant_id = :tenant_id AND active = 1 ORDER BY id LIMIT 1');
    $entity->execute(['tenant_id' => $tenantId]);
    $entity = $entity->fetch(PDO::FETCH_ASSOC);
    if (!$entity) throw new RuntimeException('Fixture needs an active synthetic entity.');
    $invoiceBody = [
        'client_name' => 'Rollback-only reviewer client ' . bin2hex(random_bytes(4)),
        'entity_id' => (int) $entity['id'],
        'issue_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+30 days')),
        'currency' => (string) $entity['base_currency'],
        'tax_rate_pct' => '0',
        'lines' => [[
            'description' => 'Reviewer snapshot fixture', 'quantity' => '1',
            'unit' => 'each', 'unit_price' => '1.00', 'taxable' => false,
        ]],
    ];

    billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId, $secondId], $reviewerId);
    $invoice = billingCreateDirectInvoiceDraft($tenantId, $invoiceBody, $reviewerId);
    $invoiceId = (int) $invoice['id'];
    $instanceId = billingInvoiceWorkflowStart($tenantId, $invoiceId, $reviewerId);
    $payloadStmt = $pdo->prepare('SELECT payload_json FROM workflow_instances
        WHERE tenant_id = :tenant_id AND id = :instance_id');
    $payloadStmt->execute(['tenant_id' => $tenantId, 'instance_id' => $instanceId]);
    $payload = json_decode((string) $payloadStmt->fetchColumn(), true) ?: [];
    $checks['pending_assignment_snapshots_independent_reviewer'] = $instanceId > 0
        && ($payload['billing_reviewer_user_ids_snapshot'] ?? null) === [$secondId];
    $checks['maker_cannot_approve_own_draft'] = $rejects(
        static fn() => billingInvoiceWorkflowAct($tenantId, $invoiceId, $reviewerId),
        RuntimeException::class
    ) && billingInvoiceWorkflowRow($tenantId, $invoiceId)['status'] === 'draft';

    billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId, $newId], $reviewerId);
    $oldInbox = workflowGetPendingForUser($tenantId, $secondId, 'billing_invoice');
    $newInbox = workflowGetPendingForUser($tenantId, $newId, 'billing_invoice');
    $checks['pending_inbox_keeps_original_reviewer'] = in_array($instanceId,
        array_column($oldInbox, 'id'), true)
        && !in_array($instanceId, array_column($newInbox, 'id'), true);
    $checks['new_reviewer_cannot_take_pending_assignment'] = $rejects(
        static fn() => billingInvoiceWorkflowAct($tenantId, $invoiceId, $newId),
        RuntimeException::class
    ) && billingInvoiceWorkflowRow($tenantId, $invoiceId)['status'] === 'draft';
    $decision = billingInvoiceWorkflowAct($tenantId, $invoiceId, $secondId);
    $checks['original_reviewer_can_approve_after_policy_change'] = !empty($decision['approved'])
        && billingInvoiceWorkflowRow($tenantId, $invoiceId)['status'] === 'approved';

    billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId, $secondId], $reviewerId);
    $reassignInvoice = billingCreateDirectInvoiceDraft($tenantId, $invoiceBody, $reviewerId);
    $reassignInvoiceId = (int) $reassignInvoice['id'];
    $initialAssignment = billingInvoiceApprovalAssignmentRead($tenantId, $reassignInvoiceId, $reviewerId);
    $checks['maker_can_request_independent_review'] = $initialAssignment['viewer_can_request']
        && !$initialAssignment['viewer_can_approve'];
    $reassignInstanceId = billingInvoiceWorkflowStart($tenantId, $reassignInvoiceId, $reviewerId);
    billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId, $newId], $reviewerId);
    $repeatId = billingInvoiceWorkflowStart($tenantId, $reassignInvoiceId, $reviewerId);
    $beforeReassign = billingInvoiceApprovalAssignmentRead($tenantId, $reassignInvoiceId, $newId);
    $checks['repeated_start_preserves_pending_assignment'] = $repeatId === $reassignInstanceId
        && $beforeReassign['assigned_reviewer_user_ids'] === [$secondId]
        && !$beforeReassign['viewer_can_approve'];
    $checks['cannot_reassign_to_invoice_maker'] = $rejects(
        static fn() => billingInvoiceApprovalReassign($tenantId, $reassignInvoiceId, [$reviewerId], $reviewerId),
        InvalidArgumentException::class
    ) && billingInvoiceApprovalAssignmentRead($tenantId, $reassignInvoiceId)['assigned_reviewer_user_ids'] === [$secondId];
    $reassigned = billingInvoiceApprovalReassign($tenantId, $reassignInvoiceId, [$newId], $reviewerId);
    $checks['explicit_reassignment_changes_pending_reviewer'] = $reassigned['assigned_reviewer_user_ids'] === [$newId]
        && $reassigned['managed_assignment'];
    $checks['old_reviewer_cannot_approve_after_reassignment'] = $rejects(
        static fn() => billingInvoiceWorkflowAct($tenantId, $reassignInvoiceId, $secondId),
        RuntimeException::class
    );
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id')->execute(['id' => $newId]);
    $checks['revoked_reviewer_cannot_approve_or_see_inbox'] = $rejects(
        static fn() => billingInvoiceWorkflowAct($tenantId, $reassignInvoiceId, $newId),
        RuntimeException::class
    ) && !in_array($reassignInstanceId,
        array_column(workflowGetPendingForUser($tenantId, $newId, 'billing_invoice'), 'id'), true);
    $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')->execute(['id' => $newId]);
    $newDecision = billingInvoiceWorkflowAct($tenantId, $reassignInvoiceId, $newId);
    $checks['reassigned_reviewer_can_approve'] = !empty($newDecision['approved'])
        && billingInvoiceWorkflowRow($tenantId, $reassignInvoiceId)['status'] === 'approved';

    billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId, $secondId], $reviewerId);
    $rejectedInvoice = billingCreateDirectInvoiceDraft($tenantId, $invoiceBody, $reviewerId);
    $rejectedInvoiceId = (int) $rejectedInvoice['id'];
    $rejectedInstanceId = billingInvoiceWorkflowStart($tenantId, $rejectedInvoiceId, $reviewerId);
    billingInvoiceWorkflowAct($tenantId, $rejectedInvoiceId, $secondId, 'reject', 'Rollback-only review rejection');
    $rejectedAssignment = billingInvoiceApprovalAssignmentRead($tenantId, $rejectedInvoiceId, $reviewerId);
    $checks['rejected_invoice_exposes_terminal_review'] = $rejectedAssignment['prior_review_status'] === 'rejected'
        && $rejectedAssignment['prior_review_note'] === 'Rollback-only review rejection'
        && $rejectedAssignment['viewer_can_request'];
    $checks['rejected_invoice_requires_explicit_resubmission'] = $rejects(
        static fn() => billingInvoiceWorkflowAct($tenantId, $rejectedInvoiceId, $secondId),
        RuntimeException::class
    ) && billingInvoiceWorkflowRow($tenantId, $rejectedInvoiceId)['status'] === 'draft';
    $pdo->prepare('UPDATE people_graph_approval_policies SET status = "inactive"
        WHERE tenant_id = :tenant_id AND policy_key = :policy_key')
        ->execute(['tenant_id' => $tenantId, 'policy_key' => BILLING_INVOICE_APPROVAL_POLICY_KEY]);
    $checks['removed_policy_cannot_bypass_rejected_review'] = $rejects(
        static fn() => billingInvoiceWorkflowAct($tenantId, $rejectedInvoiceId, $secondId),
        RuntimeException::class
    ) && billingInvoiceWorkflowRow($tenantId, $rejectedInvoiceId)['status'] === 'draft';
    $pdo->prepare('UPDATE people_graph_approval_policies SET status = "active"
        WHERE tenant_id = :tenant_id AND policy_key = :policy_key')
        ->execute(['tenant_id' => $tenantId, 'policy_key' => BILLING_INVOICE_APPROVAL_POLICY_KEY]);
    $secondAttemptId = billingInvoiceWorkflowStart($tenantId, $rejectedInvoiceId, $reviewerId);
    $checks['rejected_invoice_starts_distinct_pending_attempt'] = $secondAttemptId > 0
        && $secondAttemptId !== $rejectedInstanceId
        && billingInvoiceWorkflowPendingInstanceId($tenantId, $rejectedInvoiceId) === $secondAttemptId
        && billingInvoiceWorkflowStart($tenantId, $rejectedInvoiceId, $reviewerId) === $secondAttemptId;
    $history = $pdo->prepare('SELECT id, status, unique_subject_id FROM workflow_instances
        WHERE tenant_id = :tenant_id AND subject_type = "billing_invoice"
          AND subject_id = :invoice_id ORDER BY id');
    $history->execute(['tenant_id' => $tenantId, 'invoice_id' => $rejectedInvoiceId]);
    $attempts = $history->fetchAll(PDO::FETCH_ASSOC);
    $checks['old_decision_retained_with_one_live_attempt'] = count($attempts) === 2
        && (int) $attempts[0]['id'] === $rejectedInstanceId
        && $attempts[0]['status'] === 'rejected' && $attempts[0]['unique_subject_id'] === null
        && (int) $attempts[1]['id'] === $secondAttemptId
        && $attempts[1]['status'] === 'pending'
        && (int) $attempts[1]['unique_subject_id'] === $rejectedInvoiceId;
    $duplicatePending = $pdo->prepare('INSERT INTO workflow_instances
        (tenant_id, definition_id, subject_type, subject_id, status, current_step)
        SELECT tenant_id, definition_id, subject_type, subject_id, status, current_step
          FROM workflow_instances WHERE tenant_id = :tenant_id AND id = :instance_id');
    $checks['database_rejects_second_pending_attempt'] = $rejects(
        static fn() => $duplicatePending->execute([
            'tenant_id' => $tenantId, 'instance_id' => $secondAttemptId,
        ]), PDOException::class
    );
    $pdo->prepare('INSERT INTO workflow_instances
        (tenant_id, definition_id, subject_type, subject_id, status, current_step)
        SELECT tenant_id, definition_id, "rollback_unique_subject", subject_id, "rejected", current_step
          FROM workflow_instances WHERE tenant_id = :tenant_id AND id = :instance_id')
        ->execute(['tenant_id' => $tenantId, 'instance_id' => $rejectedInstanceId]);
    $duplicateOtherSubject = $pdo->prepare('INSERT INTO workflow_instances
        (tenant_id, definition_id, subject_type, subject_id, status, current_step)
        SELECT tenant_id, definition_id, subject_type, subject_id, status, current_step
          FROM workflow_instances WHERE tenant_id = :tenant_id
            AND subject_type = "rollback_unique_subject" AND subject_id = :subject_id LIMIT 1');
    $checks['other_workflow_subjects_stay_unique'] = $rejects(
        static fn() => $duplicateOtherSubject->execute([
            'tenant_id' => $tenantId, 'subject_id' => $rejectedInvoiceId,
        ]), PDOException::class
    );
    $secondDecision = billingInvoiceWorkflowAct($tenantId, $rejectedInvoiceId, $secondId);
    $checks['second_attempt_approves_without_erasing_rejection'] = !empty($secondDecision['approved'])
        && billingInvoiceWorkflowRow($tenantId, $rejectedInvoiceId)['status'] === 'approved'
        && workflowGetInstance($tenantId, $rejectedInstanceId)['status'] === 'rejected'
        && workflowGetInstance($tenantId, $secondAttemptId)['status'] === 'approved';
    $duplicateApproved = $pdo->prepare('INSERT INTO workflow_instances
        (tenant_id, definition_id, subject_type, subject_id, status, current_step)
        SELECT tenant_id, definition_id, subject_type, subject_id, status, current_step
          FROM workflow_instances WHERE tenant_id = :tenant_id AND id = :instance_id');
    $checks['database_rejects_second_approved_attempt'] = $rejects(
        static fn() => $duplicateApproved->execute([
            'tenant_id' => $tenantId, 'instance_id' => $secondAttemptId,
        ]), PDOException::class
    );

    billingInvoiceApprovalSettingsSave($tenantId, [$reviewerId], $reviewerId);
    $selfOnly = billingCreateDirectInvoiceDraft($tenantId, $invoiceBody, $reviewerId);
    $selfOnlyId = (int) $selfOnly['id'];
    $checks['self_only_policy_cannot_start_dead_end_workflow'] = $rejects(
        static fn() => billingInvoiceWorkflowAct($tenantId, $selfOnlyId, $reviewerId),
        RuntimeException::class
    ) && billingInvoiceWorkflowPendingInstanceId($tenantId, $selfOnlyId) === 0;
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    $checks['fixture_completed_without_exception'] = false;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$after = billingInvoiceApprovalSettingsRead($tenantId);
$checks['rollback_removed_policy_and_reviewers'] = $after['configured'] === $original['configured']
    && $after['reviewer_user_ids'] === $original['reviewer_user_ids'];
$failed = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $passed) echo ($passed ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
