<?php
/**
 * Zoho Books outbound sync — cron driver (Slice 2: JE push).
 *
 * Cron entry (Cloudways):
 *   Every 15 minutes:
 *     H/15 * * * * php /home/master/applications/<app>/public_html/cron/zoho_books_sync_outbound.php
 *
 * Iterates every tenant with an active Zoho Books connection AND the
 * `journal_entries` direction set to push or two_way. Continues on
 * per-tenant failure; emits aggregate counts to stdout.
 *
 * Slice 2 scope: journal entries only. Slice 4 will add invoices,
 * bills, and payments — register them here as they ship.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/zoho_books/client.php';
require_once __DIR__ . '/../core/zoho_books/sync_je.php';
require_once __DIR__ . '/../core/zoho_books/sync_invoices.php';
require_once __DIR__ . '/../core/zoho_books/sync_bills.php';
require_once __DIR__ . '/../core/zoho_books/sync_payments.php';

$pdo = getDB();
try {
    $stmt = $pdo->query(
        "SELECT tenant_id
           FROM zoho_books_connections
          WHERE status = 'active'
            AND organization_id <> 'pending'
       ORDER BY tenant_id"
    );
    $tenants = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "Zoho outbound cron: migration 064 not applied yet — skipping. ({$e->getMessage()})\n");
    exit(0);
}
if (!$tenants) {
    fwrite(STDOUT, "Zoho outbound cron: no active connections, nothing to do.\n");
    exit(0);
}

$total = ['pushed' => 0, 'skipped' => 0, 'failed' => 0, 'tenants_ok' => 0, 'tenants_err' => 0];

// Slice 4 push workers — each gated on its own sync_config direction.
$WORKERS = [
    'journal_entries' => ['fn' => 'zohoBooksSyncJournalEntries', 'limit' => 50],
    'invoices'        => ['fn' => 'zohoBooksSyncInvoices',       'limit' => 50],
    'bills'           => ['fn' => 'zohoBooksSyncBills',          'limit' => 50],
    'payments'        => ['fn' => 'zohoBooksSyncVendorPayments', 'limit' => 50],
];

foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        $cfg = zohoBooksSyncConfigRead($tid);
        foreach ($WORKERS as $entity => $worker) {
            $dir = $cfg[$entity] ?? 'off';
            if (!in_array($dir, ['push', 'two_way'], true)) continue;
            $res = ($worker['fn'])($tid, null, ['limit' => $worker['limit']]);
            $total['pushed']  += (int) ($res['pushed']           ?? 0);
            $total['skipped'] += (int) (($res['skipped'] ?? $res['skipped_unmapped']) ?? 0);
            $total['failed']  += (int) ($res['failed']           ?? 0);
        }
        $total['tenants_ok']++;
    } catch (\Throwable $e) {
        $total['tenants_err']++;
        fwrite(STDERR, "Zoho outbound cron: tenant={$tid} push failed: {$e->getMessage()}\n");
    }
}

fwrite(
    STDOUT,
    sprintf(
        "Zoho outbound cron done: %d tenants ok / %d errored — JEs: %d pushed · %d skipped · %d failed\n",
        $total['tenants_ok'], $total['tenants_err'],
        $total['pushed'], $total['skipped'], $total['failed']
    )
);
exit(0);
