<?php
/** Business checks for the accounting CSV importer. Preview must not write periods. */
declare(strict_types=1);

require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/dimensions.php';
require_once __DIR__ . '/../../../core/accounting/control_accounts.php';

function accountingImportJeKey(int $tenantId, string $batchRef): string
{
    return 'csv:' . hash('sha256', $tenantId . ':' . $batchRef);
}

function accountingImportCents(mixed $value): int
{
    $raw = trim((string) $value);
    if ($raw === '') return 0;
    if (!preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/D', $raw, $m)) {
        throw new InvalidArgumentException('Use a non-negative amount with at most two decimal places.');
    }
    return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
}

/** @return array{errors:list<string>,journal:array<string,mixed>} */
function accountingImportReviewJe(int $tenantId, string $batchRef, array $journal,
    ?array $protectedCodes = null): array
{
    $errors = [];
    $rawEntityId = trim((string) ($journal['entity_id'] ?? ''));
    $entityId = ctype_digit($rawEntityId) ? (int) $rawEntityId : 0;
    if ($entityId <= 0) $errors[] = 'entity id must be a positive whole number';
    $date = (string) ($journal['posting_date'] ?? '');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        $errors[] = 'posting date must be a real YYYY-MM-DD date';
    }

    $pdo = getDB();
    $entity = $pdo->prepare(
        'SELECT base_currency FROM accounting_entities
          WHERE tenant_id = :t AND id = :e AND active = 1 LIMIT 1'
    );
    $entity->execute(['t' => $tenantId, 'e' => $entityId]);
    $currency = $entity->fetchColumn();
    if (!$currency) $errors[] = 'choose an active legal entity in this workspace';

    $journal['currency'] = $currency ?: null;
    $journal['source_module'] = 'manual';
    $journal['idempotency_key'] = accountingImportJeKey($tenantId, $batchRef);
    if ($currency && is_array($journal['lines'] ?? null)) {
        try {
            $journal['lines'] = accountingStampLegalEntityDimension($journal['lines'], $entityId);
            if (accountingImportAssertJeReplay($tenantId, $journal) && !$errors) {
                return ['errors' => [], 'journal' => $journal];
            }
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }

    if (!$errors) {
        $period = $pdo->prepare(
            'SELECT status FROM accounting_periods
              WHERE tenant_id = :t AND entity_id = :e
                AND start_date <= :date1 AND end_date >= :date2 LIMIT 1'
        );
        $period->execute(['t' => $tenantId, 'e' => $entityId, 'date1' => $date, 'date2' => $date]);
        $status = $period->fetchColumn();
        if ($status === false) $errors[] = 'create the accounting period before importing journals';
        elseif (!in_array($status, ['open', 'reopened'], true)) {
            $errors[] = 'the accounting period is ' . $status . '; open it through Periods before importing';
        }
    }

    $lines = $journal['lines'] ?? [];
    if (!is_array($lines) || count($lines) < 2 || count($lines) > 200) {
        $errors[] = 'a journal batch needs 2 to 200 lines';
        $lines = [];
    }
    $lookup = $pdo->prepare(
        'SELECT a.id, a.active, a.is_postable, a.currency,
                ba.id AS bank_account_id
           FROM accounting_accounts a
           LEFT JOIN accounting_bank_accounts ba
             ON ba.tenant_id = a.tenant_id AND ba.gl_account_code = a.code
          WHERE a.tenant_id = :t AND a.code = :code LIMIT 1'
    );
    $protectedCodes = array_fill_keys(
        $protectedCodes ?? accountingSourceOwnedControlCodes($tenantId, $pdo), true
    );
    $debitCents = $creditCents = 0;
    $dimensionLines = [];
    foreach ($lines as $index => $line) {
        $code = trim((string) ($line['account_code'] ?? ''));
        $lookup->execute(['t' => $tenantId, 'code' => $code]);
        $account = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$account || !(int) $account['active'] || !(int) $account['is_postable']) {
            $errors[] = 'line ' . ($index + 1) . ': choose an active, postable account';
            continue;
        }
        if (isset($protectedCodes[$code])
            || $account['bank_account_id'] !== null) {
            $errors[] = 'line ' . ($index + 1)
                . ': this account belongs to an invoice, bill, payroll, tax, or bank workflow';
        }
        if ($currency && !empty($account['currency'])
            && strcasecmp((string) $account['currency'], (string) $currency) !== 0) {
            $errors[] = 'line ' . ($index + 1) . ': account currency differs from the legal entity';
        }
        try {
            $debit = accountingImportCents($line['debit'] ?? '');
            $credit = accountingImportCents($line['credit'] ?? '');
            if (($debit > 0) === ($credit > 0)) {
                $errors[] = 'line ' . ($index + 1) . ': enter exactly one positive debit or credit';
            }
            $debitCents += $debit;
            $creditCents += $credit;
        } catch (InvalidArgumentException $error) {
            $errors[] = 'line ' . ($index + 1) . ': ' . $error->getMessage();
        }
        $dimensionLines[] = ['account_id' => (int) $account['id'],
            'dims' => (array) ($line['dims'] ?? [])];
    }
    if ($debitCents === 0 || $debitCents !== $creditCents) {
        $errors[] = 'journal debits and credits must balance to the cent';
    }
    if ($entityId > 0 && !$errors) {
        try {
            $lines = accountingStampLegalEntityDimension($lines, $entityId);
            foreach ($dimensionLines as $index => &$dimensionLine) {
                $dimensionLine['dims'] = (array) ($lines[$index]['dims'] ?? []);
            }
            unset($dimensionLine);
            accountingValidateJeDims($tenantId, $dimensionLines);
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }
    $journal['lines'] = $lines;
    return ['errors' => array_values(array_unique($errors)), 'journal' => $journal];
}

function accountingImportCanonicalDims(array $dims): string
{
    ksort($dims);
    return json_encode($dims, JSON_THROW_ON_ERROR);
}

/** Reject a changed file under an already-used batch reference. */
function accountingImportAssertJeReplay(int $tenantId, array $journal): bool
{
    $pdo = getDB();
    $prior = $pdo->prepare(
        'SELECT je.* FROM accounting_posting_idempotency i
           JOIN accounting_journal_entries je ON je.id = i.je_id AND je.tenant_id = i.tenant_id
          WHERE i.tenant_id = :t AND i.idempotency_key = :key LIMIT 1'
    );
    $prior->execute(['t' => $tenantId, 'key' => $journal['idempotency_key']]);
    $entry = $prior->fetch(PDO::FETCH_ASSOC);
    if (!$entry) return false;
    if ($entry['status'] !== 'posted'
        || $entry['source_module'] !== 'manual'
        || (int) $entry['entity_id'] !== (int) $journal['entity_id']
        || (string) $entry['posting_date'] !== (string) $journal['posting_date']
        || (string) $entry['currency'] !== (string) $journal['currency']
        || (string) $entry['memo'] !== (string) ($journal['memo'] ?? '')) {
        throw new RuntimeException('Batch reference was already used for a different or no-longer-posted journal.');
    }
    $query = $pdo->prepare(
        'SELECT a.code, l.debit, l.credit, l.memo, l.dim_json
           FROM accounting_journal_entry_lines l
           JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
          WHERE l.tenant_id = :t AND l.je_id = :id ORDER BY l.line_no'
    );
    $query->execute(['t' => $tenantId, 'id' => (int) $entry['id']]);
    $actual = $query->fetchAll(PDO::FETCH_ASSOC);
    $expected = $journal['lines'];
    if (count($actual) !== count($expected)) {
        throw new RuntimeException('Batch reference was already used for different journal lines.');
    }
    foreach ($actual as $index => $line) {
        $wanted = $expected[$index];
        $storedDims = json_decode((string) ($line['dim_json'] ?? ''), true);
        if ((string) $line['code'] !== trim((string) $wanted['account_code'])
            || accountingImportCents($line['debit']) !== accountingImportCents($wanted['debit'] ?? '')
            || accountingImportCents($line['credit']) !== accountingImportCents($wanted['credit'] ?? '')
            || (string) $line['memo'] !== (string) ($wanted['memo'] ?? '')
            || accountingImportCanonicalDims(is_array($storedDims) ? $storedDims : [])
                !== accountingImportCanonicalDims((array) ($wanted['dims'] ?? []))) {
            throw new RuntimeException('Batch reference was already used for different journal lines.');
        }
    }
    return true;
}

/** Existing periods may be replayed exactly; lifecycle changes use Periods. */
function accountingImportPeriodError(int $tenantId, array $row, bool $lockCurrent = false): ?string
{
    $rawEntityId = trim((string) ($row['entity_id'] ?? ''));
    if (!ctype_digit($rawEntityId) || (int) $rawEntityId <= 0) {
        return 'Entity id must be a positive whole number.';
    }
    $entityId = (int) $rawEntityId;
    $start = (string) ($row['start_date'] ?? '');
    $end = (string) ($row['end_date'] ?? '');
    foreach ([$start, $end] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) return 'Use real YYYY-MM-DD start and end dates.';
    }
    if ($start > $end) return 'Start date must be on or before end date.';
    $periodNumber = trim((string) ($row['period_number'] ?? ''));
    if (!ctype_digit($periodNumber) || (int) $periodNumber < 1 || (int) $periodNumber > 53) {
        return 'Period number must be 1 to 53.';
    }
    if (!in_array($row['status'] ?? '', ['open', 'future'], true)) {
        return 'CSV can create only open or future periods; use Periods for closing and reopening.';
    }
    $pdo = getDB();
    $entity = $pdo->prepare(
        'SELECT id FROM accounting_entities WHERE tenant_id = :t AND id = :e AND active = 1 LIMIT 1'
    );
    $entity->execute(['t' => $tenantId, 'e' => $entityId]);
    if (!$entity->fetchColumn()) return 'Choose an active legal entity in this workspace.';
    $overlapSql =
        'SELECT period_number, start_date, end_date, status FROM accounting_periods
          WHERE tenant_id = :t AND entity_id = :e
            AND start_date <= :end_date AND end_date >= :start_date LIMIT 1';
    $overlap = $pdo->prepare($overlapSql . ($lockCurrent ? ' FOR UPDATE' : ''));
    $overlap->execute(['t' => $tenantId, 'e' => $entityId,
        'end_date' => $end, 'start_date' => $start]);
    $prior = $overlap->fetch(PDO::FETCH_ASSOC);
    if (!$prior) return null;
    if ((int) $prior['period_number'] === (int) $row['period_number']
        && (string) $prior['start_date'] === $start
        && (string) $prior['end_date'] === $end
        && (string) $prior['status'] === (string) $row['status']) return null;
    return 'This period overlaps an existing period or changes its status; use Periods for corrections.';
}

/** @return array<int,list<string>> */
function accountingImportPeriodErrors(int $tenantId, array $rows, array $errors): array
{
    $seen = [];
    foreach ($rows as $rowNum => $row) {
        if (isset($errors[$rowNum])) continue;
        $message = accountingImportPeriodError($tenantId, $row);
        if ($message !== null) $errors[$rowNum][] = $message;
        $entityId = (int) $row['entity_id'];
        $start = (string) $row['start_date'];
        $end = (string) $row['end_date'];
        foreach ($seen[$entityId] ?? [] as $prior) {
            if ($start <= $prior['end'] && $end >= $prior['start']) {
                $errors[$rowNum][] = 'Overlaps row ' . $prior['row'] . ' in this file.';
            }
        }
        $seen[$entityId][] = ['start' => $start, 'end' => $end, 'row' => $rowNum];
    }
    return $errors;
}
