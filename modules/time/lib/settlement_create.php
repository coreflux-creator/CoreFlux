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
    if (!in_array($target, ['billing','ap','payroll'], true)) {
        throw new TimeSettlementException('Auto-create supported for billing|ap|payroll only');
    }
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    if (!$entryIds) throw new TimeSettlementException('No entry ids provided');
    if (count($entryIds) > 5000) throw new TimeSettlementException('Batch limit 5000');

    $tenantId = currentTenantId();
    $pdo      = getDB();
    $place    = implode(',', array_fill(0, count($entryIds), '?'));

    $cols = match ($target) {
        'billing' => ['at' => 'bill_extracted_at',     'ref' => 'bill_extracted_ref',     'by' => 'bill_extracted_by_user_id'],
        'ap'      => ['at' => 'ap_extracted_at',       'ref' => 'ap_extracted_ref',       'by' => 'ap_extracted_by_user_id'],
        'payroll' => ['at' => 'payroll_extracted_at',  'ref' => 'payroll_extracted_ref',  'by' => 'payroll_extracted_by_user_id'],
    };

    // Payroll has its own grouping (by employee, not placement) and its own
    // target shape (run line item, not invoice/bill). Branch early so the
    // billing/AP path stays tight and focused.
    if ($target === 'payroll') {
        return _settleTimeIntoPayroll($entryIds, $cols, $actorUserId, $tenantId, $pdo, $place);
    }

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
                   AND effective_from <= :d1
                   AND (effective_to IS NULL OR effective_to >= :d2)
                   AND superseded_by IS NULL
                 ORDER BY effective_from DESC LIMIT 1'
            );
            $rateStmt->execute(['t' => $tenantId, 'p' => $placementId, 'd1' => $minDate, 'd2' => $minDate]);
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

/**
 * Payroll branch — group time entries by employee, find/create the active
 * draft run for each employee's pay cycle, and upsert payroll_line_items
 * with hours_regular / hours_overtime.
 *
 * Person → employee crosswalk: time_entries.person_id references the
 * `people` talent-pool table, but payroll lines key off `people_employees`.
 * We resolve via two paths (in order):
 *   1. people_employees.user_id == people.user_id (same auth identity)
 *   2. people_employees.personal_email == people.email_primary (same human)
 * Entries that resolve to no employee are returned in `skipped[]` so the
 * caller can surface them to the user — the rest of the batch still
 * settles atomically.
 *
 * Run/period selection: each employee's payroll_profile carries a
 * cycle_id. We find the cycle's newest open period (status='draft' or
 * 'open'), then the draft run on that period (creating one if missing).
 *
 * @return array{created: array<int, array>, extracted_count: int, skipped: array}
 */
function _settleTimeIntoPayroll(array $entryIds, array $cols, ?int $actorUserId, int $tenantId, \PDO $pdo, string $place): array
{
    require_once __DIR__ . '/../../payroll/lib/payroll.php';

    $pdo->beginTransaction();
    try {
        // 1. Pull entries + employee resolution in a single trip. We can't
        //    use a hard JOIN because the email/user_id crosswalk is fuzzy,
        //    so we do the resolution in PHP after fetching.
        $stmt = $pdo->prepare(
            "SELECT te.id, te.person_id, te.placement_id, te.work_date,
                    te.category, te.hours, te.description, te.status,
                    te.{$cols['at']} AS already_at,
                    pe.user_id AS person_user_id,
                    pe.email_primary AS person_email,
                    pe.first_name, pe.last_name
             FROM time_entries te
             LEFT JOIN people pe ON pe.id = te.person_id AND pe.tenant_id = te.tenant_id
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
                throw new TimeSettlementException("Entry #{$e['id']} already extracted to payroll");
            }
        }

        // 2. Resolve person → employee for the unique person set.
        $personIds = array_values(array_unique(array_map(fn($r) => (int) $r['person_id'], $entries)));
        $personById = [];
        foreach ($entries as $e) $personById[(int) $e['person_id']] = $e;
        $employeeByPerson = []; // person_id => employee row
        foreach ($personIds as $pid) {
            $p = $personById[$pid];
            $row = null;
            if (!empty($p['person_user_id'])) {
                $q = $pdo->prepare(
                    "SELECT e.id AS employee_id, e.employee_number, e.legal_first_name, e.legal_last_name,
                            pp.id AS profile_id, pp.cycle_id, pp.pay_type, pp.pay_rate_cents,
                            pp.flsa_class
                     FROM people_employees e
                     LEFT JOIN payroll_profiles pp ON pp.tenant_id = e.tenant_id AND pp.employee_id = e.id
                     WHERE e.tenant_id = ? AND e.user_id = ? AND e.status = 'active' LIMIT 1"
                );
                $q->execute([$tenantId, $p['person_user_id']]);
                $row = $q->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
            if (!$row && !empty($p['person_email'])) {
                $q = $pdo->prepare(
                    "SELECT e.id AS employee_id, e.employee_number, e.legal_first_name, e.legal_last_name,
                            pp.id AS profile_id, pp.cycle_id, pp.pay_type, pp.pay_rate_cents,
                            pp.flsa_class
                     FROM people_employees e
                     LEFT JOIN payroll_profiles pp ON pp.tenant_id = e.tenant_id AND pp.employee_id = e.id
                     WHERE e.tenant_id = ? AND e.personal_email = ? AND e.status = 'active' LIMIT 1"
                );
                $q->execute([$tenantId, $p['person_email']]);
                $row = $q->fetch(\PDO::FETCH_ASSOC) ?: null;
            }
            if ($row) $employeeByPerson[$pid] = $row;
        }

        // 3. Bucket entries → employee. Entries with no employee resolution
        //    or no payroll_profile go into `skipped`.
        $byEmployee = [];      // employee_id => [ rows... ]
        $skipped    = [];
        foreach ($entries as $e) {
            $pid = (int) $e['person_id'];
            $emp = $employeeByPerson[$pid] ?? null;
            if (!$emp) {
                $skipped[] = ['entry_id' => (int) $e['id'], 'reason' => 'no_matching_employee',
                              'person_id' => $pid, 'email' => $e['person_email']];
                continue;
            }
            if (empty($emp['profile_id']) || empty($emp['cycle_id'])) {
                $skipped[] = ['entry_id' => (int) $e['id'], 'reason' => 'no_payroll_profile_or_cycle',
                              'employee_id' => (int) $emp['employee_id']];
                continue;
            }
            $byEmployee[(int) $emp['employee_id']]['employee'] = $emp;
            $byEmployee[(int) $emp['employee_id']]['entries'][] = $e;
        }

        // 4. For each employee bucket: find/create draft run, upsert line item.
        $created = [];
        foreach ($byEmployee as $employeeId => $bucket) {
            $emp = $bucket['employee'];

            // Find newest open period for the employee's cycle.
            $perStmt = $pdo->prepare(
                "SELECT id, period_start, period_end, pay_date, status
                 FROM payroll_pay_periods
                 WHERE tenant_id = :t AND cycle_id = :c
                   AND status IN ('draft','open')
                 ORDER BY period_number DESC LIMIT 1"
            );
            $perStmt->execute(['t' => $tenantId, 'c' => (int) $emp['cycle_id']]);
            $period = $perStmt->fetch(\PDO::FETCH_ASSOC);
            if (!$period) {
                // No open period — push every entry for this employee to skipped.
                foreach ($bucket['entries'] as $e) {
                    $skipped[] = ['entry_id' => (int) $e['id'], 'reason' => 'no_open_period_in_cycle',
                                  'employee_id' => $employeeId, 'cycle_id' => (int) $emp['cycle_id']];
                }
                continue;
            }

            // Find/create draft run on that period.
            $runStmt = $pdo->prepare(
                "SELECT id FROM payroll_runs
                 WHERE tenant_id = :t AND pay_period_id = :p AND status = 'draft'
                 ORDER BY id DESC LIMIT 1"
            );
            $runStmt->execute(['t' => $tenantId, 'p' => (int) $period['id']]);
            $runRow = $runStmt->fetch(\PDO::FETCH_ASSOC);
            if ($runRow) {
                $runId = (int) $runRow['id'];
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO payroll_runs (tenant_id, pay_period_id, status, created_at)
                     VALUES (:t, :p, "draft", NOW())'
                );
                $ins->execute(['t' => $tenantId, 'p' => (int) $period['id']]);
                $runId = (int) $pdo->lastInsertId();
            }

            // Aggregate hours_regular vs hours_overtime by category.
            $hoursReg = 0.0; $hoursOt = 0.0;
            foreach ($bucket['entries'] as $e) {
                $cat = strtolower((string) $e['category']);
                if (str_contains($cat, 'overtime') || $cat === 'ot') {
                    $hoursOt += (float) $e['hours'];
                } else {
                    $hoursReg += (float) $e['hours'];
                }
            }

            // Upsert payroll_line_items (UQ on run_id+employee_id) — add to
            // existing hours if a line already exists from a prior settlement.
            $upsert = $pdo->prepare(
                'INSERT INTO payroll_line_items
                    (tenant_id, run_id, employee_id, pay_type, pay_rate_cents,
                     hours_regular, hours_overtime, gross_cents, created_at)
                 VALUES (:t, :r, :e, :pt, :rate, :hr, :ho, 0, NOW())
                 ON DUPLICATE KEY UPDATE
                    hours_regular  = hours_regular  + VALUES(hours_regular),
                    hours_overtime = hours_overtime + VALUES(hours_overtime)'
            );
            $upsert->execute([
                't' => $tenantId, 'r' => $runId, 'e' => $employeeId,
                'pt' => $emp['pay_type'] ?? 'hourly',
                'rate' => (int) ($emp['pay_rate_cents'] ?? 0),
                'hr' => round($hoursReg, 2), 'ho' => round($hoursOt, 2),
            ]);

            $created[$employeeId] = [
                'run_id'         => $runId,
                'period_id'      => (int) $period['id'],
                'employee_id'    => $employeeId,
                'employee_name'  => trim((string) $emp['legal_first_name'] . ' ' . (string) $emp['legal_last_name']),
                'hours_regular'  => round($hoursReg, 2),
                'hours_overtime' => round($hoursOt, 2),
                'line_count'     => count($bucket['entries']),
            ];

            // Stamp the entries we just settled.
            $ids = array_map(fn($e) => (int) $e['id'], $bucket['entries']);
            $stampPlace = implode(',', array_fill(0, count($ids), '?'));
            $stampRef   = "payroll:run#$runId";
            $stamp = $pdo->prepare(
                "UPDATE time_entries
                 SET {$cols['at']} = NOW(),
                     {$cols['ref']} = ?,
                     {$cols['by']}  = ?
                 WHERE tenant_id = ? AND id IN ($stampPlace)"
            );
            $stamp->execute(array_merge([$stampRef, $actorUserId, $tenantId], $ids));
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stamped = array_sum(array_map(fn($c) => $c['line_count'], $created));
    settlementAudit('time.settlement.auto_extracted_payroll', [
        'count'        => $stamped,
        'created'      => $created,
        'skipped_count'=> count($skipped),
        'ids'          => $entryIds,
    ]);
    return [
        'created'         => $created,
        'extracted_count' => $stamped,
        'skipped'         => $skipped,
    ];
}
