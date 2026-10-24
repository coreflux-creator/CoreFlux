<?php
/** MySQL-backed approval-to-ledger regression, run only in the CI sim tenant. */
declare(strict_types=1);

require_once __DIR__ . '/../modules/staffing/lib/timesheets.php';
require_once __DIR__ . '/../modules/staffing/lib/posting_rules_seed.php';

$tenantId = (int) (getenv('SIM_TENANT_ID') ?: 0);
if ($tenantId <= 0) throw new RuntimeException('SIM_TENANT_ID is required');
$pdo = getDB();
$sim = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$sim->execute(['id' => $tenantId]);
if ((int) $sim->fetchColumn() !== 1) throw new RuntimeException('Refusing to write to a non-simulation tenant');

staffingSeedPostingRules($tenantId);
$pdo->prepare(
    "INSERT INTO placements (id, tenant_id, person_id, status, start_date, engagement_type, title)
     VALUES (9401, :tenant_id, 9401, 'active', '2026-01-01', 'w2', 'Simulation consultant')"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO placement_rates
        (id, tenant_id, placement_id, effective_from, bill_rate, pay_rate,
         adjusted_bill_rate, approved_at)
     VALUES (9401, :tenant_id, 9401, '2026-01-01', 100, 60, 100, NOW())"
)->execute(['tenant_id' => $tenantId]);
$pdo->prepare(
    "INSERT INTO staffing_timesheets
        (id, tenant_id, person_id, period_start, period_end, status, total_hours)
     VALUES (9401, :tenant_id, 9401, '2026-01-05', '2026-01-11', 'approved', 7)"
)->execute(['tenant_id' => $tenantId]);

$insert = $pdo->prepare(
    "INSERT INTO time_entries
        (id, tenant_id, placement_id, person_id, period_id, timesheet_id,
         work_date, category, hour_type, billable, payable, hours, status,
         rate_snapshot_id, dimension_snapshot_json, dimension_snapshot_hash)
     VALUES
        (:id, :tenant_id, 9401, 9401, 1, 9401,
         :work_date, 'regular_billable', 'regular', 1, 1, :hours, 'approved',
         9401, :snapshot, :snapshot_hash)"
);
foreach ([
    [9401, '2026-01-06', 4, 'East'],
    [9402, '2026-01-07', 3, 'West'],
] as [$id, $workDate, $hours, $branch]) {
    $snapshot = [
        'dimensions' => [
            'client' => 42, 'placement' => 9401, 'worker' => 9401,
            'legal_entity' => 1, 'work_state' => 'NY', 'wc_class' => '8810',
            'branch' => $branch,
        ],
        'vendor_dimension' => null,
        'vendor_economic_party_id' => null,
        'vendor_company_id' => null,
        'vendor_ap_id' => null,
        'event_entity_id' => 1,
        'engagement_type' => 'w2',
        'referral_fee_per_hour' => null,
        'missing' => [],
    ];
    $json = staffingDimensionSnapshotCanonicalJson($snapshot);
    $insert->execute([
        'id' => $id, 'tenant_id' => $tenantId, 'work_date' => $workDate,
        'hours' => $hours, 'snapshot' => $json,
        'snapshot_hash' => hash('sha256', $json),
    ]);
}

// A later master-data edit must not reclassify the already approved week.
$pdo->prepare("UPDATE placements SET engagement_type = 'c2c' WHERE tenant_id = :tenant_id AND id = 9401")
    ->execute(['tenant_id' => $tenantId]);
$error = staffingEmitWorkerHoursApprovedEvent($tenantId, 9401);
if ($error !== null) throw new RuntimeException($error);

$events = $pdo->prepare(
    "SELECT source_record_id, status, journal_entry_id, payload
       FROM accounting_events
      WHERE tenant_id = :tenant_id AND event_type = 'staffing.worker_hours.approved'
        AND source_record_id LIKE 'timesheet:9401:placement:9401:w2:segment:%'"
);
$events->execute(['tenant_id' => $tenantId]);
$rows = $events->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 2) throw new RuntimeException('Two dimension versions must produce two staffing postings');
$byBranch = [];
foreach ($rows as $row) {
    if ($row['status'] !== 'posted' || (int) $row['journal_entry_id'] <= 0) {
        throw new RuntimeException('Approved hours did not post a journal');
    }
    $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
    $byBranch[(string) $payload['dimensions']['branch']] = $payload;
}
foreach (['East' => [4, 400, 240], 'West' => [3, 300, 180]] as $branch => [$hours, $revenue, $cost]) {
    $payload = $byBranch[$branch] ?? null;
    if (!$payload || $payload['engagement_type'] !== 'w2'
        || (float) $payload['hours'] !== (float) $hours
        || (float) $payload['revenue'] !== (float) $revenue
        || (float) $payload['cost'] !== (float) $cost) {
        throw new RuntimeException("Approved {$branch} dimensions or economics changed");
    }
}

$error = staffingEmitWorkerHoursApprovedEvent($tenantId, 9401);
if ($error !== null) throw new RuntimeException($error);
$events->execute(['tenant_id' => $tenantId]);
if (count($events->fetchAll(PDO::FETCH_ASSOC)) !== 2) {
    throw new RuntimeException('Replaying an approved week duplicated its journal events');
}
echo "Approved staffing dimensions posted and replayed without restatement.\n";

// Exercise the invoice/bill clearing resolver against actual posted journals.
// These temporary fixtures roll back so subsequent workflow tests see the
// original approval data, including the later placement master edit.
require_once __DIR__ . '/../modules/accounting/lib/source_accrual.php';
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO time_downstream_feed
        (tenant_id, period_id, placement_id, bundle_type, entries_json, total_amount_bill)
        VALUES (:t, 1, 9401, "ar", :entries, 700)')
        ->execute(['t' => $tenantId, 'entries' => json_encode(['entry_ids' => [9401, 9402]])]);
    $bundleId = (int) $pdo->lastInsertId();
    $line = ['source_type' => 'time', 'source_ref_id' => $bundleId,
        'placement_id' => 9401, 'quantity' => 7, 'subtotal' => 700];
    foreach ([0, 1] as $setting) {
        $pdo->prepare('UPDATE accounting_settings SET multi_period_split_enabled = :enabled WHERE tenant_id = :t')
            ->execute(['enabled' => $setting, 't' => $tenantId]);
        $parts = accountingSourceAccrualParts($tenantId, $line, 'ar', 0, 1);
        $clearing = [];
        foreach ($parts as $part) {
            if ($part['account_code'] !== '1150') throw new RuntimeException('Invoice used the legacy settings account instead of staffing unbilled');
            $branch = $part['dims']['branch'];
            $clearing[$branch] = ($clearing[$branch] ?? 0) + $part['amount'];
        }
        if (($clearing['East'] ?? 0) !== 400.0 || ($clearing['West'] ?? 0) !== 300.0) {
            throw new RuntimeException('Invoice clearing lost approved dimension amounts: ' . json_encode($clearing));
        }
        foreach (['manual', 'economic_item', 'economic_party'] as $type) {
            if (accountingSourceAccrualParts($tenantId, ['source_type' => $type, 'subtotal' => 100], 'ar', 0, 1)) {
                throw new RuntimeException('An unaccrued fee was classified as time recognition');
            }
        }
    }
    $eventRow = $rows[0];
    $pdo->prepare('UPDATE accounting_events SET status = "failed" WHERE tenant_id = :t AND source_record_id = :source')
        ->execute(['t' => $tenantId, 'source' => $eventRow['source_record_id']]);
    $blocked = false;
    try { accountingSourceAccrualParts($tenantId, $line, 'ar', 0, 1); }
    catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(), 'incomplete'); }
    if (!$blocked) throw new RuntimeException('Partial staffing recognition was allowed through invoice posting');
    $pdo->prepare('UPDATE accounting_events SET status = "posted" WHERE tenant_id = :t AND source_record_id = :source')
        ->execute(['t' => $tenantId, 'source' => $eventRow['source_record_id']]);

    // An AP source must clear a payable accrual, never a W2 payroll liability.
    $pdo->prepare('UPDATE time_entries SET ap_extracted_ref = 9399 WHERE tenant_id = :t AND id = 9401')
        ->execute(['t' => $tenantId]);
    $apLine = ['source_type' => 'time_entry', 'source_ref_id' => 9401,
        'placement_id' => 9401, 'quantity' => 4, 'subtotal' => 240];
    $blocked = false;
    try { accountingSourceAccrualParts($tenantId, $apLine, 'ap', 9399, 1); }
    catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(), 'no supported unbilled accrual'); }
    if (!$blocked) throw new RuntimeException('A W2 source was allowed to clear contractor AP');

    $dims = $byBranch['East']['dimensions'];
    $dims['vendor'] = 'company:42';
    $apJournal = accountingPostJe($tenantId, [
        'entity_id' => 1, 'posting_date' => '2026-01-11', 'currency' => 'USD',
        'source_module' => 'staffing', 'memo' => 'Temporary contractor accrual resolver fixture',
        'lines' => [
            ['account_code' => '5010', 'debit' => 240, 'credit' => 0, 'dims' => $dims],
            ['account_code' => '2050', 'debit' => 0, 'credit' => 240, 'dims' => $dims],
        ],
    ], null, true);
    $eastSource = array_values(array_filter($rows, static fn(array $r): bool =>
        (json_decode($r['payload'], true)['dimensions']['branch'] ?? '') === 'East'))[0]['source_record_id'];
    $pdo->prepare('UPDATE accounting_events SET journal_entry_id = :je WHERE tenant_id = :t AND source_record_id = :source')
        ->execute(['je' => $apJournal['je_id'], 't' => $tenantId, 'source' => $eastSource]);
    $parts = accountingSourceAccrualParts($tenantId, $apLine, 'ap', 9399, 1);
    if (count($parts) !== 1 || $parts[0]['account_code'] !== '2050' || $parts[0]['amount'] !== 240.0
        || $parts[0]['dims']['branch'] !== 'East') {
        throw new RuntimeException('AP source did not clear its original contractor accrual');
    }
    $pdo->prepare('UPDATE accounting_journal_entries SET status = "reversed" WHERE tenant_id = :t AND id = :id')
        ->execute(['t' => $tenantId, 'id' => $apJournal['je_id']]);
    $blocked = false;
    try { accountingSourceAccrualParts($tenantId, $apLine, 'ap', 9399, 1); }
    catch (RuntimeException $e) { $blocked = str_contains($e->getMessage(), 'reversed'); }
    if (!$blocked) throw new RuntimeException('A reversed source accrual was allowed through bill posting');
    echo "Source accrual clearing preserved AR/AP accounts and dimensions with either period setting; incomplete and reversed sources blocked.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
