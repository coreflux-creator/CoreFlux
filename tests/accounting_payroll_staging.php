<?php
/** Hosted synthetic-only payroll-to-ledger acceptance; no payment rail is used. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run from CLI with --execute on the isolated CoreFlux staging host only.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';

function qaPayrollJournalTotals(PDO $pdo, int $journalId): array
{
    $stmt = $pdo->prepare('SELECT a.code, ROUND(SUM(l.debit), 2) AS debit,
            ROUND(SUM(l.credit), 2) AS credit
        FROM accounting_journal_entry_lines l
        JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
        WHERE l.tenant_id = :t AND l.je_id = :je
        GROUP BY a.code');
    $stmt->execute(['t' => QA_TENANT, 'je' => $journalId]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(string) $row['code']] = [(float) $row['debit'], (float) $row['credit']];
    }
    ksort($rows);
    return $rows;
}

function qaPayrollFixture(PDO $pdo, int $creatorId): int
{
    $email = 'coreaccounting-payroll-qa@coreflux.test';
    $person = qaOne($pdo, 'SELECT id FROM people WHERE tenant_id = :t AND email_primary = :email',
        ['t' => QA_TENANT, 'email' => $email]);
    if (!$person) {
        $pdo->prepare('INSERT INTO people
            (tenant_id, first_name, last_name, email_primary, classification, status)
            VALUES (:t, "Synthetic", "Payroll QA", :email, "w2", "active")')
            ->execute(['t' => QA_TENANT, 'email' => $email]);
    }

    $employeeNumber = 'SIM-ACCT-PAYROLL-QA';
    $employee = qaOne($pdo, 'SELECT id FROM people_employees
        WHERE tenant_id = :t AND employee_number = :number',
        ['t' => QA_TENANT, 'number' => $employeeNumber]);
    if (!$employee) {
        $pdo->prepare('INSERT INTO people_employees
            (tenant_id, employee_number, legal_first_name, legal_last_name,
             work_email, status, department, location)
            VALUES (:t, :number, "Synthetic", "Payroll QA", :email,
                "active", "Accounting QA", "Staging")')
            ->execute(['t' => QA_TENANT, 'number' => $employeeNumber, 'email' => $email]);
        $employeeId = (int) $pdo->lastInsertId();
    } else {
        $employeeId = (int) $employee['id'];
    }

    $name = 'SIM CoreAccounting payroll acceptance';
    $schedule = qaOne($pdo, 'SELECT id FROM payroll_pay_schedules
        WHERE tenant_id = :t AND name = :name', ['t' => QA_TENANT, 'name' => $name]);
    if (!$schedule) {
        $pdo->prepare('INSERT INTO payroll_pay_schedules
            (tenant_id, name, frequency, period_start_anchor, active)
            VALUES (:t, :name, "weekly", "2026-10-01", 1)')
            ->execute(['t' => QA_TENANT, 'name' => $name]);
        $scheduleId = (int) $pdo->lastInsertId();
    } else {
        $scheduleId = (int) $schedule['id'];
    }

    $period = qaOne($pdo, 'SELECT id FROM payroll_pay_periods
        WHERE tenant_id = :t AND schedule_id = :schedule AND period_number = 1',
        ['t' => QA_TENANT, 'schedule' => $scheduleId]);
    if (!$period) {
        $pdo->prepare('INSERT INTO payroll_pay_periods
            (tenant_id, schedule_id, period_number, period_start, period_end, pay_date, status)
            VALUES (:t, :schedule, 1, "2026-10-01", "2026-10-07", "2026-10-08", "approved")')
            ->execute(['t' => QA_TENANT, 'schedule' => $scheduleId]);
        $periodId = (int) $pdo->lastInsertId();
    } else {
        $periodId = (int) $period['id'];
    }

    $run = qaOne($pdo, 'SELECT id FROM payroll_runs
        WHERE tenant_id = :t AND pay_period_id = :period AND run_type = "regular"
        ORDER BY id LIMIT 1', ['t' => QA_TENANT, 'period' => $periodId]);
    if (!$run) {
        $pdo->prepare('INSERT INTO payroll_runs
            (tenant_id, pay_period_id, run_type, created_by_user_id, status, employee_count,
             gross_total_cents, taxes_total_cents, deductions_total_cents, net_total_cents,
             employer_taxes_cents, approved_by, approved_at)
            VALUES (:t, :period, "regular", :creator, "approved", 1,
                100000, 18000, 5000, 77000, 8000, :approver, NOW())')
            ->execute(['t' => QA_TENANT, 'period' => $periodId,
                'creator' => $creatorId, 'approver' => $creatorId]);
        $runId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_line_items
            (tenant_id, run_id, employee_id, work_state, pay_type, pay_rate_cents,
             pay_frequency, hours_regular, gross_cents, pretax_cents, taxable_cents,
             employee_taxes_cents, posttax_cents, net_cents, employer_taxes_cents,
             payment_method, status)
            VALUES (:t, :run, :employee, "NY", "hourly", 2500,
                "weekly", 40, 100000, 3000, 97000,
                18000, 2000, 77000, 8000, "check", "approved")')
            ->execute(['t' => QA_TENANT, 'run' => $runId, 'employee' => $employeeId]);
    } else {
        $runId = (int) $run['id'];
    }
    return $runId;
}

$approver = qaEnsureActor($pdo, 'payroll-approver');
$payer = qaEnsureActor($pdo, 'payroll-payer');
$approverCookie = tempnam(sys_get_temp_dir(), 'cf-payroll-approve-');
$payerCookie = tempnam(sys_get_temp_dir(), 'cf-payroll-payer-');
if ($approverCookie === false || $payerCookie === false) {
    throw new RuntimeException('Could not reserve synthetic payroll sessions');
}

try {
    qaLogin($approver, $approverCookie);
    qaLogin($payer, $payerCookie);
    $runId = qaPayrollFixture($pdo, (int) $approver['id']);
    $run = qaOne($pdo, 'SELECT status, journal_entry_id, cash_journal_entry_id
        FROM payroll_runs WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $runId]);
    qaExpect($run && in_array($run['status'], ['approved', 'paid'], true),
        'synthetic payroll run is approved or paid');

    $postPath = '/modules/payroll/api/runs.php';
    $firstPost = qaRequest($postPath, 'POST', ['run_id' => $runId, 'action' => 'post'], $approverCookie);
    $accrualId = (int) ($firstPost['accrual']['je_id'] ?? 0);
    qaExpect($accrualId > 0 && ($firstPost['accrual']['status'] ?? '') === 'posted',
        'payroll approval posts an accrual through the shared ledger');
    qaExpect(qaPayrollJournalTotals($pdo, $accrualId) === [
        '2200' => [0.0, 770.0], '2210' => [0.0, 260.0],
        '2220' => [0.0, 50.0], '5000' => [1000.0, 0.0],
        '5020' => [80.0, 0.0],
    ], 'accrual recognizes wages, employer tax, and distinct payables');

    if ($run['status'] === 'approved') {
        $paid = qaRequest($postPath, 'POST', ['run_id' => $runId, 'action' => 'paid'], $payerCookie);
        qaExpect(($paid['status'] ?? '') === 'paid' && (int) ($paid['cash_post']['je_id'] ?? 0) > 0,
            'a different actor marks payroll paid and posts cash without a payment rail');
    }
    $final = qaOne($pdo, 'SELECT status, journal_entry_id, cash_journal_entry_id
        FROM payroll_runs WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $runId]);
    $cashId = (int) ($final['cash_journal_entry_id'] ?? 0);
    qaExpect($final['status'] === 'paid' && (int) $final['journal_entry_id'] === $accrualId
        && $cashId > 0, 'paid run retains both posted journal links');
    qaExpect(qaPayrollJournalTotals($pdo, $cashId) === [
        '1000' => [0.0, 770.0], '2200' => [770.0, 0.0],
    ], 'cash posting clears only net-pay payable');

    $replay = qaRequest($postPath, 'POST', ['run_id' => $runId, 'action' => 'post'], $approverCookie);
    qaExpect((int) ($replay['accrual']['je_id'] ?? 0) === $accrualId
        && (int) ($replay['cash']['je_id'] ?? 0) === $cashId
        && !empty($replay['accrual']['idempotent_replay'])
        && !empty($replay['cash']['idempotent_replay']),
        'replaying a paid run returns the same two journal entries');
    $events = qaOne($pdo, 'SELECT COUNT(*) AS event_count,
            COUNT(DISTINCT journal_entry_id) AS journal_count
        FROM accounting_events
        WHERE tenant_id = :t AND source_module = "payroll"
          AND source_record_id IN (:accrual, :cash) AND status = "posted"',
        ['t' => QA_TENANT, 'accrual' => 'payroll_run:' . $runId . ':accrual',
            'cash' => 'payroll_run:' . $runId . ':cash']);
    qaExpect((int) $events['event_count'] === 2 && (int) $events['journal_count'] === 2,
        'one posted event and one journal exist for each payroll leg');
    $income = qaRequest('/modules/accounting/api/reports.php?type=income_statement'
        . '&entity_id=1&from=2026-10-07&to=2026-10-08', 'GET', null, $approverCookie);
    $expenses = array_column($income['expense'] ?? [], 'amount', 'code');
    qaExpect((float) ($expenses['5000'] ?? 0) >= 1000
        && (float) ($expenses['5020'] ?? 0) >= 80,
        'income statement includes payroll wages and employer tax');
    $sheet = qaRequest('/modules/accounting/api/reports.php?type=balance_sheet'
        . '&entity_id=1&as_of=2026-10-08', 'GET', null, $approverCookie);
    qaExpect(!empty($sheet['balanced']), 'balance sheet stays balanced after payroll');
    $cashFlow = qaRequest('/modules/accounting/api/reports.php?type=cash_flow_indirect'
        . '&entity_id=1&from=2026-10-07&to=2026-10-08', 'GET', null, $approverCookie);
    $operating = array_column($cashFlow['sections']['operating']['lines'] ?? [], 'amount', 'code');
    qaExpect(!empty($cashFlow['balanced'])
        && abs((float) ($cashFlow['reconciliation_diff'] ?? 1)) < 0.005
        && (float) ($operating['2220'] ?? 0) === 50.0,
        'cash flow classifies withheld deductions as operating and ties to cash');
    echo "Synthetic payroll run {$runId}, accrual JE {$accrualId}, cash JE {$cashId}.\n";
} finally {
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE tenant_id = :t AND id IN (:approver, :payer)')
        ->execute(['t' => QA_TENANT, 'approver' => $approver['id'], 'payer' => $payer['id']]);
    foreach ([$approverCookie, $payerCookie] as $cookie) {
        if (is_file($cookie)) unlink($cookie);
    }
}
