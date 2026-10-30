<?php
declare(strict_types=1);

/** Legacy installs have no applications until migration 044 is installed. */
function accountingCreditSchemaReady(PDO $pdo): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return (bool) $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='accounting_credit_applications'")->fetchColumn();
    }
    return (bool) $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE()
        AND table_name = 'accounting_credit_applications'")->fetchColumn();
}

/** Signed settlements, independent of cash and of current document status. */
function accountingCreditAsOfSql(PDO $pdo, string $side, string $alias, string $asOf): string
{
    if (!in_array([$side, $alias], [['ar','i'], ['ap','b']], true)) throw new InvalidArgumentException('Invalid source.');
    if (!accountingCreditSchemaReady($pdo)) return '0';
    $date = $pdo->quote($asOf);
    return "COALESCE((SELECT SUM(CASE WHEN ca.document_id = $alias.id THEN ca.amount ELSE -ca.amount END)
        FROM accounting_credit_applications ca WHERE ca.tenant_id = $alias.tenant_id AND ca.side = '$side'
        AND (ca.document_id = $alias.id OR ca.credit_id = $alias.id)
        AND ca.application_date <= $date AND (ca.reversal_date IS NULL OR ca.reversal_date > $date)), 0)";
}

function accountingCreditAssertJournalMutable(PDO $pdo, int $tenantId, int $jeId, bool $deleting = false): void
{
    if (!accountingCreditSchemaReady($pdo)) return;
    $q = $pdo->prepare('SELECT id FROM accounting_credit_applications WHERE tenant_id = :t
        AND (credit_je_id = :c OR document_je_id = :d)'
        . ($deleting ? '' : ' AND reversal_date IS NULL') . ' LIMIT 1');
    $q->execute(['t' => $tenantId, 'c' => $jeId, 'd' => $jeId]);
    if ($q->fetchColumn()) throw new RuntimeException($deleting
        ? 'This journal has credit application history. Use a dated source correction.'
        : 'Reverse the active credit applications before correcting this source journal.');
}
