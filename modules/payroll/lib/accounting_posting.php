<?php
/**
 * Payroll → Accounting posting bridge.
 *
 * Approval recognizes wages, payroll taxes, deductions, and net-pay
 * liabilities. Payment clears only the net-pay liability against cash;
 * tax and deduction liabilities remain until their own remittance flows.
 */

require_once __DIR__ . '/../../accounting/lib/accounting.php';
require_once __DIR__ . '/../../../core/posting_engine/process.php';

function payrollAccountingPostingDefaults(): array
{
    return [
        'wage_expense_account_code'              => '5000',
        'payroll_tax_expense_account_code'       => '5010',
        'payroll_payable_account_code'            => '2200',
        'payroll_tax_payable_account_code'        => '2210',
        'payroll_deduction_payable_account_code'  => '2220',
        'payroll_cash_account_code'               => '1000',
        'auto_post_to_ledger'                     => 1,
    ];
}

function payrollAccountingPostingSettings(int $tenantId): array
{
    $stmt = getDB()->prepare('SELECT * FROM payroll_settings WHERE tenant_id = :t LIMIT 1');
    $stmt->execute(['t' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $settings = payrollAccountingPostingDefaults();
    foreach ($settings as $key => $default) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            $settings[$key] = $row[$key];
        }
    }
    return $settings;
}

function payrollAccountingPostingReadiness(int $tenantId, array $run): array
{
    $settings = payrollAccountingPostingSettings($tenantId);
    $expectations = [
        'wage_expense_account_code'             => ['label' => 'Wage expense', 'type' => 'expense'],
        'payroll_tax_expense_account_code'      => ['label' => 'Employer payroll tax expense', 'type' => 'expense'],
        'payroll_payable_account_code'           => ['label' => 'Net-pay payable', 'type' => 'liability'],
        'payroll_tax_payable_account_code'       => ['label' => 'Payroll tax payable', 'type' => 'liability'],
        'payroll_deduction_payable_account_code' => ['label' => 'Deduction payable', 'type' => 'liability'],
        'payroll_cash_account_code'              => ['label' => 'Payroll cash', 'type' => 'asset'],
    ];

    $stmt = getDB()->prepare(
        'SELECT code, name, account_type, active, is_postable
           FROM accounting_accounts
          WHERE tenant_id = :t'
    );
    $stmt->execute(['t' => $tenantId]);
    $accounts = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $account) {
        $accounts[strtolower((string) $account['code'])] = $account;
    }

    $mappings = [];
    $issues = [];
    foreach ($expectations as $key => $expectation) {
        $code = trim((string) ($settings[$key] ?? ''));
        $account = $accounts[strtolower($code)] ?? null;
        $mapping = [
            'key' => $key,
            'label' => $expectation['label'],
            'code' => $code,
            'expected_type' => $expectation['type'],
            'account_name' => $account['name'] ?? null,
            'account_type' => $account['account_type'] ?? null,
            'ready' => false,
        ];
        if ($code === '' || !$account) {
            $issues[] = "{$expectation['label']}: account {$code} was not found";
        } elseif (!(bool) $account['active']) {
            $issues[] = "{$expectation['label']}: account {$code} is inactive";
        } elseif (!(bool) $account['is_postable']) {
            $issues[] = "{$expectation['label']}: account {$code} is a summary account";
        } elseif ((string) $account['account_type'] !== $expectation['type']) {
            $issues[] = "{$expectation['label']}: account {$code} must be {$expectation['type']}";
        } else {
            $mapping['ready'] = true;
        }
        $mappings[] = $mapping;
    }

    $status = (string) ($run['status'] ?? '');
    return [
        'ready' => count($issues) === 0,
        'auto_post' => (bool) ((int) ($settings['auto_post_to_ledger'] ?? 1)),
        'issues' => $issues,
        'mappings' => $mappings,
        'accrual_required' => in_array($status, ['approved', 'paid'], true),
        'accrual_posted' => !empty($run['journal_entry_id']),
        'journal_entry_id' => !empty($run['journal_entry_id']) ? (int) $run['journal_entry_id'] : null,
        'cash_required' => $status === 'paid',
        'cash_posted' => !empty($run['cash_journal_entry_id']),
        'cash_journal_entry_id' => !empty($run['cash_journal_entry_id']) ? (int) $run['cash_journal_entry_id'] : null,
    ];
}

function payrollAccountingRequireReady(int $tenantId, array $run): array
{
    $readiness = payrollAccountingPostingReadiness($tenantId, $run);
    if (!$readiness['ready']) {
        throw new \RuntimeException(
            'Payroll accounting is not configured: ' . implode('; ', $readiness['issues'])
            . '. Update Payroll → Settings → Accounting.'
        );
    }
    return payrollAccountingPostingSettings($tenantId);
}

function payrollAccountingAppendLine(array &$lines, string $accountCode, int $debitCents, int $creditCents, string $memo): void
{
    if ($debitCents <= 0 && $creditCents <= 0) return;
    $key = strtolower($accountCode) . ':' . ($debitCents > 0 ? 'debit' : 'credit');
    if (!isset($lines[$key])) {
        $lines[$key] = [
            'account_code' => $accountCode,
            'debit' => 0.0,
            'credit' => 0.0,
            'memo' => $memo,
        ];
    }
    $lines[$key]['debit'] += $debitCents / 100;
    $lines[$key]['credit'] += $creditCents / 100;
}

function payrollAccountingStampLink(int $tenantId, int $runId, int $journalEntryId, string $kind): void
{
    try {
        getDB()->prepare(
            'INSERT IGNORE INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "payroll", :source, :je, :kind)'
        )->execute([
            't' => $tenantId,
            'source' => 'payroll_run:' . $runId,
            'je' => $journalEntryId,
            'kind' => $kind,
        ]);
    } catch (\Throwable $e) {
        error_log('[payroll accounting link] ' . $e->getMessage());
    }
}

function payrollAccountingPostedEventResult(array $eventResult, string $label): array
{
    if (($eventResult['status'] ?? null) !== 'posted' || empty($eventResult['journal_entry_id'])) {
        throw new \RuntimeException(
            $label . ' could not be posted to the ledger: '
            . (string) ($eventResult['error'] ?? 'no posting rule matched')
        );
    }

    return [
        'je_id' => (int) $eventResult['journal_entry_id'],
        'je_number' => $eventResult['je_number'] ?? null,
        'status' => 'posted',
        'total_debit' => (float) ($eventResult['total_debit'] ?? 0),
        'total_credit' => (float) ($eventResult['total_credit'] ?? 0),
        'idempotent_replay' => !empty($eventResult['idempotent_replay']),
        'event_id' => isset($eventResult['event_id']) ? (int) $eventResult['event_id'] : null,
    ];
}

function payrollAccountingAccountId(int $tenantId, string $accountCode): int
{
    $stmt = getDB()->prepare(
        'SELECT id FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND code = :code AND active = 1 AND is_postable = 1
          LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'code' => $accountCode]);
    $accountId = (int) ($stmt->fetchColumn() ?: 0);
    if ($accountId <= 0) throw new \RuntimeException("Payroll ledger account {$accountCode} is unavailable");
    return $accountId;
}

function payrollPostRunAccrual(int $tenantId, int $runId, ?int $actorUserId): array
{
    $run = payrollAccountingLoadRun($tenantId, $runId);
    if (!in_array((string) $run['status'], ['approved', 'paid'], true)) {
        throw new \RuntimeException('Payroll accrual can only post after final approval');
    }
    $settings = payrollAccountingRequireReady($tenantId, $run);

    $gross = (int) $run['gross_total_cents'];
    $employeeTaxes = (int) $run['taxes_total_cents'];
    $deductions = (int) $run['deductions_total_cents'];
    $net = (int) $run['net_total_cents'];
    $employerTaxes = (int) $run['employer_taxes_cents'];
    $debits = $gross + $employerTaxes;
    $credits = $net + $employeeTaxes + $deductions + $employerTaxes;
    if ($gross <= 0 || abs($debits - $credits) > 1) {
        throw new \RuntimeException(
            sprintf('Payroll totals do not balance: debits=%0.2f credits=%0.2f', $debits / 100, $credits / 100)
        );
    }

    $memo = 'Payroll run #' . $runId . ' · ' . $run['period_start'] . ' to ' . $run['period_end'];
    $lines = [];
    payrollAccountingAppendLine($lines, (string) $settings['wage_expense_account_code'], $gross, 0, $memo . ' · wages');
    payrollAccountingAppendLine($lines, (string) $settings['payroll_tax_expense_account_code'], $employerTaxes, 0, $memo . ' · employer taxes');
    payrollAccountingAppendLine($lines, (string) $settings['payroll_payable_account_code'], 0, $net, $memo . ' · net pay payable');
    payrollAccountingAppendLine($lines, (string) $settings['payroll_tax_payable_account_code'], 0, $employeeTaxes + $employerTaxes, $memo . ' · taxes payable');
    payrollAccountingAppendLine($lines, (string) $settings['payroll_deduction_payable_account_code'], 0, $deductions, $memo . ' · deductions payable');

    $entityId = (int) accountingDefaultEntity($tenantId)['id'];
    $eventResult = accountingProcessEvent($tenantId, [
        'entity_id' => $entityId,
        'event_type' => 'payroll.run.approved',
        'source_module' => 'payroll',
        'source_record_id' => 'payroll_run:' . $runId . ':accrual',
        'event_date' => (string) $run['period_end'],
        'payload' => [
            'run_id' => $runId,
            'gross' => $gross / 100,
            'taxes' => $employeeTaxes / 100,
            'net' => $net / 100,
            'currency' => 'USD',
            'employer_tax_total' => $employerTaxes / 100,
            'deductions' => $deductions / 100,
            'memo' => $memo,
            'lines' => array_values($lines),
        ],
    ], $actorUserId);
    $result = payrollAccountingPostedEventResult($eventResult, 'Payroll accrual');

    getDB()->prepare(
        'UPDATE payroll_runs SET journal_entry_id = :je, updated_at = NOW()
          WHERE tenant_id = :t AND id = :id'
    )->execute(['je' => (int) $result['je_id'], 't' => $tenantId, 'id' => $runId]);
    payrollAccountingStampLink($tenantId, $runId, (int) $result['je_id'], 'accrual');
    payrollAudit('payroll.run.posted', [
        'run_id' => $runId,
        'journal_entry_id' => (int) $result['je_id'],
        'leg' => 'accrual',
        'idempotent_replay' => !empty($result['idempotent_replay']),
    ], $runId);

    return $result;
}

function payrollPostRunCash(int $tenantId, int $runId, ?int $actorUserId): array
{
    $run = payrollAccountingLoadRun($tenantId, $runId);
    if ((string) $run['status'] !== 'paid') {
        throw new \RuntimeException('Payroll cash can only post after the run is marked paid');
    }
    $settings = payrollAccountingRequireReady($tenantId, $run);
    $net = (int) $run['net_total_cents'];
    if ($net <= 0) throw new \RuntimeException('Payroll net pay must be greater than zero');

    $memo = 'Payroll run #' . $runId . ' · net pay disbursed';
    $cashAccountId = payrollAccountingAccountId($tenantId, (string) $settings['payroll_cash_account_code']);
    $entityId = (int) accountingDefaultEntity($tenantId)['id'];
    $eventResult = accountingProcessEvent($tenantId, [
        'entity_id' => $entityId,
        'event_type' => 'payroll.cash.disbursed',
        'source_module' => 'payroll',
        'source_record_id' => 'payroll_run:' . $runId . ':cash',
        'event_date' => (string) ($run['pay_date'] ?: date('Y-m-d')),
        'payload' => [
            'run_id' => $runId,
            'amount' => $net / 100,
            'currency' => 'USD',
            'bank_account_id' => $cashAccountId,
            'method' => (string) ($run['disbursement_rail'] ?? 'internal'),
            'bank_ref' => $run['rail_external_ref'] ?? null,
            'memo' => $memo,
            'lines' => [
                ['account_code' => (string) $settings['payroll_payable_account_code'], 'debit' => $net / 100, 'credit' => 0, 'memo' => $memo],
                ['account_code' => (string) $settings['payroll_cash_account_code'], 'debit' => 0, 'credit' => $net / 100, 'memo' => $memo],
            ],
        ],
    ], $actorUserId);
    $result = payrollAccountingPostedEventResult($eventResult, 'Payroll cash disbursement');

    getDB()->prepare(
        'UPDATE payroll_runs SET cash_journal_entry_id = :je, updated_at = NOW()
          WHERE tenant_id = :t AND id = :id'
    )->execute(['je' => (int) $result['je_id'], 't' => $tenantId, 'id' => $runId]);
    payrollAccountingStampLink($tenantId, $runId, (int) $result['je_id'], 'cash');
    payrollAudit('payroll.run.posted', [
        'run_id' => $runId,
        'journal_entry_id' => (int) $result['je_id'],
        'leg' => 'cash',
        'idempotent_replay' => !empty($result['idempotent_replay']),
    ], $runId);

    return $result;
}

function payrollAccountingLoadRun(int $tenantId, int $runId): array
{
    $stmt = getDB()->prepare(
        'SELECT r.*, pp.period_start, pp.period_end, pp.pay_date
           FROM payroll_runs r
           JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
          WHERE r.tenant_id = :t AND r.id = :id
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $runId]);
    $run = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$run) throw new \RuntimeException('Payroll run not found');
    return $run;
}
