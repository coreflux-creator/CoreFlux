<?php
/** Hosted synthetic-only payroll build/approval/ledger acceptance; no external rails. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run from CLI with --execute on the isolated CoreFlux staging host only.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../core/people_graph.php';
require_once __DIR__ . '/../modules/payroll/lib/payroll.php';

function qaWorkflowFixture(PDO $pdo): array
{
    $email = 'coreaccounting-payroll-period-qa@coreflux.test';
    if (!qaOne($pdo, 'SELECT id FROM people WHERE tenant_id = :t AND email_primary = :email',
        ['t' => QA_TENANT, 'email' => $email])) {
        $pdo->prepare('INSERT INTO people
            (tenant_id, first_name, last_name, email_primary, classification, status)
            VALUES (:t, "Synthetic", "Period QA", :email, "w2", "active")')
            ->execute(['t' => QA_TENANT, 'email' => $email]);
    }

    $employeeNumber = 'SIM-ACCT-PERIOD-QA';
    $employee = qaOne($pdo, 'SELECT id FROM people_employees
        WHERE tenant_id = :t AND employee_number = :number',
        ['t' => QA_TENANT, 'number' => $employeeNumber]);
    if (!$employee) {
        $pdo->prepare('INSERT INTO people_employees
            (tenant_id, employee_number, legal_first_name, legal_last_name, work_email,
             status, date_of_birth, ssn_cipher, hire_date, department)
            VALUES (:t, :number, "Synthetic", "Period QA", :email, "active",
                "1990-01-01", UNHEX("53494D554C41544544"), "2026-01-01", "Accounting QA")')
            ->execute(['t' => QA_TENANT, 'number' => $employeeNumber, 'email' => $email]);
        $employeeId = (int) $pdo->lastInsertId();
    } else {
        $employeeId = (int) $employee['id'];
    }

    $name = 'SIM CoreAccounting effective-period payroll';
    $schedule = qaOne($pdo, 'SELECT id FROM payroll_pay_schedules
        WHERE tenant_id = :t AND name = :name', ['t' => QA_TENANT, 'name' => $name]);
    if (!$schedule) {
        $pdo->prepare('INSERT INTO payroll_pay_schedules
            (tenant_id, name, frequency, period_start_anchor, active)
            VALUES (:t, :name, "weekly", "2026-09-01", 1)')
            ->execute(['t' => QA_TENANT, 'name' => $name]);
        $scheduleId = (int) $pdo->lastInsertId();
    } else {
        $scheduleId = (int) $schedule['id'];
    }

    if (!qaOne($pdo, 'SELECT id FROM payroll_profiles
        WHERE tenant_id = :t AND employee_id = :employee',
        ['t' => QA_TENANT, 'employee' => $employeeId])) {
        $pdo->prepare('INSERT INTO payroll_profiles
            (tenant_id, employee_id, schedule_id, work_state, payment_method,
             default_hours_per_period, enabled)
            VALUES (:t, :employee, :schedule, "CA", "check", 40, 1)')
            ->execute(['t' => QA_TENANT, 'employee' => $employeeId, 'schedule' => $scheduleId]);
    }
    foreach ([['2026-01-01', '2026-10-01', 2500],
              ['2026-10-01', null, 3000]] as [$start, $end, $rate]) {
        if (!qaOne($pdo, 'SELECT id FROM people_compensation
            WHERE tenant_id = :t AND employee_id = :employee AND effective_from = :start',
            ['t' => QA_TENANT, 'employee' => $employeeId, 'start' => $start])) {
            $pdo->prepare('INSERT INTO people_compensation
                (tenant_id, employee_id, pay_type, pay_rate_cents, pay_frequency,
                 effective_from, effective_to)
                VALUES (:t, :employee, "hourly", :rate, "weekly", :start, :end)')
                ->execute(['t' => QA_TENANT, 'employee' => $employeeId,
                    'rate' => $rate, 'start' => $start, 'end' => $end]);
        }
    }
    foreach ([['2026-01-01', 'single'], ['2026-10-01', 'married_filing_jointly']]
        as [$effectiveDate, $filing]) {
        if (!qaOne($pdo, 'SELECT id FROM people_tax_federal
            WHERE tenant_id = :t AND employee_id = :employee AND effective_date = :date',
            ['t' => QA_TENANT, 'employee' => $employeeId, 'date' => $effectiveDate])) {
            $pdo->prepare('INSERT INTO people_tax_federal
                (tenant_id, employee_id, filing_status, effective_date)
                VALUES (:t, :employee, :filing, :date)')
                ->execute(['t' => QA_TENANT, 'employee' => $employeeId,
                    'filing' => $filing, 'date' => $effectiveDate]);
        }
    }
    if (!qaOne($pdo, 'SELECT id FROM people_tax_state
        WHERE tenant_id = :t AND employee_id = :employee AND state_code = "CA"',
        ['t' => QA_TENANT, 'employee' => $employeeId])) {
        $pdo->prepare('INSERT INTO people_tax_state
            (tenant_id, employee_id, state_code, filing_status, effective_date)
            VALUES (:t, :employee, "CA", "single", "2026-01-01")')
            ->execute(['t' => QA_TENANT, 'employee' => $employeeId]);
    }

    $period = qaOne($pdo, 'SELECT id FROM payroll_pay_periods
        WHERE tenant_id = :t AND schedule_id = :schedule AND period_number = 1',
        ['t' => QA_TENANT, 'schedule' => $scheduleId]);
    if (!$period) {
        $pdo->prepare('INSERT INTO payroll_pay_periods
            (tenant_id, schedule_id, period_number, period_start, period_end, pay_date, status)
            VALUES (:t, :schedule, 1, "2026-09-01", "2026-09-07", "2026-09-08", "open")')
            ->execute(['t' => QA_TENANT, 'schedule' => $scheduleId]);
        $periodId = (int) $pdo->lastInsertId();
    } else {
        $periodId = (int) $period['id'];
    }
    return [$employeeId, $periodId];
}

function qaWorkflowStatus(string $path, array $body, string $cookie): int
{
    $curl = curl_init(QA_BASE_URL . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json',
            'X-CoreFlux-Tenant-Id: ' . QA_TENANT],
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($raw === false) throw new RuntimeException("POST {$path}: {$error}");
    return $status;
}

$maker = qaEnsureActor($pdo, 'payroll-workflow-maker');
$reviewer = qaEnsureActor($pdo, 'payroll-workflow-reviewer');
$payer = qaEnsureActor($pdo, 'payroll-workflow-payer');
$cookies = [];
$policyId = 0;
try {
    $policy = peopleGraphCreateApprovalPolicy(QA_TENANT, [
        'policy_key' => 'qa_payroll_run_approval',
        'name' => 'Synthetic payroll run reviewer',
        'resource_module' => 'payroll',
        'resource_type' => 'run',
        'status' => 'active',
    ]);
    $policyId = (int) $policy['id'];
    if (!qaOne($pdo, 'SELECT id FROM people_graph_approval_policy_rules
        WHERE tenant_id = :t AND policy_id = :policy AND approver_strategy = "named_actor"
          AND approver_actor_type = "user" AND approver_actor_id = :reviewer AND status = "active"',
        ['t' => QA_TENANT, 'policy' => $policyId, 'reviewer' => $reviewer['id']])) {
        peopleGraphCreateApprovalRule(QA_TENANT, [
            'policy_id' => $policyId,
            'approver_strategy' => 'named_actor',
            'approver_actor_type' => 'user',
            'approver_actor_id' => $reviewer['id'],
            'separation_of_duties_required' => true,
        ]);
    }

    foreach (['maker' => $maker, 'reviewer' => $reviewer, 'payer' => $payer] as $role => $actor) {
        $cookie = tempnam(sys_get_temp_dir(), 'cf-payroll-' . $role . '-');
        if ($cookie === false) throw new RuntimeException('Could not reserve payroll test session');
        $cookies[$role] = $cookie;
        qaLogin($actor, $cookie);
    }

    [$employeeId, $periodId] = qaWorkflowFixture($pdo);
    setRequestTenantId(QA_TENANT);
    try {
        $context = payrollBuildComputeContext($employeeId,
            ['period_start' => '2026-09-01', 'period_end' => '2026-09-07',
                'pay_date' => '2026-09-08'], payrollGetTenantSettings());
    } finally {
        setRequestTenantId(null);
    }
    qaExpect($context && (int) $context['pay_rate_cents'] === 2500
        && $context['fed_filing_status'] === 'single',
        'historical run selects compensation and W-4 effective in its period');

    $preflight = qaRequest('/modules/payroll/api/preflight.php?period_id=' . $periodId,
        'GET', null, $cookies['maker']);
    qaExpect(!empty($preflight['summary']['ready_to_run'])
        && (int) ($preflight['summary']['total_w2_employees'] ?? 0) === 1,
        'payroll preflight recognizes one ready synthetic employee');

    $path = '/modules/payroll/api/runs.php';
    $created = qaRequest($path, 'POST', ['pay_period_id' => $periodId], $cookies['maker']);
    $runId = (int) ($created['id'] ?? 0);
    qaExpect($runId > 0, 'payroll API creates or reopens the existing run');
    $run = qaOne($pdo, 'SELECT status FROM payroll_runs WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $runId]);
    if ($run['status'] === 'draft' || $run['status'] === 'computed') {
        $computed = qaRequest($path, 'POST', ['run_id' => $runId, 'action' => 'compute'],
            $cookies['maker']);
        qaExpect(($computed['run']['status'] ?? '') === 'computed'
            && (int) ($computed['run']['gross_total_cents'] ?? 0) === 100000
            && (int) ($computed['run']['employee_count'] ?? 0) === 1,
            'gross-to-net computes September wages from the September rate');
    }
    $run = qaOne($pdo, 'SELECT status FROM payroll_runs WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $runId]);
    if ($run['status'] === 'computed') {
        qaExpect(qaWorkflowStatus($path,
            ['run_id' => $runId, 'action' => 'approve'], $cookies['maker']) === 403,
            'the builder cannot approve the same payroll run');
        $approved = qaRequest($path, 'POST', ['run_id' => $runId, 'action' => 'approve'],
            $cookies['reviewer']);
        qaExpect(($approved['status'] ?? '') === 'approved'
            && (int) ($approved['accounting_post']['je_id'] ?? 0) > 0,
            'independent approval posts the computed accrual');
    }
    $run = qaOne($pdo, 'SELECT status, journal_entry_id, cash_journal_entry_id,
            gross_total_cents, net_total_cents FROM payroll_runs WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $runId]);
    qaExpect((int) $run['gross_total_cents'] === 100000
        && (int) $run['journal_entry_id'] > 0,
        'approved run retains its computed totals and canonical journal');
    if ($run['status'] === 'approved') {
        $paid = qaRequest($path, 'POST', ['run_id' => $runId, 'action' => 'paid'],
            $cookies['payer']);
        qaExpect(($paid['status'] ?? '') === 'paid'
            && (int) ($paid['cash_post']['je_id'] ?? 0) > 0,
            'third actor records paid state and cash journal without an external rail');
    }
    $run = qaOne($pdo, 'SELECT status, journal_entry_id, cash_journal_entry_id,
            gross_total_cents, net_total_cents FROM payroll_runs WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $runId]);
    qaExpect($run['status'] === 'paid' && (int) $run['cash_journal_entry_id'] > 0,
        'full payroll workflow ends with two linked posted journals');
    $replay = qaRequest($path, 'POST', ['run_id' => $runId, 'action' => 'post'],
        $cookies['maker']);
    qaExpect((int) ($replay['accrual']['je_id'] ?? 0) === (int) $run['journal_entry_id']
        && (int) ($replay['cash']['je_id'] ?? 0) === (int) $run['cash_journal_entry_id']
        && !empty($replay['accrual']['idempotent_replay'])
        && !empty($replay['cash']['idempotent_replay']),
        'replay reuses both posted journals');
    $events = qaOne($pdo, 'SELECT COUNT(*) AS event_count,
            COUNT(DISTINCT journal_entry_id) AS journal_count
        FROM accounting_events
        WHERE tenant_id = :t AND source_module = "payroll"
          AND source_record_id IN (:accrual, :cash) AND status = "posted"',
        ['t' => QA_TENANT, 'accrual' => 'payroll_run:' . $runId . ':accrual',
            'cash' => 'payroll_run:' . $runId . ':cash']);
    qaExpect((int) $events['event_count'] === 2 && (int) $events['journal_count'] === 2,
        'computed run has exactly one posted event per accounting leg');
    $income = qaRequest('/modules/accounting/api/reports.php?type=income_statement'
        . '&entity_id=1&from=2026-09-01&to=2026-09-08', 'GET', null, $cookies['maker']);
    $expenses = array_column($income['expense'] ?? [], 'amount', 'code');
    qaExpect((float) ($expenses['5000'] ?? 0) >= (int) $run['gross_total_cents'] / 100,
        'income statement includes computed payroll wages');
    $sheet = qaRequest('/modules/accounting/api/reports.php?type=balance_sheet'
        . '&entity_id=1&as_of=2026-09-08', 'GET', null, $cookies['maker']);
    qaExpect(!empty($sheet['balanced']), 'balance sheet stays balanced after computed payroll');
    $cashFlow = qaRequest('/modules/accounting/api/reports.php?type=cash_flow_indirect'
        . '&entity_id=1&from=2026-09-01&to=2026-09-08', 'GET', null, $cookies['maker']);
    qaExpect(!empty($cashFlow['balanced'])
        && abs((float) ($cashFlow['reconciliation_diff'] ?? 1)) < 0.005,
        'cash flow ties computed payroll to cash');
    echo "Synthetic computed payroll run {$runId}, accrual JE {$run['journal_entry_id']}, "
        . "cash JE {$run['cash_journal_entry_id']}.\n";
} finally {
    setRequestTenantId(null);
    if ($policyId > 0) {
        $pdo->prepare('UPDATE people_graph_approval_policies SET status = "inactive"
            WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $policyId]);
    }
    foreach ([$maker, $reviewer, $payer] as $actor) {
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => QA_TENANT, 'id' => $actor['id']]);
    }
    foreach ($cookies as $cookie) {
        if (is_file($cookie)) unlink($cookie);
    }
}
