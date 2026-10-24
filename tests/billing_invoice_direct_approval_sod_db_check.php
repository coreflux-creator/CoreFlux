<?php
/** Exercise no-policy invoice approval in the disposable installed runtime. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
if ($root === false || !is_file($root . '/modules/billing/lib/workflow.php')) {
    throw new RuntimeException('Installed CoreAccounting runtime is required.');
}
require_once $root . '/core/db.php';
require_once $root . '/core/memberships.php';
require_once $root . '/modules/billing/lib/invoice_drafts.php';
require_once $root . '/modules/billing/lib/approval_assignment.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$creator = $pdo->query("SELECT id, tenant_id FROM users
    WHERE email = 'ci-admin@coreaccounting.invalid' AND is_active = 1 LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
if (!$creator) throw new RuntimeException('Disposable administrator missing.');
$tenantId = (int) $creator['tenant_id'];
$creatorId = (int) $creator['id'];
$entity = $pdo->prepare('SELECT id FROM accounting_entities
    WHERE tenant_id = :tenant AND active = 1 ORDER BY id LIMIT 1');
$entity->execute(['tenant' => $tenantId]);
$entityId = (int) $entity->fetchColumn();
if ($entityId <= 0) throw new RuntimeException('Disposable legal entity missing.');
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO users (name, email, password_hash, role, is_active)
        VALUES ("CI Independent Billing Reviewer", "ci-billing-reviewer@coreaccounting.invalid",
                "!no-login-ci-fixture", "tenant_admin", 1)')->execute();
    $reviewerId = (int) $pdo->lastInsertId();
    provisionMembership($reviewerId, $tenantId, 'tenant_admin', [
        'persona_label' => 'CI Billing Reviewer', 'is_primary' => true,
    ]);

    $draft = billingCreateDirectInvoiceDraft($tenantId, [
        'entity_id' => $entityId,
        'client_name' => 'CI Independent Approval Client',
        'issue_date' => '2026-10-11',
        'due_date' => '2026-11-10',
        'currency' => 'USD',
        'tax_rate_pct' => 0,
        'lines' => [[
            'description' => 'Synthetic no-policy approval service',
            'quantity' => 1,
            'unit' => 'each',
            'unit_price' => 0.11,
            'item_type' => 'fixed_fee',
            'gl_revenue_account_code' => '4000',
        ]],
    ], $creatorId);
    $invoiceId = (int) $draft['id'];
    $invoice = billingInvoiceWorkflowRow($tenantId, $invoiceId);
    $routing = billingInvoiceApprovalRouting($tenantId, $invoice);
    $assert($invoiceId > 0 && $invoice['status'] === 'draft'
        && empty($routing['workflow_required']),
        'new synthetic invoice is a draft with no matching approval policy');

    $creatorView = billingInvoiceApprovalAssignmentRead($tenantId, $invoiceId, $creatorId);
    $reviewerView = billingInvoiceApprovalAssignmentRead($tenantId, $invoiceId, $reviewerId);
    $assert(!$creatorView['viewer_can_approve'] && $reviewerView['viewer_can_approve'],
        'approval screen offers the direct action only to the independent reviewer');

    try {
        billingInvoiceWorkflowAct($tenantId, $invoiceId, $creatorId);
        throw new RuntimeException('Creator self-approval unexpectedly succeeded.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'Separation of duties')) throw $error;
    }
    $unchanged = billingInvoiceWorkflowRow($tenantId, $invoiceId);
    $assert($unchanged['status'] === 'draft' && empty($unchanged['approved_by_user_id']),
        'direct API refuses creator self-approval without changing the draft');

    $approved = billingInvoiceWorkflowAct($tenantId, $invoiceId, $reviewerId);
    $saved = billingInvoiceWorkflowRow($tenantId, $invoiceId);
    $assert(!empty($approved['approved']) && $saved['status'] === 'approved'
        && (int) $saved['approved_by_user_id'] === $reviewerId
        && empty($saved['workflow_instance_id']) && empty($saved['journal_entry_id']),
        'independent reviewer approves the synthetic invoice without posting or routing');

    try {
        billingInvoiceApproveDirect($tenantId, $invoiceId, $reviewerId, $invoice);
        throw new RuntimeException('Stale direct approval unexpectedly succeeded.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'changed while you were approving')) throw $error;
    }
    $assert((int) billingInvoiceWorkflowRow($tenantId, $invoiceId)['approved_by_user_id'] === $reviewerId,
        'stale approval cannot claim another completed decision');

    $pdo->rollBack();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    unset($GLOBALS['__cf_request_tenant_id']);
    throw $error;
}
unset($GLOBALS['__cf_request_tenant_id']);
