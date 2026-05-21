<?php
/**
 * /api/admin/accounting_sync_reconcile.php — fire a sync on each
 * accounting system for a specific entity, from inside the unified
 * dashboard's drift table. Returns per-system results so the UI can
 * surface partial success.
 *
 *   POST body: { entity_key: "journal_entries" | "customers" | ... }
 *
 *   Response:
 *   {
 *     entity_key: "...",
 *     qbo: {
 *       attempted: true|false,
 *       reason: "...",           // when attempted=false
 *       result: { ... }           // adapter return shape (only when attempted=true)
 *     },
 *     zoho_books: {
 *       attempted: true|false,
 *       reason: "worker_pending"  // Slice 2+ — workers not yet built
 *     }
 *   }
 *
 * RBAC: writes to both systems → `integrations.qbo.manage`. Since the
 * dashboard endpoint already gates on `integrations.qbo.view` for read
 * and the same wildcard `integrations.*` covers Zoho for tenant_admin,
 * this is the consistent write gate.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/qbo/client.php';
require_once __DIR__ . '/../../core/qbo/sync_je.php';
require_once __DIR__ . '/../../core/qbo/sync_in.php';
require_once __DIR__ . '/../../core/qbo/sync_accounts.php';
require_once __DIR__ . '/../../core/qbo/sync_bills.php';
require_once __DIR__ . '/../../core/qbo/sync_invoices.php';
require_once __DIR__ . '/../../core/qbo/sync_payments.php';
require_once __DIR__ . '/../../core/zoho_books/client.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'integrations.qbo.manage');

$body      = api_json_body();
$entityKey = trim((string) ($body['entity_key'] ?? ''));

// Canonical entity → (qbo_dir_key, qbo_runner, zoho_dir_key)
// `qbo_runner` is a closure that knows how to invoke the existing
// per-entity QBO worker and what direction it satisfies.
$ENTITY_RUNNERS = [
    'journal_entries' => [
        'qbo_dir_key'  => 'journal_entries',
        'qbo_runs_on'  => ['push', 'two_way'],
        'qbo_runner'   => static function (int $t, ?int $u) { return qboSyncJournalEntries($t, $u, ['limit' => 50]); },
        'zoho_dir_key' => 'journal_entries',
    ],
    'customers' => [
        'qbo_dir_key'  => 'customers',
        'qbo_runs_on'  => ['pull', 'two_way'],
        'qbo_runner'   => static function (int $t, ?int $u) { return qboSyncCustomers($t, $u, ['limit' => 1000]); },
        'zoho_dir_key' => 'contacts',
    ],
    'vendors' => [
        'qbo_dir_key'  => 'vendors',
        'qbo_runs_on'  => ['pull', 'two_way'],
        'qbo_runner'   => static function (int $t, ?int $u) { return qboSyncVendors($t, $u, ['limit' => 1000]); },
        'zoho_dir_key' => 'contacts',
    ],
    'invoices' => [
        'qbo_dir_key'  => 'invoices',
        'qbo_runs_on'  => ['push', 'two_way'],
        'qbo_runner'   => static function (int $t, ?int $u) { return qboSyncInvoices($t, $u, ['limit' => 50]); },
        'zoho_dir_key' => 'invoices',
    ],
    'bills' => [
        'qbo_dir_key'  => 'bills',
        'qbo_runs_on'  => ['push', 'two_way'],
        'qbo_runner'   => static function (int $t, ?int $u) { return qboSyncBills($t, $u, ['limit' => 50]); },
        'zoho_dir_key' => 'bills',
    ],
    'payments' => [
        'qbo_dir_key'  => 'payments',
        'qbo_runs_on'  => ['push', 'two_way'],
        'qbo_runner'   => static function (int $t, ?int $u) { return qboSyncBillPayments($t, $u, ['limit' => 50]); },
        'zoho_dir_key' => 'payments',
    ],
    'chart_of_accounts' => [
        'qbo_dir_key'  => 'chart_of_accounts',
        'qbo_runs_on'  => ['pull', 'two_way'],
        'qbo_runner'   => static function (int $t, ?int $u) { return qboSyncAccounts($t, $u, ['limit' => 1000]); },
        'zoho_dir_key' => 'chart_of_accounts',
    ],
];
if (!isset($ENTITY_RUNNERS[$entityKey])) {
    api_error('Unknown entity_key: ' . $entityKey, 422, ['valid' => array_keys($ENTITY_RUNNERS)]);
}
$spec = $ENTITY_RUNNERS[$entityKey];

// ---------------------------------------------------------------------
// QBO side
// ---------------------------------------------------------------------
$qboRow    = qboConnection($tid);
$qboActive = $qboRow && $qboRow['status'] === 'active';
$qboCfg    = qboSyncConfigRead($tid);
$qboDir    = $qboCfg[$spec['qbo_dir_key']] ?? 'off';

$qboResult = ['attempted' => false, 'reason' => null];
if (!$qboActive) {
    $qboResult['reason'] = 'not_connected';
} elseif (!in_array($qboDir, $spec['qbo_runs_on'], true)) {
    $qboResult['reason'] = 'direction_not_eligible';
    $qboResult['current_direction'] = $qboDir;
    $qboResult['eligible_directions'] = $spec['qbo_runs_on'];
} else {
    try {
        $qboResult['attempted'] = true;
        $qboResult['result']    = ($spec['qbo_runner'])($tid, $user['id'] ?? null);
    } catch (\Throwable $e) {
        $qboResult['attempted'] = true;
        $qboResult['error']     = $e->getMessage();
    }
}

// ---------------------------------------------------------------------
// Zoho Books side — Slice 1 has the connection but no workers yet.
// We honestly return worker_pending and audit the request so the
// pending-work signal is visible in the dashboard's activity feed.
// ---------------------------------------------------------------------
$zohoRow    = zohoBooksConnection($tid);
$zohoActive = $zohoRow && $zohoRow['status'] === 'active' && (string) $zohoRow['organization_id'] !== 'pending';
$zohoCfg    = zohoBooksSyncConfigRead($tid);
$zohoDir    = $zohoCfg[$spec['zoho_dir_key']] ?? 'off';

$zohoResult = ['attempted' => false];
if (!$zohoActive) {
    $zohoResult['reason'] = 'not_connected';
} elseif ($zohoDir === 'off') {
    $zohoResult['reason'] = 'direction_off';
} else {
    $zohoResult['reason'] = 'worker_pending';
    // Audit the request so an upcoming Slice 2+ runner can opt to
    // replay queued reconcile requests, and so it shows up in the
    // unified activity feed immediately.
    zohoBooksAudit($tid, 'reconcile_requested', [
        'actor_user_id' => $user['id'] ?? null,
        'entity_type'   => $spec['zoho_dir_key'],
        'direction'     => $zohoDir,
        'detail'        => ['entity_key' => $entityKey, 'note' => 'Zoho Books sync workers ship in Slice 2+; request queued.'],
    ]);
}

api_ok([
    'entity_key' => $entityKey,
    'qbo'        => $qboResult,
    'zoho_books' => $zohoResult,
]);
