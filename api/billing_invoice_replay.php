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
 * Same shape + behaviour as `ap_bill_replay.php`. Records a posted
 * `billing.invoice.sent` audit event with `source_module='billing_replay'`
 * and links it to the invoice's existing journal entry. Replay never runs
 * posting rules and never creates a second journal entry.
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
        AND source_module = "billing_replay"
        AND source_record_id = :sr
        AND event_type = "billing.invoice.sent"
      LIMIT 1'
);
$insertLink = $pdo->prepare(
    'INSERT IGNORE INTO accounting_subledger_links
        (tenant_id, source_module, source_record_id, journal_entry_id,
         accounting_event_id, link_kind)
     VALUES (:t, "billing", :sr, :je, :ev, "primary")'
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
    $journalStmt->execute([
        'line_tenant' => $tid,
        'account_tenant' => $tid,
        'journal_tenant' => $tid,
        'je' => (int) $r['journal_entry_id'],
    ]);
    $journalRows = $journalStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if (!$journalRows) {
        $out['failed']++;
        $out['errors'][] = [
            'invoice_id' => $invId,
            'invoice_number' => (string) $r['invoice_number'],
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
    $party = !empty($r['client_company_id']) ? (int) $r['client_company_id'] : null;

    $event = [
        'entity_id'        => (int) $journalRows[0]['entity_id'],
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
            'replay_mode'       => 'audit_link_only',
            'journal_posting_date' => (string) $journalRows[0]['posting_date'],
            'journal_currency'  => (string) $journalRows[0]['journal_currency'],
            'journal_memo'      => $journalRows[0]['journal_memo'] !== null ? (string) $journalRows[0]['journal_memo'] : null,
            'original_journal_entry_id' => (int) $r['journal_entry_id'],
        ],
    ];

    try {
        $ownsTxn = cf_tx_begin($pdo);
        try {
            $insertEvent->execute([
                't' => $tid,
                'e' => (int) $event['entity_id'],
                'et' => 'billing.invoice.sent',
                'sm' => 'billing_replay',
                'sr' => $sr,
                'ed' => $event['event_date'],
                'pl' => json_encode($event['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'je' => (int) $r['journal_entry_id'],
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
                'je' => (int) $r['journal_entry_id'],
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
            'invoice_id'     => $invId,
            'invoice_number' => (string) $r['invoice_number'],
            'error'          => $e->getMessage(),
        ];
        if (count($out['errors']) > 50) break;
    }
}

api_ok($out);
