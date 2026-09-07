<?php
/**
 * Account-level interest calculation and reconciliation-close posting.
 */
declare(strict_types=1);

require_once __DIR__ . '/accounting.php';

function accountInterestDate(string $value): \DateTimeImmutable
{
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new \InvalidArgumentException('Date must be YYYY-MM-DD');
    }
    return $date;
}

function accountInterestDayCount(string $periodStart, string $periodEnd): int
{
    $start = accountInterestDate($periodStart);
    $end = accountInterestDate($periodEnd);
    if ($end < $start) throw new \InvalidArgumentException('Period end must be on or after period start');
    return (int) $start->diff($end)->days + 1;
}

function accountInterestCalculate(
    float $balanceBasis,
    float $annualRatePercent,
    string $periodStart,
    string $periodEnd,
    string $dayCountBasis
): float {
    if ($annualRatePercent < 0) throw new \InvalidArgumentException('Annual rate cannot be negative');
    if (!in_array($dayCountBasis, ['actual_365', 'actual_360'], true)) {
        throw new \InvalidArgumentException('Unsupported day-count basis');
    }
    $days = accountInterestDayCount($periodStart, $periodEnd);
    $denominator = $dayCountBasis === 'actual_360' ? 360 : 365;
    return round(abs($balanceBasis) * ($annualRatePercent / 100) * ($days / $denominator), 2);
}

/**
 * Average end-of-day balance. $openingBalance is the balance immediately
 * before periodStart; movements are signed account movements by posting date.
 */
function accountInterestAverageDailyBalance(
    float $openingBalance,
    string $periodStart,
    string $periodEnd,
    array $movements
): float {
    $start = accountInterestDate($periodStart);
    $end = accountInterestDate($periodEnd);
    if ($end < $start) throw new \InvalidArgumentException('Period end must be on or after period start');

    $byDate = [];
    foreach ($movements as $movement) {
        $date = (string) ($movement['posted_date'] ?? '');
        if ($date < $periodStart || $date > $periodEnd) continue;
        $byDate[$date] = ($byDate[$date] ?? 0.0) + (float) ($movement['amount'] ?? 0);
    }

    $balance = $openingBalance;
    $total = 0.0;
    $days = 0;
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $key = $day->format('Y-m-d');
        $balance += $byDate[$key] ?? 0.0;
        $total += abs($balance);
        $days++;
    }
    return $days > 0 ? round($total / $days, 6) : 0.0;
}

function accountInterestTermsForAccount(int $tenantId, int $bankAccountId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT t.*, a.code AS offset_account_code, a.name AS offset_account_name,
                a.account_type AS offset_account_type
           FROM accounting_bank_account_terms t
           LEFT JOIN accounting_accounts a
             ON a.id = t.offset_account_id AND a.tenant_id = t.tenant_id
          WHERE t.tenant_id = :t AND t.bank_account_id = :b LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'b' => $bankAccountId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['interest_enabled'] = (bool) $row['interest_enabled'];
    $row['offset_account_id'] = $row['offset_account_id'] !== null ? (int) $row['offset_account_id'] : null;
    $row['annual_rate_percent'] = (float) $row['annual_rate_percent'];
    return $row;
}

function accountInterestPeriodStart(int $tenantId, int $bankAccountId, int $reconciliationId, string $periodEnd): string
{
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT statement_from
           FROM accounting_bank_statement_imports
          WHERE tenant_id = :t AND bank_account_id = :b
            AND statement_to = :pe AND statement_from IS NOT NULL
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'b' => $bankAccountId, 'pe' => $periodEnd]);
    $from = $stmt->fetchColumn();
    if ($from && (string) $from <= $periodEnd) return (string) $from;

    $stmt = $db->prepare(
        'SELECT period_end
           FROM accounting_reconciliations
          WHERE tenant_id = :t AND bank_account_id = :b AND id <> :id
            AND period_end < :pe
          ORDER BY period_end DESC, id DESC LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'b' => $bankAccountId, 'id' => $reconciliationId, 'pe' => $periodEnd]);
    $previousEnd = $stmt->fetchColumn();
    if ($previousEnd) return accountInterestDate((string) $previousEnd)->modify('+1 day')->format('Y-m-d');

    return accountInterestDate($periodEnd)->modify('first day of this month')->format('Y-m-d');
}

function accountInterestGlBalance(int $tenantId, int $entityId, string $accountCode, string $asOf): float
{
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, normal_side FROM accounting_accounts
          WHERE tenant_id = :t AND code = :c LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'c' => $accountCode]);
    $account = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$account) throw new \RuntimeException("Ledger account {$accountCode} was not found");

    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit
           FROM accounting_journal_entry_lines l
           JOIN accounting_journal_entries je ON je.id = l.je_id
          WHERE l.account_id = :a AND je.tenant_id = :t AND je.entity_id = :e
            AND je.status = "posted" AND je.posting_date <= :d'
    );
    $stmt->execute(['a' => (int) $account['id'], 't' => $tenantId, 'e' => $entityId, 'd' => $asOf]);
    $totals = $stmt->fetch(\PDO::FETCH_ASSOC) ?: ['debit' => 0, 'credit' => 0];
    $debit = (float) $totals['debit'];
    $credit = (float) $totals['credit'];
    return round($account['normal_side'] === 'debit' ? $debit - $credit : $credit - $debit, 2);
}

function accountInterestSaveRun(array $run): int
{
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO accounting_bank_interest_runs
            (tenant_id, bank_account_id, reconciliation_id, period_start, period_end,
             statement_balance, balance_basis_amount, annual_rate_percent, day_count,
             day_count_basis, balance_method, interest_direction, interest_amount,
             offset_account_id, journal_entry_id, matched_statement_line_id, status,
             status_reason, created_at)
         VALUES
            (:tenant_id, :bank_account_id, :reconciliation_id, :period_start, :period_end,
             :statement_balance, :balance_basis_amount, :annual_rate_percent, :day_count,
             :day_count_basis, :balance_method, :interest_direction, :interest_amount,
             :offset_account_id, :journal_entry_id, :matched_statement_line_id, :status,
             :status_reason, NOW())
         ON DUPLICATE KEY UPDATE
             statement_balance = VALUES(statement_balance),
             balance_basis_amount = VALUES(balance_basis_amount),
             annual_rate_percent = VALUES(annual_rate_percent),
             day_count = VALUES(day_count),
             day_count_basis = VALUES(day_count_basis),
             balance_method = VALUES(balance_method),
             interest_direction = VALUES(interest_direction),
             interest_amount = VALUES(interest_amount),
             offset_account_id = VALUES(offset_account_id),
             journal_entry_id = VALUES(journal_entry_id),
             matched_statement_line_id = VALUES(matched_statement_line_id),
             status = VALUES(status), status_reason = VALUES(status_reason), updated_at = NOW()'
    );
    $stmt->execute($run);
    if ((int) $db->lastInsertId() > 0) return (int) $db->lastInsertId();
    $find = $db->prepare(
        'SELECT id FROM accounting_bank_interest_runs
          WHERE tenant_id = :t AND bank_account_id = :b AND period_end = :pe LIMIT 1'
    );
    $find->execute(['t' => $run['tenant_id'], 'b' => $run['bank_account_id'], 'pe' => $run['period_end']]);
    return (int) $find->fetchColumn();
}

function accountInterestRunForReconciliation(int $tenantId, int $reconciliationId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT r.*, je.je_number, oa.code AS offset_account_code, oa.name AS offset_account_name
           FROM accounting_bank_interest_runs r
           JOIN accounting_reconciliations rec
             ON rec.tenant_id = r.tenant_id AND rec.id = :reconciliation_id
           LEFT JOIN accounting_journal_entries je
             ON je.id = r.journal_entry_id AND je.tenant_id = r.tenant_id
           LEFT JOIN accounting_accounts oa
             ON oa.id = r.offset_account_id AND oa.tenant_id = r.tenant_id
          WHERE r.tenant_id = :tenant_id
            AND (r.reconciliation_id = rec.id
                 OR (r.bank_account_id = rec.bank_account_id AND r.period_end = rec.period_end))
          ORDER BY (r.reconciliation_id = rec.id) DESC LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'reconciliation_id' => $reconciliationId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function accountInterestProcessReconciliation(
    int $tenantId,
    int $reconciliationId,
    ?int $actorUserId,
    ?float $statementBalanceOverride = null
): array {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT r.id, r.bank_account_id, r.period_end, r.statement_balance,
                ba.name AS bank_account_name, ba.entity_id, ba.gl_account_code, ba.currency,
                la.id AS ledger_account_id
           FROM accounting_reconciliations r
           JOIN accounting_bank_accounts ba
             ON ba.id = r.bank_account_id AND ba.tenant_id = r.tenant_id
           LEFT JOIN accounting_accounts la
             ON la.tenant_id = ba.tenant_id AND la.code = ba.gl_account_code
          WHERE r.tenant_id = :t AND r.id = :r LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'r' => $reconciliationId]);
    $recon = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$recon) throw new \RuntimeException('Reconciliation not found');

    $terms = accountInterestTermsForAccount($tenantId, (int) $recon['bank_account_id']);
    if (!$terms || !$terms['interest_enabled']) return ['status' => 'not_configured'];

    $entityId = !empty($recon['entity_id'])
        ? (int) $recon['entity_id']
        : (int) accountingDefaultEntity($tenantId)['id'];

    $existing = accountInterestRunForReconciliation($tenantId, $reconciliationId);
    if ($existing && !empty($existing['journal_entry_id'])) {
        return [
            'status' => 'posted',
            'run' => $existing,
            'idempotent_replay' => true,
            'gl_balance' => accountInterestGlBalance(
                $tenantId,
                $entityId,
                (string) $recon['gl_account_code'],
                (string) $recon['period_end']
            ),
        ];
    }

    $periodEnd = (string) $recon['period_end'];
    if (!empty($terms['effective_from']) && $periodEnd < $terms['effective_from']) {
        return ['status' => 'not_effective', 'effective_from' => $terms['effective_from']];
    }
    if (!empty($terms['maturity_date']) && $periodEnd > $terms['maturity_date']) {
        return ['status' => 'matured', 'maturity_date' => $terms['maturity_date']];
    }
    if (empty($recon['ledger_account_id'])) {
        throw new \RuntimeException('This account is not linked to a valid ledger account');
    }
    if (empty($terms['offset_account_id'])) {
        throw new \RuntimeException('Choose an interest income or expense account in the account terms');
    }

    $periodStart = accountInterestPeriodStart(
        $tenantId,
        (int) $recon['bank_account_id'],
        $reconciliationId,
        $periodEnd
    );
    $dayCount = accountInterestDayCount($periodStart, $periodEnd);
    $statementBalance = $statementBalanceOverride ?? (float) $recon['statement_balance'];

    $stmt = $db->prepare(
        'SELECT posted_date, amount
           FROM accounting_bank_statement_lines
          WHERE tenant_id = :t AND bank_account_id = :b
            AND posted_date BETWEEN :ps AND :pe
          ORDER BY posted_date, id'
    );
    $stmt->execute([
        't' => $tenantId,
        'b' => (int) $recon['bank_account_id'],
        'ps' => $periodStart,
        'pe' => $periodEnd,
    ]);
    $movements = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $movementTotal = array_sum(array_map(static fn(array $row): float => (float) $row['amount'], $movements));
    $openingBalance = $statementBalance - $movementTotal;
    $balanceBasis = $terms['balance_method'] === 'closing_balance'
        ? abs($statementBalance)
        : accountInterestAverageDailyBalance($openingBalance, $periodStart, $periodEnd, $movements);
    $amount = accountInterestCalculate(
        $balanceBasis,
        (float) $terms['annual_rate_percent'],
        $periodStart,
        $periodEnd,
        (string) $terms['day_count_basis']
    );

    $run = [
        'tenant_id' => $tenantId,
        'bank_account_id' => (int) $recon['bank_account_id'],
        'reconciliation_id' => $reconciliationId,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'statement_balance' => round($statementBalance, 2),
        'balance_basis_amount' => $balanceBasis,
        'annual_rate_percent' => (float) $terms['annual_rate_percent'],
        'day_count' => $dayCount,
        'day_count_basis' => (string) $terms['day_count_basis'],
        'balance_method' => (string) $terms['balance_method'],
        'interest_direction' => (string) $terms['interest_direction'],
        'interest_amount' => $amount,
        'offset_account_id' => (int) $terms['offset_account_id'],
        'journal_entry_id' => null,
        'matched_statement_line_id' => null,
        'status' => 'skipped',
        'status_reason' => 'amount_below_posting_threshold',
    ];

    if ($amount < 0.01) {
        accountInterestSaveRun($run);
        return ['status' => 'skipped', 'reason' => $run['status_reason'], 'amount' => 0.0];
    }

    $earned = $terms['interest_direction'] === 'earned';
    $memo = sprintf(
        'Interest %s for %s, statement period %s to %s',
        $earned ? 'earned' : 'charged',
        $recon['bank_account_name'],
        $periodStart,
        $periodEnd
    );
    $entry = accountingPostJe($tenantId, [
        'entity_id' => $entityId,
        'posting_date' => $periodEnd,
        'currency' => (string) ($recon['currency'] ?: 'USD'),
        'source_module' => 'account_interest',
        'source_ref_type' => 'accounting_reconciliation',
        'source_ref_id' => $reconciliationId,
        'idempotency_key' => 'account_interest:' . (int) $recon['bank_account_id'] . ':' . $periodEnd,
        'memo' => $memo,
        'lines' => $earned
            ? [
                ['account_id' => (int) $recon['ledger_account_id'], 'debit' => $amount, 'credit' => 0, 'memo' => $memo],
                ['account_id' => (int) $terms['offset_account_id'], 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
            ]
            : [
                ['account_id' => (int) $terms['offset_account_id'], 'debit' => $amount, 'credit' => 0, 'memo' => $memo],
                ['account_id' => (int) $recon['ledger_account_id'], 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
            ],
    ], $actorUserId, true);

    $expectedStatementAmount = $earned ? $amount : -$amount;
    $stmt = $db->prepare(
        'SELECT id FROM accounting_bank_statement_lines
          WHERE tenant_id = :t AND bank_account_id = :b AND matched_je_id = :je
          ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([
        't' => $tenantId,
        'b' => (int) $recon['bank_account_id'],
        'je' => (int) $entry['je_id'],
    ]);
    $matchedLineId = (int) ($stmt->fetchColumn() ?: 0);

    if ($matchedLineId === 0) {
        $stmt = $db->prepare(
            'SELECT id FROM accounting_bank_statement_lines
              WHERE tenant_id = :t AND bank_account_id = :b AND match_status = "unmatched"
                AND posted_date BETWEEN :ps AND :pe
                AND LOWER(COALESCE(description, "")) LIKE "%interest%"
                AND ABS(amount - :amount) < 0.011
              ORDER BY posted_date DESC, id DESC LIMIT 1'
        );
        $stmt->execute([
            't' => $tenantId,
            'b' => (int) $recon['bank_account_id'],
            'ps' => $periodStart,
            'pe' => $periodEnd,
            'amount' => $expectedStatementAmount,
        ]);
        $matchedLineId = (int) ($stmt->fetchColumn() ?: 0);
    }
    if ($matchedLineId > 0) {
        $db->prepare(
            'UPDATE accounting_bank_statement_lines
                SET match_status = "matched", matched_je_id = :je,
                    matched_at = NOW(), matched_by_user_id = :u
              WHERE tenant_id = :t AND id = :id AND match_status = "unmatched"'
        )->execute([
            'je' => (int) $entry['je_id'],
            'u' => $actorUserId,
            't' => $tenantId,
            'id' => $matchedLineId,
        ]);
    }

    $run['journal_entry_id'] = (int) $entry['je_id'];
    $run['matched_statement_line_id'] = $matchedLineId ?: null;
    $run['status'] = 'posted';
    $run['status_reason'] = null;
    accountInterestSaveRun($run);

    accountingAudit('accounting.bank_account.interest_posted', [
        'bank_account_id' => (int) $recon['bank_account_id'],
        'reconciliation_id' => $reconciliationId,
        'journal_entry_id' => (int) $entry['je_id'],
        'amount' => $amount,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'direction' => $terms['interest_direction'],
    ], (int) $recon['bank_account_id']);

    return [
        'status' => 'posted',
        'amount' => $amount,
        'journal_entry' => $entry,
        'matched_statement_line_id' => $matchedLineId ?: null,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'balance_basis_amount' => $balanceBasis,
        'day_count' => $dayCount,
        'gl_balance' => accountInterestGlBalance($tenantId, $entityId, (string) $recon['gl_account_code'], $periodEnd),
    ];
}
