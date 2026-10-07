<?php
/** Reviewed first-run balance-sheet import into the canonical journal. */
declare(strict_types=1);

require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../../../core/accounting/control_accounts.php';

use Core\CsvImportService;

class AccountingOpeningConflict extends RuntimeException {}

function accountingOpeningRegisterSchema(): void
{
    CsvImportService::registerSchema('accounting_opening_balances', [
        'fields' => [
            'account_code' => ['label' => 'Account code', 'required' => true],
            'balance' => ['label' => 'Balance', 'required' => true],
        ],
        'unique_within_batch' => ['account_code'],
    ]);
}

/** Positive balances follow the account's normal side; parentheses mean negative. */
function accountingOpeningSignedCents(string $input): int
{
    $value = trim($input);
    $negative = false;
    if (str_starts_with($value, '(') && str_ends_with($value, ')')) {
        $negative = true;
        $value = trim(substr($value, 1, -1));
    } elseif (str_starts_with($value, '-')) {
        $negative = true;
        $value = trim(substr($value, 1));
    }
    if (str_starts_with($value, '$')) $value = substr($value, 1);
    if (!preg_match('/^(?:\d{1,10}|\d{1,3}(?:,\d{3}){1,3})(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Use a balance of at most $999,999,999.99 with up to two decimals.');
    }
    $plain = str_replace(',', '', $value);
    [$whole, $fraction] = array_pad(explode('.', $plain, 2), 2, '');
    $cents = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    if ($cents > 99999999999) {
        throw new InvalidArgumentException('A balance cannot exceed $999,999,999.99.');
    }
    return $negative ? -$cents : $cents;
}

function accountingOpeningAmount(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** @return list<array{account_code:string,debit:string,credit:string}> */
function accountingOpeningStoredLines(PDO $pdo, int $tenantId, int $jeId): array
{
    $stmt = $pdo->prepare(
        'SELECT a.code AS account_code, l.debit, l.credit
           FROM accounting_journal_entry_lines l
           JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
          WHERE l.tenant_id = :t AND l.je_id = :je ORDER BY a.code'
    );
    $stmt->execute(['t' => $tenantId, 'je' => $jeId]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lines[] = [
            'account_code' => (string) $row['account_code'],
            'debit' => accountingOpeningAmount(accountingOpeningSignedCents((string) $row['debit'])),
            'credit' => accountingOpeningAmount(accountingOpeningSignedCents((string) $row['credit'])),
        ];
    }
    return $lines;
}

/** Preview is read-only; commit calls it again after locking the legal entity. */
function accountingOpeningReview(PDO $pdo, int $tenantId, int $entityId, string $csv): array
{
    if ($tenantId <= 0 || $entityId <= 0) throw new InvalidArgumentException('Choose a legal entity.');
    if (strlen($csv) > 1048576) throw new InvalidArgumentException('Opening balances CSV is limited to 1 MB.');
    accountingOpeningRegisterSchema();
    $entityStmt = $pdo->prepare(
        'SELECT id, code, legal_name, country, base_currency, accounting_basis, active
           FROM accounting_entities WHERE tenant_id = :t AND id = :e'
    );
    $entityStmt->execute(['t' => $tenantId, 'e' => $entityId]);
    $entity = $entityStmt->fetch(PDO::FETCH_ASSOC);
    if (!$entity || !(int) $entity['active']) throw new InvalidArgumentException('Choose an active legal entity in this workspace.');
    if ($entity['country'] !== 'US' || $entity['base_currency'] !== 'USD'
        || $entity['accounting_basis'] !== 'accrual') {
        throw new InvalidArgumentException('Opening balance import currently supports US/USD accrual entities only.');
    }
    $calendar = $pdo->prepare(
        'SELECT MIN(start_date) FROM accounting_fiscal_calendars
          WHERE tenant_id = :t AND entity_id = :e AND active = 1'
    );
    $calendar->execute(['t' => $tenantId, 'e' => $entityId]);
    $firstDay = $calendar->fetchColumn();
    if (!$firstDay) throw new InvalidArgumentException('Set up the first fiscal calendar before importing opening balances.');
    $cutoverDate = (new DateTimeImmutable((string) $firstDay))->modify('-1 day')->format('Y-m-d');

    $dry = CsvImportService::dryRun('accounting_opening_balances', $csv);
    $errors = $dry['errors'];
    if ($dry['row_count'] > 500) $errors[0][] = 'Use at most 500 accounts per opening import.';
    if ($dry['row_count'] === 0) $errors[0][] = 'Add at least one balance row.';
    $accounts = $pdo->prepare(
        'SELECT code, name, account_type, normal_side, currency, active, is_postable
           FROM accounting_accounts WHERE tenant_id = :t AND code = :code LIMIT 1'
    );
    $previewRows = [];
    $lines = [];
    $netDebitCents = 0;
    foreach ($dry['rows'] as $rowNumber => $row) {
        if (isset($errors[$rowNumber])) continue;
        $accounts->execute(['t' => $tenantId, 'code' => trim((string) $row['account_code'])]);
        $account = $accounts->fetch(PDO::FETCH_ASSOC);
        if (!$account || !(int) $account['active'] || !(int) $account['is_postable']) {
            $errors[$rowNumber][] = 'Choose an active, postable account in this workspace.';
            continue;
        }
        $code = (string) $account['code'];
        if (!in_array($account['account_type'], ['asset', 'liability', 'equity'], true)
            || in_array($code, ACCOUNTING_SOURCE_OWNED_CONTROL_CODES, true)) {
            $errors[$rowNumber][] = 'This account belongs to a source-document workflow or is not a balance-sheet account.';
            continue;
        }
        if ($code === '3000') {
            $errors[$rowNumber][] = 'Opening Balance Equity is calculated from the other balances; omit this row.';
            continue;
        }
        if ($account['currency'] && strcasecmp((string) $account['currency'], 'USD') !== 0) {
            $errors[$rowNumber][] = 'Account currency differs from the legal entity.';
            continue;
        }
        try {
            $balance = accountingOpeningSignedCents((string) $row['balance']);
        } catch (InvalidArgumentException $error) {
            $errors[$rowNumber][] = $error->getMessage();
            continue;
        }
        if ($balance === 0) continue;
        $debit = ($account['normal_side'] === 'debit' ? $balance : -$balance);
        $debitCents = max(0, $debit);
        $creditCents = max(0, -$debit);
        $netDebitCents += $debitCents - $creditCents;
        $previewRows[] = [
            'account_code' => $code, 'account_name' => $account['name'],
            'balance' => accountingOpeningAmount(abs($balance)), 'negative' => $balance < 0,
            'debit' => accountingOpeningAmount($debitCents),
            'credit' => accountingOpeningAmount($creditCents),
        ];
        $lines[] = ['account_code' => $code,
            'debit' => accountingOpeningAmount($debitCents),
            'credit' => accountingOpeningAmount($creditCents)];
    }
    if (!$previewRows) $errors[0][] = 'At least one nonzero balance is required.';
    $equityDebit = max(0, -$netDebitCents);
    $equityCredit = max(0, $netDebitCents);
    if ($netDebitCents !== 0) {
        $accounts->execute(['t' => $tenantId, 'code' => '3000']);
        $equity = $accounts->fetch(PDO::FETCH_ASSOC);
        if (!$equity || !(int) $equity['active'] || !(int) $equity['is_postable']
            || $equity['account_type'] !== 'equity' || $equity['normal_side'] !== 'credit'
            || ($equity['currency'] && strcasecmp((string) $equity['currency'], 'USD') !== 0)) {
            $errors[0][] = 'Opening Balance Equity account 3000 must be active, postable USD equity.';
        }
        $lines[] = ['account_code' => '3000',
            'debit' => accountingOpeningAmount($equityDebit),
            'credit' => accountingOpeningAmount($equityCredit)];
    }
    if (count($lines) < 2) $errors[0][] = 'Opening balances must form at least two journal lines.';
    $totalDebit = $totalCredit = 0;
    foreach ($lines as $line) {
        $totalDebit += accountingOpeningSignedCents($line['debit']);
        $totalCredit += accountingOpeningSignedCents($line['credit']);
    }
    if ($totalDebit !== $totalCredit || $totalDebit > 99999999999) {
        $errors[0][] = 'Opening journal must balance and total no more than $999,999,999.99.';
    }
    usort($lines, static fn(array $a, array $b): int => strcmp($a['account_code'], $b['account_code']));
    $token = hash('sha256', json_encode([$tenantId, $entityId, $cutoverDate, $lines], JSON_THROW_ON_ERROR));
    $prior = $pdo->prepare(
        'SELECT id, status, posting_date FROM accounting_journal_entries
          WHERE tenant_id = :t AND entity_id = :e AND source_ref_type = "opening_balance"
          ORDER BY id LIMIT 1'
    );
    $prior->execute(['t' => $tenantId, 'e' => $entityId]);
    $posted = $prior->fetch(PDO::FETCH_ASSOC);
    if ($posted) {
        if ($posted['status'] !== 'posted'
            || $posted['posting_date'] !== $cutoverDate
            || accountingOpeningStoredLines($pdo, $tenantId, (int) $posted['id']) !== $lines) {
            $errors[0][] = 'Opening balances already exist with different details or were reversed.';
        }
    } else {
        $journals = $pdo->prepare(
            'SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = :t AND entity_id = :e'
        );
        $journals->execute(['t' => $tenantId, 'e' => $entityId]);
        if ((int) $journals->fetchColumn() > 0) {
            $errors[0][] = 'Opening balances must be imported before other journals for this legal entity.';
        }
        $bankLines = $pdo->prepare(
            'SELECT COUNT(*) FROM accounting_bank_statement_lines l
               JOIN accounting_bank_accounts b ON b.id = l.bank_account_id AND b.tenant_id = l.tenant_id
              WHERE l.tenant_id = :t AND b.entity_id = :e'
        );
        $bankLines->execute(['t' => $tenantId, 'e' => $entityId]);
        if ((int) $bankLines->fetchColumn() > 0) {
            $errors[0][] = 'Import opening balances before bank statement lines for this legal entity.';
        }
    }
    $period = $pdo->prepare(
        'SELECT id, status FROM accounting_periods
          WHERE tenant_id = :t AND entity_id = :e
            AND start_date <= :d1 AND end_date >= :d2 LIMIT 1'
    );
    $period->execute(['t' => $tenantId, 'e' => $entityId, 'd1' => $cutoverDate, 'd2' => $cutoverDate]);
    $existingPeriod = $period->fetch(PDO::FETCH_ASSOC);
    if ($existingPeriod && !in_array($existingPeriod['status'], ['open', 'reopened'], true)) {
        $errors[0][] = 'The cutover period is closed; reopen it before importing opening balances.';
    }
    return [
        'entity_id' => $entityId, 'entity_name' => $entity['legal_name'],
        'first_fiscal_day' => $firstDay, 'posting_date' => $cutoverDate,
        'rows' => $previewRows, 'row_count' => $dry['row_count'],
        'balancing_equity' => [
            'account_code' => '3000',
            'debit' => accountingOpeningAmount($equityDebit),
            'credit' => accountingOpeningAmount($equityCredit),
        ],
        'total_debit' => accountingOpeningAmount($totalDebit),
        'total_credit' => accountingOpeningAmount($totalCredit),
        'errors' => $errors, 'error_count' => count($errors),
        'preview_token' => $errors ? null : $token,
        'already_posted' => $posted && !$errors,
        'journal_entry_id' => $posted && !$errors ? (int) $posted['id'] : null,
        'journal_lines' => $lines,
    ];
}

function accountingOpeningCommit(PDO $pdo, int $tenantId, int $entityId, string $csv,
    string $previewToken, ?int $actorUserId): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $previewToken)) {
        throw new InvalidArgumentException('Preview the opening balances before posting.');
    }
    $ownsTransaction = cf_tx_begin($pdo);
    try {
        $lock = $pdo->prepare(
            'SELECT id FROM accounting_entities WHERE tenant_id = :t AND id = :e FOR UPDATE'
        );
        $lock->execute(['t' => $tenantId, 'e' => $entityId]);
        if (!$lock->fetchColumn()) throw new InvalidArgumentException('Choose a legal entity in this workspace.');
        $review = accountingOpeningReview($pdo, $tenantId, $entityId, $csv);
        if ($review['error_count'] > 0) {
            throw new AccountingOpeningConflict('Opening balances cannot be posted: '
                . implode('; ', array_merge(...array_values($review['errors']))));
        }
        if (!hash_equals($review['preview_token'], $previewToken)) {
            throw new AccountingOpeningConflict('Opening balances changed since preview; review them again.');
        }
        if ($review['already_posted']) {
            cf_tx_commit($pdo, $ownsTransaction);
            return ['journal_entry_id' => $review['journal_entry_id'], 'idempotent_replay' => true];
        }
        $period = $pdo->prepare(
            'SELECT id FROM accounting_periods WHERE tenant_id = :t AND entity_id = :e
                AND start_date <= :d1 AND end_date >= :d2 LIMIT 1 FOR UPDATE'
        );
        $period->execute(['t' => $tenantId, 'e' => $entityId,
            'd1' => $review['posting_date'], 'd2' => $review['posting_date']]);
        if (!$period->fetchColumn()) {
            $pdo->prepare(
                'INSERT INTO accounting_periods
                   (tenant_id, entity_id, period_number, start_date, end_date, status)
                 VALUES (:t, :e, 0, :start_date, :end_date, "open")'
            )->execute(['t' => $tenantId, 'e' => $entityId,
                'start_date' => $review['posting_date'], 'end_date' => $review['posting_date']]);
        }
        $posted = accountingPostJe($tenantId, [
            'entity_id' => $entityId, 'posting_date' => $review['posting_date'],
            'currency' => 'USD', 'source_module' => 'system',
            'source_ref_type' => 'opening_balance', 'source_ref_id' => $entityId,
            'idempotency_key' => 'opening:balance:' . $tenantId . ':' . $entityId,
            'memo' => 'Opening balances before ' . $review['first_fiscal_day'],
            'lines' => $review['journal_lines'],
        ], $actorUserId, true);
        cf_tx_commit($pdo, $ownsTransaction);
        return ['journal_entry_id' => (int) $posted['je_id'], 'idempotent_replay' => false];
    } catch (Throwable $error) {
        cf_tx_rollback($pdo, $ownsTransaction);
        throw $error;
    }
}
