<?php
/**
 * AP Phase A1 follow-ups V2 — bill approvals fixes, vendor portal Phase 2,
 * recurring bills, three-way match, 1099 readiness check, approval comments.
 *
 * Each section asserts both the new code paths and the bug fixes against
 * actual schema column names (bill_number, total, pay_date — NOT the
 * mythical invoice_number, amount_total, payment_date).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; } else { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc); return $rc === 0;
};

// ─── Bill approvals — fixed column names ───
echo "Phase A1 V2 — bill_approvals.php column drift fixed\n";
$ba = file_get_contents(__DIR__ . '/../modules/ap/api/bill_approvals.php');
$assert('uses b.bill_number (not invoice_number)',  strpos($ba, 'b.bill_number') !== false && strpos($ba, 'b.invoice_number') === false);
$assert('uses b.total AS amount_total (not b.amount_total)', strpos($ba, 'b.total AS amount_total') !== false);
$assert('reads bill[total] not bill[amount_total]', strpos($ba, "\$bill['total']") !== false);
$assert('exposes ?count_pending=1 action',          strpos($ba, "count_pending") !== false);
$assert('exposes ?comments_for_bill=N action',      strpos($ba, 'comments_for_bill') !== false);
$assert('?action=comment writes to ap_bill_approval_comments',
                                                    strpos($ba, "INSERT INTO ap_bill_approval_comments") !== false);
$assert('apBillApprovalNotify() helper present',    strpos($ba, 'function apBillApprovalNotify') !== false);
$assert('audit ap.bill.approval_submitted emitted', strpos($ba, 'ap.bill.approval_submitted') !== false);
$assert('audit ap.bill.approval_approved emitted',  strpos($ba, 'approval_{$newState}') !== false);
$assert('PHP parses cleanly',                       $lint(__DIR__ . '/../modules/ap/api/bill_approvals.php'));

// ─── Vendor portal — bug fixes + Phase 2 actions ───
echo "Phase A1 V2 — vendor_portal.php fixes + Phase 2\n";
$vp = file_get_contents(__DIR__ . '/../modules/ap/api/vendor_portal.php');
$assert('queries ap_vendors_index (not ap_vendors)',     strpos($vp, 'FROM ap_vendors_index') !== false);
$assert('does not reference contact_email column',       strpos($vp, 'contact_email') === false);
$assert('uses remit_to_email for invite default',        strpos($vp, 'remit_to_email') !== false);
$assert('me uses bill_number (not invoice_number)',      strpos($vp, 'b.bill_number') !== false || strpos($vp, 'bill_number') !== false);
$assert('me uses pay_date (not payment_date)',           strpos($vp, 'pay_date AS payment_date') !== false);
$assert('me joins bills via vendor_name',                strpos($vp, 'vendor_name = :vn') !== false);
$assert('?action=upload_url present',                    strpos($vp, "action === 'upload_url'") !== false);
$assert('?action=upload_document present',               strpos($vp, "action === 'upload_document'") !== false);
$assert('?action=update_banking present',                strpos($vp, "action === 'update_banking'") !== false);
$assert('?action=documents present',                     strpos($vp, "action === 'documents'") !== false);
$assert('encrypts payment account via encryptField()',   strpos($vp, 'encryptField($acctFull)') !== false);
$assert('audit ap.vendor.portal_document_uploaded',      strpos($vp, 'ap.vendor.portal_document_uploaded') !== false);
$assert('audit ap.vendor.portal_banking_updated',        strpos($vp, 'ap.vendor.portal_banking_updated') !== false);
$assert('PHP parses cleanly',                            $lint(__DIR__ . '/../modules/ap/api/vendor_portal.php'));

// ─── Recurring bills (lib + API) ───
echo "Phase A1 V2 — recurring bills\n";
$mig = file_get_contents(__DIR__ . '/../modules/ap/migrations/013_recurring_bills.sql');
$assert('migration creates ap_recurring_bills',          strpos($mig, 'CREATE TABLE IF NOT EXISTS ap_recurring_bills') !== false);
$assert('frequency enum 5 values',                       strpos($mig, "ENUM('weekly','biweekly','monthly','quarterly','yearly')") !== false);
$assert('status enum active|paused|ended',               strpos($mig, "ENUM('active','paused','ended')") !== false);
$assert('next_bill_date indexed',                        strpos($mig, 'idx_aprb_tenant_status') !== false);

$rl = file_get_contents(__DIR__ . '/../modules/ap/lib/recurring.php');
$assert('apRecurringNextDate()',                          strpos($rl, 'function apRecurringNextDate') !== false);
$assert('apRecurringGenerateDue()',                       strpos($rl, 'function apRecurringGenerateDue') !== false);
$assert('weekly +7 days',                                 strpos($rl, "+7 days") !== false);
$assert('biweekly +14 days',                              strpos($rl, "+14 days") !== false);
$assert('monthly clamps to month length',                 strpos($rl, "date('t', mktime") !== false);
$assert('source=recurring on generated bill',             strpos($rl, '"recurring"') !== false);
$assert('PHP parses cleanly',                             $lint(__DIR__ . '/../modules/ap/lib/recurring.php'));

$rapi = file_get_contents(__DIR__ . '/../modules/ap/api/recurring.php');
$assert('GET list',                                       strpos($rapi, 'SELECT * FROM ap_recurring_bills') !== false);
$assert('POST create',                                    strpos($rapi, 'INSERT INTO ap_recurring_bills') !== false);
$assert('?action=pause',                                  strpos($rapi, "'pause'") !== false);
$assert('?action=resume',                                 strpos($rapi, "'resume'") !== false);
$assert('?action=end',                                    strpos($rapi, "'end'") !== false);
$assert('?action=generate_due',                           strpos($rapi, "action === 'generate_due'") !== false);
$assert('audit ap.recurring.created',                     strpos($rapi, 'ap.recurring.created') !== false);
$assert('PHP parses cleanly',                             $lint(__DIR__ . '/../modules/ap/api/recurring.php'));

// Functional: pure date math
require_once __DIR__ . '/../modules/ap/lib/recurring.php';
$assert('weekly: 2026-01-05 → 2026-01-12',                apRecurringNextDate('2026-01-05', 'weekly')   === '2026-01-12');
$assert('biweekly: 2026-01-05 → 2026-01-19',              apRecurringNextDate('2026-01-05', 'biweekly') === '2026-01-19');
$assert('monthly clamps day 31 → Feb 28',                 apRecurringNextDate('2026-01-31', 'monthly', 31) === '2026-02-28');
$assert('monthly day_of_period=15',                       apRecurringNextDate('2026-01-31', 'monthly', 15) === '2026-02-15');
$assert('quarterly: 2026-01-05 → 2026-04-05',             apRecurringNextDate('2026-01-05', 'quarterly') === '2026-04-05');
$assert('yearly: 2026-01-05 → 2027-01-05',                apRecurringNextDate('2026-01-05', 'yearly')    === '2027-01-05');

// ─── Three-way match (PO + lib) ───
echo "Phase A1 V2 — three-way match\n";
$pmig = file_get_contents(__DIR__ . '/../modules/ap/migrations/014_purchase_orders.sql');
$assert('creates ap_purchase_orders',                     strpos($pmig, 'CREATE TABLE IF NOT EXISTS ap_purchase_orders') !== false);
$assert('creates ap_purchase_order_lines',                strpos($pmig, 'CREATE TABLE IF NOT EXISTS ap_purchase_order_lines') !== false);
$assert('creates ap_po_receipts',                         strpos($pmig, 'CREATE TABLE IF NOT EXISTS ap_po_receipts') !== false);
$assert('quantity_received column',                       strpos($pmig, 'quantity_received') !== false);
$assert('tenants.ap_three_way_match_enforce',             strpos($pmig, 'ap_three_way_match_enforce') !== false);
$assert('tenants.ap_three_way_match_tolerance_pct',       strpos($pmig, 'ap_three_way_match_tolerance_pct') !== false);

$twm = file_get_contents(__DIR__ . '/../modules/ap/lib/three_way_match.php');
$assert('apThreeWayMatch()',                              strpos($twm, 'function apThreeWayMatch') !== false);
$assert('returns warnings array',                         strpos($twm, "'warnings'") !== false);
$assert('reads tolerance from tenant cfg',                strpos($twm, 'ap_three_way_match_tolerance_pct') !== false);
$assert('warns when bill > PO outside tolerance',         strpos($twm, 'differs from PO total') !== false);
$assert('warns when bill > received',                     strpos($twm, 'exceeds received total') !== false);
$assert('PHP parses cleanly',                             $lint(__DIR__ . '/../modules/ap/lib/three_way_match.php'));

$papi = file_get_contents(__DIR__ . '/../modules/ap/api/purchase_orders.php');
$assert('GET list/detail',                                strpos($papi, 'SELECT id, po_number') !== false);
$assert('POST create',                                    strpos($papi, 'INSERT INTO ap_purchase_orders') !== false);
$assert('?action=receive',                                strpos($papi, "action === 'receive'") !== false);
$assert('?action=match exposed',                          strpos($papi, "action === 'match'") !== false);
$assert('updates po status when fully received',          strpos($papi, "newStatus = \$remaining <= 0 ? 'received'") !== false);
$assert('PHP parses cleanly',                             $lint(__DIR__ . '/../modules/ap/api/purchase_orders.php'));

// ─── 1099 readiness check ───
echo "Phase A1 V2 — 1099 readiness check\n";
$ten99 = file_get_contents(__DIR__ . '/../modules/ap/api/1099.php');
$assert('?action=readiness exposed',                      strpos($ten99, "action === 'readiness'") !== false);
$assert('checks W-9 from ap_vendor_portal_documents',     strpos($ten99, 'ap_vendor_portal_documents') !== false);
$assert('returns blockers array',                         strpos($ten99, "'blockers'") !== false);
$assert('checks tin_present',                             strpos($ten99, "Missing TIN") !== false);
$assert('summary ready/blocked/total',                    strpos($ten99, "'ready'") !== false && strpos($ten99, "'blocked'") !== false);
$assert('PHP parses cleanly',                             $lint(__DIR__ . '/../modules/ap/api/1099.php'));

// ─── Migration files ───
echo "Phase A1 V2 — supporting migrations\n";
$m12 = file_get_contents(__DIR__ . '/../modules/ap/migrations/012_vendor_portal_documents.sql');
$assert('012 creates ap_vendor_portal_documents',         strpos($m12, 'CREATE TABLE IF NOT EXISTS ap_vendor_portal_documents') !== false);
$assert('012 doctype enum w9/coi/banking_form/contract',  strpos($m12, "ENUM('w9','coi','banking_form','contract','other')") !== false);
$assert('012 status pending_review/approved/rejected',    strpos($m12, "ENUM('pending_review','approved','rejected')") !== false);
$assert('012 adds ap_vendors_index.contact_email column', strpos($m12, "ADD COLUMN contact_email") !== false);

$m15 = file_get_contents(__DIR__ . '/../modules/ap/migrations/015_bill_approval_comments.sql');
$assert('015 creates ap_bill_approval_comments',          strpos($m15, 'CREATE TABLE IF NOT EXISTS ap_bill_approval_comments') !== false);
$assert('015 creates ap_bill_approval_notifications',     strpos($m15, 'CREATE TABLE IF NOT EXISTS ap_bill_approval_notifications') !== false);

// ─── Manifest ───
echo "Phase A1 V2 — manifest declarations\n";
$man = file_get_contents(__DIR__ . '/../modules/ap/manifest.php');
$assert('manifest perm ap.po.manage',                     strpos($man, 'ap.po.manage') !== false);
$assert('manifest perm ap.vendor.portal_review',          strpos($man, 'ap.vendor.portal_review') !== false);
$assert('manifest perm ap.bills.approve_admin',           strpos($man, 'ap.bills.approve_admin') !== false);
$assert('manifest action Recurring Bills route',          strpos($man, "'route' => 'recurring'") !== false);
$assert('manifest action Purchase Orders route',          strpos($man, "'route' => 'purchase-orders'") !== false);
$assert('manifest action Approvals route',                strpos($man, "'route' => 'approvals'") !== false);
$assert('audit ap.recurring.generated',                   strpos($man, 'ap.recurring.generated') !== false);
$assert('audit ap.po.created',                            strpos($man, 'ap.po.created') !== false);
$assert('audit ap.po.receipt_recorded',                   strpos($man, 'ap.po.receipt_recorded') !== false);
$assert('audit ap.bill.approval_comment_added',           strpos($man, 'ap.bill.approval_comment_added') !== false);
$assert('audit ap.vendor.portal_document_uploaded',       strpos($man, 'ap.vendor.portal_document_uploaded') !== false);
$assert('audit ap.vendor.portal_banking_updated',         strpos($man, 'ap.vendor.portal_banking_updated') !== false);

// ─── UI ───
echo "Phase A1 V2 — UI components\n";
$mod = file_get_contents(__DIR__ . '/../modules/ap/ui/APModule.jsx');
$assert('APModule imports RecurringBills',                strpos($mod, 'import RecurringBills') !== false);
$assert('APModule imports PurchaseOrders',                strpos($mod, 'import PurchaseOrders') !== false);
$assert('APModule routes /recurring',                     strpos($mod, 'path="recurring"') !== false);
$assert('APModule routes /purchase-orders',               strpos($mod, 'path="purchase-orders"') !== false);

$rec = file_get_contents(__DIR__ . '/../modules/ap/ui/RecurringBills.jsx');
$assert('Recurring page testid',                          strpos($rec, 'data-testid="ap-recurring"') !== false);
$assert('Recurring generate-due button',                  strpos($rec, 'data-testid="ap-recurring-generate-due"') !== false);
$assert('Recurring new schedule button',                  strpos($rec, 'data-testid="ap-recurring-new"') !== false);
$assert('Recurring editor frequency picker',              strpos($rec, 'data-testid="ap-recurring-frequency"') !== false);

$po = file_get_contents(__DIR__ . '/../modules/ap/ui/PurchaseOrders.jsx');
$assert('PO page testid',                                 strpos($po, 'data-testid="ap-purchase-orders"') !== false);
$assert('PO new button',                                  strpos($po, 'data-testid="ap-po-new"') !== false);
$assert('PO record-receipt button',                       strpos($po, 'data-testid="ap-po-record-receipt"') !== false);

$twmui = file_get_contents(__DIR__ . '/../modules/ap/ui/ThreeWayMatchPanel.jsx');
$assert('3WM panel testid',                               strpos($twmui, 'data-testid="ap-bill-three-way-match"') !== false);
$assert('3WM warnings list',                              strpos($twmui, 'data-testid="ap-bill-three-way-warnings"') !== false);

$thread = file_get_contents(__DIR__ . '/../modules/ap/ui/BillApprovalThread.jsx');
$assert('Approval thread testid',                         strpos($thread, 'data-testid="ap-bill-approval-thread"') !== false);
$assert('Approval thread comment input',                  strpos($thread, 'data-testid="ap-bill-comment-input"') !== false);
$assert('Approval thread comment submit',                 strpos($thread, 'data-testid="ap-bill-comment-submit"') !== false);
$assert('Approval thread renders steps + state',          strpos($thread, 'data-testid="ap-bill-approval-steps"') !== false);

$bd = file_get_contents(__DIR__ . '/../modules/ap/ui/BillDetail.jsx');
$assert('BillDetail imports ThreeWayMatchPanel',          strpos($bd, 'import ThreeWayMatchPanel') !== false);
$assert('BillDetail imports BillApprovalThread',          strpos($bd, 'import BillApprovalThread') !== false);
$assert('BillDetail conditionally renders 3WM (po_number)', strpos($bd, '{bill.po_number && <ThreeWayMatchPanel') !== false);
$assert('BillDetail renders BillApprovalThread',          strpos($bd, '<BillApprovalThread') !== false);

$ap = file_get_contents(__DIR__ . '/../modules/ap/ui/Approvals.jsx');
$assert('Approvals shows pending count badge',            strpos($ap, 'data-testid="ap-approvals-pending-badge"') !== false);
$assert('Approvals fetches count_pending=1',              strpos($ap, 'count_pending=1') !== false);

$led = file_get_contents(__DIR__ . '/../modules/ap/ui/Ledger1099.jsx');
$assert('1099 readiness toggle',                          strpos($led, 'data-testid="ap-1099-readiness-toggle"') !== false);
$assert('1099 readiness panel',                           strpos($led, 'data-testid="ap-1099-readiness"') !== false);
$assert('1099 fetches ?action=readiness',                 strpos($led, 'action=readiness') !== false);

$vpUi = file_get_contents(__DIR__ . '/../dashboard/src/pages/VendorPortal.jsx');
$assert('VendorPortal tab nav',                           strpos($vpUi, 'vendor-portal-tab-${v}') !== false);
$assert('VendorPortal documents tab option',              strpos($vpUi, "['documents','Documents']") !== false);
$assert('VendorPortal banking tab option',                strpos($vpUi, "['banking','Banking']") !== false);
$assert('VendorPortal upload posts to upload_document',   strpos($vpUi, 'action=upload_document') !== false);
$assert('VendorPortal banking save action',               strpos($vpUi, 'action=update_banking') !== false);
$assert('VendorPortal banking save testid',               strpos($vpUi, 'data-testid="vendor-portal-banking-save"') !== false);
$assert('VendorPortal doc upload testid',                 strpos($vpUi, 'data-testid="vendor-portal-doc-upload"') !== false);

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
