<?php
/**
 * GET /api/admin/integration_triage.php
 *
 * Single read-only aggregator for the Integration Triage operator
 * page. Reads from the three backend admin endpoints that all share
 * the {severity, summary, playbook} shape and returns a unified
 * action-item list.
 *
 * Sources rolled together:
 *   - qbo_push_failures           (push DLQ — typed exception failures)
 *   - qbo_sync_drift              (two-way sync drift)
 *   - qbo_payment_charges         (stale/failed/refunded merchant charges)
 *   - payment_instructions WHERE state='Failed'  (Mercury rail Failed PIs)
 *   - purepay_payment_links       (failed/unverified/needs-review vendor payments)
 *
 * Returns:
 *   {
 *     items: [
 *       {
 *         source       : 'qbo-dlq' | 'qbo-drift' | 'qbo-payments' | 'mercury-failed' | 'purepay-failed',
 *         id           : <row id in the source table>,
 *         tenant_id    : int,
 *         severity     : 'critical'|'warn'|'info',
 *         summary      : string,
 *         playbook     : {code, category, severity, summary, suggested_fix, docs_link},
 *         meta         : {...source-specific fields},
 *         created_at   : ISO datetime,
 *         actionable   : 'requeue'|'resolve'|'cancel'|null
 *       }
 *     ],
 *     counts: { critical, warn, info, total, by_source: {...} }
 *   }
 *
 * Read-only — write actions go through the per-source endpoints
 * (POST /api/admin/qbo/dead_letters.php, etc.) so each source keeps
 * its specialized validation/audit path.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/qbo/error_playbook.php';
require_once __DIR__ . '/../../core/mercury/error_playbook.php';

$ctx = api_require_auth();
rbac_legacy_require_any($currentUser ?? $ctx, ['master_admin', 'tenant_admin', '*']);

$tenantId = isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : (int) ($ctx['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(400);
    api_error('tenant_id required', 400);
}
$limit = max(10, min(500, (int) ($_GET['limit'] ?? 200)));
$wantedSources = $_GET['sources'] ?? 'all'; // comma-list or 'all'
$wanted = $wantedSources === 'all'
    ? ['qbo-dlq', 'qbo-drift', 'qbo-payments', 'mercury-failed', 'purepay-failed']
    : array_map('trim', explode(',', (string) $wantedSources));

$items  = [];
$counts = ['critical' => 0, 'warn' => 0, 'info' => 0, 'total' => 0,
           'by_source' => ['qbo-dlq' => 0, 'qbo-drift' => 0, 'qbo-payments' => 0, 'mercury-failed' => 0, 'purepay-failed' => 0]];
$db = getDB();

// ─────── 1. QBO push DLQ ───────
if (in_array('qbo-dlq', $wanted, true)) {
    try {
        $stmt = $db->prepare(
            "SELECT id, tenant_id, entity_type, source_id, attempts, max_attempts, status,
                    last_error_code, last_error_message, last_http_status, vendor_raw,
                    next_retry_at, first_failed_at, last_failed_at
               FROM qbo_push_failures
              WHERE tenant_id = :t AND cleared_at IS NULL
                AND status IN ('retrying','dead_letter')
           ORDER BY last_failed_at DESC LIMIT {$limit}"
        );
        $stmt->execute(['t' => $tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $playbook = qboErrorPlaybookLookup($r['last_error_code'] ?? null);
            $sev = $r['status'] === 'dead_letter' ? 'critical' : 'warn';
            $items[] = [
                'source'     => 'qbo-dlq',
                'id'         => (int) $r['id'],
                'tenant_id'  => (int) $r['tenant_id'],
                'severity'   => $sev,
                'summary'    => "QBO push failed — {$r['entity_type']} #{$r['source_id']} ({$r['attempts']}/{$r['max_attempts']} attempts)",
                'playbook'   => $playbook,
                'meta'       => [
                    'entity_type'    => $r['entity_type'],
                    'source_id'      => (int) $r['source_id'],
                    'attempts'       => (int) $r['attempts'],
                    'max_attempts'   => (int) $r['max_attempts'],
                    'status'         => $r['status'],
                    'last_error'     => $r['last_error_message'],
                    'http_status'    => $r['last_http_status'],
                    'vendor_raw'     => $r['vendor_raw'],
                    'next_retry_at'  => $r['next_retry_at'],
                ],
                'created_at' => $r['first_failed_at'],
                'actionable' => $r['status'] === 'dead_letter' ? 'requeue' : null,
            ];
            $counts['by_source']['qbo-dlq']++;
            $counts[$sev]++;
        }
    } catch (\Throwable $_) { /* table missing → skip */ }
}

// ─────── 2. QBO two-way sync drift ───────
if (in_array('qbo-drift', $wanted, true)) {
    try {
        $stmt = $db->prepare(
            "SELECT id, entity_type, coreflux_id, qbo_id, drift_kind, severity, summary,
                    coreflux_snapshot, qbo_snapshot, detected_at, last_seen_at
               FROM qbo_sync_drift
              WHERE tenant_id = :t AND status = 'open'
           ORDER BY (severity='critical') DESC, (severity='warn') DESC, detected_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute(['t' => $tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $sev = $r['severity'] ?: 'warn';
            // Drift rows don't have a vendor error code; we synthesise a
            // playbook-shape stub from drift_kind so the UI renders the
            // same row layout as DLQ/Failed-PI items.
            $playbook = [
                'code'          => $r['drift_kind'],
                'category'      => 'two_way_sync_drift',
                'severity'      => ($sev === 'critical') ? 'fix_data'
                                 : (($sev === 'warn') ? 'fix_data' : 'requeue_safe'),
                'summary'       => $r['summary'] ?: "QBO drift: {$r['drift_kind']}",
                'suggested_fix' => _triageDriftFix($r['drift_kind']),
                'docs_link'     => null,
            ];
            $items[] = [
                'source'     => 'qbo-drift',
                'id'         => (int) $r['id'],
                'tenant_id'  => $tenantId,
                'severity'   => $sev,
                'summary'    => $r['summary'] ?: "QBO drift: {$r['drift_kind']} on {$r['entity_type']}",
                'playbook'   => $playbook,
                'meta'       => [
                    'entity_type'      => $r['entity_type'],
                    'coreflux_id'      => $r['coreflux_id'] !== null ? (int) $r['coreflux_id'] : null,
                    'qbo_id'           => $r['qbo_id'],
                    'drift_kind'       => $r['drift_kind'],
                    'coreflux_snapshot'=> json_decode((string) $r['coreflux_snapshot'], true) ?: null,
                    'qbo_snapshot'     => json_decode((string) $r['qbo_snapshot'],      true) ?: null,
                ],
                'created_at' => $r['detected_at'],
                'actionable' => 'resolve',
            ];
            $counts['by_source']['qbo-drift']++;
            $counts[$sev]++;
        }
    } catch (\Throwable $_) { /* table missing */ }
}

// ─────── 3. QBO merchant-payment exceptions ───────
if (in_array('qbo-payments', $wanted, true)) {
    try {
        $stmt = $db->prepare(
            "SELECT id, tenant_id, qbo_charge_id, charge_type, amount_cents,
                    currency, status, coreflux_invoice_id, coreflux_payment_id,
                    context_token, error_code, error_message, raw_payload,
                    created_at, updated_at
               FROM qbo_payment_charges
              WHERE tenant_id = :t
                AND (
                    error_message IS NOT NULL
                    OR status IN ('DECLINED','FAILED','REFUNDED','VOIDED')
                    OR (
                        status IN ('ISSUED','PENDING','AUTHORIZED')
                        AND created_at < (NOW() - INTERVAL 24 HOUR)
                    )
                )
           ORDER BY updated_at DESC, created_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute(['t' => $tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $status = strtoupper((string) $r['status']);
            $moneyMoved = in_array($status, ['REFUNDED', 'VOIDED'], true)
                && !empty($r['coreflux_payment_id']);
            $sev = $moneyMoved ? 'critical'
                 : (in_array($status, ['DECLINED', 'FAILED'], true) || !empty($r['error_message']) ? 'warn' : 'info');
            $playbook = qboErrorPlaybookLookup($r['error_code'] ?? null);
            $playbook['summary'] = "QBO Payments {$status}";
            $playbook['suggested_fix'] = $moneyMoved
                ? 'This transaction already created a CoreFlux payment but is now refunded or voided in Intuit. Review the invoice and record the reversal before collecting again.'
                : (in_array($status, ['ISSUED', 'PENDING', 'AUTHORIZED'], true)
                    ? 'The transaction has remained pending for more than 24 hours. Check the live status in Intuit and confirm the hourly QBO payments poll is scheduled.'
                    : 'Review the Intuit error and payment method, then retry with a new payment intent only after confirming that no charge was captured.');

            $items[] = [
                'source'    => 'qbo-payments',
                'id'        => (int) $r['id'],
                'tenant_id' => (int) $r['tenant_id'],
                'severity'  => $sev,
                'summary'   => "QBO {$r['charge_type']} {$status} — {$r['qbo_charge_id']} (\$"
                    . number_format(((int) $r['amount_cents']) / 100, 2) . " {$r['currency']})",
                'playbook'  => $playbook,
                'meta'      => [
                    'charge_id'          => $r['qbo_charge_id'],
                    'charge_type'        => $r['charge_type'],
                    'status'             => $status,
                    'invoice_id'         => $r['coreflux_invoice_id'] !== null ? (int) $r['coreflux_invoice_id'] : null,
                    'payment_id'         => $r['coreflux_payment_id'] !== null ? (int) $r['coreflux_payment_id'] : null,
                    'request_id'         => $r['context_token'],
                    'error_code'         => $r['error_code'],
                    'error_message'      => $r['error_message'],
                    'vendor_raw'         => $r['raw_payload'],
                ],
                'created_at' => $r['updated_at'] ?: $r['created_at'],
                'actionable' => null,
            ];
            $counts['by_source']['qbo-payments']++;
            $counts[$sev]++;
        }
    } catch (\Throwable $_) { /* table missing */ }
}

// ─────── 4. Mercury Failed payment instructions ───────
if (in_array('mercury-failed', $wanted, true)) {
    try {
        $stmt = $db->prepare(
            "SELECT id, state, state_reason, recipient_id, amount_cents, currency,
                    source_module, source_ref, state_changed_at, created_at
               FROM payment_instructions
              WHERE tenant_id = :t AND state IN ('Failed','Returned')
           ORDER BY state_changed_at DESC LIMIT {$limit}"
        );
        $stmt->execute(['t' => $tenantId]);
        $piRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($piRows as $pi) {
            // Pull the most recent audit row that carries vendor_raw.
            $vendorMeta = null;
            try {
                $ev = $db->prepare(
                    'SELECT meta_json FROM payment_instruction_audit
                      WHERE tenant_id = :t AND instruction_id = :id AND meta_json LIKE :pat
                   ORDER BY id DESC LIMIT 1'
                );
                $ev->execute(['t' => $tenantId, 'id' => (int) $pi['id'], 'pat' => '%vendor_raw%']);
                $row = $ev->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $vendorMeta = json_decode((string) $row['meta_json'], true) ?: null;
                }
            } catch (\Throwable $_) {}

            $errCode  = $vendorMeta['vendor_error_code'] ?? null;
            $playbook = mercuryErrorPlaybookLookup($errCode);
            // Severity follows the playbook's severity except hard 'critical'
            // codes (compliance / sanctions) — those always escalate.
            $sev = $playbook['severity'] === 'fix_config' ? 'critical'
                 : ($playbook['severity'] === 'requeue_safe' ? 'info' : 'warn');
            $items[] = [
                'source'     => 'mercury-failed',
                'id'         => (int) $pi['id'],
                'tenant_id'  => $tenantId,
                'severity'   => $sev,
                'summary'    => "Mercury payment Failed — {$pi['source_module']} ref {$pi['source_ref']} (\$" . number_format(((int) $pi['amount_cents']) / 100, 2) . ' ' . $pi['currency'] . ')',
                'playbook'   => $playbook,
                'meta'       => [
                    'state'         => $pi['state'],
                    'state_reason'  => $pi['state_reason'],
                    'source_module' => $pi['source_module'],
                    'source_ref'    => $pi['source_ref'],
                    'amount_cents'  => (int) $pi['amount_cents'],
                    'currency'      => $pi['currency'],
                    'vendor'        => $vendorMeta,
                ],
                'created_at' => $pi['state_changed_at'],
                'actionable' => $pi['state'] === 'Failed' ? 'requeue' : null,
            ];
            $counts['by_source']['mercury-failed']++;
            $counts[$sev]++;
        }
    } catch (\Throwable $_) { /* table missing */ }
}

// ─────── 5. Pure//Pay failed or unverified payment releases ───────
if (in_array('purepay-failed', $wanted, true)) {
    try {
        $stmt = $db->prepare(
            "SELECT id, tenant_id, source_ref, core_payment_id, purepay_vendor_id,
                    purepay_bill_id, purepay_payment_id, amount_cents, status,
                    last_error, last_error_json, created_at, updated_at
               FROM purepay_payment_links
              WHERE tenant_id=:t
                AND status IN ('failed','needs_review','pushed_unverified','posted_unverified')
           ORDER BY updated_at DESC, id DESC LIMIT {$limit}"
        );
        $stmt->execute(['t'=>$tenantId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $critical = in_array($r['status'], ['needs_review','posted_unverified'], true);
            $sev = $critical ? 'critical' : 'warn';
            $items[] = [
                'source'=>'purepay-failed','id'=>(int)$r['id'],'tenant_id'=>(int)$r['tenant_id'],
                'severity'=>$sev,
                'summary'=>'Pure//Pay payment ' . $r['status'] . ' — ' . $r['source_ref']
                    . ' ($' . number_format(((int)$r['amount_cents']) / 100, 2) . ')',
                'playbook'=>[
                    'code'=>$r['status'],'category'=>'payment_rail','severity'=>$critical?'manual_review':'fix_data',
                    'summary'=>$r['last_error'] ?: 'Pure//Pay release requires attention',
                    'suggested_fix'=>$critical
                        ? 'Check the bill and payment in Pure//Pay before retrying; the public API does not document idempotency, so an uncertain POST must not be repeated blindly.'
                        : 'Confirm the vendor has completed payout onboarding in Pure//Pay, review the provider error, then retry the CoreFlux payment.',
                    'docs_link'=>'https://purepay.online/developers',
                ],
                'meta'=>[
                    'source_ref'=>$r['source_ref'],'core_payment_id'=>$r['core_payment_id'],
                    'bill_id'=>$r['purepay_bill_id'],'payment_id'=>$r['purepay_payment_id'],
                    'status'=>$r['status'],'last_error'=>$r['last_error'],
                    'vendor_raw'=>$r['last_error_json'],
                ],
                'created_at'=>$r['updated_at'] ?: $r['created_at'],'actionable'=>null,
            ];
            $counts['by_source']['purepay-failed']++;
            $counts[$sev]++;
        }
    } catch (\Throwable $_) { /* migration pending */ }
}

// Order: critical first, then warn, then info; secondary by created_at desc.
usort($items, function ($a, $b) {
    $rank = ['critical' => 0, 'warn' => 1, 'info' => 2];
    $ra = $rank[$a['severity']] ?? 3;
    $rb = $rank[$b['severity']] ?? 3;
    if ($ra !== $rb) return $ra <=> $rb;
    return strcmp((string) $b['created_at'], (string) $a['created_at']);
});

$counts['total'] = count($items);

api_ok([
    'items'        => array_slice($items, 0, $limit),
    'counts'       => $counts,
    'sources'      => $wanted,
    'tenant_id'    => $tenantId,
    'generated_at' => gmdate('c'),
]);

// ─────────────────────────────────────────────────────────────────────
function _triageDriftFix(string $kind): string
{
    static $tbl = [
        'paid_out_of_band' => 'Verify the QBO Payment / BillPayment was for this entity (check linked_invoice_ids / linked_bill_ids in the shadow). If correct, mark Reconciled — the CoreFlux side can be flipped to paid via the billing/AP module.',
        'balance_changed'  => 'QBO shows partial payment. Confirm the customer/vendor and either record the partial in CoreFlux or wait for full settlement.',
        'voided_in_qbo'    => 'Critical — entity was voided in QBO while still active in CoreFlux. Investigate why before any further sync to QBO; you may need to cancel/void in CoreFlux to match.',
        'amount_changed'   => 'Total amount differs between CoreFlux and QBO. Pick a source of truth and align.',
        'qbo_only_orphan'  => 'QBO has an entity with no CoreFlux mapping. Either create the matching CoreFlux row or mark dismissed if it was intentionally created outside CoreFlux.',
    ];
    return $tbl[$kind] ?? 'Investigate the drift via the QBO Sync Drift admin page; resolve manually.';
}
