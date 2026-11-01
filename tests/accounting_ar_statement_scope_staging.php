<?php
/** Read-only AR aging/statement parity check on the isolated simulation tenant. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--check') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --check.\n");
    exit(2);
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/billing/lib/statement.php';

$pdo = getDB();
if (realpath(__DIR__ . '/..') !== '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html'
    || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id = 999')->fetchColumn() !== 1) {
    throw new RuntimeException('Refusing a non-staging application, database, or tenant.');
}

$entities = $pdo->query('SELECT id FROM accounting_entities WHERE tenant_id = 999 ORDER BY id')
    ->fetchAll(PDO::FETCH_COLUMN);
if (count($entities) < 2) throw new RuntimeException('Expected multiple synthetic legal entities.');

$checks = 0;
$clients = 0;
$invoiceIds = [];
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};

foreach ([date('Y-m-d'), '2026-09-15'] as $asOf) {
    foreach ($entities as $rawId) {
        $entityId = (int) $rawId;
        $entity = billingStatementEntity(999, $entityId);
        $assert((int) $entity['id'] === $entityId, 'statement entity identity');
        foreach (billingComputeAging(999, $asOf, $entityId) as $row) {
            $client = (string) $row['client_name'];
            $invoices = billingStatementOpenInvoices(999, $client, $asOf, $entityId);
            $assert(count($invoices) > 0, 'aging client has statement invoices');
            $buckets = billingStatementBucket($invoices);
            foreach (['current' => 'bucket_current', '1_30' => 'bucket_1_30',
                '31_60' => 'bucket_31_60', '61_90' => 'bucket_61_90',
                '91_plus' => 'bucket_91_plus', 'total' => 'total_due'] as $bucket => $column) {
                $assert(abs($buckets[$bucket] - (float) $row[$column]) <= 0.01,
                    'statement agrees with AR aging for ' . $bucket);
            }
            foreach ($invoices as $invoice) {
                $query = $pdo->prepare('SELECT je.entity_id FROM billing_invoices i
                    JOIN accounting_journal_entries je ON je.id = i.journal_entry_id
                    WHERE i.tenant_id = 999 AND i.id = :id');
                $query->execute(['id' => $invoice['id']]);
                $assert((int) $query->fetchColumn() === $entityId, 'statement invoice belongs to entity');
                if ($asOf === date('Y-m-d')) {
                    $assert(!isset($invoiceIds[$invoice['id']]), 'invoice occurs in one entity only');
                    $invoiceIds[$invoice['id']] = true;
                }
            }
            $clients++;
        }
    }
}
$assert($clients > 0, 'synthetic AR clients exercised');
foreach ([null, 'all', '999999'] as $invalid) {
    try {
        billingStatementEntity(999, $invalid);
        throw new RuntimeException('Unscoped or invalid statement entity was accepted.');
    } catch (InvalidArgumentException | OutOfBoundsException $expected) {
        $checks++;
    }
}
echo "AR statement scope: {$checks} checks passed across {$clients} client snapshots.\n";
