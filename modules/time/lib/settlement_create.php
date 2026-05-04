<?php
/**
 * Time Settlement — auto-create destination targets.
 *
 * Given a set of approved + un-extracted time entry ids, group them by
 * placement, look up the active rate snapshot per work_date, create one
 * draft AR invoice / AP bill per placement, emit per-day lines, and
 * stamp the time entries — all in one DB transaction.
 *
 * Payroll auto-create is intentionally NOT supported here (it requires
 * an active payroll_run + period assignment + earnings type mapping;
 * use the existing payroll_run.line_items flow + extract by ref).
 */

declare(strict_types=1);

require_once __DIR__ . '/settlement.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';

/**
 * @param int[]  $entryIds      time_entries.id list (approved + un-extracted)
 * @param string $target        'billing' | 'ap'
 * @param ?int   $actorUserId
 * @return array{created: array<int, array>, extracted_count: int}
 *   `created` is keyed by placement_id and contains the new target row
 *   summary (id, total, currency, line_count).
 */
function timeSettlementAutoCreate(array $entryIds, string $target, ?int $actorUserId = null): array
{
    if (!in_array($target, ['billing','ap'], true)) {
        throw new TimeSettlementException('Auto-create supported for billing|ap only (payroll requires existing run line)');
    }
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    if (!$entryIds) throw new TimeSettlementException('No entry ids provided');
    if (count($entryIds) > 5000) throw new TimeSettlementException('Batch limit 5000');

    $tenantId = currentTenantId();
    $pdo      = getDB();
    $place    = implode(',', array_fill(0, count($entryIds), '?'));

    $cols = $target === 'billing'
        ? ['at' => 'bill_extracted_at',    'ref' => 'bill_extracted_ref',    'by' => 'bill_extracted_by_user_id']
        : ['at' => 'ap_extracted_at',      'ref' => 'ap_extracted_ref',      'by' => 'ap_extracted_by_user_id'];

    $pdo->beginTransaction();
    try {
        // 1) Pull entries + placement context, lock with FOR UPDATE.
        $stmt = $pdo->prepare(
            "SELECT te.id, te.placement_id, te.person_id, te.work_date,
                    te.category, te.hours, te.description, te.status,
                    te.{$cols['at']} AS already_at,
                    p.title AS placement_title, p.engagement_type, p.end_client_name
             FROM time_entries te
             LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = te.tenant_id
             WHERE te.tenant_id = ? AND te.id IN ($place)
             FOR UPDATE"
        );
        $stmt->execute(array_merge([$tenantId], $entryIds));
        $entries = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (count($entries) !== count($entryIds)) {
            throw new TimeSettlementException('Some entry ids not found in this tenant');
        }
        foreach ($entries as $e) {
            if ($e['status'] !== 'approved') {
                throw new TimeSettlementException("Entry #{$e['id']} status={$e['status']} (must be approved)");
            }
            if (!empty($e['already_at'])) {
                throw new TimeSettlementException("Entry #{$e['id']} already extracted to $target");
            }
        }

        // 2) Group by placement_id.
        $byPlacement = [];
        foreach ($entries as $e) $byPlacement[(int) $e['placement_id']][] = $e;

        // 3) Per placement: pick a rate snapshot, create the target shell + lines.
        $created = [];
        foreach ($byPlacement as $placementId => $rows) {
            // Find an active rate snapshot covering the earliest work_date.
            $minDate = min(array_column($rows, 'work_date'));
            $rateStmt = $pdo->prepare(
                'SELECT * FROM placement_rates
                 WHERE tenant_id = :t AND placement_id = :p
                   AND effective_from <= :d
                   AND (effective_to IS NULL OR effective_to >= :d)
                   AND superseded_by IS NULL
                 ORDER BY effective_from DESC LIMIT 1'
            );
            $rateStmt->execute(['t' => $tenantId, 'p' => $placementId, 'd' => $minDate]);
            $rate = $rateStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$rate) {
                throw new TimeSettlementException("Placement #$placementId has no active rate snapshot for $minDate");
            }
            $unitPrice = $target === 'billing'
                ? (float) ($rate['adjusted_bill_rate'] ?? $rate['bill_rate'])
                : (float) ($rate['net_to_vendor'] ?? $rate['pay_rate']);
            $currency  = (string) ($rate['currency'] ?? 'USD');

            // Sum + per-day lines.
            $totalHours  = 0.0; $totalAmount = 0.0;
            $lines = [];
            foreach ($rows as $e) {
                $hours = (float) $e['hours'];
                $mult  = ($e['category'] === 'OT_billable' || $e['category'] === 'OT_nonbillable')
                    ? (float) ($rate['ot_multiplier'] ?? 1.5)
                    : 1.0;
                $price = round($unitPrice * $mult, 4);
                $sub   = round($price * $hours, 2);
                $lines[] = [
                    'time_entry_id' => (int) $e['id'],
                    'work_date'     => $e['work_date'],
                    'description'   => trim((string) (($e['placement_title'] ?? '') . ' — ' . $e['work_date']
                                              . ($e['description'] ? ' · ' . $e['description'] : ''))),
                    'category'      => $e['category'],
                    'quantity'      => $hours,
                    'unit_price'    => $price,
                    'subtotal'      => $sub,
                ];
                $totalHours  += $hours;
                $totalAmount += $sub;
            }

            // 4) Create the target shell.
            $entryIdsForPlacement = array_map(fn ($e) => (int) $e['id'], $rows);
            if ($target === 'billing') {
                $clientName = (string) ($rows[0]['end_client_name'] ?? 'Unspecified Client');
                $invoice = [
                    'tenant_id'         => $tenantId,
                    'invoice_number'    => 'TS-' . date('Ymd-His') . '-P' . $placementId,
                    'client_name'       => $clientName,
                    'invoice_date'      => date('Y-m-d'),
                    'due_date'          => date('Y-m-d', strtotime('+30 days')),
                    'currency'          => $currency,
                    'subtotal'          => $totalAmount,
                    'tax_total'         => 0,
                    'total'             => $totalAmount,
                    'amount_paid'       => 0,
                    'amount_due'        => $totalAmount,
                    'status'            => 'draft',
                    'source'            => 'time_settlement',
                    'created_by_user_id'=> $actorUserId,
                ];
                $invId = scopedInsert('billing_invoices', $invoice);
                $i = 1;
                $lstmt = $pdo->prepare(
                    'INSERT INTO billing_invoice_lines
                       (invoice_id, line_no, source_type, item_type, source_ref_id, placement_id,
                        description, quantity, unit, unit_price, subtotal, tax_rate_pct, tax_amount, total)
                     VALUES (?, ?, "time", "time_hourly", ?, ?, ?, ?, "hour", ?, ?, 0, 0, ?)'
                );
                foreach ($lines as $l) {
                    $lstmt->execute([
                        $invId, $i++, $l['time_entry_id'], $placementId, $l['description'],
                        $l['quantity'], $l['unit_price'], $l['subtotal'], $l['subtotal'],
                    ]);
                }
                $created[$placementId] = [
                    'target_id'   => $invId,
                    'kind'        => 'invoice',
                    'invoice_number' => $invoice['invoice_number'],
                    'currency'    => $currency,
                    'total'       => $totalAmount,
                    'line_count'  => count($lines),
                ];
                $targetRefForStamp = $invId;
            } else {  // ap
                $vendorName = $rows[0]['placement_title']
                    ? 'Placement #' . $placementId . ' — ' . $rows[0]['placement_title']
                    : 'Placement #' . $placementId;
                $bill = [
                    'tenant_id'      => $tenantId,
                    'internal_ref'   => 'TS-AP-' . date('Ymd-His') . '-P' . $placementId,
                    'bill_number'    => null,
                    'vendor_name'    => $vendorName,
                    'vendor_type'    => $rows[0]['engagement_type'] === '1099' ? '1099_individual'
                                       : ($rows[0]['engagement_type'] === 'c2c' ? 'c2c' : 'other'),
                    'source'         => 'time_settlement',
                    'placement_id'   => $placementId,
                    'bill_date'      => date('Y-m-d'),
                    'due_date'       => date('Y-m-d', strtotime('+15 days')),
                    'currency'       => $currency,
                    'subtotal'       => $totalAmount,
                    'tax_total'      => 0,
                    'total'          => $totalAmount,
                    'amount_paid'    => 0,
                    'amount_due'     => $totalAmount,
                    'status'         => 'pending_approval',
                    'created_by_user_id' => $actorUserId,
                ];
                $billId = scopedInsert('ap_bills', $bill);
                $created[$placementId] = [
                    'target_id'    => $billId,
                    'kind'         => 'bill',
                    'internal_ref' => $bill['internal_ref'],
                    'currency'     => $currency,
                    'total'        => $totalAmount,
                    'line_count'   => count($lines),
                ];
                $targetRefForStamp = $billId;
            }

            // 5) Stamp this placement's entries with the new target ref.
            $stampPlace = implode(',', array_fill(0, count($entryIdsForPlacement), '?'));
            $upd = $pdo->prepare(
                "UPDATE time_entries
                 SET {$cols['at']} = NOW(),
                     {$cols['ref']} = ?,
                     {$cols['by']}  = ?
                 WHERE tenant_id = ? AND id IN ($stampPlace)"
            );
            $upd->execute(array_merge([$targetRefForStamp, $actorUserId, $tenantId], $entryIdsForPlacement));
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    settlementAudit("time.settlement.auto_extracted_$target", [
        'count' => count($entryIds), 'created' => $created, 'ids' => $entryIds,
    ]);
    return ['created' => $created, 'extracted_count' => count($entryIds)];
}
