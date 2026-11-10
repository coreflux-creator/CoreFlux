<?php
/** Check AP reviewer projection against the real workflow in a disposable CI database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/workflow_engine.php';
require_once __DIR__ . '/../modules/ap/lib/approval_router.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
if ($tenantId <= 0) throw new RuntimeException('The CI tenant was not provisioned.');
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};

$index = $pdo->query(
    "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_used
       FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_bill_approvals'
        AND INDEX_NAME = 'uq_apba_step_actor'"
)->fetchColumn();
$assert($index === 'tenant_id,bill_id,step_no,approver_user_id',
    'approval projection has a distinct key for every step reviewer');
$stateType = $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ap_bill_approvals'
        AND COLUMN_NAME = 'state'"
)->fetchColumn();
$assert(is_string($stateType) && str_contains($stateType, "'superseded'"),
    'unused reviewers have a non-decision state');

$pdo->beginTransaction();
try {
    $ids = [];
    $user = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, is_active)
         VALUES (:name, :email, :hash, "tenant_admin", 1)'
    );
    $member = $pdo->prepare(
        'INSERT INTO tenant_memberships
            (user_id, tenant_id, persona_label, persona_type, is_primary, status)
         VALUES (:user_id, :tenant_id, :name, "tenant_admin", 1, "active")'
    );
    foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
        $user->execute([
            'name' => "CI AP {$name}",
            'email' => 'ci-ap-' . strtolower($name) . '@coreaccounting.invalid',
            'hash' => '!no-login-ci-fixture',
        ]);
        $id = (int) $pdo->lastInsertId();
        $member->execute(['user_id' => $id, 'tenant_id' => $tenantId, 'name' => "CI AP {$name}"]);
        $ids[] = $id;
    }

    $chain = [
        ['step' => 1, 'approver_user_ids' => [$ids[0], $ids[1]], 'quorum' => 1, 'label' => 'AP review'],
        ['step' => 2, 'approver_user_ids' => [$ids[2]], 'quorum' => 1, 'label' => 'Controller'],
    ];
    $pdo->prepare(
        'INSERT INTO ap_approval_policies
            (tenant_id, name, priority, vendor_type, chain_json, active)
         VALUES (:tenant_id, "CI AP reviewer projection", -1000, "other", :chain, 1)'
    )->execute(['tenant_id' => $tenantId, 'chain' => json_encode($chain, JSON_THROW_ON_ERROR)]);
    $policyId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO ap_bills
            (tenant_id, bill_number, internal_ref, vendor_name, vendor_type, received_at,
             bill_date, due_date, subtotal, total, amount_due, status, source)
         VALUES (:tenant_id, "CI-AP-PROJECTION", "CI-AP-PROJECTION",
                 "CI AP projection vendor", "other", "2026-10-11", "2026-10-11", "2026-11-10",
                 1.00, 1.00, 1.00, "pending_approval", "manual")'
    )->execute(['tenant_id' => $tenantId]);
    $billId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO ap_bill_lines (bill_id, line_no, source_type, description,
            quantity, unit, unit_price, subtotal, total, gl_expense_account_code)
         VALUES (:bill_id, 1, "manual", "CI approval projection", 1, "each", 1, 1, 1, "6200")'
    )->execute(['bill_id' => $billId]);

    $bill = $pdo->query('SELECT * FROM ap_bills WHERE id = ' . $billId)->fetch(PDO::FETCH_ASSOC);
    $routing = apRouteBillForApproval($tenantId, $bill, null, true);
    $assert((int) ($routing['policy_id'] ?? 0) === $policyId
        && count($routing['approval_ids'] ?? []) === 2
        && (int) ($routing['workflow_instance_id'] ?? 0) > 0,
        'both eligible first-step reviewers persist with the shared workflow');
    $instanceId = (int) $routing['workflow_instance_id'];

    $first = workflowAct($tenantId, $instanceId, $ids[0], 'approve', null, 'app');
    $assert(($first['status'] ?? null) === WORKFLOW_STATUS_PENDING
        && (int) ($first['current_step'] ?? 0) === 2,
        'first reviewer advances the workflow without prematurely approving the bill');
    $rows = $pdo->prepare(
        'SELECT approver_user_id, step_no, state FROM ap_bill_approvals
          WHERE tenant_id = :tenant_id AND bill_id = :bill_id ORDER BY step_no, approver_user_id'
    );
    $rows->execute(['tenant_id' => $tenantId, 'bill_id' => $billId]);
    $states = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $states[(int) $row['approver_user_id']] = (string) $row['state'];
    }
    $assert($states === [$ids[0] => 'approved', $ids[1] => 'superseded', $ids[2] => 'pending'],
        'quorum completion retires the unused reviewer and routes the next step');

    $final = workflowAct($tenantId, $instanceId, $ids[2], 'approve', null, 'app');
    $assert(($final['status'] ?? null) === WORKFLOW_STATUS_APPROVED,
        'second reviewer completes the shared workflow');
    $rows->execute(['tenant_id' => $tenantId, 'bill_id' => $billId]);
    $states = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $states[(int) $row['approver_user_id']] = (string) $row['state'];
    }
    $status = $pdo->query('SELECT status FROM ap_bills WHERE id = ' . $billId)->fetchColumn();
    $assert($states === [$ids[0] => 'approved', $ids[1] => 'superseded', $ids[2] => 'approved']
        && $status === 'approved',
        'completed bill and all reviewer rows agree without a false pending action');

    $pdo->rollBack();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    unset($GLOBALS['__cf_request_tenant_id']);
    throw $error;
}
unset($GLOBALS['__cf_request_tenant_id']);
