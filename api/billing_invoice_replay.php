<?php
/**
 * Billing invoice audit-trail replay endpoint (Sprint 7e follow-up).
 *
 *   POST /api/billing_invoice_replay.php
 *     query: ?days=180             (default 180, max 1825)
 *            ?since=YYYY-MM-DD     (overrides days)
 *            ?status=...           (default 'approved,sent,partially_paid,paid')
 *            ?dry_run=1
 *            ?only_unlinked=1
 *
 *   → { since, scanned, replayed, skipped_already_event, skipped_no_je,
 *       failed, errors:[{invoice_id, invoice_number, error}] }
 *
 * Same shape + behaviour as `ap_bill_replay.php`. Emits
 * `billing.invoice.sent` with `source_module='billing_replay'`. Falls
 * back to writing a stub posted-event row pointing at the existing JE
 * when no rule is seeded so the audit trail is always populated.
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

$statusList = (string) (api_query('status') ?? 'approved,sent,partially_paid,paid');
$statuses   = array_filter(array_map('trim', explode(',', $statusList)));
$allowedStatuses = ['approved','sent','partially_paid','paid'];
$statuses = array_values(array_intersect($statuses, $allowedStatuses));
if (!$statuses) $statuses = $allowedStatuses;

$pdo = getDB();

$inPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
$params  = array_merge([$tid, $since], $statuses);
$sql = "SELECT id, invoice_number, client_name, client_company_id, issue_date,
               currency, total, journal_entry_id, entity_id
          FROM billing_invoices
         WHERE tenant_id = ?
           AND issue_date >= ?
           AND status IN ({$inPlaceholders})
           AND journal_entry_id IS NOT NULL
         ORDER BY issue_date ASC, id ASC";
$rs = $pdo->prepare($sql);
$rs->execute($params);
$rows = $rs->fetchAll(\PDO::FETCH_ASSOC);

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
        AND source_module = 'billing_replay'
        AND source_record_id = :sr
        AND event_type = 'billing.invoice.sent'
      LIMIT 1"
);
$liveEvCheck = $pdo->prepare(
    "SELECT 1 FROM accounting_events
      WHERE tenant_id = :t
        AND source_module = 'billing'
        AND source_record_id = :sr
        AND event_type = 'billing.invoice.sent'
      LIMIT 1"
);
$lineStmt = $pdo->prepare(
    'SELECT item_type, gl_revenue_account_code, SUM(subtotal) AS s
       FROM billing_invoice_lines
      WHERE invoice_id = :id
   GROUP BY item_type, gl_revenue_account_code'
);

foreach ($rows as $r) {
    $invId = (int) $r['id'];
    if (!$r['journal_entry_id']) { $out['skipped_no_je']++; continue; }

    $sr = 'billing_invoice:' . $invId;

    $evCheck->execute(['t' => $tid, 'sr' => $sr]);
    if ($evCheck->fetchColumn()) { $out['skipped_already_event']++; continue; }
    if ($onlyUnlinked) {
        $liveEvCheck->execute(['t' => $tid, 'sr' => $sr]);
        if ($liveEvCheck->fetchColumn()) { $out['skipped_already_event']++; continue; }
    }
    if ($dryRun) { $out['replayed']++; continue; }

    $lineStmt->execute(['id' => $invId]);
    $bucketSums = [];
    foreach ($lineStmt->fetchAll(\PDO::FETCH_ASSOC) as $bl) {
        $code = $bl['gl_revenue_account_code'] ?: '4000';
        $bucketSums[$code] = ($bucketSums[$code] ?? 0) + (float) $bl['s'];
    }
    $total = (float) $r['total'];
    $party = !empty($r['client_company_id']) ? (int) $r['client_company_id'] : null;
    $payloadLines = [
        ['account_code' => '1100', 'debit' => $total, 'credit' => 0,
         'description' => "Inv {$r['invoice_number']} / {$r['client_name']}",
         'counterparty_company_id' => $party],
    ];
    $sumRev = 0.0;
    foreach ($bucketSums as $code => $amt) {
        if (round($amt, 2) <= 0.005) continue;
        $payloadLines[] = ['account_code' => $code, 'debit' => 0, 'credit' => round($amt, 2),
                           'description' => "Revenue — {$r['invoice_number']}",
                           'counterparty_company_id' => $party];
        $sumRev += round($amt, 2);
    }
    $tax = round($total - $sumRev, 2);
    if ($tax > 0.005) {
        $payloadLines[] = ['account_code' => '2100', 'debit' => 0, 'credit' => $tax,
                           'description' => "Sales tax — {$r['invoice_number']}",
                           'counterparty_company_id' => $party];
    }

    $event = [
        'entity_id'        => !empty($r['entity_id']) ? (int) $r['entity_id'] : 0,
        'event_type'       => 'billing.invoice.sent',
        'source_module'    => 'billing_replay',
        'source_record_id' => $sr,
        'event_date'       => (string) $r['issue_date'],
        'payload' => [
            'invoice_id'        => $invId,
            'invoice_number'    => (string) $r['invoice_number'],
            'client_name'       => (string) $r['client_name'],
            'client_company_id' => $party,
            'amount'            => (float) $r['total'],
            'currency'          => (string) $r['currency'],
            'lines'             => $payloadLines,
            'replay'            => true,
            'original_journal_entry_id' => (int) $r['journal_entry_id'],
        ],
    ];

    try {
        $rs2 = accountingProcessEvent($tid, $event, $user['id'] ?? null, /* dryRun */ false);
        if (($rs2['status'] ?? null) === 'posted') {
            $out['replayed']++;
        } elseif (($rs2['status'] ?? null) === 'ignored') {
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
                'et' => 'billing.invoice.sent', 'sm' => 'billing_replay',
                'sr' => $sr, 'ed' => $event['event_date'],
                'pl' => json_encode($event['payload']),
                'je' => (int) $r['journal_entry_id'],
                'err' => 'replay: stamped existing JE (no rule matched, JE was posted via legacy path)',
                'u' => $user['id'] ?? null,
            ]);
            $pdo->prepare(
                'INSERT IGNORE INTO accounting_subledger_links
                    (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
                 VALUES (:t, "billing", :sr, :je, "primary")'
            )->execute([
                't' => $tid, 'sr' => $sr, 'je' => (int) $r['journal_entry_id'],
            ]);
            $out['replayed']++;
        } else {
            $out['failed']++;
            $out['errors'][] = [
                'invoice_id'     => $invId,
                'invoice_number' => (string) $r['invoice_number'],
                'status'         => $rs2['status'] ?? 'unknown',
                'error'          => $rs2['error']  ?? null,
            ];
            if (count($out['errors']) > 50) break;
        }
    } catch (\Throwable $e) {
        $out['failed']++;
        $out['errors'][] = [
            'invoice_id'     => $invId,
            'invoice_number' => (string) $r['invoice_number'],
            'error'          => $e->getMessage(),
        ];
        if (count($out['errors']) > 50) break;
    }
}

api_ok($out);
