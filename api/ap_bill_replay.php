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
 * the legacy fallback path because no rule was seeded yet). Records each
 * bill as `ap.bill.approved` with `source_module='ap_replay'` and a
 * differentiated `source_record_id` namespace so the (tenant,
 * source_module, source_record_id, event_type) unique key on
 * accounting_events keeps replay idempotent and doesn't collide with the
 * live `source_module='ap'` events. Replay links to the existing journal
 * entry; it never runs posting rules or creates a second journal entry.
 *
 * RBAC: `accounting.manage_posting_rules` (admin-gated).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

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
$journalStmt = $pdo->prepare(
    'SELECT je.entity_id, je.posting_date, je.currency AS journal_currency,
            je.memo AS journal_memo, jl.line_no, a.code AS account_code,
            jl.debit, jl.credit, jl.memo, jl.description,
            jl.counterparty_company_id, jl.counterparty_person_id,
            jl.counterparty_entity_id, jl.dim_json
       FROM accounting_journal_entries je
       JOIN accounting_journal_entry_lines jl
         ON jl.je_id = je.id AND jl.tenant_id = :line_tenant
       JOIN accounting_accounts a
         ON a.id = jl.account_id AND a.tenant_id = :account_tenant
      WHERE je.tenant_id = :journal_tenant
        AND je.id = :je
        AND je.status = "posted"
   ORDER BY jl.line_no ASC'
);
$insertEvent = $pdo->prepare(
    'INSERT IGNORE INTO accounting_events
        (tenant_id, entity_id, event_type, source_module,
         source_record_id, event_date, payload, status,
         journal_entry_id, posted_at, created_by_user_id)
     VALUES (:t, :e, :et, :sm, :sr, :ed, :pl, "posted",
             :je, NOW(), :u)'
);
$findEvent = $pdo->prepare(
    'SELECT id FROM accounting_events
      WHERE tenant_id = :t
        AND source_module = "ap_replay"
        AND source_record_id = :sr
        AND event_type = "ap.bill.approved"
      LIMIT 1'
);
$insertLink = $pdo->prepare(
    'INSERT IGNORE INTO accounting_subledger_links
        (tenant_id, source_module, source_record_id, journal_entry_id,
         accounting_event_id, link_kind)
     VALUES (:t, "ap", :sr, :je, :ev, "primary")'
);

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

    $journalStmt->execute([
        'line_tenant' => $tid,
        'account_tenant' => $tid,
        'journal_tenant' => $tid,
        'je' => (int) $b['journal_entry_id'],
    ]);
    $journalRows = $journalStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (!$journalRows) {
        $out['failed']++;
        $out['errors'][] = [
            'bill_id' => $billId,
            'internal_ref' => (string) $b['internal_ref'],
            'error' => 'The linked journal entry is missing, unposted, or has no lines.',
        ];
        if (count($out['errors']) > 50) break;
        continue;
    }

    if ($dryRun) { $out['replayed']++; continue; }

    $payloadLines = array_map(static function (array $line): array {
        $dimensions = !empty($line['dim_json'])
            ? (json_decode((string) $line['dim_json'], true) ?: [])
            : [];
        return [
            'line_no' => (int) $line['line_no'],
            'account_code' => (string) $line['account_code'],
            'debit' => (float) $line['debit'],
            'credit' => (float) $line['credit'],
            'memo' => $line['memo'] !== null ? (string) $line['memo'] : null,
            'description' => $line['description'] !== null ? (string) $line['description'] : null,
            'counterparty_company_id' => !empty($line['counterparty_company_id']) ? (int) $line['counterparty_company_id'] : null,
            'counterparty_person_id' => !empty($line['counterparty_person_id']) ? (int) $line['counterparty_person_id'] : null,
            'counterparty_entity_id' => !empty($line['counterparty_entity_id']) ? (int) $line['counterparty_entity_id'] : null,
            'dims' => $dimensions,
        ];
    }, $journalRows);

    $event = [
        'entity_id'        => (int) $journalRows[0]['entity_id'],
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
            'replay_mode'  => 'audit_link_only',
            'journal_posting_date' => (string) $journalRows[0]['posting_date'],
            'journal_currency' => (string) $journalRows[0]['journal_currency'],
            'journal_memo' => $journalRows[0]['journal_memo'] !== null ? (string) $journalRows[0]['journal_memo'] : null,
            'original_journal_entry_id' => (int) $b['journal_entry_id'],
        ],
    ];

    try {
        $ownsTxn = cf_tx_begin($pdo);
        try {
            $insertEvent->execute([
                't' => $tid,
                'e' => (int) $event['entity_id'],
                'et' => 'ap.bill.approved',
                'sm' => 'ap_replay',
                'sr' => $sr,
                'ed' => $event['event_date'],
                'pl' => json_encode($event['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'je' => (int) $b['journal_entry_id'],
                'u' => $user['id'] ?? null,
            ]);
            $findEvent->execute(['t' => $tid, 'sr' => $sr]);
            $eventId = (int) ($findEvent->fetchColumn() ?: 0);
            if ($eventId <= 0) {
                throw new \RuntimeException('Could not create or locate the replay audit event.');
            }
            $insertLink->execute([
                't' => $tid,
                'sr' => $sr,
                'je' => (int) $b['journal_entry_id'],
                'ev' => $eventId,
            ]);
            cf_tx_commit($pdo, $ownsTxn);
        } catch (\Throwable $e) {
            cf_tx_rollback($pdo, $ownsTxn);
            throw $e;
        }
        $out['replayed']++;
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
