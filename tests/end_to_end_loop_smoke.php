<?php
/**
 * Smoke: End-to-end staffing cash cycle loop.
 *
 * Static contract test that verifies the wiring between modules in the order
 * money actually moves through a staffing agency:
 *
 *   People → Placement → Time → Billing (AR) → Cash Application → PWP release
 *   → AP Bill Approved → AP Payment → Payroll
 *
 * For each handoff we assert:
 *   (a) the source module's API exists and emits the expected artifact
 *   (b) the downstream module reads that artifact via the documented
 *       integration point (function name, SQL JOIN, or feed table)
 *   (c) the data primitives that thread through (placement_id, period
 *       boundaries, bundle_ids) are referenced consistently
 *
 * Live DB is NOT required — this is a regression net for the integration
 * surface, not a feature test.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$has = fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : '';

// ── stage 1: People → Placement ───────────────────────────────────────
echo "1. People → Placement\n";
$peopleApi    = $has(__DIR__ . '/../modules/people/api/people.php');
$placementApi = $has(__DIR__ . '/../modules/placements/api/placements.php');
$a('people module exists',                            $peopleApi !== '');
$a('placements module exists',                        $placementApi !== '');
$a('placement references person_id',                  str_contains($placementApi, 'person_id'));
$a('placement exposes rates() + currentRate()',       str_contains($placementApi, 'placementRates') && str_contains($placementApi, 'placementCurrentRate'));
$a('placement carries engagement_type',               str_contains($placementApi, 'engagement_type'));

// ── stage 2: Placement → Time ─────────────────────────────────────────
echo "\n2. Placement → Time entries\n";
$timeApi  = $has(__DIR__ . '/../modules/time/api/entries.php');
$timeLib  = $has(__DIR__ . '/../modules/time/api/intake.php');
$a('time entries API exists',                         $timeApi !== '');
$a('time module references placement_id',             str_contains($timeApi . $timeLib, 'placement_id'));
$a('time entries have approval workflow',             preg_match('/status\s*ENUM\(.*\bapproved\b/i', (string) file_get_contents(__DIR__ . '/../modules/time/migrations/001_init.sql')) === 1);

// ── stage 3: Time → Downstream Feed ───────────────────────────────────
echo "\n3. Time → time_downstream_feed (the AR/AP bundle pivot)\n";
$tdfMig = (string) file_get_contents(__DIR__ . '/../modules/time/migrations/001_init.sql');
$a('time_downstream_feed table defined',              str_contains($tdfMig, 'CREATE TABLE IF NOT EXISTS time_downstream_feed'));
$a('feed rows carry placement_id + period_id',        str_contains($tdfMig, 'placement_id') && str_contains($tdfMig, 'period_id'));
$a('feed has bundle_type (ar/ap split)',              str_contains($tdfMig, 'bundle_type'));
$a('feed has consumed status lifecycle',              str_contains($tdfMig, 'consumed_at') && str_contains($tdfMig, 'consumed_by_module'));

// ── stage 4: Feed → Billing (AR invoices) ─────────────────────────────
echo "\n4. Time feed → Billing AR invoices\n";
$billInvApi = $has(__DIR__ . '/../modules/billing/api/invoices.php');
$a("from-time-bundle action wired",                   str_contains($billInvApi, "action === 'from-time-bundle'"));
$a('reads period_id + placement_ids',                 str_contains($billInvApi, 'period_id') && str_contains($billInvApi, 'placement_ids'));
$a('writes placement_id on invoice line',             str_contains($billInvApi, "placement_id"));
$a('marks bundle as consumed by billing',             str_contains($billInvApi, 'consumed_by_module') || str_contains($billInvApi, "'billing'"));
$a('triggers PWP auto-link after commit',             str_contains($billInvApi, 'apPwpAutoLinkForArInvoice($tid, (int) $c[\'id\']'));

// ── stage 5: Feed → AP bills ──────────────────────────────────────────
echo "\n5. Time feed → AP bills\n";
$apLib  = $has(__DIR__ . '/../modules/ap/lib/ap.php');
$apApi  = $has(__DIR__ . '/../modules/ap/api/bills.php');
$a('apBuildDraftFromBundle() defined',                str_contains($apLib, 'function apBuildDraftFromBundle'));
$a('builder reads bundles by placement_ids',          str_contains($apLib, 'placement_ids requi'));
$a('bills get vendor_type 1099/c2c',                  str_contains($apLib, '1099_individual') && str_contains($apLib, 'c2c_corp'));
$a('bill lines reference source_ref_id (bundle id)',  str_contains($apLib, "source_ref_id'] = \$l['source_ref_id']") || str_contains($apLib, 'source_ref_id'));
$a('bill stamps PWP when vendor has default_pwp',     str_contains($apLib, "'pwp_status'    => \$isPwp ? 'awaiting_ar' : 'not_pwp'"));
$a('PWP bills get +90 day due_date carry',            str_contains($apLib, '$pwpNetDays = 90'));

// ── stage 6: AR Cash Application → PWP release → AP transition ───────
echo "\n6. AR cash → PWP release → AP bill 'approved'\n";
$billingLib = $has(__DIR__ . '/../modules/billing/lib/billing.php');
$pwpLib     = $has(__DIR__ . '/../modules/ap/lib/pwp.php');
$a('billingAllocatePayment defined',                  str_contains($billingLib, 'function billingAllocatePayment'));
$a('allocate transitions invoice to "paid"',          str_contains($billingLib, "if (\$newDue < 0.005) { \$newDue = 0; \$newStatus = 'paid'; }"));
$a('allocate calls apPwpReleaseForArInvoice',         str_contains($billingLib, 'apPwpReleaseForArInvoice($tenantId, (int) $a[\'invoice_id\']'));
$a('allocate exposes pwp array in response',          str_contains($billingLib, "'pwp' => \$pwpResults"));
$a('PWP release sets bill status to approved',        str_contains($pwpLib, "in_array(\$prevStatus, ['inbox', 'pending_review', 'pending_approval']"));
$a('PWP release stamps approved_at + approver',       str_contains($pwpLib, 'approved_by_user_id = COALESCE') && str_contains($pwpLib, 'approved_at = COALESCE(approved_at, NOW())'));
$a('PWP release only when amount_due ≈ 0',            str_contains($pwpLib, 'round((float) $invRow[\'amount_due\'], 2) > 0.005'));

// ── stage 7: AR cash application UI → PWP toast ──────────────────────
echo "\n7. Payments UI surfaces PWP results\n";
$paymentsJsx = $has(__DIR__ . '/../modules/billing/ui/PaymentsList.jsx');
$a('UI captures result.pwp from API',                 str_contains($paymentsJsx, 'res?.pwp') && str_contains($paymentsJsx, 'auto_allocation?.pwp'));
$a('UI renders billing-pwp-toast on release',         str_contains($paymentsJsx, 'data-testid="billing-pwp-toast"'));
$a('Toast lists each released bill',                  str_contains($paymentsJsx, 'data-testid={`billing-pwp-released-${r.bill_id}`}'));
$a('AllocateModal threads result through onSaved',    str_contains($paymentsJsx, 'onSaved?.(res)'));
$a('RecordPaymentModal threads result through',       substr_count($paymentsJsx, 'onSaved?.(res)') >= 2);

// ── stage 8: AP bill 'approved' → AP payment ─────────────────────────
echo "\n8. AP bill approved → AP payment\n";
$apPayApi = $has(__DIR__ . '/../modules/ap/api/payments.php');
$a('AP payments API exists',                          $apPayApi !== '');
$a('AP payment requires bill_id',                     str_contains($apPayApi, 'bill_id'));
$a('AP payment lib bumps amount_paid + status',       str_contains($apLib, 'amount_paid') && (bool) preg_match('/(partially_paid|paid)/', $apLib));
$a('AP allocations table referenced',                 str_contains($apLib, 'ap_payment_allocations'));

// ── stage 9: AP payment → Payroll (1099 ledger) ──────────────────────
echo "\n9. AP payment → Payroll (1099 ledger)\n";
$ledger1099Api = $has(__DIR__ . '/../modules/ap/api/1099.php');
$a('1099 ledger API exists',                          $ledger1099Api !== '');
$a('1099 ledger has rebuild action',                  str_contains($ledger1099Api, "action === 'rebuild'") || str_contains($ledger1099Api, "'rebuild'"));
$a('1099 rebuild joins ap_payments → ap_bills',       str_contains($apLib, 'FROM ap_payments p')
                                                       && str_contains($apLib, 'JOIN ap_payment_allocations a ON a.payment_id = p.id')
                                                       && str_contains($apLib, 'JOIN ap_bills b'));
$a('1099 rebuild filters by vendor_type=1099',        (bool) preg_match("/(vendor_type\s*=\s*['\"]1099|requires_1099_nec)/", $apLib));

// ── stage 10: Weekly Queue knows the whole chain ─────────────────────
echo "\n10. Weekly Queue closes the loop\n";
$wqLib = $has(__DIR__ . '/../modules/ap/lib/weekly_queue.php');
$a('weekly queue surfaces PWP blocker (stage 5↔6)',   str_contains($wqLib, "'awaiting_client'") && str_contains($wqLib, "'awaiting_ar'"));
$a('weekly queue surfaces hours blocker (stage 3↔5)', str_contains($wqLib, "'missing_hours'") && str_contains($wqLib, 'time_downstream_feed'));
$a('weekly queue surfaces approver blocker',          str_contains($wqLib, "'approver_pending'"));
$a('weekly queue surfaces disputed blocker',          str_contains($wqLib, "'disputed'"));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
