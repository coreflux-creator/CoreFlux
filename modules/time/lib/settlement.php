<?php
/**
 * Time Settlement Engine
 *
 * Decouples Time → Billing/AP/Payroll from period close. Approved time
 * entries are extracted independently to each downstream target and
 * stamped with target-specific flags so they can never be double-counted.
 *
 * Public API:
 *   timeSettlementReady(target, filters)     — list approved + un-extracted entries
 *   timeSettlementExtract(ids, target, ref)  — atomically mark a batch as extracted
 *   timeSettlementUnExtract(ids, target, why) — undo for corrections
 *   timeSettlementCycleSuggestion(cycle, anchor, asOf) — advisory cycle window
 *
 * Targets:
 *   'billing'  — entries → AR invoice (placement.client_bill_cycle default)
 *   'ap'      — entries → AP bill    (placement.vendor_pay_cycle default)
 *   'payroll' — entries → payroll line item (payroll_pay_schedules.frequency default)
 *
 * Period close:
 *   *Never* checked. A day is extractable iff
 *     status='approved' AND <target>_extracted_at IS NULL.
 *   period_id on the entry is informational only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/time.php';

const TIME_SETTLEMENT_TARGETS = ['billing','ap','payroll'];

class TimeSettlementException extends \RuntimeException {}

function _settlementColumns(string $target): array
{
    if (!in_array($target, TIME_SETTLEMENT_TARGETS, true)) {
        throw new TimeSettlementException("Invalid target: $target");
    }
    $prefix = $target === 'billing' ? 'bill' : $target;   // bill_* / ap_* / payroll_*
    return [
        'at'      => $prefix . '_extracted_at',
        'ref'     => $prefix . '_extracted_ref',
        'by_user' => $prefix . '_extracted_by_user_id',
    ];
}

/**
 * List approved + un-extracted time entries grouped by placement × work_date,
 * for a single target. Each row represents one extractable day-block.
 *
 * @param array{placement_id?:int, person_id?:int, from?:string, to?:string,
 *              client_id?:int, vendor_id?:int} $filters
 */
function timeSettlementReady(string $target, array $filters = []): array
{
    $cols = _settlementColumns($target);
    $where  = ["te.tenant_id = :tenant_id", "te.status = 'approved'", "te.{$cols['at']} IS NULL"];
    $params = ['tenant_id' => currentTenantId()];

    if (!empty($filters['placement_id'])) {
        $where[] = 'te.placement_id = :placement_id';
        $params['placement_id'] = (int) $filters['placement_id'];
    }
    if (!empty($filters['person_id'])) {
        $where[] = 'te.person_id = :person_id';
        $params['person_id'] = (int) $filters['person_id'];
    }
    if (!empty($filters['from'])) {
        $where[] = 'te.work_date >= :from';
        $params['from'] = $filters['from'];
    }
    if (!empty($filters['to'])) {
        $where[] = 'te.work_date <= :to';
        $params['to'] = $filters['to'];
    }

    // Only consider categories relevant to each target.
    if ($target === 'billing') {
        $where[] = "te.category IN ('regular_billable','OT_billable')";
    } elseif ($target === 'ap') {
        $where[] = "te.category IN ('regular_billable','OT_billable')";
    } else {  // payroll
        $where[] = "te.category NOT IN ('unpaid_leave')";
    }

    $sql = "SELECT te.id, te.placement_id, te.person_id, te.work_date,
                   te.category, te.hours, te.description, te.status,
                   te.period_id, te.approved_at,
                   p.client_bill_cycle, p.client_bill_cycle_anchor,
                   p.vendor_pay_cycle, p.vendor_pay_cycle_anchor
            FROM time_entries te
            LEFT JOIN placements p ON p.id = te.placement_id AND p.tenant_id = te.tenant_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY te.placement_id, te.work_date, te.id";

    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
}

/**
 * Atomically mark a set of time entry ids as extracted to a target.
 * All-or-nothing: any guard violation rolls back the whole batch.
 *
 * Guards:
 *   - All ids belong to this tenant.
 *   - Every entry status='approved'.
 *   - No entry already extracted to this target.
 *
 * @return array{extracted_count:int, ids:int[]}
 */
function timeSettlementExtract(array $entryIds, string $target, int $targetRef, ?int $actorUserId = null): array
{
    $cols = _settlementColumns($target);
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    if (!$entryIds) throw new TimeSettlementException('No entry ids provided');
    if (count($entryIds) > 5000) throw new TimeSettlementException('Batch limit 5000');
    if ($targetRef <= 0) throw new TimeSettlementException('target_ref must be positive');

    $tenantId = currentTenantId();
    $pdo = getDB();
    $place = implode(',', array_fill(0, count($entryIds), '?'));
    $beforeRows = [];
    $afterRows = [];

    $ownsTxn = cf_tx_begin($pdo);
    try {
        // Lock + validate the batch.
        $stmt = $pdo->prepare(
            "SELECT id, status, {$cols['at']} AS already_at, {$cols['ref']} AS already_ref
             FROM time_entries
             WHERE tenant_id = ? AND id IN ($place)
             FOR UPDATE"
        );
        $stmt->execute(array_merge([$tenantId], $entryIds));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) !== count($entryIds)) {
            throw new TimeSettlementException('Some entry ids not found in this tenant');
        }
        foreach ($rows as $r) {
            if ($r['status'] !== 'approved') {
                throw new TimeSettlementException("Entry #{$r['id']} status={$r['status']} (must be approved)");
            }
            if (!empty($r['already_at'])) {
                throw new TimeSettlementException("Entry #{$r['id']} already extracted to $target (ref={$r['already_ref']})");
            }
        }
        $beforeRows = timeSettlementAuditRowsForTenant($tenantId, $entryIds);

        // Stamp the batch.
        $upd = $pdo->prepare(
            "UPDATE time_entries
             SET {$cols['at']}      = NOW(),
                 {$cols['ref']}     = ?,
                 {$cols['by_user']} = ?
             WHERE tenant_id = ? AND id IN ($place)"
        );
        $upd->execute(array_merge([$targetRef, $actorUserId, $tenantId], $entryIds));
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    settlementAudit("time.settlement.extracted_$target", [
        'count' => count($entryIds), 'target' => $target, 'target_ref' => $targetRef, 'ids' => $entryIds,
    ], null, [
        'tenant_id' => $tenantId,
        'actor_user_id' => $actorUserId,
        'before' => $beforeRows,
        'after' => $afterRows,
    ]);
    return ['extracted_count' => count($entryIds), 'ids' => $entryIds];
}

/**
 * Reverse an extract — for corrections (e.g. invoice voided, payroll re-run).
 * Clears the target-specific stamp + ref so the day is eligible again.
 */
function timeSettlementUnExtract(array $entryIds, string $target, string $reason, ?int $actorUserId = null): array
{
    if (trim($reason) === '') throw new TimeSettlementException('reason required for un-extract');
    $cols = _settlementColumns($target);
    $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds))));
    if (!$entryIds) throw new TimeSettlementException('No entry ids provided');

    $tenantId = currentTenantId();
    $pdo = getDB();
    $place = implode(',', array_fill(0, count($entryIds), '?'));
    $beforeRows = [];
    $afterRows = [];

    $ownsTxn = cf_tx_begin($pdo);
    try {
        $stmt = $pdo->prepare(
            "SELECT id, {$cols['at']} AS already_at FROM time_entries
             WHERE tenant_id = ? AND id IN ($place) FOR UPDATE"
        );
        $stmt->execute(array_merge([$tenantId], $entryIds));
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) !== count($entryIds)) {
            throw new TimeSettlementException('Some entry ids not found');
        }
        foreach ($rows as $r) {
            if (empty($r['already_at'])) {
                throw new TimeSettlementException("Entry #{$r['id']} is not extracted to $target");
            }
        }
        $beforeRows = timeSettlementAuditRowsForTenant($tenantId, $entryIds);
        $upd = $pdo->prepare(
            "UPDATE time_entries
             SET {$cols['at']} = NULL, {$cols['ref']} = NULL, {$cols['by_user']} = NULL
             WHERE tenant_id = ? AND id IN ($place)"
        );
        $upd->execute(array_merge([$tenantId], $entryIds));
        cf_tx_commit($pdo, $ownsTxn);
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        throw $e;
    }

    settlementAudit("time.settlement.unextracted_$target", [
        'count' => count($entryIds), 'target' => $target, 'reason' => $reason, 'ids' => $entryIds,
    ], null, [
        'tenant_id' => $tenantId,
        'actor_user_id' => $actorUserId,
        'before' => $beforeRows,
        'after' => $afterRows,
    ]);
    return ['unextracted_count' => count($entryIds), 'ids' => $entryIds];
}

/**
 * Compute the advisory cycle window containing $asOf, given a cycle name +
 * anchor date. Pure function — no DB. Used by the UI to group ready-to-
 * extract days into cycle chunks (Mon-Sun for weekly, etc.).
 *
 * Returns ['from' => 'YYYY-MM-DD', 'to' => 'YYYY-MM-DD', 'label' => '...']
 * For 'adhoc' returns just the as_of day as a 1-day window.
 */
function timeSettlementCycleSuggestion(string $cycle, ?string $anchorDate, string $asOf): array
{
    $asOfTs = strtotime($asOf);
    if ($asOfTs === false) throw new TimeSettlementException('Invalid as_of date');
    $anchorTs = $anchorDate ? strtotime($anchorDate) : null;

    switch ($cycle) {
        case 'weekly': {
            // Anchor = first day of cycle (e.g. Monday). Find the cycle window containing $asOf.
            $start = $anchorTs ?? strtotime('monday this week', $asOfTs);
            $diffDays = (int) floor(($asOfTs - $start) / 86400);
            $cycleStart = $start + (intdiv($diffDays, 7) * 7 * 86400);
            $cycleEnd   = $cycleStart + (6 * 86400);
            return [
                'from'  => date('Y-m-d', $cycleStart),
                'to'    => date('Y-m-d', $cycleEnd),
                'label' => 'Week of ' . date('M j', $cycleStart),
            ];
        }
        case 'biweekly': {
            $start = $anchorTs ?? strtotime('monday this week -1 week', $asOfTs);
            $diffDays = (int) floor(($asOfTs - $start) / 86400);
            $cycleStart = $start + (intdiv($diffDays, 14) * 14 * 86400);
            $cycleEnd   = $cycleStart + (13 * 86400);
            return [
                'from'  => date('Y-m-d', $cycleStart),
                'to'    => date('Y-m-d', $cycleEnd),
                'label' => 'Bi-week ' . date('M j', $cycleStart) . ' – ' . date('M j', $cycleEnd),
            ];
        }
        case 'semimonthly': {
            $day = (int) date('j', $asOfTs);
            $month = (int) date('n', $asOfTs);
            $year  = (int) date('Y', $asOfTs);
            if ($day <= 15) {
                return ['from' => sprintf('%04d-%02d-01', $year, $month),
                        'to'   => sprintf('%04d-%02d-15', $year, $month),
                        'label' => date('M', $asOfTs) . ' 1 – 15'];
            }
            $last = (int) date('t', $asOfTs);
            return ['from' => sprintf('%04d-%02d-16', $year, $month),
                    'to'   => sprintf('%04d-%02d-%02d', $year, $month, $last),
                    'label' => date('M', $asOfTs) . " 16 – $last"];
        }
        case 'monthly': {
            $year = (int) date('Y', $asOfTs);
            $mon  = (int) date('n', $asOfTs);
            return [
                'from'  => sprintf('%04d-%02d-01', $year, $mon),
                'to'    => sprintf('%04d-%02d-%02d', $year, $mon, (int) date('t', $asOfTs)),
                'label' => date('F Y', $asOfTs),
            ];
        }
        case 'adhoc':
        default: {
            return [
                'from'  => date('Y-m-d', $asOfTs),
                'to'    => date('Y-m-d', $asOfTs),
                'label' => 'Ad-hoc — ' . date('M j', $asOfTs),
            ];
        }
    }
}

function timeSettlementAuditRowsForTenant(int $tenantId, array $entryIds): array
{
    $entryIds = array_values(array_unique(array_filter(
        array_map('intval', $entryIds),
        static fn (int $id): bool => $id > 0
    )));
    if (!$entryIds) return [];

    $place = implode(',', array_fill(0, count($entryIds), '?'));
    $stmt = getDB()->prepare(
        "SELECT *
         FROM time_entries
         WHERE tenant_id = ? AND id IN ($place)
         ORDER BY id ASC"
    );
    $stmt->execute(array_merge([$tenantId], $entryIds));
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function settlementAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = []): void
{
    try {
        $auditOpts = array_merge([
            'object_type' => 'time_settlement',
            'source' => $meta['source'] ?? 'time',
        ], $opts);
        if (!array_key_exists('tenant_id', $auditOpts) && function_exists('currentTenantId')) {
            $auditOpts['tenant_id'] = currentTenantId();
        }
        if (!array_key_exists('actor_user_id', $auditOpts) && isset($_SESSION['user']['id'])) {
            $auditOpts['actor_user_id'] = (int) $_SESSION['user']['id'];
        }
        timeAudit($event, $meta, $targetId, $auditOpts);
    } catch (\Throwable $e) {
        error_log('[time.settlement.audit] ' . $event . ' write-failed: ' . $e->getMessage());
    }
}
