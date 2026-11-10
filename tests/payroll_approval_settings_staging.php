<?php
/** Hosted synthetic-only payroll reviewer setup acceptance. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run from CLI with --execute on the isolated CoreFlux staging host only.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../modules/payroll/lib/approval_settings.php';

function qaPayrollApprovalStatus(string $path, array $body, string $cookie): int
{
    $curl = curl_init(QA_BASE_URL . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json',
            'X-CoreFlux-Tenant-Id: ' . QA_TENANT],
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) throw new RuntimeException("PUT {$path}: {$error}");
    return $status;
}

$admin = qaEnsureActor($pdo, 'payroll-review-settings-admin');
$reviewer = qaEnsureActor($pdo, 'payroll-review-settings-reviewer');
$cookie = tempnam(sys_get_temp_dir(), 'cf-payroll-review-');
if ($cookie === false) throw new RuntimeException('Could not reserve payroll settings test session');
$policyId = 0;
try {
    qaLogin($admin, $cookie);
    $path = '/modules/payroll/api/approval_settings.php';
    $initial = qaRequest($path, 'GET', null, $cookie);
    qaExpect(in_array((int) $reviewer['id'], array_column($initial['eligible_reviewers'] ?? [], 'id'), true),
        'active payroll administrator is offered as a reviewer');
    qaExpect(qaPayrollApprovalStatus($path, ['reviewer_user_ids' => [99999999]], $cookie) === 422,
        'a nonmember cannot be assigned as a reviewer');

    $saved = qaRequest($path, 'PUT', ['reviewer_user_ids' => [(int) $reviewer['id']]], $cookie);
    $policyId = (int) (qaOne($pdo, 'SELECT id FROM people_graph_approval_policies
        WHERE tenant_id = :t AND policy_key = :key',
        ['t' => QA_TENANT, 'key' => PAYROLL_RUN_APPROVAL_POLICY_KEY])['id'] ?? 0);
    qaExpect(!empty($saved['configured']) && $saved['reviewer_user_ids'] === [(int) $reviewer['id']]
        && $policyId > 0, 'settings API saves a tenant-owned People Graph policy');
    $resolved = peopleGraphResolveApprovers(QA_TENANT, [
        'resource_module' => 'payroll', 'resource_type' => 'run',
        'resource_id' => '999999',
    ]);
    $approvers = [];
    foreach ($resolved['requirements'] ?? [] as $requirement) {
        foreach ($requirement['approvers'] ?? [] as $actor) {
            $approvers[] = (int) ($actor['actor_id'] ?? 0);
        }
    }
    qaExpect(in_array((int) $reviewer['id'], $approvers, true),
        'the payroll workflow resolver sees the selected reviewer');
    qaExpect(payrollRunApprovalReadiness(QA_TENANT, (int) $admin['id'])['ready'],
        'a separate reviewer makes payroll computation ready');
    qaExpect(!payrollRunApprovalReadiness(QA_TENANT, (int) $reviewer['id'])['ready'],
        'the builder cannot be their only reviewer');

    qaRequest($path, 'PUT', ['reviewer_user_ids' => [(int) $reviewer['id']]], $cookie);
    $activeRules = qaOne($pdo, 'SELECT COUNT(*) AS n FROM people_graph_approval_policy_rules
        WHERE tenant_id = :t AND policy_id = :policy AND status = "active"',
        ['t' => QA_TENANT, 'policy' => $policyId]);
    qaExpect((int) $activeRules['n'] === 1, 'saving the same reviewer does not duplicate approval rules');

    $pdo->prepare('UPDATE users SET is_active = 0 WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => QA_TENANT, 'id' => $reviewer['id']]);
    qaExpect(qaPayrollApprovalStatus($path,
        ['reviewer_user_ids' => [(int) $reviewer['id']]], $cookie) === 422,
        'an inactive reviewer cannot be saved');
} finally {
    if ($policyId > 0) {
        $pdo->prepare('UPDATE people_graph_approval_policies SET status = "inactive"
            WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $policyId]);
    }
    foreach ([$admin, $reviewer] as $actor) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $actor['id']]);
    }
    if (is_file($cookie)) unlink($cookie);
}
