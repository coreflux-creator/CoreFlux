<?php
declare(strict_types=1);

require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/../../billing/lib/billing.php';
require_once __DIR__ . '/../../ap/lib/ap.php';

/** Convert a database DECIMAL to cents without floating-point summation. */
function accountingControlCents($amount): int
{
    $value = trim((string) $amount);
    if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
        throw new \UnexpectedValueException('Invalid accounting amount.');
    }
    $negative = $value[0] === '-';
    if ($negative) $value = substr($value, 1);
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    return $negative ? -$cents : $cents;
}

/**
 * Read-only AR/AP control totals for one entity and date. A match is a
 * balance check, not proof that every individual source document is correct.
 */
function accountingSourceControlTieOut(int $tenantId, int $entityId, string $asOf): array
{
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
    if (!$date || $date->format('Y-m-d') !== $asOf) {
        throw new \InvalidArgumentException('Choose a valid as-of date.');
    }
    if (accountingValidateActiveEntityId($tenantId, $entityId) !== $entityId) {
        throw new \InvalidArgumentException('Choose an active legal entity.');
    }

    $pdo = getDB();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
    }
    try {
        $entityStmt = $pdo->prepare('SELECT code, legal_name AS name, base_currency FROM accounting_entities
            WHERE tenant_id = :tenant_id AND id = :entity_id AND active = 1');
        $entityStmt->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId]);
        $entity = $entityStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$entity) throw new \InvalidArgumentException('Choose an active legal entity.');

        $controls = [
            'ar' => ['account_code' => '1100', 'label' => 'Accounts receivable', 'source_cents' => 0,
                'gl_cents' => 0, 'sources' => []],
            'ap' => ['account_code' => '2000', 'label' => 'Accounts payable', 'source_cents' => 0,
                'gl_cents' => 0, 'sources' => []],
        ];
        foreach (billingComputeAging($tenantId, $asOf, $entityId) as $row) {
            $controls['ar']['source_cents'] += accountingControlCents($row['total_due']);
        }
        foreach (apComputeAging($tenantId, $asOf, $entityId) as $row) {
            $controls['ap']['source_cents'] += accountingControlCents($row['total_due']);
        }

        $ledger = $pdo->prepare('SELECT a.code, a.normal_side, je.source_module,
                COUNT(DISTINCT je.id) AS journal_count,
                COALESCE(SUM(l.debit), 0) AS debits,
                COALESCE(SUM(l.credit), 0) AS credits
            FROM accounting_journal_entry_lines l
            JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
            JOIN accounting_journal_entries je ON je.id = l.je_id AND je.tenant_id = l.tenant_id
            WHERE je.tenant_id = :tenant_id AND je.entity_id = :entity_id
              AND je.status IN ("posted", "reversed") AND je.posting_date <= :as_of
              AND a.code IN ("1100", "2000")
            GROUP BY a.code, a.normal_side, je.source_module
            ORDER BY a.code, je.source_module');
        $ledger->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId, 'as_of' => $asOf]);
        while ($row = $ledger->fetch(\PDO::FETCH_ASSOC)) {
            $key = $row['code'] === '1100' ? 'ar' : 'ap';
            $debits = accountingControlCents($row['debits']);
            $credits = accountingControlCents($row['credits']);
            $net = $row['normal_side'] === 'debit' ? $debits - $credits : $credits - $debits;
            $controls[$key]['gl_cents'] += $net;
            $controls[$key]['sources'][] = [
                'module' => (string) $row['source_module'],
                'journal_count' => (int) $row['journal_count'],
                'net' => $net / 100,
            ];
        }

        // An unconverted foreign-currency source amount must not look like a clean tie-out.
        [$invoiceSql, $invoiceBind] = billingOpenInvoiceAsOfSource($tenantId, $asOf, $entityId);
        $foreignInvoice = $pdo->prepare('SELECT DISTINCT aged.currency FROM (' . $invoiceSql . ') aged
            WHERE aged.currency <> :base_currency');
        $foreignInvoice->execute($invoiceBind + ['base_currency' => $entity['base_currency']]);
        $foreignBill = $pdo->prepare('SELECT DISTINCT b.currency FROM ap_bills b
            JOIN accounting_journal_entries je ON je.id = b.journal_entry_id AND je.tenant_id = b.tenant_id
            WHERE b.tenant_id = :tenant_id AND je.entity_id = :entity_id
              AND je.status IN ("posted", "reversed") AND je.posting_date <= :as_of
              AND b.bill_date <= :bill_as_of AND b.currency <> :base_currency');
        $foreignBill->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId,
            'as_of' => $asOf, 'bill_as_of' => $asOf, 'base_currency' => $entity['base_currency']]);
        $foreignCurrencies = array_values(array_unique(array_merge(
            $foreignInvoice->fetchAll(\PDO::FETCH_COLUMN), $foreignBill->fetchAll(\PDO::FETCH_COLUMN)
        )));
        sort($foreignCurrencies);

        foreach ($controls as &$control) {
            $control['source_due'] = $control['source_cents'] / 100;
            $control['gl_balance'] = $control['gl_cents'] / 100;
            $control['difference'] = ($control['gl_cents'] - $control['source_cents']) / 100;
            $control['matched'] = $control['gl_cents'] === $control['source_cents'];
            unset($control['source_cents'], $control['gl_cents']);
        }
        unset($control);

        $hasActivity = $controls['ar']['source_due'] != 0 || $controls['ap']['source_due'] != 0
            || $controls['ar']['sources'] || $controls['ap']['sources'];
        $result = [
            'entity_id' => $entityId,
            'entity_code' => $entity['code'],
            'entity_name' => $entity['name'],
            'base_currency' => $entity['base_currency'],
            'as_of' => $asOf,
            'controls' => $controls,
            'foreign_currencies' => $foreignCurrencies,
            'has_activity' => (bool) $hasActivity,
            'matched' => $hasActivity && $controls['ar']['matched'] && $controls['ap']['matched']
                && !$foreignCurrencies,
        ];
        if ($ownsTransaction) $pdo->commit();
        return $result;
    } catch (\Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
