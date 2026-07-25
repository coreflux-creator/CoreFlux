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
require_once __DIR__ . '/../../placements/lib/economics.php';
require_once __DIR__ . '/../../ap/lib/ap.php';
require_once __DIR__ . '/../../ap/lib/pwp.php';
require_once __DIR__ . '/../../payroll/lib/cycles.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';

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
    $placementsTenantId = effectiveTenantIdForModule('placements', (int) $tenantId) ?? $tenantId;
    $pdo      = getDB();
    $place    = implode(',', array_fill(0, count($entryIds), '?'));
    timeRepairApprovedRateSnapshots((int) $tenantId, ['ids' => $entryIds], count($entryIds));

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

    $ownsTxn = cf_tx_begin($pdo);
    try {
        // 1) Pull entries + placement context, lock with FOR UPDATE.
        $stmt = $pdo->prepare(
            "SELECT te.id, te.placement_id, te.person_id, te.work_date,
                    te.category, te.hours, te.description, te.status, te.rate_snapshot_id,
                    te.{$cols['at']} AS already_at,
                    p.title AS placement_title, p.engagement_type, p.end_client_name
             FROM time_entries te
             LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = ?
             WHERE te.tenant_id = ? AND te.id IN ($place)
             FOR UPDATE"
        );
        $stmt->execute(array_merge([$placementsTenantId, $tenantId], $entryIds));
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
            if (empty($e['rate_snapshot_id'])) {
                throw new TimeSettlementException("Entry #{$e['id']} is approved but has no locked rate snapshot");
            }
        }

        // 2) Group by placement_id.
        $byPlacement = [];
        foreach ($entries as $e) $byPlacement[(int) $e['placement_id']][] = $e;
        $ratesById = timeRateSnapshotsById(array_column($entries, 'rate_snapshot_id'), $tenantId);

        // 3) Per placement: price each row from its locked rate snapshot.
        $created = [];
        foreach ($byPlacement as $placementId => $rows) {
            // Sum + per-day lines.
            $totalHours  = 0.0; $totalAmount = 0.0; $currency = 'USD';
            $lines = [];
            foreach ($rows as $e) {
                $rateId = (int) ($e['rate_snapshot_id'] ?? 0);
                $rate = $rateId > 0 ? ($ratesById[$rateId] ?? null) : null;
                if (!$rate) {
                    throw new TimeSettlementException("Entry #{$e['id']} references missing rate snapshot #{$rateId}");
                }
                $unitPrice = $target === 'billing'
                    ? (float) ($rate['adjusted_bill_rate'] ?? $rate['bill_rate'] ?? 0)
                    : (float) ($rate['pay_rate'] ?? 0);
                if ($unitPrice <= 0) {
                    $rateField = $target === 'billing' ? 'bill_rate' : 'pay_rate';
                    throw new TimeSettlementException("Entry #{$e['id']} rate snapshot #{$rateId} has no positive {$rateField}");
                }
                $currency = (string) ($rate['currency'] ?? $currency);
                $hours = (float) $e['hours'];
                $mult  = timeRateCategoryMultiplier($rate, (string) $e['category']);
                $price = round($unitPrice * $mult, 4);
                $sub   = round($price * $hours, 2);
                $lines[] = [
                    'time_entry_id' => (int) $e['id'],
                    'rate_snapshot_id' => $rateId,
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
                $receivableContracts = [];
                foreach (array_unique(array_column($lines, 'rate_snapshot_id')) as $rateSnapshotId) {
                    $contract = placementEconomicsReceivableContract(
                        (int) $placementsTenantId,
                        $placementId,
                        true,
                        (int) $rateSnapshotId
                    );
                    $signature = ($contract['client_company_id'] ?? 0)
                        . '|' . $contract['client_name']
                        . '|' . $contract['payment_terms'];
                    $receivableContracts[$signature] = $contract;
                }
                if (count($receivableContracts) !== 1) {
                    throw new TimeSettlementException(
                        "Placement #{$placementId} spans approved contract snapshots with different bill-to terms; settle them separately."
                    );
                }
                $receivable = reset($receivableContracts);
                $issueDate = date('Y-m-d');
                $workDates = array_column($rows, 'work_date');
                sort($workDates);
                $invoice = [
                    'tenant_id'         => $tenantId,
                    'invoice_number'    => 'TS-' . date('Ymd-His') . '-P' . $placementId,
                    'client_name'       => $receivable['client_name'],
                    'client_company_id' => $receivable['client_company_id'],
                    'issue_date'        => $issueDate,
                    'due_date'          => date('Y-m-d', strtotime('+' . (int) $receivable['payment_terms_days'] . ' days', strtotime($issueDate))),
                    'payment_terms'     => $receivable['payment_terms'],
                    'period_start'      => reset($workDates) ?: date('Y-m-d'),
                    'period_end'        => end($workDates) ?: date('Y-m-d'),
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
                        rate_snapshot_id, description, quantity, unit, unit_price, subtotal, tax_rate_pct, tax_amount, total)
                     VALUES (?, ?, "time", "time_hourly", ?, ?, ?, ?, ?, "hour", ?, ?, 0, 0, ?)'
                );
                foreach ($lines as $l) {
                    $lstmt->execute([
                        $invId, $i++, $l['time_entry_id'], $placementId, $l['rate_snapshot_id'], $l['description'],
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
                $drafts = apBuildDraftFromTimeEntries($tenantId, $entryIdsForPlacement, 'per_placement');
                if (!$drafts) throw new TimeSettlementException("Placement #{$placementId} produced no AP obligations.");
                $billIds = [];
                $primaryBillId = null;
                $createdTotal = 0.0;
                $createdLineCount = 0;
                foreach ($drafts as $draft) {
                    $bill = $draft['bill'];
                    $bill['tenant_id'] = $tenantId;
                    $bill['internal_ref'] = apNextInternalRef($tenantId);
                    $bill['bill_number'] = $bill['internal_ref'];
                    $bill['created_by_user_id'] = $actorUserId;
                    $billId = scopedInsert('ap_bills', $bill);
                    $billIds[] = $billId;
                    $hasLabor = false;
                    foreach ($draft['lines'] as $line) {
                        unset($line['_entry_ids']);
                        $line['bill_id'] = $billId;
                        $line['item_type'] = apNormalizeItemType($line['item_type'] ?? null, $line['source_type'] ?? 'time');
                        if ($line['item_type'] === 'labor') $hasLabor = true;
                        $cols = array_keys($line);
                        $params = [];
                        foreach ($cols as $col) $params['v_' . $col] = $line[$col];
                        $pdo->prepare(
                            'INSERT INTO ap_bill_lines (`' . implode('`,`', $cols) . '`)
                             VALUES (:' . implode(',:', array_keys($params)) . ')'
                        )->execute($params);
                    }
                    if ($hasLabor && $primaryBillId === null) $primaryBillId = $billId;
                    foreach ((array) ($draft['obligations'] ?? []) as $obligation) {
                        placementEconomicsRecordObligation(
                            $tenantId, (int) $obligation['placement_id'], (int) $obligation['economic_party_id'],
                            (string) $obligation['source_type'], (int) $obligation['source_ref_id'],
                            array_merge($obligation, ['status' => 'billed', 'ap_bill_id' => $billId])
                        );
                    }
                    $createdTotal += (float) $bill['total'];
                    $createdLineCount += count($draft['lines']);
                }
                $targetRefForStamp = $primaryBillId ?? $billIds[0];
                $created[$placementId] = [
                    'target_id' => $targetRefForStamp,
                    'target_ids' => $billIds,
                    'kind' => 'bill',
                    'currency' => $currency,
                    'total' => round($createdTotal, 2),
                    'line_count' => $createdLineCount,
                ];
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

        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    foreach ($created as &$createdTarget) {
        try {
            if ($target === 'billing') {
                $createdTarget['pwp_links'] = apPwpAutoLinkForArInvoice(
                    (int) $tenantId, (int) $createdTarget['target_id'], $actorUserId
                );
            } else {
                $createdTarget['pwp_links'] = [];
                foreach ((array) ($createdTarget['target_ids'] ?? [$createdTarget['target_id']]) as $billId) {
                    $createdTarget['pwp_links'][] = apPwpAutoLinkForApBill(
                        (int) $tenantId, (int) $billId, $actorUserId
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('[time settlement pwp link] ' . $e->getMessage());
        }
    }
    unset($createdTarget);

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
 * Run/period selection: the placement's payroll operating cycle wins;
 * the employee payroll profile is the fallback for legacy placements.
 * We find that cycle's newest open period (status='draft' or 'open'),
 * then the draft run on that period (creating one if missing).
 *
 * @return array{created: array<int, array>, extracted_count: int, skipped: array}
 */
function _settlementPayrollRunForCycle(\PDO $pdo, int $tenantId, int $cycleId, ?int $actorUserId): ?array
{
    if ($cycleId <= 0) return null;
    $periodSt = $pdo->prepare(
        'SELECT id, period_start, period_end, pay_date, status
           FROM payroll_pay_periods
          WHERE tenant_id = :t AND cycle_id = :c AND status IN ("draft","open")
          ORDER BY period_number DESC LIMIT 1'
    );
    $periodSt->execute(['t' => $tenantId, 'c' => $cycleId]);
    $period = $periodSt->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$period) {
        $advanced = payrollCycleAdvance($cycleId, $actorUserId);
        $periodSt->execute(['t' => $tenantId, 'c' => $cycleId]);
        $period = $periodSt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (!$period && !empty($advanced['period_id'])) {
            $period = ['id' => (int) $advanced['period_id']] + ($advanced['window'] ?? []);
        }
    }
    if (!$period) return null;

    $runSt = $pdo->prepare(
        'SELECT id FROM payroll_runs
          WHERE tenant_id = :t AND pay_period_id = :p AND status = "draft"
          ORDER BY id DESC LIMIT 1'
    );
    $runSt->execute(['t' => $tenantId, 'p' => (int) $period['id']]);
    $runId = (int) $runSt->fetchColumn();
    if (!$runId) {
        $pdo->prepare(
            'INSERT INTO payroll_runs (tenant_id, pay_period_id, status, created_at)
             VALUES (:t, :p, "draft", NOW())'
        )->execute(['t' => $tenantId, 'p' => (int) $period['id']]);
        $runId = (int) $pdo->lastInsertId();
    }
    return ['run_id' => $runId, 'period' => $period, 'cycle_id' => $cycleId];
}

function _settleTimeIntoPayroll(array $entryIds, array $cols, ?int $actorUserId, int $tenantId, \PDO $pdo, string $place): array
{
    require_once __DIR__ . '/../../payroll/lib/payroll.php';
    $peopleTenantId = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;

    $ownsTxn = cf_tx_begin($pdo);
    try {
        // 1. Pull entries + employee resolution in a single trip. We can't
        //    use a hard JOIN because the email/user_id crosswalk is fuzzy,
        //    so we do the resolution in PHP after fetching.
        $stmt = $pdo->prepare(
            "SELECT te.id, te.person_id, te.placement_id, te.work_date,
                    te.category, te.hours, te.description, te.status, te.rate_snapshot_id,
                    te.{$cols['at']} AS already_at,
                    pe.user_id AS person_user_id,
                    pe.email_primary AS person_email,
                    pe.first_name, pe.last_name,
                    p.payroll_cycle_id AS placement_payroll_cycle_id,
                    p.payroll_operating_cycle_id
             FROM time_entries te
             LEFT JOIN people pe ON pe.id = te.person_id AND pe.tenant_id = ?
             LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = ?
             WHERE te.tenant_id = ? AND te.id IN ($place)
             FOR UPDATE"
        );
        $stmt->execute(array_merge([$peopleTenantId, $tenantId, $tenantId], $entryIds));
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
            if (empty($e['rate_snapshot_id'])) {
                throw new TimeSettlementException("Entry #{$e['id']} is approved but has no locked rate snapshot");
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
                            pp.id AS profile_id, pp.cycle_id, pp.schedule_id, pp.work_state,
                            pp.payment_method, pp.pay_type, pp.pay_rate_cents, pp.flsa_class
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
                            pp.id AS profile_id, pp.cycle_id, pp.schedule_id, pp.work_state,
                            pp.payment_method, pp.pay_type, pp.pay_rate_cents, pp.flsa_class
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
        $byEmployee = [];      // employee_id:cycle_id => [ rows... ]
        $skipped    = [];
        foreach ($entries as $e) {
            $pid = (int) $e['person_id'];
            $emp = $employeeByPerson[$pid] ?? null;
            if (!$emp) {
                $skipped[] = ['entry_id' => (int) $e['id'], 'reason' => 'no_matching_employee',
                              'person_id' => $pid, 'email' => $e['person_email']];
                continue;
            }
            $cycleId = (int) ($e['placement_payroll_cycle_id'] ?: $emp['cycle_id'] ?? 0);
            if (empty($emp['profile_id']) || $cycleId <= 0) {
                $skipped[] = ['entry_id' => (int) $e['id'], 'reason' => 'no_payroll_profile_or_cycle',
                              'employee_id' => (int) $emp['employee_id']];
                continue;
            }
            $key = (int) $emp['employee_id'] . ':' . $cycleId;
            $byEmployee[$key]['employee'] = $emp;
            $byEmployee[$key]['cycle_id'] = $cycleId;
            $byEmployee[$key]['entries'][] = $e;
        }

        // 4. For each employee and placement cycle: find/create the draft run.
        $created = [];
        $ratesById = timeRateSnapshotsById(array_column($entries, 'rate_snapshot_id'), $tenantId);
        foreach ($byEmployee as $bucket) {
            $emp = $bucket['employee'];
            $employeeId = (int) $emp['employee_id'];

            // Find newest open period for the employee's cycle.
            $runTarget = _settlementPayrollRunForCycle($pdo, $tenantId, (int) $bucket['cycle_id'], $actorUserId);
            $period = $runTarget['period'] ?? null;
            if (!$period) {
                // No open period — push every entry for this employee to skipped.
                foreach ($bucket['entries'] as $e) {
                    $skipped[] = ['entry_id' => (int) $e['id'], 'reason' => 'no_open_period_in_cycle',
                                  'employee_id' => $employeeId, 'cycle_id' => (int) $bucket['cycle_id']];
                }
                continue;
            }

            // Find/create draft run on that period.
            $runId = (int) $runTarget['run_id'];

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

            // Stamp source hours and economic earnings on the run target.
            // Payroll compute reads those sources and creates the final line.
            $createdKey = $employeeId . ':' . $runId;
            $created[$createdKey] = [
                'run_id'         => $runId,
                'period_id'      => (int) $period['id'],
                'employee_id'    => $employeeId,
                'employee_name'  => trim((string) $emp['legal_first_name'] . ' ' . (string) $emp['legal_last_name']),
                'hours_regular'  => round($hoursReg, 2),
                'hours_overtime' => round($hoursOt, 2),
                'line_count'     => count($bucket['entries']),
                'economic_earnings' => 0,
            ];

            $entriesByPlacement = [];
            foreach ($bucket['entries'] as $entry) $entriesByPlacement[(int) $entry['placement_id']][] = $entry;
            foreach ($entriesByPlacement as $placementId => $placementEntries) {
                $entriesByRate = [];
                foreach ($placementEntries as $entry) {
                    $entriesByRate[(int) $entry['rate_snapshot_id']][] = $entry;
                }
                foreach ($entriesByRate as $rateSnapshotId => $snapshotEntries) {
                    $hours = 0.0; $billAmount = 0.0; $payAmount = 0.0; $workDates = [];
                    foreach ($snapshotEntries as $entry) {
                        $rate = $ratesById[$rateSnapshotId] ?? null;
                        if (!$rate) continue;
                        $entryHours = (float) $entry['hours'];
                        $multiplier = timeRateCategoryMultiplier($rate, (string) $entry['category']);
                        $hours += $entryHours;
                        $billAmount += $entryHours * $multiplier * (float) ($rate['adjusted_bill_rate'] ?? $rate['bill_rate'] ?? 0);
                        $payAmount += $entryHours * $multiplier * (float) ($rate['pay_rate'] ?? 0);
                        $workDates[] = (string) $entry['work_date'];
                    }
                    sort($workDates);
                    $sourceRefId = min(array_map(
                        static fn(array $entry): int => (int) $entry['id'],
                        $snapshotEntries
                    ));
                    $charges = placementEconomicsPayrollCharges(
                        $tenantId,
                        $placementId,
                        $hours,
                        $billAmount,
                        $payAmount,
                        end($workDates) ?: null,
                        $rateSnapshotId
                    );
                    foreach ($charges as $charge) {
                        $recipient = placementEconomicsPayrollEmployee($tenantId, $charge);
                        if (!$recipient) {
                            throw new TimeSettlementException("Payroll recipient {$charge['display_name']} is not linked to a payroll-ready employee");
                        }
                        $recipientCycleId = 0;
                        if (!empty($charge['operating_cycle_id'])) {
                            $cycleSt = $pdo->prepare('SELECT payroll_pay_cycle_id FROM staffing_operating_cycles WHERE tenant_id = :t AND id = :id');
                            $cycleSt->execute(['t' => $tenantId, 'id' => (int) $charge['operating_cycle_id']]);
                            $recipientCycleId = (int) $cycleSt->fetchColumn();
                        }
                        if ($recipientCycleId <= 0) $recipientCycleId = (int) ($recipient['cycle_id'] ?? $bucket['cycle_id']);
                        $recipientRun = _settlementPayrollRunForCycle($pdo, $tenantId, $recipientCycleId, $actorUserId);
                        if (!$recipientRun) throw new TimeSettlementException("No open payroll period for {$charge['display_name']}");
                        placementEconomicsRecordObligation(
                            $tenantId, $placementId, (int) $charge['id'], 'time_bundle', $sourceRefId,
                            [
                                'period_start' => reset($workDates) ?: null,
                                'period_end' => end($workDates) ?: null,
                                'quantity' => $charge['calculation_quantity'],
                                'basis_amount' => $charge['calculation_basis_amount'],
                                'amount' => $charge['calculated_amount'],
                                'currency' => 'USD',
                                'status' => 'payroll',
                                'payroll_ref_id' => (int) $recipientRun['run_id'],
                            ]
                        );
                        $created[$createdKey]['economic_earnings']++;
                    }
                }
            }

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

        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
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
