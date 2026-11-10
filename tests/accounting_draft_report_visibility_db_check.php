<?php
/** Draft-only accounts must remain visible at zero in accounting reports. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
if ($root === false || !is_file($root . '/modules/accounting/lib/standard_reports.php')) {
    throw new RuntimeException('Installed CoreAccounting runtime is required.');
}
require_once $root . '/core/db.php';
require_once $root . '/modules/accounting/lib/standard_reports.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$admin = $pdo->query("SELECT tenant_id FROM users
    WHERE email = 'ci-admin@coreaccounting.invalid' AND is_active = 1 LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
if (!$admin) throw new RuntimeException('Disposable administrator missing.');
$tenantId = (int) $admin['tenant_id'];
$period = $pdo->prepare('SELECT id, entity_id FROM accounting_periods
    WHERE tenant_id = :tenant AND status = "open"
      AND start_date <= "2026-10-11" AND end_date >= "2026-10-11"
    ORDER BY id LIMIT 1');
$period->execute(['tenant' => $tenantId]);
$open = $period->fetch(PDO::FETCH_ASSOC);
if (!$open) throw new RuntimeException('Disposable open period missing.');
$entityId = (int) $open['entity_id'];

$find = static function (array $rows, string $code): array {
    foreach ($rows as $row) {
        if ($row['code'] === $code) return $row;
    }
    throw new RuntimeException("Report omitted synthetic account {$code}");
};
$snapshot = static function () use ($tenantId, $entityId, $find): array {
    $tb = accountingTrialBalance($tenantId, '2026-10-11', $entityId);
    $income = reportIncomeStatement($tenantId, '2026-10-01', '2026-10-11', $entityId);
    $balance = reportBalanceSheet($tenantId, '2026-10-11', $entityId);
    $consolidatedIncome = consolidateIncomeStatement($tenantId, [$entityId],
        '2026-10-01', '2026-10-11');
    $consolidatedTb = consolidateTrialBalance($tenantId, [$entityId], '2026-10-11');
    return [
        'trial' => array_map(static fn(string $code): float =>
            (float) $find($tb, $code)['balance_signed'],
            ['CI-DRAFT-ASSET', 'CI-DRAFT-EXP', 'CI-DRAFT-REV']),
        'income_expense' => (float) $find($income['expense'], 'CI-DRAFT-EXP')['amount'],
        'income_revenue' => (float) $find($income['revenue'], 'CI-DRAFT-REV')['amount'],
        'balance_asset' => (float) $find($balance['assets'], 'CI-DRAFT-ASSET')['amount'],
        'consolidated_expense' => (float) $find($consolidatedIncome['expense'], 'CI-DRAFT-EXP')['amount'],
        'consolidated_revenue' => (float) $find($consolidatedIncome['revenue'], 'CI-DRAFT-REV')['amount'],
        'consolidated_trial' => $consolidatedTb['rows'],
        'totals' => [
            $income['total_revenue'], $income['total_expense'], $income['net_income'],
            $balance['total_assets'], $balance['total_equity'],
            $consolidatedIncome['total_revenue'], $consolidatedIncome['total_expense'],
        ],
    ];
};

$pdo->beginTransaction();
try {
    $addAccount = $pdo->prepare('INSERT INTO accounting_accounts
        (tenant_id, code, name, account_type, normal_side, currency)
        VALUES (:tenant, :code, :name, :type, :side, "USD")');
    $accounts = [
        'CI-DRAFT-ASSET' => ['CI Draft-only asset', 'asset', 'debit'],
        'CI-DRAFT-EXP' => ['CI Draft-only expense', 'expense', 'debit'],
        'CI-DRAFT-REV' => ['CI Draft-only revenue', 'revenue', 'credit'],
    ];
    $accountIds = [];
    foreach ($accounts as $code => [$name, $type, $side]) {
        $addAccount->execute([
            'tenant' => $tenantId, 'code' => $code,
            'name' => $name, 'type' => $type, 'side' => $side,
        ]);
        $accountIds[$code] = (int) $pdo->lastInsertId();
    }
    $before = $snapshot();
    if ($before['trial'] !== [0.0, 0.0, 0.0]
        || $before['income_expense'] !== 0.0 || $before['income_revenue'] !== 0.0
        || $before['balance_asset'] !== 0.0) {
        throw new RuntimeException('Synthetic accounts did not start at zero.');
    }

    $addJournal = $pdo->prepare('INSERT INTO accounting_journal_entries
        (tenant_id, entity_id, period_id, je_number, posting_date, status, currency,
         total_debit, total_credit, memo)
        VALUES (:tenant, :entity, :period, :number, "2026-10-11", :status, "USD",
                0.19, 0.19, "CI synthetic draft report visibility")');
    $addLine = $pdo->prepare('INSERT INTO accounting_journal_entry_lines
        (tenant_id, je_id, line_no, account_id, debit, credit)
        VALUES (:tenant, :journal, :line, :account, :debit, :credit)');
    foreach ([
        ['CI-DRAFT-ASSET', 'CI-DRAFT-REV'],
        ['CI-DRAFT-EXP', 'CI-DRAFT-ASSET'],
    ] as $index => [$debitCode, $creditCode]) {
        $addJournal->execute([
            'tenant' => $tenantId, 'entity' => $entityId, 'period' => (int) $open['id'],
            'number' => 'CI-DRAFT-REPORT-' . ($index + 1), 'status' => 'draft',
        ]);
        $journalId = (int) $pdo->lastInsertId();
        $addLine->execute(['tenant' => $tenantId, 'journal' => $journalId,
            'line' => 1, 'account' => $accountIds[$debitCode], 'debit' => 0.19, 'credit' => 0]);
        $addLine->execute(['tenant' => $tenantId, 'journal' => $journalId,
            'line' => 2, 'account' => $accountIds[$creditCode], 'debit' => 0, 'credit' => 0.19]);
    }
    if ($snapshot() !== $before) {
        throw new RuntimeException('Draft-only journals changed report rows or amounts.');
    }
    echo "PASS draft-only accounts remain visible at zero in trial balance, income statement, balance sheet, and consolidated reports\n";

    $addJournal->execute([
        'tenant' => $tenantId, 'entity' => $entityId, 'period' => (int) $open['id'],
        'number' => 'CI-DRAFT-REPORT-POSTED', 'status' => 'posted',
    ]);
    $postedId = (int) $pdo->lastInsertId();
    $addLine->execute(['tenant' => $tenantId, 'journal' => $postedId,
        'line' => 1, 'account' => $accountIds['CI-DRAFT-ASSET'], 'debit' => 0.19, 'credit' => 0]);
    $addLine->execute(['tenant' => $tenantId, 'journal' => $postedId,
        'line' => 2, 'account' => $accountIds['CI-DRAFT-REV'], 'debit' => 0, 'credit' => 0.19]);
    $posted = $snapshot();
    if ($posted['trial'] !== [0.19, 0.0, 0.19]
        || $posted['income_expense'] !== 0.0 || $posted['income_revenue'] !== 0.19
        || $posted['balance_asset'] !== 0.19
        || $posted['consolidated_expense'] !== 0.0
        || $posted['consolidated_revenue'] !== 0.19
        || (float) $find($posted['consolidated_trial'], 'CI-DRAFT-ASSET')['balance_signed'] !== 0.19
        || (float) $find($posted['consolidated_trial'], 'CI-DRAFT-REV')['balance_signed'] !== 0.19) {
        throw new RuntimeException('Posted journal was not counted exactly once alongside drafts.');
    }
    echo "PASS posted activity is counted exactly once alongside excluded drafts\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
