<?php
declare(strict_types=1);

function booksHealthResolveEntity(PDO $pdo, int $tenantId, mixed $requested): ?int
{
    if ($requested === null) return null;
    if (!is_scalar($requested) || !preg_match('/^[1-9][0-9]*$/D', (string) $requested)) {
        throw new InvalidArgumentException('Select a valid legal entity.');
    }
    $entityId = (int) $requested;
    $query = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :t AND id = :e');
    $query->execute(['t' => $tenantId, 'e' => $entityId]);
    if (!$query->fetchColumn()) throw new OutOfBoundsException('Legal entity not found.');
    return $entityId;
}

function booksHealthLastReconciledDate(PDO $pdo, int $tenantId, ?int $entityId): ?string
{
    if (!$pdo->query("SHOW TABLES LIKE 'accounting_reconciliations'")->fetchColumn()) return null;
    $query = $pdo->prepare(
        'SELECT MAX(COALESCE(r.statement_end_date, r.period_end))
           FROM accounting_reconciliations r
           JOIN accounting_bank_accounts ba ON ba.id = r.bank_account_id AND ba.tenant_id = r.tenant_id
          WHERE r.tenant_id = :t AND r.status = "closed"'
        . ($entityId === null ? '' : ' AND ba.entity_id = :e')
    );
    $query->execute($entityId === null ? ['t' => $tenantId] : ['t' => $tenantId, 'e' => $entityId]);
    return $query->fetchColumn() ?: null;
}

function booksHealthFinancialTasks(PDO $pdo, int $tenantId, ?int $entityId): array
{
    $counts = ['bills_pending' => 0, 'payments_pending' => 0, 'transfers_pending' => 0];
    if ($pdo->query("SHOW TABLES LIKE 'ap_bills'")->fetchColumn()) {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM ap_bills WHERE tenant_id = :t
               AND status IN ("inbox", "pending_review", "pending_approval", "approved", "partially_paid", "disputed")'
            . ($entityId === null ? '' : ' AND entity_id = :e')
        );
        $query->execute($entityId === null ? ['t' => $tenantId] : ['t' => $tenantId, 'e' => $entityId]);
        $counts['bills_pending'] = (int) $query->fetchColumn();
    }
    if ($pdo->query("SHOW TABLES LIKE 'treasury_payments'")->fetchColumn()) {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM treasury_payments WHERE tenant_id = :t
               AND status IN ("draft", "pending_approval", "approved", "scheduled")'
            . ($entityId === null ? '' : ' AND entity_id = :e')
        );
        $query->execute($entityId === null ? ['t' => $tenantId] : ['t' => $tenantId, 'e' => $entityId]);
        $counts['payments_pending'] = (int) $query->fetchColumn();
    }
    if ($pdo->query("SHOW TABLES LIKE 'treasury_transfers'")->fetchColumn()) {
        $query = $pdo->prepare(
            'SELECT COUNT(*) FROM treasury_transfers WHERE tenant_id = :t
               AND status IN ("draft", "pending_approval", "approved", "scheduled")'
            . ($entityId === null ? '' : ' AND (source_entity_id = :source_e OR destination_entity_id = :destination_e)')
        );
        $params = ['t' => $tenantId];
        if ($entityId !== null) {
            $params['source_e'] = $entityId;
            $params['destination_e'] = $entityId;
        }
        $query->execute($params);
        $counts['transfers_pending'] = (int) $query->fetchColumn();
    }
    return $counts;
}

function booksHealthMonthlyPl(PDO $pdo, int $tenantId, ?int $entityId, string $asOf): array
{
    $month = (new DateTimeImmutable($asOf))->modify('first day of this month');
    $start = $month->modify('-5 months')->format('Y-m-d');
    $query = $pdo->prepare(
        'SELECT DATE_FORMAT(je.posting_date, "%Y-%m") AS month, a.account_type,
                SUM(jl.credit - jl.debit) AS net
           FROM accounting_journal_entry_lines jl
           JOIN accounting_journal_entries je ON je.id = jl.je_id
           JOIN accounting_accounts a ON a.id = jl.account_id
          WHERE je.tenant_id = :t AND je.status IN ("posted", "reversed")
            AND je.posting_date >= :start AND je.posting_date <= :as_of
            AND a.account_type IN ("revenue", "expense", "contra_revenue", "cost_of_goods_sold", "other_income", "other_expense")'
        . ($entityId === null ? '' : ' AND je.entity_id = :e') . '
          GROUP BY month, a.account_type ORDER BY month ASC'
    );
    $params = ['t' => $tenantId, 'start' => $start, 'as_of' => $asOf];
    if ($entityId !== null) $params['e'] = $entityId;
    $query->execute($params);
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = $month->modify("-{$i} months")->format('Y-m');
        $months[$key] = ['month' => $key, 'revenue' => 0.0, 'expense' => 0.0, 'net' => 0.0];
    }
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!isset($months[$row['month']])) continue;
        $net = (float) $row['net'];
        if (in_array($row['account_type'], ['revenue', 'other_income'], true)) {
            $months[$row['month']]['revenue'] += $net;
        } else {
            $months[$row['month']]['expense'] -= $net;
        }
    }
    foreach ($months as &$row) {
        $row['revenue'] = round($row['revenue'], 2);
        $row['expense'] = round($row['expense'], 2);
        $row['net'] = round($row['revenue'] - $row['expense'], 2);
    }
    unset($row);
    return array_values($months);
}

function booksHealthRecentEvents(PDO $pdo, int $tenantId, ?int $entityId): array
{
    if (!$pdo->query("SHOW TABLES LIKE 'accounting_events'")->fetchColumn()) return [];
    $query = $pdo->prepare(
        'SELECT id, event_type, journal_entry_id, source_module, source_record_id, posted_at
           FROM accounting_events WHERE tenant_id = :t AND status = "posted"'
        . ($entityId === null ? '' : ' AND entity_id = :e') . '
          ORDER BY posted_at DESC, id DESC LIMIT 10'
    );
    $query->execute($entityId === null ? ['t' => $tenantId] : ['t' => $tenantId, 'e' => $entityId]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
