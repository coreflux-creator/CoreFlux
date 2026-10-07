<?php
/** Rollback-only Billing reviewer setup check on an isolated tenant. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../modules/billing/lib/approval_settings.php';
require_once __DIR__ . '/../modules/billing/lib/workflow.php';

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
