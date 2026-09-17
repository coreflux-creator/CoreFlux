<?php
/**
 * Time Settlement smoke
 *
 * Validates the per-day settlement engine introduced in time/003_settlement.sql:
 *  - migration adds {bill,ap,payroll}_extracted_at|ref|by_user_id (idempotent)
 *  - placements/002_cycles.sql adds client_bill_cycle + vendor_pay_cycle
 *  - lib/settlement.php exposes the public API + functional cycle math
 *  - api/settlement.php gates by per-target permission
 *  - manifest declares the new perms + audit events
 *  - TimeSettlement.jsx + TimeModule.jsx are wired
 *  - period close is NEVER referenced (decoupling guarantee)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

echo "Migration time/003_settlement.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/time/migrations/003_settlement.sql');
foreach (['bill','ap','payroll'] as $t) {
    $a("adds {$t}_extracted_at",     $c($mig, "{$t}_extracted_at"));
    $a("adds {$t}_extracted_ref",    $c($mig, "{$t}_extracted_ref"));
    $a("adds {$t}_extracted_by_user_id", $c($mig, "{$t}_extracted_by_user_id"));
    $a("adds idx_te_{$t}_ready (status, $t" . "_extracted_at)",
        $c($mig, "idx_te_{$t}_ready (tenant_id, status, {$t}_extracted_at)"));
}
$a('idempotent (information_schema guards)',  $c($mig, 'information_schema.COLUMNS'));
$a('utf8mb4_unicode_ci safe',                 stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\nMigration placements/002_cycles.sql\n";
$mig2 = (string) file_get_contents(__DIR__ . '/../modules/placements/migrations/002_cycles.sql');
$a('adds client_bill_cycle ENUM',      $c($mig2, "client_bill_cycle ENUM('weekly','biweekly','semimonthly','monthly','adhoc')"));
$a('adds vendor_pay_cycle ENUM',       $c($mig2, "vendor_pay_cycle ENUM('weekly','biweekly','semimonthly','monthly','adhoc')"));
$a('client default = monthly',         $c($mig2, "DEFAULT 'monthly'"));
$a('vendor default = biweekly',        $c($mig2, "DEFAULT 'biweekly'"));
$a('idempotent guards present',        $c($mig2, 'information_schema.COLUMNS'));

echo "\ntime/lib/settlement.php — public API\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/time/lib/settlement.php');
$a('TIME_SETTLEMENT_TARGETS const',          $c($lib, 'TIME_SETTLEMENT_TARGETS'));
$a('timeSettlementReady()',                  $c($lib, 'function timeSettlementReady'));
$a('timeSettlementCandidates() includes blocked approved rows',
    $c($lib, 'function timeSettlementCandidates') && $c($lib, '_timeSettlementCandidates($target, $filters, false)'));
$a('candidate query reports target routing readiness',
    $c($lib, 'AS target_ready') && $c($lib, 'placement_economic_parties ep'));
$a('timeSettlementExtract()',                $c($lib, 'function timeSettlementExtract'));
$a('timeSettlementUnExtract()',              $c($lib, 'function timeSettlementUnExtract'));
$a('timeSettlementCycleSuggestion()',        $c($lib, 'function timeSettlementCycleSuggestion'));
$a('TimeSettlementException class',          $c($lib, 'class TimeSettlementException'));
$a('PERIOD CLOSE NOT REFERENCED (period.status NEVER queried)',
    !preg_match('/time_periods.*\.status|tp\.status|p\.status\s*=\s*[\'"]closed/', $lib));
$a('only allows status=approved on extract',
    $c($lib, "\$r['status'] !== 'approved'"));
$a('extract refuses approved rows without locked snapshots',
    $c($lib, "is approved but has no locked rate snapshot"));
$a('extract repairs legacy approved rows before validating',
    $c($lib, 'timeRepairApprovedRateSnapshots((int) $tenantId, [\'ids\' => $entryIds]'));
$a('idempotent guard: refuses if already extracted',
    $c($lib, 'already extracted to'));
$a('FOR UPDATE row lock on extract',         $c($lib, 'FOR UPDATE'));
$a('atomic transaction wrap',                ($c($lib, '$pdo->beginTransaction()') || $c($lib, 'cf_tx_begin($pdo)'))
                                          && ($c($lib, '$pdo->commit()') || $c($lib, 'cf_tx_commit('))
                                          && ($c($lib, '$pdo->rollBack()') || $c($lib, 'cf_tx_rollback(')));
$a('5000 batch cap',                         $c($lib, 'Batch limit 5000'));
$a('un-extract requires reason',             $c($lib, 'reason required for un-extract'));
$a('audits time.settlement.extracted_*',     $c($lib, 'time.settlement.extracted_'));
$a('audits time.settlement.unextracted_*',   $c($lib, 'time.settlement.unextracted_'));
$a('billing → bill_ column prefix',           $c($lib, "\$prefix = \$target === 'billing' ? 'bill' : \$target"));

require_once __DIR__ . '/../modules/time/lib/settlement.php';

echo "\nFunctional — timeSettlementCycleSuggestion() math\n";
$weekly = timeSettlementCycleSuggestion('weekly', '2026-01-05', '2026-01-08');  // anchor Mon 1/5, asof Thu 1/8
$a('weekly: from = anchor Monday',           $weekly['from'] === '2026-01-05');
$a('weekly: to = following Sunday',          $weekly['to']   === '2026-01-11');

$weekly2 = timeSettlementCycleSuggestion('weekly', '2026-01-05', '2026-01-15');  // 10 days later
$a('weekly: rolls forward to next cycle',    $weekly2['from'] === '2026-01-12' && $weekly2['to'] === '2026-01-18');

$bi = timeSettlementCycleSuggestion('biweekly', '2026-01-05', '2026-01-19');
$a('biweekly: 14-day window aligned to anchor',
    $bi['from'] === '2026-01-19' && $bi['to'] === '2026-02-01');

$semi1 = timeSettlementCycleSuggestion('semimonthly', null, '2026-02-09');
$a('semimonthly H1 (1-15)',                  $semi1['from'] === '2026-02-01' && $semi1['to'] === '2026-02-15');
$semi2 = timeSettlementCycleSuggestion('semimonthly', null, '2026-02-22');
$a('semimonthly H2 (16-end)',                $semi2['from'] === '2026-02-16' && $semi2['to'] === '2026-02-28');

$mon = timeSettlementCycleSuggestion('monthly', null, '2024-02-15');  // leap-year Feb
$a('monthly: full calendar month, leap-aware',
    $mon['from'] === '2024-02-01' && $mon['to'] === '2024-02-29');

$adhoc = timeSettlementCycleSuggestion('adhoc', null, '2026-03-07');
$a('adhoc: 1-day window',                    $adhoc['from'] === '2026-03-07' && $adhoc['to'] === '2026-03-07');

echo "\ntime/api/settlement.php — endpoint\n";
$api = (string) file_get_contents(__DIR__ . '/../modules/time/api/settlement.php');
$a('GET handler returns blocks',             $c($api, "if (\$method === 'GET')") && $c($api, "'blocks'"));
$a('GET keeps blocked rows visible',          $c($api, 'timeSettlementCandidates($target, $filters)'));
$a('GET returns ready and blocked counts',    $c($api, "'ready_count'") && $c($api, "'blocked_count'"));
$a('GET explains missing placement routing',  $c($api, "'blocked_reason'") && $c($api, 'Placement Economics needs'));
$a('groups by (placement_id, work_date)',    $c($api, "\$r['placement_id'] . '|' . \$r['work_date']"));
$a('attaches cycle_window per block',        $c($api, "timeSettlementCycleSuggestion("));
$a('per-target view permission gate',        $c($api, "\"time.settlement.view.\$target\""));
$a('extract action endpoint',                $c($api, "\$action === 'extract'"));
$a('unextract action endpoint',              $c($api, "\$action === 'unextract'"));
$a('extract gates on time.settlement.extract.<target>',
    $c($api, "\"time.settlement.extract.\$target\""));
$a('un-extract gates on time.settlement.unextract.<target>',
    $c($api, "\"time.settlement.unextract.\$target\""));

echo "\nmanifest — perms + audit events\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/time/manifest.php');
foreach (['billing','ap','payroll'] as $t) {
    $a("perm time.settlement.view.$t",        $c($man, "time.settlement.view.$t"));
    $a("perm time.settlement.extract.$t",     $c($man, "time.settlement.extract.$t"));
    $a("perm time.settlement.unextract.$t",   $c($man, "time.settlement.unextract.$t"));
    $a("audit time.settlement.extracted_$t",   $c($man, "time.settlement.extracted_$t"));
    $a("audit time.settlement.unextracted_$t", $c($man, "time.settlement.unextracted_$t"));
}
$a('Settlement action wired in manifest nav', $c($man, "'route' => 'settlement'"));

echo "\nUI — TimeSettlement.jsx + TimeModule.jsx\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/time/ui/TimeSettlement.jsx');
$a('three target tabs',                      $c($ui, 'billing') && $c($ui, "'ap'") && $c($ui, "'payroll'"));
$a('day-block selection (placement:date keys)',
    $c($ui, "\${b.placement_id}:\${b.work_date}"));
$a('reuses useBulkSelection',                $c($ui, "from '../../../dashboard/src/lib/useBulkSelection'"));
$a('shows cycle window per row',             $c($ui, 'cycle_window?.label'));
$a('extract button disabled without target_ref',
    $c($ui, '!targetRef'));
$a('clears selection on success',            $c($ui, 'sel.clear()'));
$a('shows total selected hours',             $c($ui, 'totalSelectedHours'));
$a('blocked settlement rows cannot be selected',
    $c($ui, "blocks.filter(b => b.ready !== false)") && $c($ui, 'disabled={blocked}'));
$a('blocked rows explain the setup problem', $c($ui, 'time-settlement-blocked-summary')
                                                  && $c($ui, 'b.blocked_reason'));
$a('blocked rows link directly to placement economics',
    $c($ui, '/economics`}') && $c($ui, 'Fix placement economics'));

$mod = (string) file_get_contents(__DIR__ . '/../modules/time/ui/TimeModule.jsx');
$a('TimeModule routes /settlement → TimeSettlement',
    $c($mod, '<Route path="settlement" element={<TimeSettlement />}'));

echo "\nlib/settlement_create.php — auto-create engine\n";
$cr = (string) file_get_contents(__DIR__ . '/../modules/time/lib/settlement_create.php');
$payrollLib = (string) file_get_contents(__DIR__ . '/../modules/payroll/lib/payroll.php');
$a('timeSettlementAutoCreate() exposed',         $c($cr, 'function timeSettlementAutoCreate'));
$a('accepts billing|ap|payroll targets',          $c($cr, "['billing','ap','payroll']"));
$a('payroll branch routes to dedicated handler',  $c($cr, '_settleTimeIntoPayroll'));
$a('payroll: groups entries by employee and selected placement route',
                                                  $c($cr, '$byEmployee[$key][\'entries\'][] = $e')
                                                  && $c($cr, 'placement_payroll_cycle_id')
                                                  && $c($cr, 'operating_payroll_cycle_id'));
$a('payroll: resolves person_id → employee via user_id',
                                                  $c($cr, 'WHERE e.tenant_id = ? AND e.user_id = ?'));
$a('payroll: resolves person_id → employee via work/personal email fallback',
                                                  $c($cr, 'LOWER(e.work_email) = LOWER(?)')
                                                  && $c($cr, 'LOWER(e.personal_email) = LOWER(?)'));
$a('payroll: employee lookup only selects real profile columns',
                                                  !$c($cr, 'pp.pay_type')
                                                  && !$c($cr, 'pp.pay_rate_cents')
                                                  && !$c($cr, 'pp.flsa_class'));
$a('payroll: finds open period in employee\'s cycle and work-date window',
                                                  $c($cr, "WHERE tenant_id = :t AND cycle_id = :c") &&
                                                  $c($cr, 'status IN ("draft","open")') &&
                                                  $c($cr, 'period_start <= :work_date_start') &&
                                                  $c($cr, 'period_end >= :work_date_end'));
$a('payroll: schedule-only profiles route without a cycle',
                                                  $c($cr, 'function _settlementPayrollRunForSchedule')
                                                  && $c($cr, 'schedule_id = :s AND cycle_id IS NULL')
                                                  && $c($cr, 'payrollGenerateNextPeriods($scheduleId, 6)'));
$a('payroll: finds-or-creates draft run on period',$c($cr, 'INSERT INTO payroll_runs') && $c($cr, '"draft", NOW()'));
$a('payroll: does not create a duplicate run after compute',
                                                  $c($cr, 'status <> "voided"')
                                                  && $c($cr, "\$run['status'] !== 'draft'"));
$a('payroll: records run creator for two-eye controls',
                                                  $c($cr, 'created_by_user_id'));
$a('payroll: groups a cross-period selection by employee and run',
                                                  $c($cr, '$byRun[$key][\'run_target\']')
                                                  && $c($cr, '$byRun[$key][\'entries\'][]'));
$a('payroll: compute reads settled source time instead of additive line-item writes',
                                                  $c($payrollLib, 'function payrollRunExtractedHours')
                                                  && !$c($cr, 'hours_regular  + VALUES(hours_regular)'));
$a('payroll: splits OT vs regular by category',   $c($cr, "str_contains(\$cat, 'overtime')"));
$a('payroll: skipped[] for unmatched employees',  $c($cr, "'no_matching_employee'"));
$a('payroll: skipped[] for missing payroll_profile/schedule',
                                                  $c($cr, "'no_payroll_profile_or_schedule'"));
$a('payroll: leaves out-of-period time visible with an actionable reason',
                                                  $c($cr, "'no_editable_pay_period_for_work_date'"));
$a('payroll: advances or creates the selected payroll-cycle run',
                                                  $c($cr, '_settlementPayrollRunForCycle')
                                                  && $c($cr, 'payrollCycleAdvance'));
$a('payroll: maps placement operating cycles to payroll cycles',
                                                  $c($cr, 'poc.payroll_pay_cycle_id AS operating_payroll_cycle_id')
                                                  && $c($cr, 'LEFT JOIN staffing_operating_cycles poc'));
$a('payroll: records commission/referral obligations against the run',
                                                  $c($cr, 'placementEconomicsPayrollCharges')
                                                  && $c($cr, '\'payroll_ref_id\' => (int) $recipientRun[\'run_id\']'));
$a('groups entries by placement_id',              $c($cr, '$byPlacement[(int) $e[\'placement_id\']]'));
$a('auto-create repairs legacy approved rows first',
                                                  $c($cr, 'timeRepairApprovedRateSnapshots((int) $tenantId'));
$a('auto-create loads locked rate snapshots by id',$c($cr, 'timeRateSnapshotsById(array_column($entries, \'rate_snapshot_id\')'));
$a('auto-create refuses approved rows without snapshots',
                                                  $c($cr, 'is approved but has no locked rate snapshot'));
$a('billing uses adjusted bill rate',              $c($cr, "\$rate['adjusted_bill_rate'] ?? \$rate['bill_rate']"));
$a('AP uses pay rate, not margin/net snapshot',    $c($cr, "\$rate['pay_rate'] ?? 0")
                                                  && !$c($cr, "\$rate['net_to_vendor'] ?? \$rate['pay_rate']"));
$a('OT/DT multiplier delegated to shared helper',  $c($cr, 'timeRateCategoryMultiplier($rate'));
$a('billing creates draft AR invoice + per-day lines',
    $c($cr, "scopedInsert('billing_invoices'") && $c($cr, "INSERT INTO billing_invoice_lines")
    && $c($cr, 'rate_snapshot_id, description'));
$a('AP creates graph-backed bills and obligation lines',
    $c($cr, 'apBuildDraftFromTimeEntries(') && $c($cr, "scopedInsert('ap_bills'")
    && $c($cr, 'placementEconomicsRecordObligation(') && $c($cr, "INSERT INTO ap_bill_lines"));
$a('atomic: begin/commit/rollBack',                ($c($cr, '$pdo->beginTransaction()') || $c($cr, 'cf_tx_begin($pdo)')) && ($c($cr, '$pdo->commit()') || $c($cr, 'cf_tx_commit(')) && ($c($cr, '$pdo->rollBack()') || $c($cr, 'cf_tx_rollback(')));
$a('only allows status=approved entries',          $c($cr, "\$e['status'] !== 'approved'"));
$a('refuses already-extracted entries',            $c($cr, 'already extracted to'));
$a('audits time.settlement.auto_extracted_*',      $c($cr, 'time.settlement.auto_extracted_'));
$a('FOR UPDATE row lock',                          $c($cr, 'FOR UPDATE'));
$a('PERIOD CLOSE NOT REFERENCED in auto-create',
    !preg_match('/time_periods.*\.status|tp\.status|p\.status\s*=\s*[\'"]closed/', $cr));

echo "\nlib/settlement_ai.php — AI assist + fallback\n";
$ai = (string) file_get_contents(__DIR__ . '/../modules/time/lib/settlement_ai.php');
$a('timeSettlementAiSuggest()',                    $c($ai, 'function timeSettlementAiSuggest'));
$a('uses gpt-4o-mini',                             $c($ai, "'model'           => 'gpt-4o-mini'"));
$a('low temperature (0.2) for deterministic output', $c($ai, "'temperature'     => 0.2"));
$a('forces JSON response_format',                  $c($ai, "'response_format' => ['type' => 'json_object']"));
$a('compresses blocks before send',                $c($ai, '$compact'));
$a('caps payload at 200 blocks',                   $c($ai, 'count($blocks) > 200'));
$a('rules-based fallback when AI unavailable',     $c($ai, '_settlementFallbackGroup'));
$a('fallback flags weekend + long_day',            $c($ai, "'weekend'") && $c($ai, "'long_day'"));
$a('writes ai_audit row',                          $c($ai, 'aiAuditWrite('));
$a('gracefully handles unparseable AI output',     $c($ai, 'unparseable'));

echo "\napi — auto_extract + ai_suggest actions\n";
$api2 = (string) file_get_contents(__DIR__ . '/../modules/time/api/settlement.php');
$a('auto_extract action handler',                  $c($api2, "\$action === 'auto_extract'"));
$a('auto_extract gates extract permission',         $c($api2, "rbac_legacy_require(\$user, \"time.settlement.extract.\$target\")"));
$a('auto_extract accepts target=payroll',           $c($api2, "auto_extract supports billing|ap|payroll only"));
$a('ai_suggest action handler',                    $c($api2, "\$action === 'ai_suggest'"));
$a('ai_suggest builds blocks server-side if FE omitted',
    $c($api2, '!is_array($blocks)') && $c($api2, 'timeSettlementReady($target, $filters)'));

echo "\nUI — TimeSettlement.jsx auto-extract + AI\n";
$ui2 = (string) file_get_contents(__DIR__ . '/../modules/time/ui/TimeSettlement.jsx');
$a('Auto-create button (billing+ap+payroll)',      $c($ui2, 'data-testid="time-settlement-auto-extract"'));
$a('auto-create is the primary settlement action', $c($ui2, 'Create draft invoices')
                                                  && $c($ui2, 'Create draft vendor bills')
                                                  && $c($ui2, 'Add to draft payroll runs'));
$a('manual target id is isolated as an advanced action',
                                                  $c($ui2, 'Advanced: link to an existing record')
                                                  && $c($ui2, 'time-settlement-existing-target'));
$a('auto-create does not add a redundant browser confirmation',
                                                  !$c($ui2, 'confirm('));
$a('created settlement targets link to their destination records',
                                                  $c($ui2, '/modules/billing/invoices/${info.target_id}')
                                                  && $c($ui2, '/modules/ap/bills/${id}')
                                                  && $c($ui2, '/modules/payroll/runs/${info.run_id}'));
$a('AI Suggest button',                            $c($ui2, 'data-testid="time-settlement-ai-suggest"'));
$a('AI panel renders suggestions',                 $c($ui2, 'time-settlement-ai-panel') && $c($ui2, 'time-settlement-ai-suggestion-'));
$a('"Use this batch" → applies to selection',      $c($ui2, 'sel.selectMany(blockKeys)'));
$a('payroll auto-create now enabled in UI',        $c($ui2, "target === 'payroll'"));
$a('selected-time summary explains that downstream records remain drafts',
                                                  $c($ui2, 'Nothing is sent or posted')
                                                  && $c($ui2, 'Nothing is approved or paid')
                                                  && $c($ui2, 'Nothing is submitted or paid'));
$a('payroll UI asks for a run, not a nonexistent run line target',
                                                  $c($ui2, "refLabel: 'Payroll run ID'")
                                                  && !$c($ui2, "refLabel: 'Payroll Line ID'"));
$a('payroll UI surfaces partial settlement skips', $c($ui2, 'time-settlement-warning')
                                                  && $c($ui2, 'Review payroll setup'));

echo "\nManual extract target validation\n";
$a('manual payroll extract validates draft run',  $c($lib, 'time can only be linked to a draft run'));
$a('manual payroll extract validates work-date window',
                                                  $c($lib, 'is outside payroll run'));
$a('manual payroll extract writes canonical run reference',
                                                  $c($lib, "'payroll:run#' . \$targetRef"));
$a('manual billing/AP extract validates target exists',
                                                  $c($lib, 'Billing invoice #{$targetRef}')
                                                  && $c($lib, 'AP bill #{$targetRef}'));

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
