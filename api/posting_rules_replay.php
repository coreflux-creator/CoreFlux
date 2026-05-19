<?php
/**
 * Posting-rules replay endpoint (Sprint 7c.2).
 *
 *   POST /api/posting_rules_replay.php
 *     query: ?bank_account_id=N (optional, omit for all)
 *            ?days=30           (default 30, max 365)
 *            ?since=YYYY-MM-DD  (overrides days)
 *            ?dry_run=1         (no events written, just counts)
 *
 *   → { scanned, replayed, skipped_no_event_row, skipped_no_bank_gl,
 *       failed, errors:[ {line_id, error} ] }
 *
 * Use case: backfill the audit ledger when a tenant migrates from manual
 * posting. Iterates `accounting_bank_statement_lines` within the window,
 * emits `treasury.bank_transaction.matched` events for each. Idempotent
 * via `accounting_events`'s (tenant, source_module, source_record_id,
 * event_type) unique key — re-runs don't double-post.
 *
 * RBAC: `accounting.manage_posting_rules` (admin-gated; this writes
 * accounting events at scale).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/posting_engine/process.php';

$ctx = api_require_auth();
$tid = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'accounting.manage_posting_rules');

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$bankAccountId = api_query('bank_account_id') ? (int) api_query('bank_account_id') : null;
$days          = max(1, min(365, (int) (api_query('days') ?? 30)));
$since         = (string) (api_query('since') ?? date('Y-m-d', strtotime("-{$days} days")));
$dryRun        = !empty(api_query('dry_run'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) api_error('since must be YYYY-MM-DD', 400);

$pdo = getDB();

// Hydrate bank → entity_id + GL account map up-front.
$bankInfo = $pdo->prepare(
    'SELECT ba.id, ba.entity_id, ba.name AS bank_name, ba.gl_account_code,
            aa.id AS gl_account_id
       FROM accounting_bank_accounts ba
       LEFT JOIN accounting_accounts aa
         ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
      WHERE ba.tenant_id = :t' .
      ($bankAccountId ? ' AND ba.id = :id' : '')
);
$bankInfo->execute($bankAccountId ? ['t' => $tid, 'id' => $bankAccountId] : ['t' => $tid]);
$banksByID = [];
foreach ($bankInfo->fetchAll(\PDO::FETCH_ASSOC) as $r) {
    $banksByID[(int) $r['id']] = $r;
}
if (!$banksByID) api_error('No bank accounts found for replay window', 404);

// Pull statement lines.
$lineWhere  = ['tenant_id = :t', 'posted_date >= :since'];
$lineParams = ['t' => $tid, 'since' => $since];
if ($bankAccountId) {
    $lineWhere[] = 'bank_account_id = :ba';
    $lineParams['ba'] = $bankAccountId;
}
$lineStmt = $pdo->prepare(
    'SELECT id, bank_account_id, posted_date, description, amount,
            bank_reference, fitid, match_status
       FROM accounting_bank_statement_lines
      WHERE ' . implode(' AND ', $lineWhere) . '
      ORDER BY posted_date ASC, id ASC'
);
$lineStmt->execute($lineParams);
$lines = $lineStmt->fetchAll(\PDO::FETCH_ASSOC);

$out = [
    'since'                 => $since,
    'bank_account_id'       => $bankAccountId,
    'dry_run'               => $dryRun,
    'scanned'               => count($lines),
    'replayed'              => 0,
    'skipped_already_event' => 0,
    'skipped_no_bank_gl'    => 0,
    'failed'                => 0,
    'errors'                => [],
];

// Pre-check existing events to count idempotency hits cheaply.
$evCheck = $pdo->prepare(
    "SELECT 1 FROM accounting_events
      WHERE tenant_id = :t
        AND source_module = 'treasury_replay'
        AND source_record_id = :sr
        AND event_type = 'treasury.bank_transaction.matched'
      LIMIT 1"
);

foreach ($lines as $line) {
    $bank = $banksByID[(int) $line['bank_account_id']] ?? null;
    if (!$bank) { $out['skipped_no_bank_gl']++; continue; }
    if (!$bank['gl_account_id']) { $out['skipped_no_bank_gl']++; continue; }

    $sr = 'bank_line:' . $line['id'];
    $evCheck->execute(['t' => $tid, 'sr' => $sr]);
    if ($evCheck->fetchColumn()) { $out['skipped_already_event']++; continue; }

    if ($dryRun) { $out['replayed']++; continue; }

    $event = [
        'entity_id'        => (int) ($bank['entity_id'] ?? 0),
        'event_type'       => 'treasury.bank_transaction.matched',
        'source_module'    => 'treasury_replay',
        'source_record_id' => $sr,
        'event_date'       => (string) $line['posted_date'],
        'payload' => [
            'bank_account_id'       => (int) $line['bank_account_id'],
            'bank_account_name'     => (string) $bank['bank_name'],
            'bank_gl_account_id'    => (int) $bank['gl_account_id'],
            'bank_gl_account_code'  => (string) $bank['gl_account_code'],
            'amount'                => abs((float) $line['amount']),
            'signed_amount'         => (float) $line['amount'],
            'description'           => (string) $line['description'],
            'bank_reference'        => $line['bank_reference'],
            'fitid'                 => $line['fitid'],
            'currency'              => 'USD',
        ],
    ];

    try {
        $r = accountingProcessEvent($tid, $event, $ctx['user']['id'] ?? null, /* dryRun */ false);
        if ($r['status'] === 'posted') {
            $out['replayed']++;
        } else {
            $out['failed']++;
            $out['errors'][] = [
                'line_id' => (int) $line['id'],
                'status'  => $r['status'],
                'error'   => $r['error'] ?? null,
            ];
            if (count($out['errors']) > 50) break;  // truncate for sanity
        }
    } catch (\Throwable $e) {
        $out['failed']++;
        $out['errors'][] = ['line_id' => (int) $line['id'], 'error' => $e->getMessage()];
        if (count($out['errors']) > 50) break;
    }
}

api_ok($out);
