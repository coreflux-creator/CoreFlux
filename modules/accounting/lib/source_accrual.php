<?php
declare(strict_types=1);

require_once __DIR__ . '/multi_period.php';

/**
 * Resolve the accrual actually posted for a generated document line. A feature
 * switch or a source label is not evidence of recognition. One-time items and
 * manual charges have no time accrual; mixed documents keep their own coding.
 * Returns clearing parts with the original journal dimensions, or [] when the
 * source has never accrued. Incomplete/reversed sources must be repaired first.
 */
function accountingSourceAccrualParts(int $tenantId, array $line, string $side, int $documentId, int $entityId): array
{
    if (!in_array($side, ['ar', 'ap'], true)) throw new InvalidArgumentException('Invalid accrual side');
    $type = (string) ($line['source_type'] ?? 'manual');
    if (!in_array($type, ['time', 'time_entry'], true)) return [];
    $sourceId = (int) ($line['source_ref_id'] ?? 0);
    $placementId = (int) ($line['placement_id'] ?? 0);
    $pdo = getDB();
    $settings = accountingSettingsGet($tenantId);
    $accountingTenantId = effectiveTenantIdForModule('accounting', $tenantId) ?? $tenantId;
    if ($accountingTenantId !== $tenantId) {
        throw new RuntimeException('Post this staffing document in the accounting tenant that owns its accrual.');
    }
    $codes = [$side === 'ar' ? '1150' : '2050', $settings[$side === 'ar' ? 'ar_unbilled_account_code' : 'ap_accrued_account_code']];
    $readJournal = static function (int $journalId) use ($pdo, $tenantId, $entityId, $codes, $side): array {
        $stmt = $pdo->prepare('SELECT je.status, je.entity_id, a.code AS account_code, l.debit, l.credit, l.dim_json
            FROM accounting_journal_entries je
            JOIN accounting_journal_entry_lines l ON l.je_id = je.id
            JOIN accounting_accounts a ON a.id = l.account_id AND a.tenant_id = je.tenant_id
            WHERE je.tenant_id = :t AND je.id = :id');
        $stmt->execute(['t' => $tenantId, 'id' => $journalId]);
        $parts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['status'] !== 'posted' || (int) $row['entity_id'] !== $entityId) {
                throw new RuntimeException('The source accrual is reversed, unposted, or belongs to another legal entity.');
            }
            if (!in_array((string) $row['account_code'], $codes, true)) continue;
            $amount = $side === 'ar' ? (float) $row['debit'] - (float) $row['credit'] : (float) $row['credit'] - (float) $row['debit'];
            if ($amount <= 0.005) continue;
            $parts[] = ['account_code' => (string) $row['account_code'], 'amount' => $amount,
                'dims' => json_decode((string) $row['dim_json'], true) ?: []];
        }
        if (!$parts) throw new RuntimeException('The source journal has no supported unbilled accrual to clear. Review its posting rule.');
        return $parts;
    };

    if ($type === 'time') {
        $stmt = $pdo->prepare('SELECT * FROM time_downstream_feed WHERE tenant_id = :t AND id = :id AND placement_id = :p AND bundle_type = :side');
        $stmt->execute(['t' => $tenantId, 'id' => $sourceId, 'p' => $placementId, 'side' => $side]);
        $bundle = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$bundle) throw new RuntimeException('The time bundle for this document line is unavailable.');
        $ids = array_map('intval', (array) (json_decode((string) $bundle['entries_json'], true)['entry_ids'] ?? []));
        // Older period accruals have their own journal identities. Read those
        // journals even if the current period-splitting setting is now off.
        $stmt = $pdo->prepare('SELECT id FROM accounting_journal_entries WHERE tenant_id = :t
            AND source_module = "time" AND source_ref_type = "time_bundle" AND source_ref_id = :id');
        $stmt->execute(['t' => $tenantId, 'id' => $sourceId]);
        $legacyParts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $journalId) {
            foreach ($readJournal((int) $journalId) as $part) $legacyParts[] = $part;
        }
        if ($legacyParts) {
            if (abs(array_sum(array_column($legacyParts, 'amount')) - (float) $line['subtotal']) > 0.02) {
                throw new RuntimeException('The document amount no longer agrees with its posted time accrual.');
            }
            return $legacyParts;
        }
        if (!$ids) throw new RuntimeException('The time bundle has no entry trail to verify its accrual.');
        $stmt = $pdo->prepare('SELECT te.*, p.engagement_type FROM time_entries te
            JOIN placements p ON p.id = te.placement_id AND p.tenant_id = ?
            WHERE te.tenant_id = ? AND te.placement_id = ? AND te.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute([effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId, $tenantId, $placementId, ...$ids]);
    } else {
        $extractedRef = $side === 'ar' ? 'bill_extracted_ref' : 'ap_extracted_ref';
        $lineTable = $side === 'ar' ? 'billing_invoice_lines' : 'ap_bill_lines';
        $documentColumn = $side === 'ar' ? 'invoice_id' : 'bill_id';
        // A generated line can aggregate several entries on the same work day.
        // Recover all linked rows, rather than trusting only its primary ref.
        $stmt = $pdo->prepare('SELECT te.*, p.engagement_type FROM time_entries primary_entry
            JOIN time_entries te ON te.tenant_id = primary_entry.tenant_id
              AND te.placement_id = primary_entry.placement_id AND te.work_date = primary_entry.work_date
              AND te.hour_type = primary_entry.hour_type
              AND te.rate_snapshot_id <=> primary_entry.rate_snapshot_id
              AND te.dimension_snapshot_hash <=> primary_entry.dimension_snapshot_hash
              AND te.' . $extractedRef . ' = :document_id
              AND (te.id = primary_entry.id OR te.id NOT IN (
                  SELECT source_ref_id FROM ' . $lineTable . ' WHERE ' . $documentColumn . ' = :other_document_id
                  AND source_type = "time_entry" AND source_ref_id IS NOT NULL))
            JOIN placements p ON p.id = te.placement_id AND p.tenant_id = :placements_tid
            WHERE primary_entry.tenant_id = :t AND primary_entry.id = :id AND primary_entry.placement_id = :p');
        $stmt->execute(['document_id' => $documentId, 'other_document_id' => $documentId, 'placements_tid' => effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId,
            't' => $tenantId, 'id' => $sourceId, 'p' => $placementId]);
    }
    $entries = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
        static fn(array $entry): bool => (int) $entry[$side === 'ar' ? 'billable' : 'payable'] === 1));
    if (!$entries) throw new RuntimeException('The approved time behind this document line is unavailable.');
    if (abs(array_sum(array_column($entries, 'hours')) - (float) $line['quantity']) > 0.0001) {
        throw new RuntimeException('The document line combines different approved rate or dimension snapshots. Rebuild its draft from approved time.');
    }
    $eventStmt = $pdo->prepare('SELECT status, journal_entry_id FROM accounting_events WHERE tenant_id = :t
        AND source_module = "staffing" AND event_type = "staffing.worker_hours.approved"
        AND source_record_id IN (:segment, :assignment, :legacy)');
    $parts = [];
    $missing = 0;
    $rateStmt = $pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id = :t AND id = :id');
    foreach ($entries as $entry) {
        $snapshot = json_decode((string) ($entry['dimension_snapshot_json'] ?? ''), true) ?: [];
        $engagement = (string) ($snapshot['engagement_type'] ?? $entry['engagement_type']);
        $base = 'timesheet:' . $entry['timesheet_id'] . ':placement:' . $entry['placement_id'] . ':' . $engagement;
        $eventStmt->execute(['t' => $tenantId, 'segment' => $base . ':segment:' . substr((string) ($entry['dimension_snapshot_hash'] ?? ''), 0, 24),
            'assignment' => $base, 'legacy' => $entry['timesheet_id'] . ':' . $engagement]);
        $events = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$events) {
            if ($type === 'time_entry') {
                $legacyCheck = $pdo->prepare('SELECT je.id FROM time_downstream_feed bundle
                    JOIN accounting_journal_entries je ON je.tenant_id = bundle.tenant_id
                      AND je.source_module = "time" AND je.source_ref_type = "time_bundle" AND je.source_ref_id = bundle.id
                    WHERE bundle.tenant_id = :t AND bundle.period_id = :period AND bundle.placement_id = :p
                      AND bundle.bundle_type = :side LIMIT 1');
                $legacyCheck->execute(['t' => $tenantId, 'period' => $entry['period_id'], 'p' => $placementId, 'side' => $side]);
                if ($legacyCheck->fetchColumn()) throw new RuntimeException('This time was accrued as a period bundle. Rebuild the document from that bundle.');
            }
            $missing++;
            continue;
        }
        if (count($events) !== 1 || $events[0]['status'] !== 'posted') {
            throw new RuntimeException('Staffing recognition is incomplete or ambiguous. Repair the source event before posting.');
        }
        $journalParts = $readJournal((int) $events[0]['journal_entry_id']);
        if (count($journalParts) !== 1) throw new RuntimeException('The staffing accrual has multiple clearing dimensions; review the source journal.');
        $part = $journalParts[0];
        $rateStmt->execute(['t' => effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId, 'id' => $entry['rate_snapshot_id']]);
        $rate = $rateStmt->fetch(PDO::FETCH_ASSOC);
        if (!$rate) throw new RuntimeException('An approved rate snapshot is missing from this source.');
        $multiplier = match ((string) $entry['hour_type']) {
            'overtime' => (float) ($rate['ot_multiplier'] ?? 1.5),
            'doubletime' => (float) ($rate['dt_multiplier'] ?? 2),
            default => 1.0,
        };
        $unitRate = $side === 'ar' ? (float) ($rate['adjusted_bill_rate'] ?? max(0,
            (float) $rate['bill_rate'] * (1 + (float) ($rate['bill_adder_pct'] ?? 0) - (float) ($rate['bill_discount_pct'] ?? 0))
            + (float) ($rate['bill_adder_flat'] ?? 0) - (float) ($rate['bill_discount_flat'] ?? 0))) : (float) $rate['pay_rate'];
        if ($side === 'ap' && $engagement === 'referral') {
            $unitRate = (float) ($snapshot['referral_fee_per_hour'] ?? 0);
            $multiplier = 1.0;
        }
        $part['weight'] = (float) $entry['hours'] * $unitRate * $multiplier;
        $parts[] = $part;
    }
    if (!$parts) return [];
    if ($missing) throw new RuntimeException('Time on this line is only partly accrued. Repair the missing staffing event before posting.');
    // A line is grouped by one frozen rate; allocate its exact cents over the
    // original dimensions, letting the last entry absorb rounding.
    $quantity = array_sum(array_column($parts, 'weight'));
    if ($quantity <= 0) throw new RuntimeException('The approved source has no billable or payable value to clear.');
    $allocated = 0.0;
    foreach ($parts as $index => &$part) {
        $part['amount'] = $index === count($parts) - 1 ? round((float) $line['subtotal'] - $allocated, 2)
            : round((float) $line['subtotal'] * $part['weight'] / $quantity, 2);
        $allocated += $part['amount'];
        unset($part['weight']);
    }
    unset($part);
    return $parts;
}
