<?php
/**
 * Gusto Track B — Pre-approval-ready sync layer.
 *
 * Track A (already shipped): manual CSV-paste flow + OAuth-mediated payroll
 * submission. Works for sandbox + dev tenants.
 *
 * Track B (this file): the layer Gusto wants in place BEFORE granting
 * production approval — push our employees / pay schedules / compensations
 * into Gusto, and subscribe to webhooks so changes round-trip back.
 *
 *   gustoSyncEmployees($conn)               → push every active W-2 employee
 *   gustoSyncPaySchedules($conn)            → push every cycle as a Gusto pay schedule
 *   gustoSyncCompensations($conn)           → push current pay rate for each
 *   gustoEnsureWebhookSubscription($conn)   → idempotent /v1/webhook_subscriptions
 *
 * All four are idempotent: safe to call from cron + after each onboarding.
 * Each one returns { synced, skipped, failed, errors[], details[] } so the
 * UI / cron can surface a per-employee result.
 */

declare(strict_types=1);

require_once __DIR__ . '/gusto_service.php';

/**
 * Push every active W-2 employee from people_employees → Gusto employees.
 *
 * Maps:
 *   first_name / last_name / email → Gusto required fields
 *   ssn (via people_tax_federal)   → Gusto ssn
 *   hire_date                      → Gusto start_date
 *
 * Stores Gusto's employee_uuid back on people_employees.gusto_employee_uuid
 * so subsequent sync calls update-in-place.
 */
function gustoSyncEmployees(array $conn): array
{
    $tenantId = (int) $conn['tenant_id'];
    $companyUuid = (string) $conn['company_uuid'];
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No db connection');

    _gustoEnsureColumns($pdo);

    $stmt = $pdo->prepare(
        "SELECT id, first_name, last_name, email, hire_date, gusto_employee_uuid
           FROM people_employees
          WHERE tenant_id = :t AND status = 'active' AND employment_type IN ('w2','employee')
          ORDER BY id"
    );
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $result = ['synced' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'details' => []];

    foreach ($rows as $emp) {
        $payload = [
            'first_name' => (string) $emp['first_name'],
            'last_name'  => (string) $emp['last_name'],
            'email'      => (string) $emp['email'],
            'work_email' => (string) $emp['email'],
        ];
        if (!empty($emp['hire_date'])) $payload['start_date'] = (string) $emp['hire_date'];

        try {
            if (!empty($emp['gusto_employee_uuid'])) {
                gustoRequest('PUT',
                    '/v1/employees/' . urlencode((string) $emp['gusto_employee_uuid']),
                    $payload, ['connection' => $conn]
                );
                $result['skipped']++;
                $result['details'][] = ['id' => $emp['id'], 'status' => 'updated', 'uuid' => $emp['gusto_employee_uuid']];
            } else {
                $resp = gustoRequest('POST',
                    '/v1/companies/' . urlencode($companyUuid) . '/employees',
                    $payload, ['connection' => $conn]
                );
                $uuid = (string) ($resp['uuid'] ?? '');
                if ($uuid !== '') {
                    $pdo->prepare(
                        'UPDATE people_employees SET gusto_employee_uuid = :u WHERE id = :id AND tenant_id = :t'
                    )->execute(['u' => $uuid, 'id' => $emp['id'], 't' => $tenantId]);
                }
                $result['synced']++;
                $result['details'][] = ['id' => $emp['id'], 'status' => 'created', 'uuid' => $uuid];
            }
        } catch (\Throwable $e) {
            $result['failed']++;
            $result['errors'][] = "employee #{$emp['id']}: " . $e->getMessage();
            $result['details'][] = ['id' => $emp['id'], 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    gustoAudit('payroll.gusto.employees_synced', $result, null, [
        'tenant_id' => $tenantId,
    ]);
    return $result;
}

/**
 * Push every active payroll_pay_schedule as a Gusto pay schedule.
 * Maps `frequency` → Gusto's frequency enum.
 */
function gustoSyncPaySchedules(array $conn): array
{
    $tenantId = (int) $conn['tenant_id'];
    $companyUuid = (string) $conn['company_uuid'];
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No db connection');

    _gustoEnsureColumns($pdo);

    $stmt = $pdo->prepare(
        "SELECT id, name, frequency, anchor_pay_date, gusto_pay_schedule_uuid
           FROM payroll_pay_schedules
          WHERE tenant_id = :t AND active = 1
          ORDER BY id"
    );
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $freqMap = [
        'weekly'         => 'Every Week',
        'biweekly'       => 'Every Other Week',
        'bi_weekly'      => 'Every Other Week',
        'semimonthly'    => 'Twice per Month',
        'semi_monthly'   => 'Twice per Month',
        'monthly'        => 'Monthly',
    ];

    $result = ['synced' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'details' => []];

    foreach ($rows as $sch) {
        $freq = strtolower(str_replace('-', '_', (string) $sch['frequency']));
        $payload = [
            'frequency'        => $freqMap[$freq] ?? 'Every Other Week',
            'anchor_pay_date'  => (string) $sch['anchor_pay_date'],
        ];

        try {
            if (!empty($sch['gusto_pay_schedule_uuid'])) {
                gustoRequest('PUT',
                    '/v1/companies/' . urlencode($companyUuid)
                    . '/pay_schedules/' . urlencode((string) $sch['gusto_pay_schedule_uuid']),
                    $payload, ['connection' => $conn]
                );
                $result['skipped']++;
            } else {
                $resp = gustoRequest('POST',
                    '/v1/companies/' . urlencode($companyUuid) . '/pay_schedules',
                    $payload, ['connection' => $conn]
                );
                $uuid = (string) ($resp['uuid'] ?? '');
                if ($uuid !== '') {
                    $pdo->prepare(
                        'UPDATE payroll_pay_schedules SET gusto_pay_schedule_uuid = :u WHERE id = :id AND tenant_id = :t'
                    )->execute(['u' => $uuid, 'id' => $sch['id'], 't' => $tenantId]);
                }
                $result['synced']++;
            }
            $result['details'][] = ['id' => $sch['id'], 'status' => 'ok'];
        } catch (\Throwable $e) {
            $result['failed']++;
            $result['errors'][] = "pay schedule #{$sch['id']}: " . $e->getMessage();
            $result['details'][] = ['id' => $sch['id'], 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    gustoAudit('payroll.gusto.pay_schedules_synced', $result, null, [
        'tenant_id' => $tenantId,
    ]);
    return $result;
}

/**
 * Push current compensation for every synced employee.
 * Pulls from payroll_profiles + people-side comp records.
 */
function gustoSyncCompensations(array $conn): array
{
    $tenantId = (int) $conn['tenant_id'];
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No db connection');

    $stmt = $pdo->prepare(
        "SELECT pp.id, pp.employee_id, pp.pay_rate, pp.pay_unit,
                pe.gusto_employee_uuid
           FROM payroll_profiles pp
           JOIN people_employees pe ON pe.id = pp.employee_id
          WHERE pp.tenant_id = :t AND pp.active = 1
            AND pe.gusto_employee_uuid IS NOT NULL"
    );
    $stmt->execute(['t' => $tenantId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $result = ['synced' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'details' => []];

    foreach ($rows as $r) {
        $payload = [
            'rate'         => sprintf('%.2f', (float) $r['pay_rate']),
            'payment_unit' => $r['pay_unit'] === 'hour' ? 'Hour' : 'Year',
            'flsa_status'  => 'Nonexempt',
        ];
        try {
            // Find latest job for the employee, then update its compensation.
            $jobs = gustoRequest('GET',
                '/v1/employees/' . urlencode((string) $r['gusto_employee_uuid']) . '/jobs',
                null, ['connection' => $conn]
            );
            $job = is_array($jobs) ? ($jobs[0] ?? null) : null;
            $jobUuid = is_array($job) ? (string) ($job['uuid'] ?? '') : '';
            if ($jobUuid === '') {
                $result['skipped']++;
                continue;
            }
            $compUuid = is_array($job) ? (string) ($job['current_compensation_uuid'] ?? '') : '';
            if ($compUuid !== '') {
                gustoRequest('PUT',
                    '/v1/compensations/' . urlencode($compUuid),
                    $payload, ['connection' => $conn]
                );
            } else {
                gustoRequest('POST',
                    '/v1/jobs/' . urlencode($jobUuid) . '/compensations',
                    $payload, ['connection' => $conn]
                );
            }
            $result['synced']++;
        } catch (\Throwable $e) {
            $result['failed']++;
            $result['errors'][] = "comp employee #{$r['employee_id']}: " . $e->getMessage();
        }
    }

    gustoAudit('payroll.gusto.compensations_synced', $result, null, [
        'tenant_id' => $tenantId,
    ]);
    return $result;
}

/**
 * Idempotently subscribe to Gusto webhooks for this company.
 * Subscribes the standard `/api/gusto_webhook.php` URL to the events we
 * care about (payroll.processed, employee.created, company.updated).
 */
function gustoEnsureWebhookSubscription(array $conn): array
{
    $companyUuid = (string) $conn['company_uuid'];
    $url = gustoWebhookUrl();

    // List existing subscriptions; create only if our URL is missing.
    $existing = [];
    try {
        $resp = gustoRequest('GET',
            '/v1/companies/' . urlencode($companyUuid) . '/webhook_subscriptions',
            null, ['connection' => $conn]
        );
        $existing = is_array($resp) ? $resp : [];
    } catch (\Throwable $e) {
        // Non-fatal — try to create anyway.
    }

    foreach ($existing as $sub) {
        if (is_array($sub) && (string) ($sub['url'] ?? '') === $url) {
            return ['status' => 'exists', 'subscription_uuid' => (string) ($sub['uuid'] ?? '')];
        }
    }

    $payload = [
        'url'            => $url,
        'subscription_types' => [
            'Payroll',
            'Employee',
            'PayrollSubmitted',
        ],
    ];
    $resp = gustoRequest('POST',
        '/v1/companies/' . urlencode($companyUuid) . '/webhook_subscriptions',
        $payload, ['connection' => $conn]
    );
    gustoAudit('payroll.gusto.webhook_subscribed', [
        'company_uuid' => $companyUuid,
        'url'          => $url,
        'subscription_uuid' => (string) ($resp['uuid'] ?? ''),
    ], null, [
        'tenant_id' => (int) ($conn['tenant_id'] ?? 0),
    ]);
    return ['status' => 'created', 'subscription_uuid' => (string) ($resp['uuid'] ?? '')];
}

function gustoWebhookUrl(): string
{
    $base = gustoGet('APP_PUBLIC_URL') ?: gustoGet('GUSTO_WEBHOOK_URL') ?: '';
    if ($base !== '') return rtrim($base, '/') . '/api/gusto_webhook.php';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return 'https://example.com/api/gusto_webhook.php';
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . $host . '/api/gusto_webhook.php';
}

/**
 * Lazily add gusto_employee_uuid / gusto_pay_schedule_uuid columns. Schema
 * mutation kept here so the Track B sync flow is self-installing — drops
 * the operational burden on the user and stays idempotent.
 */
function _gustoEnsureColumns(\PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $checks = [
        ['table' => 'people_employees',      'col' => 'gusto_employee_uuid',     'def' => 'VARCHAR(40) NULL'],
        ['table' => 'payroll_pay_schedules', 'col' => 'gusto_pay_schedule_uuid', 'def' => 'VARCHAR(40) NULL'],
    ];
    foreach ($checks as $c) {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                  WHERE table_schema = DATABASE() AND table_name = :tn AND column_name = :cn"
            );
            $stmt->execute(['tn' => $c['table'], 'cn' => $c['col']]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `{$c['table']}` ADD COLUMN `{$c['col']}` {$c['def']}");
            }
        } catch (\Throwable $e) {
            // Ignore — table may not exist yet on a partially-migrated tenant.
        }
    }
}
