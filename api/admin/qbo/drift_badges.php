<?php
/**
 * GET /api/admin/qbo/drift_badges.php?type=bill|invoice&ids=1,2,3
 *
 * Compact batch lookup used by BillsList / InvoicesList to render a
 * "QBO drift" chip next to each CoreFlux row. Returns a map keyed by
 * the CoreFlux id so the React side renders without N+1 round-trips.
 *
 * Response:
 *   {
 *     "items": {
 *        "<coreflux_id>": {
 *           "kind":       "paid_out_of_band" | "balance_changed" | "voided_in_qbo" | ...,
 *           "severity":   "info" | "warn" | "critical",
 *           "summary":    string,
 *           "qbo_balance_cents":  int|null,    // 0 = paid in QBO
 *           "qbo_total_amount_cents": int|null,
 *           "qbo_status":  "Paid" | "PartiallyPaid" | "Open" | null,
 *           "qbo_id":      string,
 *           "last_seen_at": iso string
 *        } | null,
 *        ...
 *     },
 *     "generated_at": iso string
 *   }
 *
 * Only rows with an OPEN drift entry are returned non-null. Rows
 * present in the shadow but with no open drift get a snapshot-only
 * payload (so the UI can still show "in sync with QBO" for clarity).
 *
 * Read-only. Auth-gated (any authenticated user — drift badges should
 * appear for everyone who sees the underlying bill/invoice).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';

$ctx = api_require_auth();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if ($tenantId <= 0) { http_response_code(400); api_error('tenant_id missing', 400); }

$type = $_GET['type'] ?? '';
if (!in_array($type, ['bill', 'invoice'], true)) {
    http_response_code(400);
    api_error("type must be 'bill' or 'invoice'", 400);
}
$idsRaw = (string) ($_GET['ids'] ?? '');
$ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn ($n) => $n > 0));
if (!$ids) {
    api_ok(['items' => (object) [], 'generated_at' => gmdate('c')]);
}
// Cap list size — keeps the IN-clause bounded.
$ids = array_slice($ids, 0, 500);

$shadowTable = $type === 'bill' ? 'qbo_inbound_bills' : 'qbo_inbound_invoices';
$linkColumn  = $type === 'bill' ? 'coreflux_bill_id' : 'coreflux_invoice_id';
$idColumn    = $type === 'bill' ? 'qbo_bill_id'      : 'qbo_invoice_id';

// Build IN(...) placeholders.
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = array_merge([$tenantId], $ids);

// 1) Pull shadow snapshots for every requested id.
$snapshots = [];
try {
    $sql = "SELECT {$linkColumn} AS cf_id, {$idColumn} AS qbo_id,
                   balance_cents, total_amount_cents, qbo_status, last_seen_at
              FROM {$shadowTable}
             WHERE tenant_id = ? AND {$linkColumn} IN ({$placeholders})";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $snapshots[(int) $r['cf_id']] = [
            'qbo_balance_cents'       => (int) $r['balance_cents'],
            'qbo_total_amount_cents'  => (int) $r['total_amount_cents'],
            'qbo_status'              => $r['qbo_status'],
            'qbo_id'                  => $r['qbo_id'],
            'last_seen_at'            => $r['last_seen_at'],
        ];
    }
} catch (\Throwable $_) { /* shadow table missing → keep empty */ }

// 2) Pull open drift rows for the same set.
$drifts = [];
try {
    $sql = "SELECT coreflux_id, drift_kind, severity, summary
              FROM qbo_sync_drift
             WHERE tenant_id = ? AND status = 'open'
               AND entity_type = ? AND coreflux_id IN ({$placeholders})";
    $params2 = array_merge([$tenantId, $type], $ids);
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params2);
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $cfId = (int) $r['coreflux_id'];
        // If multiple open drift rows exist for the same entity, prefer
        // the most severe (critical > warn > info).
        $rank = ['critical' => 0, 'warn' => 1, 'info' => 2];
        $existing = $drifts[$cfId] ?? null;
        if (!$existing || ($rank[$r['severity']] ?? 9) < ($rank[$existing['severity']] ?? 9)) {
            $drifts[$cfId] = [
                'kind'     => $r['drift_kind'],
                'severity' => $r['severity'],
                'summary'  => $r['summary'],
            ];
        }
    }
} catch (\Throwable $_) { /* drift table missing */ }

// 3) Merge into the response map.
$items = [];
foreach ($ids as $id) {
    $snap  = $snapshots[$id] ?? null;
    $drift = $drifts[$id]   ?? null;
    if (!$snap && !$drift) {
        // Not seen in QBO at all — useful for UI to show "not synced".
        $items[(string) $id] = null;
        continue;
    }
    $items[(string) $id] = array_merge(
        ['kind' => null, 'severity' => null, 'summary' => null],
        $drift ?: [],
        $snap  ?: []
    );
}

api_ok([
    'items'        => (object) $items,
    'generated_at' => gmdate('c'),
]);
