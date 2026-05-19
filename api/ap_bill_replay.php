<?php
/**
 * AP bill audit-trail replay endpoint (Sprint 7e follow-up).
 *
 *   POST /api/ap_bill_replay.php
 *     query: ?days=180             (default 180, max 1825 = 5 years)
 *            ?since=YYYY-MM-DD     (overrides days)
 *            ?status=approved      (default 'approved,partially_paid,paid')
 *            ?dry_run=1            (no events written, just counts)
 *            ?only_unlinked=1      (skip bills that already have an event row)
 *
 *   → { since, scanned, replayed, skipped_already_event, skipped_no_je,
 *       failed, errors:[{bill_id, internal_ref, error}] }
 *
 * Use case: backfill `accounting_events` + `accounting_subledger_links`
 * for AP bills that were posted before Sprint 7e shipped (or that took
 * the legacy fallback path because no rule was seeded yet). Re-emits each
 * bill as `ap.bill.approved` with `source_module='ap_replay'` and a
 * differentiated `source_record_id` namespace so the (tenant,
 * source_module, source_record_id, event_type) unique key on
 * accounting_events keeps replay idempotent and doesn't collide with the
 * live `source_module='ap'` events.
 *
 * RBAC: `accounting.manage_posting_rules` (admin-gated).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/posting_engine/process.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'accounting.manage_posting_rules');

$days        = max(1, min(1825, (int) (api_query('days') ?? 180)));
$since       = (string) (api_query('since') ?? date('Y-m-d', strtotime("-{$days} days")));
$dryRun      = !empty(api_query('dry_run'));
$onlyUnlinked = !empty(api_query('only_unlinked'));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) api_error('since must be YYYY-MM-DD', 400);

$statusList = (string) (api_query('status') ?? 'approved,partially_paid,paid');
$statuses   = array_filter(array_map('trim', explode(',', $statusList)));
$allowedStatuses = ['approved','partially_paid','paid'];
$statuses = array_values(array_intersect($statuses, $allowedStatuses));
if (!$statuses) $statuses = $allowedStatuses;

$pdo = getDB();

// Pull candidate bills.  We want only bills that have a journal_entry_id
// already (i.e. they actually posted) — replaying an unposted bill makes
// no sense for audit-trail backfill.
$inPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
$params  = array_merge([$tid, $since], $statuses);
$sql = "SELECT id, internal_ref, vendor_name, vendor_company_id, bill_date,
               currency, total, journal_entry_id, entity_id
          FROM ap_bills
         WHERE tenant_id = ?
           AND bill_date >= ?
           AND status IN ({$inPlaceholders})
           AND journal_entry_id IS NOT NULL
         ORDER BY bill_date ASC, id ASC";
$bills = $pdo->prepare($sql);
$bills->execute($params);
$rows = $bills->fetchAll(\PDO::FETCH_ASSOC);

$out = [
    'since'                 => $since,
    'status_filter'         => $statuses,
    'dry_run'               => $dryRun,
    'only_unlinked'         => $onlyUnlinked,
    'scanned'               => count($rows),
    'replayed'              => 0,
    'skipped_already_event' => 0,
    'skipped_no_je'         => 0,
    'failed'                => 0,
    'errors'                => [],
];

$evCheck = $pdo->prepare(
    "SELECT 1 FROM accounting_events
      WHERE tenant_id = :t
        AND source_module = 'ap_replay'
        AND source_record_id = :sr
        AND event_type = 'ap.bill.approved'
      LIMIT 1"
);
$liveEvCheck = $pdo->prepare(
    "SELECT 1 FROM accounting_events
      WHERE tenant_id = :t
        AND source_module = 'ap'
        AND source_record_id = :sr
        AND event_type = 'ap.bill.approved'
      LIMIT 1"
);
$lineStmt = $pdo->prepare('SELECT * FROM ap_bill_lines WHERE bill_id = :id ORDER BY line_no');

foreach ($rows as $b) {
    $billId = (int) $b['id'];
    if (!$b['journal_entry_id']) { $out['skipped_no_je']++; continue; }

    $sr = 'ap_bill:' . $billId;

    // Already replayed once?
    $evCheck->execute(['t' => $tid, 'sr' => $sr]);
    if ($evCheck->fetchColumn()) { $out['skipped_already_event']++; continue; }

    if ($onlyUnlinked) {
        $liveEvCheck->execute(['t' => $tid, 'sr' => $sr]);
        if ($liveEvCheck->fetchColumn()) { $out['skipped_already_event']++; continue; }
    }

    if ($dryRun) { $out['replayed']++; continue; }

    // Rebuild payload.lines from ap_bill_lines.
    $lineStmt->execute(['id' => $billId]);
    $billLines = $lineStmt->fetchAll(\PDO::FETCH_ASSOC);

    $payloadLines = [];
    foreach ($billLines as $bl) {
        $payloadLines[] = [
            'account_code' => $bl['gl_expense_account_code'] ?? '5000',
            'debit'        => (float) $bl['total'],
            'credit'       => 0,
            'description'  => $bl['description'],
            'counterparty_company_id' => !empty($b['vendor_company_id']) ? (int) $b['vendor_company_id'] : null,
        ];
    }
    $payloadLines[] = [
        'account_code' => '2000',
        'debit'        => 0,
        'credit'       => (float) $b['total'],
        'description'  => "AP " . $b['internal_ref'] . " — " . $b['vendor_name'],
        'counterparty_company_id' => !empty($b['vendor_company_id']) ? (int) $b['vendor_company_id'] : null,
    ];

    $event = [
        'entity_id'        => !empty($b['entity_id']) ? (int) $b['entity_id'] : 0,
        'event_type'       => 'ap.bill.approved',
        'source_module'    => 'ap_replay',
        'source_record_id' => $sr,
        'event_date'       => (string) $b['bill_date'],
        'payload' => [
            'bill_id'      => $billId,
            'internal_ref' => (string) $b['internal_ref'],
            'vendor_name'  => (string) $b['vendor_name'],
            'vendor_company_id' => !empty($b['vendor_company_id']) ? (int) $b['vendor_company_id'] : null,
            'amount'       => (float) $b['total'],
            'currency'     => (string) $b['currency'],
            'lines'        => $payloadLines,
            'replay'       => true,
            'original_journal_entry_id' => (int) $b['journal_entry_id'],
        ],
    ];

    try {
        $r = accountingProcessEvent($tid, $event, $user['id'] ?? null, /* dryRun */ false);
        if (($r['status'] ?? null) === 'posted') {
            $out['replayed']++;
        } elseif (($r['status'] ?? null) === 'ignored') {
            // No rule seeded — degrade to writing a stub event row + a
            // subledger_links row pointing back at the original JE so the
            // audit trail isn't lost. This is the "everything has an
            // audit trail" guarantee.
            $insStub = $pdo->prepare(
                'INSERT IGNORE INTO accounting_events
                    (tenant_id, entity_id, event_type, source_module,
                     source_record_id, event_date, payload, status,
                     journal_entry_id, posted_at, error_message,
                     created_by_user_id)
                 VALUES (:t, :e, :et, :sm, :sr, :ed, :pl, "posted",
                         :je, NOW(), :err, :u)'
            );
            $insStub->execute([
                't' => $tid, 'e' => (int) ($event['entity_id'] ?? 0),
                'et' => 'ap.bill.approved', 'sm' => 'ap_replay',
                'sr' => $sr, 'ed' => $event['event_date'],
                'pl' => json_encode($event['payload']),
                'je' => (int) $b['journal_entry_id'],
                'err' => 'replay: stamped existing JE (no rule matched, JE was posted via legacy path)',
                'u' => $user['id'] ?? null,
            ]);
            // Backfill subledger_links too.
            $pdo->prepare(
                'INSERT IGNORE INTO accounting_subledger_links
                    (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
                 VALUES (:t, "ap", :sr, :je, "primary")'
            )->execute([
                't' => $tid, 'sr' => $sr, 'je' => (int) $b['journal_entry_id'],
            ]);
            $out['replayed']++;
        } else {
            $out['failed']++;
            $out['errors'][] = [
                'bill_id'      => $billId,
                'internal_ref' => (string) $b['internal_ref'],
                'status'       => $r['status'] ?? 'unknown',
                'error'        => $r['error']  ?? null,
            ];
            if (count($out['errors']) > 50) break;
        }
    } catch (\Throwable $e) {
        $out['failed']++;
        $out['errors'][] = [
            'bill_id'      => $billId,
            'internal_ref' => (string) $b['internal_ref'],
            'error'        => $e->getMessage(),
        ];
        if (count($out['errors']) > 50) break;
    }
}

api_ok($out);
