<?php
/** Scheduled interest for any balance-sheet ledger account. */
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
    $denominator = $dayCountBasis === 'actual_360' ? 360 : 365;
    return round(abs($balanceBasis) * ($annualRatePercent / 100)
        * (accountInterestDayCount($periodStart, $periodEnd) / $denominator), 2);
}

/** $openingBalance is the balance immediately before periodStart. */
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
        $balance += $byDate[$day->format('Y-m-d')] ?? 0.0;
        $total += abs($balance);
        $days++;
    }
    return $days ? round($total / $days, 6) : 0.0;
}

/** Move a scheduled period end while preserving month-end semantics. */
function accountInterestShiftDate(string $iso, string $cadence, int $direction = 1): string
{
    $date = accountInterestDate($iso);
    if (!in_array($cadence, ['weekly', 'monthly', 'quarterly', 'annual'], true)) {
        throw new \InvalidArgumentException('Unsupported posting cadence');
    }
    if ($cadence === 'weekly') {
        return $date->modify(($direction > 0 ? '+' : '-') . '7 days')->format('Y-m-d');
    }
    $months = match ($cadence) {
        'monthly' => 1,
        'quarterly' => 3,
        'annual' => 12,
    } * ($direction > 0 ? 1 : -1);
    $day = (int) $date->format('j');
    $isMonthEnd = $date->format('Y-m-d') === $date->modify('last day of this month')->format('Y-m-d');
    $target = $date->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
    if ($isMonthEnd) return $target->modify('last day of this month')->format('Y-m-d');
    return $target->setDate(
        (int) $target->format('Y'),
        (int) $target->format('n'),
        min($day, (int) $target->format('t'))
    )->format('Y-m-d');
}

function accountInterestPeriodStart(array $terms, string $periodEnd): string
{
    $stmt = getDB()->prepare(
        'SELECT period_end FROM accounting_account_interest_runs
          WHERE tenant_id = :t AND account_terms_id = :terms AND period_end < :pe
          ORDER BY period_end DESC, id DESC LIMIT 1'
    );
    $stmt->execute(['t' => (int) $terms['tenant_id'], 'terms' => (int) $terms['id'], 'pe' => $periodEnd]);
    $previousEnd = $stmt->fetchColumn();
    if ($previousEnd) return accountInterestDate((string) $previousEnd)->modify('+1 day')->format('Y-m-d');
    if (!empty($terms['effective_from']) && (string) $terms['effective_from'] <= $periodEnd) {
        return (string) $terms['effective_from'];
    }
    return accountInterestDate(accountInterestShiftDate($periodEnd, (string) $terms['posting_cadence'], -1))
        ->modify('+1 day')->format('Y-m-d');
}

function accountInterestTermsForLedgerAccount(int $tenantId, int $accountId, ?int $entityId = null): array
{
    $sql = 'SELECT t.*, a.code AS account_code, a.name AS account_name,
                   a.account_type, a.normal_side,
                   pa.code AS posting_account_code, pa.name AS posting_account_name,
                   oa.code AS offset_account_code, oa.name AS offset_account_name
              FROM accounting_account_terms t
              JOIN accounting_accounts a ON a.id = t.account_id AND a.tenant_id = t.tenant_id
              LEFT JOIN accounting_accounts pa
                ON pa.id = COALESCE(t.posting_account_id, t.account_id) AND pa.tenant_id = t.tenant_id
              LEFT JOIN accounting_accounts oa
                ON oa.id = t.offset_account_id AND oa.tenant_id = t.tenant_id
             WHERE t.tenant_id = :t AND t.account_id = :a';
    $params = ['t' => $tenantId, 'a' => $accountId];
    if ($entityId !== null) {
        $sql .= ' AND t.entity_id = :e';
        $params['e'] = $entityId;
    }
    $sql .= ' ORDER BY t.entity_id';
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['interest_enabled'] = (bool) $row['interest_enabled'];
        $row['auto_post'] = (bool) $row['auto_post'];
        $row['annual_rate_percent'] = (float) $row['annual_rate_percent'];
        foreach (['account_id','entity_id','posting_account_id','offset_account_id','last_run_je_id'] as $field) {
            $row[$field] = $row[$field] !== null ? (int) $row[$field] : null;
        }
    }
    unset($row);
    return $rows;
}

function accountInterestLedgerBalance(
    int $tenantId,
    int $entityId,
    int $accountId,
    string $asOf,
    ?string $excludeIdempotencyKey = null
): float
{
    $stmt = getDB()->prepare(
        'SELECT a.normal_side,
                COALESCE(SUM(CASE WHEN je.status = "posted" AND je.posting_date <= :d
                  AND (:x = "" OR COALESCE(je.idempotency_key, "") <> :x2) THEN l.debit ELSE 0 END), 0) AS debit,
                COALESCE(SUM(CASE WHEN je.status = "posted" AND je.posting_date <= :d2
                  AND (:x3 = "" OR COALESCE(je.idempotency_key, "") <> :x4) THEN l.credit ELSE 0 END), 0) AS credit
           FROM accounting_accounts a
           LEFT JOIN accounting_journal_entry_lines l ON l.account_id = a.id
           LEFT JOIN accounting_journal_entries je
             ON je.id = l.je_id AND je.tenant_id = a.tenant_id AND je.entity_id = :e
          WHERE a.tenant_id = :t AND a.id = :a
          GROUP BY a.id, a.normal_side'
    );
    $exclude = $excludeIdempotencyKey ?: '';
    $stmt->execute([
        'd' => $asOf, 'x' => $exclude, 'x2' => $exclude,
        'd2' => $asOf, 'x3' => $exclude, 'x4' => $exclude,
        'e' => $entityId, 't' => $tenantId, 'a' => $accountId,
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \RuntimeException('Ledger account not found');
    $debit = (float) $row['debit'];
    $credit = (float) $row['credit'];
    return round($row['normal_side'] === 'debit' ? $debit - $credit : $credit - $debit, 6);
}

function accountInterestLedgerMovements(
    int $tenantId,
    int $entityId,
    int $accountId,
    string $periodStart,
    string $periodEnd,
    string $normalSide,
    ?string $excludeIdempotencyKey = null
): array {
    $stmt = getDB()->prepare(
        'SELECT je.posting_date AS posted_date, SUM(l.debit) AS debit, SUM(l.credit) AS credit
           FROM accounting_journal_entry_lines l
           JOIN accounting_journal_entries je ON je.id = l.je_id
          WHERE je.tenant_id = :t AND je.entity_id = :e AND l.account_id = :a
            AND je.status = "posted" AND je.posting_date BETWEEN :ps AND :pe
            AND (:x = "" OR COALESCE(je.idempotency_key, "") <> :x2)
          GROUP BY je.posting_date ORDER BY je.posting_date'
    );
    $exclude = $excludeIdempotencyKey ?: '';
    $stmt->execute([
        't' => $tenantId, 'e' => $entityId, 'a' => $accountId,
        'ps' => $periodStart, 'pe' => $periodEnd, 'x' => $exclude, 'x2' => $exclude,
    ]);
    return array_map(static function (array $row) use ($normalSide): array {
        $debit = (float) $row['debit'];
        $credit = (float) $row['credit'];
        return [
            'posted_date' => (string) $row['posted_date'],
            'amount' => $normalSide === 'debit' ? $debit - $credit : $credit - $debit,
        ];
    }, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
}

function accountInterestRunForPeriod(int $tenantId, int $accountId, int $entityId, string $periodEnd): ?array
{
    $stmt = getDB()->prepare(
        'SELECT r.*, je.je_number, pa.code AS posting_account_code, pa.name AS posting_account_name,
                oa.code AS offset_account_code, oa.name AS offset_account_name
           FROM accounting_account_interest_runs r
           LEFT JOIN accounting_journal_entries je ON je.id = r.journal_entry_id AND je.tenant_id = r.tenant_id
           LEFT JOIN accounting_accounts pa ON pa.id = r.posting_account_id AND pa.tenant_id = r.tenant_id
           LEFT JOIN accounting_accounts oa ON oa.id = r.offset_account_id AND oa.tenant_id = r.tenant_id
          WHERE r.tenant_id = :t AND r.account_id = :a AND r.entity_id = :e AND r.period_end = :pe LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'a' => $accountId, 'e' => $entityId, 'pe' => $periodEnd]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

function accountInterestSaveRun(array $run): int
{
    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO accounting_account_interest_runs
            (tenant_id, account_terms_id, account_id, entity_id, period_start, period_end,
             opening_balance, closing_balance, balance_basis_amount, annual_rate_percent,
             day_count, day_count_basis, balance_method, interest_direction, interest_amount,
             posting_account_id, offset_account_id, journal_entry_id, status, status_reason, created_at)
         VALUES
            (:tenant_id, :account_terms_id, :account_id, :entity_id, :period_start, :period_end,
             :opening_balance, :closing_balance, :balance_basis_amount, :annual_rate_percent,
             :day_count, :day_count_basis, :balance_method, :interest_direction, :interest_amount,
             :posting_account_id, :offset_account_id, :journal_entry_id, :status, :status_reason, NOW())
         ON DUPLICATE KEY UPDATE
             opening_balance = VALUES(opening_balance), closing_balance = VALUES(closing_balance),
             balance_basis_amount = VALUES(balance_basis_amount), annual_rate_percent = VALUES(annual_rate_percent),
             day_count = VALUES(day_count), day_count_basis = VALUES(day_count_basis),
             balance_method = VALUES(balance_method), interest_direction = VALUES(interest_direction),
             interest_amount = VALUES(interest_amount), posting_account_id = VALUES(posting_account_id),
             offset_account_id = VALUES(offset_account_id), journal_entry_id = VALUES(journal_entry_id),
             status = VALUES(status), status_reason = VALUES(status_reason), updated_at = NOW()'
    );
    $stmt->execute($run);
    if ((int) $db->lastInsertId() > 0) return (int) $db->lastInsertId();
    $find = $db->prepare(
        'SELECT id FROM accounting_account_interest_runs
          WHERE tenant_id = :t AND account_id = :a AND entity_id = :e AND period_end = :pe LIMIT 1'
    );
    $find->execute(['t' => $run['tenant_id'], 'a' => $run['account_id'], 'e' => $run['entity_id'], 'pe' => $run['period_end']]);
    return (int) $find->fetchColumn();
}

function accountInterestRunOnce(int $tenantId, int $termsId, ?int $actorUserId = null, ?string $forcePeriodEnd = null): array
{
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT t.*, a.name AS account_name, a.account_type, a.normal_side, a.currency,
                pa.id AS resolved_posting_account_id, oa.id AS resolved_offset_account_id
           FROM accounting_account_terms t
           JOIN accounting_accounts a
             ON a.id = t.account_id AND a.tenant_id = t.tenant_id AND a.active = 1 AND a.is_postable = 1
           JOIN accounting_accounts pa
             ON pa.id = COALESCE(t.posting_account_id, t.account_id) AND pa.tenant_id = t.tenant_id
            AND pa.active = 1 AND pa.is_postable = 1
           JOIN accounting_accounts oa
             ON oa.id = t.offset_account_id AND oa.tenant_id = t.tenant_id
            AND oa.active = 1 AND oa.is_postable = 1
          WHERE t.tenant_id = :t AND t.id = :id LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $termsId]);
    $terms = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$terms) throw new \RuntimeException('Account terms are incomplete or reference an inactive account');
    if (empty($terms['interest_enabled'])) return ['status' => 'not_enabled'];

    $periodEnd = $forcePeriodEnd ?: (string) ($terms['next_post_date'] ?? '');
    if ($periodEnd === '') throw new \RuntimeException('Set the next interest posting date');
    accountInterestDate($periodEnd);
    // A period is complete only after its end date has passed. This keeps the
    // every-minute worker from posting at midnight before that day's activity.
    if (!$forcePeriodEnd && $periodEnd >= date('Y-m-d')) return ['status' => 'not_due', 'next_post_date' => $periodEnd];
    if (!empty($terms['effective_from']) && $periodEnd < $terms['effective_from']) {
        throw new \RuntimeException('The next posting date is before the terms effective date');
    }
    if (!empty($terms['maturity_date']) && $periodEnd > $terms['maturity_date']) {
        $db->prepare('UPDATE accounting_account_terms SET interest_enabled = 0 WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => $tenantId, 'id' => $termsId]);
        return ['status' => 'matured', 'maturity_date' => $terms['maturity_date']];
    }

    $nextPostDate = accountInterestShiftDate($periodEnd, (string) $terms['posting_cadence']);
    $existing = accountInterestRunForPeriod($tenantId, (int) $terms['account_id'], (int) $terms['entity_id'], $periodEnd);
    if ($existing && !empty($existing['journal_entry_id'])) {
        $db->prepare(
            'UPDATE accounting_account_terms SET next_post_date = :next, last_run_at = NOW(), last_run_je_id = :je
              WHERE tenant_id = :t AND id = :id AND (next_post_date IS NULL OR next_post_date <= :pe)'
        )->execute(['next' => $nextPostDate, 'je' => (int) $existing['journal_entry_id'], 't' => $tenantId, 'id' => $termsId, 'pe' => $periodEnd]);
        return ['status' => $existing['status'], 'run' => $existing, 'idempotent_replay' => true, 'next_post_date' => $nextPostDate];
    }

    $periodStart = accountInterestPeriodStart($terms, $periodEnd);
    $idempotencyKey = 'account_interest:' . (int) $terms['account_id'] . ':' . (int) $terms['entity_id'] . ':' . $periodEnd;
    $openingDate = accountInterestDate($periodStart)->modify('-1 day')->format('Y-m-d');
    $openingBalance = accountInterestLedgerBalance(
        $tenantId, (int) $terms['entity_id'], (int) $terms['account_id'], $openingDate, $idempotencyKey
    );
    $movements = accountInterestLedgerMovements(
        $tenantId, (int) $terms['entity_id'], (int) $terms['account_id'],
        $periodStart, $periodEnd, (string) $terms['normal_side'], $idempotencyKey
    );
    $closingBalance = $openingBalance + array_sum(array_column($movements, 'amount'));
    $balanceBasis = $terms['balance_method'] === 'closing_balance'
        ? abs($closingBalance)
        : accountInterestAverageDailyBalance($openingBalance, $periodStart, $periodEnd, $movements);
    $amount = accountInterestCalculate(
        $balanceBasis, (float) $terms['annual_rate_percent'], $periodStart,
        $periodEnd, (string) $terms['day_count_basis']
    );

    $run = [
        'tenant_id' => $tenantId,
        'account_terms_id' => $termsId,
        'account_id' => (int) $terms['account_id'],
        'entity_id' => (int) $terms['entity_id'],
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'opening_balance' => $openingBalance,
        'closing_balance' => $closingBalance,
        'balance_basis_amount' => $balanceBasis,
        'annual_rate_percent' => (float) $terms['annual_rate_percent'],
        'day_count' => accountInterestDayCount($periodStart, $periodEnd),
        'day_count_basis' => (string) $terms['day_count_basis'],
        'balance_method' => (string) $terms['balance_method'],
        'interest_direction' => (string) $terms['interest_direction'],
        'interest_amount' => $amount,
        'posting_account_id' => (int) $terms['resolved_posting_account_id'],
        'offset_account_id' => (int) $terms['resolved_offset_account_id'],
        'journal_entry_id' => null,
        'status' => 'skipped',
        'status_reason' => 'amount_below_posting_threshold',
    ];

    if ($amount >= 0.01) {
        $earned = $terms['interest_direction'] === 'earned';
        $memo = sprintf('Interest %s on %s for %s to %s', $earned ? 'earned' : 'charged', $terms['account_name'], $periodStart, $periodEnd);
        $entry = accountingPostJe($tenantId, [
            'entity_id' => (int) $terms['entity_id'],
            'posting_date' => $periodEnd,
            'currency' => (string) ($terms['currency'] ?: 'USD'),
            'source_module' => 'system',
            'source_ref_type' => 'accounting_account_terms',
            'source_ref_id' => $termsId,
            'idempotency_key' => $idempotencyKey,
            'memo' => $memo,
            'lines' => $earned
                ? [
                    ['account_id' => (int) $terms['resolved_posting_account_id'], 'debit' => $amount, 'credit' => 0, 'memo' => $memo],
                    ['account_id' => (int) $terms['resolved_offset_account_id'], 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
                ]
                : [
                    ['account_id' => (int) $terms['resolved_offset_account_id'], 'debit' => $amount, 'credit' => 0, 'memo' => $memo],
                    ['account_id' => (int) $terms['resolved_posting_account_id'], 'debit' => 0, 'credit' => $amount, 'memo' => $memo],
                ],
        ], $actorUserId, !empty($terms['auto_post']));
        $run['journal_entry_id'] = (int) $entry['je_id'];
        $run['status'] = !empty($terms['auto_post']) ? 'posted' : 'draft';
        $run['status_reason'] = null;
    }

    $runId = accountInterestSaveRun($run);
    $db->prepare(
        'UPDATE accounting_account_terms SET next_post_date = :next, last_run_at = NOW(), last_run_je_id = :je
          WHERE tenant_id = :t AND id = :id'
    )->execute(['next' => $nextPostDate, 'je' => $run['journal_entry_id'], 't' => $tenantId, 'id' => $termsId]);
    accountingAudit('accounting.account.interest_run', [
        'terms_id' => $termsId, 'account_id' => (int) $terms['account_id'],
        'entity_id' => (int) $terms['entity_id'], 'period_start' => $periodStart,
        'period_end' => $periodEnd, 'amount' => $amount, 'status' => $run['status'],
        'journal_entry_id' => $run['journal_entry_id'],
    ], (int) $terms['account_id']);
    return [
        'status' => $run['status'], 'run_id' => $runId, 'amount' => $amount,
        'journal_entry_id' => $run['journal_entry_id'], 'period_start' => $periodStart,
        'period_end' => $periodEnd, 'opening_balance' => $openingBalance,
        'closing_balance' => $closingBalance, 'balance_basis_amount' => $balanceBasis,
        'next_post_date' => $nextPostDate,
    ];
}

function accountInterestRunDueForTenant(int $tenantId, ?int $actorUserId = null, ?string $asOf = null): array
{
    $asOf = $asOf ?: date('Y-m-d');
    accountInterestDate($asOf);
    $stmt = getDB()->prepare(
        'SELECT id FROM accounting_account_terms
          WHERE tenant_id = :t AND interest_enabled = 1
            AND next_post_date IS NOT NULL AND next_post_date < :d
          ORDER BY next_post_date, id'
    );
    $stmt->execute(['t' => $tenantId, 'd' => $asOf]);
    $summary = ['ran' => 0, 'skipped' => 0, 'errors' => 0, 'detail' => []];
    foreach (array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) as $termsId) {
        for ($catchup = 0; $catchup < 24; $catchup++) {
            $next = getDB()->prepare('SELECT interest_enabled, next_post_date FROM accounting_account_terms WHERE tenant_id = :t AND id = :id');
            $next->execute(['t' => $tenantId, 'id' => $termsId]);
            $current = $next->fetch(\PDO::FETCH_ASSOC);
            if (!$current || empty($current['interest_enabled']) || empty($current['next_post_date']) || $current['next_post_date'] >= $asOf) break;
            try {
                $result = accountInterestRunOnce($tenantId, $termsId, $actorUserId);
                $summary['detail'][] = ['terms_id' => $termsId] + $result;
                in_array($result['status'], ['posted','draft'], true) ? $summary['ran']++ : $summary['skipped']++;
                if (in_array($result['status'], ['matured','not_enabled','not_due'], true)) break;
            } catch (\Throwable $e) {
                $summary['errors']++;
                $summary['detail'][] = ['terms_id' => $termsId, 'error' => $e->getMessage()];
                error_log('[account_interest] terms ' . $termsId . ' failed: ' . $e->getMessage());
                break;
            }
        }
    }
    return $summary;
}

function accountInterestRunDueAllTenants(?int $onlyTenant = null, ?string $asOf = null): array
{
    $asOf = $asOf ?: date('Y-m-d');
    $sql = 'SELECT DISTINCT tenant_id FROM accounting_account_terms
             WHERE interest_enabled = 1 AND next_post_date IS NOT NULL AND next_post_date < :d';
    $params = ['d' => $asOf];
    if ($onlyTenant !== null && $onlyTenant > 0) {
        $sql .= ' AND tenant_id = :t';
        $params['t'] = $onlyTenant;
    }
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $summary = ['tenants' => 0, 'ran' => 0, 'skipped' => 0, 'errors' => 0, 'detail' => []];
    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $tenantId) {
        $result = accountInterestRunDueForTenant((int) $tenantId, null, $asOf);
        $summary['tenants']++;
        $summary['ran'] += $result['ran'];
        $summary['skipped'] += $result['skipped'];
        $summary['errors'] += $result['errors'];
        $summary['detail'][(int) $tenantId] = $result['detail'];
    }
    return $summary;
}
