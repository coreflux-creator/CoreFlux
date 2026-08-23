<?php
/**
 * Direction-aware, operator-triggered QuickBooks sync runner.
 *
 * This is the canonical implementation behind every "Sync now" button.
 * It deliberately reuses the same entity workers as the scheduled jobs so
 * manual and automatic runs cannot drift into different behavior.
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/sync_je.php';
require_once __DIR__ . '/sync_in.php';
require_once __DIR__ . '/sync_accounts.php';
require_once __DIR__ . '/sync_items.php';
require_once __DIR__ . '/sync_bills.php';
require_once __DIR__ . '/sync_invoices.php';
require_once __DIR__ . '/sync_payments.php';
require_once __DIR__ . '/sync_in_arap.php';
require_once __DIR__ . '/sync_lock.php';

/** @return array<string,array<int,string>> */
function qboSyncNowCapabilities(): array
{
    return [
        'journal_entries'   => ['push', 'two_way'],
        'customers'         => ['pull', 'two_way'],
        'vendors'           => ['pull', 'two_way'],
        'invoices'          => ['push', 'pull', 'two_way'],
        'bills'             => ['push', 'pull', 'two_way'],
        'payments'          => ['push', 'pull', 'two_way'],
        'chart_of_accounts' => ['pull', 'two_way'],
    ];
}

/**
 * Run one configured QBO workflow immediately.
 *
 * @return array{
 *   ok:bool,entity:string,direction:string,runs:array<string,array>,
 *   summary:array<string,int>,latency_ms:int
 * }
 */
function qboSyncNowEntity(int $tenantId, string $entity, ?int $userId = null, array $opts = []): array
{
    $started = microtime(true);
    $capabilities = qboSyncNowCapabilities();
    if (!isset($capabilities[$entity])) {
        throw new \InvalidArgumentException('Unknown QuickBooks sync workflow: ' . $entity);
    }

    $connection = qboConnection($tenantId);
    if (!$connection || ($connection['status'] ?? '') !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }

    $config = qboSyncConfigRead($tenantId);
    $direction = (string) ($config[$entity] ?? 'off');
    if ($direction === 'off') {
        throw new \RuntimeException('Enable a sync direction for ' . str_replace('_', ' ', $entity) . ' first');
    }
    if (!in_array($direction, $capabilities[$entity], true)) {
        throw new \RuntimeException(
            ucfirst(str_replace('_', ' ', $entity)) . ' does not have a QuickBooks worker for direction ' . $direction
        );
    }

    $pushOpts = [
        'limit' => max(1, min(500, (int) ($opts['push_limit'] ?? $opts['limit'] ?? 50))),
    ];
    if (array_key_exists('dry_run', $opts)) {
        $pushOpts['dry_run'] = (bool) $opts['dry_run'];
    }
    $pullOpts = [
        'limit'     => max(1, min(5000, (int) ($opts['pull_limit'] ?? $opts['limit'] ?? 1000))),
        'max_pages' => max(1, min(50, (int) ($opts['max_pages'] ?? 20))),
    ];
    if (!empty($opts['modified_since'])) {
        $pullOpts['modified_since'] = (string) $opts['modified_since'];
    }

    $runs = [];
    $wantsPull = in_array($direction, ['pull', 'two_way'], true);
    $wantsPush = in_array($direction, ['push', 'two_way'], true);

    $lockName = qboSyncLockAcquire($tenantId, $entity);
    try {
        switch ($entity) {
            case 'journal_entries':
                if ($wantsPush) $runs['push_journal_entries'] = qboSyncJournalEntries($tenantId, $userId, $pushOpts);
                break;

            case 'customers':
                if ($wantsPull) $runs['pull_customers'] = qboSyncCustomers($tenantId, $userId, $pullOpts);
                break;

            case 'vendors':
                if ($wantsPull) $runs['pull_vendors'] = qboSyncVendors($tenantId, $userId, $pullOpts);
                break;

            case 'chart_of_accounts':
                if ($wantsPull) $runs['pull_chart_of_accounts'] = qboSyncAccounts($tenantId, $userId, $pullOpts);
                break;

            case 'invoices':
                if ($wantsPull) $runs['pull_invoices'] = qboPullInvoices($tenantId, $pullOpts);
                if ($wantsPush) {
                    // QBO requires an ItemRef on invoice lines. Refresh the Item
                    // mirror immediately before pushing so this button is
                    // self-contained for a newly connected company.
                    $runs['pull_items'] = qboSyncItems($tenantId, $userId, $pullOpts);
                    $runs['push_invoices'] = qboSyncInvoices($tenantId, $userId, $pushOpts);
                }
                break;

            case 'bills':
                if ($wantsPull) $runs['pull_bills'] = qboPullBills($tenantId, $pullOpts);
                if ($wantsPush) $runs['push_bills'] = qboSyncBills($tenantId, $userId, $pushOpts);
                break;

            case 'payments':
                if ($wantsPull) {
                    // Payments span both AR and AP in QBO; Deposits are included
                    // because they carry the settlement linkage for AR payments.
                    $runs['pull_payments'] = qboPullPayments($tenantId, $pullOpts);
                    $runs['pull_deposits'] = qboPullDeposits($tenantId, $pullOpts);
                    $runs['pull_bill_payments'] = qboPullBillPayments($tenantId, $pullOpts);
                }
                if ($wantsPush) $runs['push_bill_payments'] = qboSyncBillPayments($tenantId, $userId, $pushOpts);
                break;
        }
    } finally {
        qboSyncLockRelease($lockName);
    }

    $summary = [
        'pulled' => 0, 'pushed' => 0, 'created' => 0, 'updated' => 0,
        'matched' => 0, 'unchanged' => 0, 'skipped' => 0,
        'failed' => 0, 'drift_rows_written' => 0,
    ];
    foreach ($runs as $run) {
        foreach (array_keys($summary) as $key) {
            if (isset($run[$key]) && is_numeric($run[$key])) {
                $summary[$key] += (int) $run[$key];
            }
        }
        // The JE worker calls this count `skipped_unmapped`.
        if (isset($run['skipped_unmapped'])) {
            $summary['skipped'] += (int) $run['skipped_unmapped'];
        }
        if (isset($run['import_errors']) && is_array($run['import_errors'])) {
            $summary['failed'] += count($run['import_errors']);
        }
    }

    return [
        'ok'         => $summary['failed'] === 0,
        'entity'     => $entity,
        'direction'  => $direction,
        'runs'       => $runs,
        'summary'    => $summary,
        'latency_ms' => (int) round((microtime(true) - $started) * 1000),
    ];
}
