<?php
/**
 * Tenant Treasury policy helpers.
 *
 * Stores durable defaults for reserve planning and recommendation governance.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/audit.php';

function treasuryPolicyDefault(): array
{
    return [
        'tenant_id' => null,
        'currency' => 'USD',
        'minimum_cash_reserve' => 0.0,
        'payroll_reserve' => 0.0,
        'tax_reserve' => 0.0,
        'ap_reserve' => 0.0,
        'operating_reserve' => 0.0,
        'materiality_threshold' => 10000.0,
        'forecast_days' => 30,
        'review_cadence_days' => 30,
        'approval_resource' => 'treasury.payment',
        'approval_permission' => 'treasury.approve_payment',
        'execution_permission' => 'treasury.execute_payment',
        'effective_date' => date('Y-m-d'),
        'policy_version' => 0,
        'status' => 'default',
        'source' => 'system_default',
    ];
}

function treasuryPolicyGet(int $tenantId): array
{
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare('SELECT * FROM tenant_treasury_policy WHERE tenant_id = :t AND status = "active" LIMIT 1');
        $stmt->execute(['t' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_) {
        $row = null;
    }
    if (!$row) {
        $default = treasuryPolicyDefault();
        $default['tenant_id'] = $tenantId;
        return $default;
    }
    return treasuryPolicyNormalize($row + ['source' => 'tenant_policy'], $tenantId);
}

function treasuryPolicySave(int $tenantId, array $body, ?int $actorUserId = null): array
{
    $pdo = getDB();
    $before = treasuryPolicyGet($tenantId);
    $policy = treasuryPolicyNormalize($body, $tenantId);
    $policy['policy_version'] = max(1, (int) ($before['policy_version'] ?? 0) + 1);
    $policy['source'] = 'tenant_policy';

    $pdo->prepare(
        'INSERT INTO tenant_treasury_policy
            (tenant_id, currency, minimum_cash_reserve, payroll_reserve, tax_reserve,
             ap_reserve, operating_reserve, materiality_threshold, forecast_days,
             review_cadence_days, approval_resource, approval_permission,
             execution_permission, effective_date, policy_version, status,
             updated_by_user_id)
         VALUES
            (:tenant_id, :currency, :minimum_cash_reserve, :payroll_reserve, :tax_reserve,
             :ap_reserve, :operating_reserve, :materiality_threshold, :forecast_days,
             :review_cadence_days, :approval_resource, :approval_permission,
             :execution_permission, :effective_date, :policy_version, "active",
             :updated_by_user_id)
         ON DUPLICATE KEY UPDATE
            currency = VALUES(currency),
            minimum_cash_reserve = VALUES(minimum_cash_reserve),
            payroll_reserve = VALUES(payroll_reserve),
            tax_reserve = VALUES(tax_reserve),
            ap_reserve = VALUES(ap_reserve),
            operating_reserve = VALUES(operating_reserve),
            materiality_threshold = VALUES(materiality_threshold),
            forecast_days = VALUES(forecast_days),
            review_cadence_days = VALUES(review_cadence_days),
            approval_resource = VALUES(approval_resource),
            approval_permission = VALUES(approval_permission),
            execution_permission = VALUES(execution_permission),
            effective_date = VALUES(effective_date),
            policy_version = VALUES(policy_version),
            status = "active",
            updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute([
        'tenant_id' => $tenantId,
        'currency' => $policy['currency'],
        'minimum_cash_reserve' => $policy['minimum_cash_reserve'],
        'payroll_reserve' => $policy['payroll_reserve'],
        'tax_reserve' => $policy['tax_reserve'],
        'ap_reserve' => $policy['ap_reserve'],
        'operating_reserve' => $policy['operating_reserve'],
        'materiality_threshold' => $policy['materiality_threshold'],
        'forecast_days' => $policy['forecast_days'],
        'review_cadence_days' => $policy['review_cadence_days'],
        'approval_resource' => $policy['approval_resource'],
        'approval_permission' => $policy['approval_permission'],
        'execution_permission' => $policy['execution_permission'],
        'effective_date' => $policy['effective_date'],
        'policy_version' => $policy['policy_version'],
        'updated_by_user_id' => $actorUserId,
    ]);

    $after = treasuryPolicyGet($tenantId);
    platformAuditLogWrite($tenantId, $actorUserId, 'treasury.policy.updated', null, [
        'policy_version' => $after['policy_version'] ?? null,
        'effective_date' => $after['effective_date'] ?? null,
        'source' => 'treasury_policy',
    ], [
        'object_type' => 'treasury_policy',
        'source' => 'treasury_policy',
        'before' => $before,
        'after' => $after,
    ]);

    return $after;
}

function treasuryPolicyNormalize(array $row, ?int $tenantId = null): array
{
    $default = treasuryPolicyDefault();
    $currency = strtoupper(substr((string) ($row['currency'] ?? $default['currency']), 0, 3)) ?: 'USD';
    $effectiveDate = (string) ($row['effective_date'] ?? $default['effective_date']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) $effectiveDate = date('Y-m-d');

    return [
        'tenant_id' => $tenantId ?? (isset($row['tenant_id']) ? (int) $row['tenant_id'] : null),
        'currency' => $currency,
        'minimum_cash_reserve' => treasuryPolicyMoney($row['minimum_cash_reserve'] ?? $default['minimum_cash_reserve']),
        'payroll_reserve' => treasuryPolicyMoney($row['payroll_reserve'] ?? $default['payroll_reserve']),
        'tax_reserve' => treasuryPolicyMoney($row['tax_reserve'] ?? $default['tax_reserve']),
        'ap_reserve' => treasuryPolicyMoney($row['ap_reserve'] ?? $default['ap_reserve']),
        'operating_reserve' => treasuryPolicyMoney($row['operating_reserve'] ?? $default['operating_reserve']),
        'materiality_threshold' => treasuryPolicyMoney($row['materiality_threshold'] ?? $default['materiality_threshold']),
        'forecast_days' => max(1, min(365, (int) ($row['forecast_days'] ?? $default['forecast_days']))),
        'review_cadence_days' => max(1, min(365, (int) ($row['review_cadence_days'] ?? $default['review_cadence_days']))),
        'approval_resource' => trim((string) ($row['approval_resource'] ?? $default['approval_resource'])) ?: $default['approval_resource'],
        'approval_permission' => trim((string) ($row['approval_permission'] ?? $default['approval_permission'])) ?: $default['approval_permission'],
        'execution_permission' => trim((string) ($row['execution_permission'] ?? $default['execution_permission'])) ?: $default['execution_permission'],
        'effective_date' => $effectiveDate,
        'policy_version' => max(0, (int) ($row['policy_version'] ?? $default['policy_version'])),
        'status' => (string) ($row['status'] ?? $default['status']),
        'source' => (string) ($row['source'] ?? $default['source']),
    ];
}

function treasuryPolicyMoney(mixed $value): float
{
    return max(0.0, round((float) $value, 2));
}

function treasuryPolicyRequiredReserves(array $policy): float
{
    return round(
        (float) ($policy['minimum_cash_reserve'] ?? 0)
        + (float) ($policy['payroll_reserve'] ?? 0)
        + (float) ($policy['tax_reserve'] ?? 0)
        + (float) ($policy['ap_reserve'] ?? 0)
        + (float) ($policy['operating_reserve'] ?? 0),
        2
    );
}
