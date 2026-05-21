<?php
/**
 * Zoho Books inbound sync — cron driver (Slice 3: CoA + Contacts pull).
 *
 * Cron entry (Cloudways):
 *   Every 30 minutes:
 *     H/30 * * * * php /home/master/applications/<app>/public_html/cron/zoho_books_sync_inbound.php
 *
 * Iterates every active Zoho Books connection. For each tenant, runs:
 *   1. Chart of Accounts pull (if direction is pull/two_way)
 *   2. Customers pull         (if contacts direction is pull/two_way)
 *   3. Vendors pull           (if contacts direction is pull/two_way)
 *
 * Continues on per-tenant failure; emits aggregate counts to stdout.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/zoho_books/client.php';
require_once __DIR__ . '/../core/zoho_books/sync_accounts.php';
require_once __DIR__ . '/../core/zoho_books/sync_contacts.php';

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
    fwrite(STDERR, "Zoho inbound cron: migration 064 not applied yet — skipping. ({$e->getMessage()})\n");
    exit(0);
}
if (!$tenants) {
    fwrite(STDOUT, "Zoho inbound cron: no active connections, nothing to do.\n");
    exit(0);
}

$total = [
    'accounts_matched' => 0, 'accounts_unmapped' => 0,
    'customers_created' => 0, 'customers_updated' => 0,
    'vendors_created'   => 0, 'vendors_updated'   => 0,
    'tenants_ok' => 0, 'tenants_err' => 0,
];

foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        $cfg = zohoBooksSyncConfigRead($tid);
        $coaDir      = $cfg['chart_of_accounts'] ?? 'off';
        $contactsDir = $cfg['contacts']          ?? 'off';

        if (in_array($coaDir, ['pull', 'two_way'], true)) {
            $r = zohoBooksSyncChartOfAccounts($tid, null);
            $total['accounts_matched']  += (int) ($r['matched']  ?? 0);
            $total['accounts_unmapped'] += (int) ($r['unmapped'] ?? 0);
        }
        if (in_array($contactsDir, ['pull', 'two_way'], true)) {
            $rc = zohoBooksSyncContactsCustomers($tid, null);
            $total['customers_created'] += (int) ($rc['created'] ?? 0);
            $total['customers_updated'] += (int) ($rc['updated'] ?? 0);
            $rv = zohoBooksSyncContactsVendors($tid, null);
            $total['vendors_created'] += (int) ($rv['created'] ?? 0);
            $total['vendors_updated'] += (int) ($rv['updated'] ?? 0);
        }
        $total['tenants_ok']++;
    } catch (\Throwable $e) {
        $total['tenants_err']++;
        fwrite(STDERR, "Zoho inbound cron: tenant={$tid} failed: {$e->getMessage()}\n");
    }
}

fwrite(
    STDOUT,
    sprintf(
        "Zoho inbound cron done: %d tenants ok / %d errored — accounts: %d matched, %d unmapped · customers: %d created / %d updated · vendors: %d created / %d updated\n",
        $total['tenants_ok'], $total['tenants_err'],
        $total['accounts_matched'], $total['accounts_unmapped'],
        $total['customers_created'], $total['customers_updated'],
        $total['vendors_created'], $total['vendors_updated']
    )
);
exit(0);
