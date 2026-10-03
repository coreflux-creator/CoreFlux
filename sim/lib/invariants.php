<?php
/**
 * Simulation invariants — financial truth checks per harness spec §15-17.
 *
 * Every invariant returns:
 *   [ 'name' => ..., 'ok' => true|false, 'severity' => 'error'|'warning',
 *     'details' => [..] ]
 *
 * Invariants operate on a single sim-tenant context; pass the tenant_id.
 *
 * The runner persists one row per invariant into simulation_assertions
 * and a denormalized failure row into simulation_failures.
 */
declare(strict_types=1);

require_once __DIR__ . '/seed.php';
require_once __DIR__ . '/document_links.php';

/** debits == credits across every JE in the tenant's books. */
function simInvariantDebitsEqualCredits(\PDO $pdo, int $tenantId): array {
    $stmt = $pdo->prepare(
        'SELECT je.id, je.je_number,
                ROUND(SUM(l.debit),  2) AS total_debit,
                ROUND(SUM(l.credit), 2) AS total_credit
           FROM accounting_journal_entries je
           JOIN accounting_journal_entry_lines l ON l.je_id = je.id
          WHERE je.tenant_id = :t
          GROUP BY je.id, je.je_number
         HAVING ROUND(SUM(l.debit), 2) <> ROUND(SUM(l.credit), 2)'
    );
    $stmt->execute(['t' => $tenantId]);
    $unbal = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    return [
        'name'     => 'debits_equal_credits',
        'ok'       => empty($unbal),
        'severity' => 'error',
        'details'  => ['unbalanced_je_count' => count($unbal), 'sample' => array_slice($unbal, 0, 5)],
    ];
}

/** Every accounting event must finish or remain fresh enough to retry. */
function simInvariantNoOrphanEvents(\PDO $pdo, int $tenantId): array {
    $stmt = $pdo->prepare(
        "SELECT id, event_type, status, journal_entry_id, error_message
           FROM accounting_events
          WHERE tenant_id = :t
            AND (
                (status IN ('received','mapped') AND created_at < DATE_SUB(NOW(), INTERVAL 60 SECOND))
                OR (status IN ('posted','reversed') AND journal_entry_id IS NULL)
                OR (status = 'ignored' AND (error_message IS NULL OR error_message = ''))
            )"
    );
    $stmt->execute(['t' => $tenantId]);
    $stuck = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    return [
        'name'     => 'no_orphan_events',
        'ok'       => empty($stuck),
        'severity' => 'error',
        'details'  => ['orphan_event_count' => count($stuck), 'sample' => array_slice($stuck, 0, 5)],
    ];
}

/** Posted simulation events must remain linked to their documents and payment allocations. */
function simInvariantPostedSourceLinks(\PDO $pdo, int $tenantId): array {
    $events = $pdo->prepare(
        'SELECT e.id, e.event_type, e.payload, e.journal_entry_id, j.entity_id, j.status AS journal_status
           FROM accounting_events e
           LEFT JOIN accounting_journal_entries j ON j.id = e.journal_entry_id AND j.tenant_id = e.tenant_id
          WHERE e.tenant_id = :tenant_id AND e.status = "posted" AND e.source_record_id LIKE "sim:%"
            AND e.event_type IN ("billing.invoice.sent", "ap.bill.approved", "ap.payment.cleared")'
    );
    $events->execute(['tenant_id' => $tenantId]);
    $missing = [];
    foreach ($events->fetchAll(\PDO::FETCH_ASSOC) as $event) {
        $payload = json_decode((string) $event['payload'], true);
        $jeId = (int) ($event['journal_entry_id'] ?? 0);
        $entityId = (int) ($event['entity_id'] ?? 0);
        $issue = null;
        if (!is_array($payload) || $jeId <= 0 || $entityId <= 0 || $event['journal_status'] !== 'posted') {
            $issue = 'missing posted journal or payload';
        } elseif ($event['event_type'] === 'ap.payment.cleared') {
            $paymentId = (int) ($payload['payment_id'] ?? 0);
            $billId = (int) ($payload['bill_id'] ?? 0);
            $payment = $pdo->prepare(
                'SELECT p.id, p.journal_entry_id, p.entity_id, p.status, p.amount,
                        a.amount_applied, b.amount_due, b.total AS bill_total
                   FROM ap_payments p
                   JOIN ap_payment_allocations a ON a.payment_id = p.id AND a.bill_id = :bill_id
                   JOIN ap_bills b ON b.id = a.bill_id AND b.tenant_id = p.tenant_id
                  WHERE p.tenant_id = :tenant_id AND p.id = :payment_id'
            );
            $payment->execute(['bill_id' => $billId, 'tenant_id' => $tenantId, 'payment_id' => $paymentId]);
            $rows = $payment->fetchAll(\PDO::FETCH_ASSOC);
            if (count($rows) !== 1
                || (int) $rows[0]['journal_entry_id'] !== $jeId
                || (int) $rows[0]['entity_id'] !== $entityId
                || $rows[0]['status'] !== 'cleared'
                || round((float) $rows[0]['amount'], 2) !== round((float) $rows[0]['amount_applied'], 2)
                || round((float) $rows[0]['amount'], 2) !== round((float) $rows[0]['bill_total'], 2)
                || round((float) $rows[0]['amount_due'], 2) !== 0.0) {
                $issue = 'missing or inconsistent cleared payment allocation';
            }
        } else {
            [$table, $idField] = simDocumentForEvent((string) $event['event_type']);
            $documentId = (int) ($payload[$idField] ?? 0);
            $document = $pdo->prepare("SELECT journal_entry_id, entity_id FROM {$table} WHERE tenant_id = :tenant_id AND id = :id");
            $document->execute(['tenant_id' => $tenantId, 'id' => $documentId]);
            $linked = $document->fetch(\PDO::FETCH_ASSOC);
            if (!$linked || (int) $linked['journal_entry_id'] !== $jeId || (int) $linked['entity_id'] !== $entityId) {
                $issue = 'missing or inconsistent source document link';
            }
        }
        if ($issue !== null) $missing[] = ['event_id' => (int) $event['id'], 'issue' => $issue];
    }
    return [
        'name' => 'posted_source_links',
        'ok' => $missing === [],
        'severity' => 'error',
        'details' => ['mismatch_count' => count($missing), 'sample' => array_slice($missing, 0, 5)],
    ];
}

/** Canonical AP clearances must be correctable, not merely balanced JEs. */
function simInvariantCanonicalApPayment(\PDO $pdo, int $tenantId, array $state): array {
    require_once __DIR__ . '/../../modules/ap/lib/payment_correction.php';
    $payments = $state['posted_payments'] ?? [];
    $issues = [];
    if (!$payments) $issues[] = ['issue' => 'scenario did not clear an AP payment'];
    $stmt = $pdo->prepare('SELECT * FROM ap_payments WHERE tenant_id = :tenant_id AND id = :id');
    foreach ($payments as $paymentId => $expectedJournalId) {
        $stmt->execute(['tenant_id' => $tenantId, 'id' => (int) $paymentId]);
        $payment = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$payment || (int) ($payment['journal_entry_id'] ?? 0) !== (int) $expectedJournalId) {
            $issues[] = ['payment_id' => (int) $paymentId, 'issue' => 'missing linked AP payment journal'];
            continue;
        }
        try {
            apInspectClearedManualPayment($pdo, $tenantId, $payment);
        } catch (\Throwable $e) {
            $issues[] = ['payment_id' => (int) $paymentId, 'issue' => $e->getMessage()];
        }
    }
    return [
        'name' => 'ap_payment_canonical_lineage',
        'ok' => $issues === [],
        'severity' => 'error',
        'details' => ['mismatch_count' => count($issues), 'sample' => array_slice($issues, 0, 5)],
    ];
}

/** Every posted JE must trace to either an accounting_event OR an
 *  approved manual JE. Anything else is a direct-GL bypass — exactly the
 *  Phase-2a discipline gap. Allowlists known module sources during the
 *  transition window. */
function simInvariantNoLegacyDirectGL(\PDO $pdo, int $tenantId, array $allowedSourceModules = ['manual','reversal','ap_replay','billing_replay']): array {
    $placeholders = implode(',', array_fill(0, count($allowedSourceModules), '?'));
    $stmt = $pdo->prepare(
        "SELECT je.id, je.je_number, je.source_module, je.source_ref_type, je.source_ref_id
           FROM accounting_journal_entries je
      LEFT JOIN accounting_subledger_links sl
             ON sl.tenant_id = je.tenant_id
            AND sl.journal_entry_id = je.id
          WHERE je.tenant_id = ?
            AND sl.id IS NULL
            AND je.source_module NOT IN ($placeholders)"
    );
    $stmt->execute(array_merge([$tenantId], $allowedSourceModules));
    $bypass = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    return [
        'name'     => 'no_direct_gl_bypass',
        'ok'       => empty($bypass),
        'severity' => 'error',
        'details'  => ['bypass_je_count' => count($bypass), 'sample' => array_slice($bypass, 0, 5)],
    ];
}

/** Replay reproducibility — given the run's replay_logs from a previous
 *  execution at the same seed, re-running the scenario must produce an
 *  identical sequence of (event_type, payload_hash, je_hash). */
function simInvariantReplayReproducible(\PDO $pdo, int $runId, array $observed): array {
    $stmt = $pdo->prepare(
        'SELECT event_index, event_type, payload_hash, je_hash
           FROM replay_logs
          WHERE run_id = :r
          ORDER BY event_index ASC'
    );
    $stmt->execute(['r' => $runId]);
    $persisted = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $mismatches = [];
    $n = max(count($persisted), count($observed));
    for ($i = 0; $i < $n; $i++) {
        $p = $persisted[$i] ?? null;
        $o = $observed[$i]  ?? null;
        if (!$p || !$o) { $mismatches[] = ['index' => $i, 'reason' => 'length_mismatch']; continue; }
        if ($p['event_type']   !== $o['event_type']
         || $p['payload_hash'] !== $o['payload_hash']
         || ($p['je_hash'] ?? null) !== ($o['je_hash'] ?? null)) {
            $mismatches[] = ['index' => $i, 'persisted' => $p, 'observed' => $o];
        }
    }
    return [
        'name'     => 'replay_reproducible',
        'ok'       => empty($mismatches),
        'severity' => 'error',
        'details'  => ['mismatch_count' => count($mismatches), 'sample' => array_slice($mismatches, 0, 5)],
    ];
}

/** Open AP and AR subledger balances equal their canonical GL balances. */
function simInvariantCustomerBalanceMatchesGL(\PDO $pdo, int $tenantId): array {
    // AP side
    $ap = $pdo->prepare(
        'SELECT COALESCE(ROUND(SUM(amount_due), 2), 0) AS bal
           FROM ap_bills
          WHERE tenant_id = :t
            AND status IN ("partially_paid","approved")'
    );
    $ap->execute(['t' => $tenantId]);
    $apModule = (float) $ap->fetchColumn();

    $apGl = $pdo->prepare(
        'SELECT COALESCE(ROUND(SUM(l.credit - l.debit), 2), 0)
           FROM accounting_journal_entry_lines l
           JOIN accounting_journal_entries je ON je.id = l.je_id
           JOIN accounting_accounts a         ON a.id  = l.account_id
          WHERE je.tenant_id = :t AND je.status IN ("posted", "reversed") AND a.code = "2000"'
    );
    $apGl->execute(['t' => $tenantId]);
    $apLedger = (float) $apGl->fetchColumn();

    $ar = $pdo->prepare(
        'SELECT COALESCE(ROUND(SUM(amount_due), 2), 0) AS bal
           FROM billing_invoices
          WHERE tenant_id = :t
            AND status IN ("sent","partially_paid")'
    );
    $ar->execute(['t' => $tenantId]);
    $arModule = (float) $ar->fetchColumn();

    $arGl = $pdo->prepare(
        'SELECT COALESCE(ROUND(SUM(l.debit - l.credit), 2), 0)
           FROM accounting_journal_entry_lines l
           JOIN accounting_journal_entries je ON je.id = l.je_id
           JOIN accounting_accounts a         ON a.id  = l.account_id
          WHERE je.tenant_id = :t AND je.status IN ("posted", "reversed") AND a.code = "1100"'
    );
    $arGl->execute(['t' => $tenantId]);
    $arLedger = (float) $arGl->fetchColumn();

    $apDrift = round($apModule - $apLedger, 2);
    $arDrift = round($arModule - $arLedger, 2);
    return [
        'name'     => 'subledger_balances_match_gl',
        'ok'       => abs($apDrift) < 0.01 && abs($arDrift) < 0.01,
        'severity' => 'error',
        'details'  => [
            'ap_module' => $apModule,
            'ap_ledger' => $apLedger,
            'ap_drift' => $apDrift,
            'ar_module' => $arModule,
            'ar_ledger' => $arLedger,
            'ar_drift' => $arDrift,
        ],
    ];
}

/** Run the same cross-module integrity audit exposed to administrators. */
function simInvariantBusinessGraphConsistent(\PDO $pdo, int $tenantId): array {
    require_once __DIR__ . '/../../core/business_integrity.php';
    $report = businessIntegrityAudit($tenantId);
    $failed = array_values(array_filter(
        $report['checks'],
        static fn(array $check): bool => in_array((string) ($check['status'] ?? ''), ['fail', 'error'], true)
            && in_array((string) ($check['severity'] ?? ''), ['critical', 'error'], true)
    ));
    return [
        'name' => 'business_graph_consistent',
        'ok' => empty($failed),
        'severity' => 'error',
        'details' => [
            'failed_check_count' => count($failed),
            'issue_count' => (int) ($report['summary']['issues'] ?? 0),
            'sample' => array_slice($failed, 0, 8),
        ],
    ];
}
