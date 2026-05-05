<?php
/**
 * AP Phase A1 follow-ups smoke.
 *   (i)   Bill approval workflows + per-bill approval steps
 *   (ii)  Vendor self-service portal (magic-link auth)
 *   (iii) 1099-NEC printable forms
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; } else { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc); return $rc === 0;
};

// ─── Migrations ───
echo "AP migrations 010 + 011\n";
$mig10 = file_get_contents(__DIR__ . '/../modules/ap/migrations/010_approval_workflows.sql');
$assert('010 creates ap_approval_workflows',          strpos($mig10, 'CREATE TABLE IF NOT EXISTS ap_approval_workflows') !== false);
$assert('010 creates ap_approval_workflow_rules',     strpos($mig10, 'CREATE TABLE IF NOT EXISTS ap_approval_workflow_rules') !== false);
$assert('010 creates ap_bill_approvals',              strpos($mig10, 'CREATE TABLE IF NOT EXISTS ap_bill_approvals') !== false);
$assert('010 has min_amount + max_amount brackets',   strpos($mig10, 'min_amount') !== false && strpos($mig10, 'max_amount') !== false);
$assert('010 step_no for multi-step',                 strpos($mig10, 'step_no') !== false);
$assert('010 state ENUM pending|approved|rejected',   strpos($mig10, "ENUM('pending','approved','rejected')") !== false);
$mig11 = file_get_contents(__DIR__ . '/../modules/ap/migrations/011_vendor_portal.sql');
$assert('011 creates ap_vendor_portal_tokens',        strpos($mig11, 'CREATE TABLE IF NOT EXISTS ap_vendor_portal_tokens') !== false);
$assert('011 stores SHA-256 token_hash (not raw)',    strpos($mig11, 'token_hash') !== false);
$assert('011 creates ap_vendor_portal_sessions',      strpos($mig11, 'CREATE TABLE IF NOT EXISTS ap_vendor_portal_sessions') !== false);

// ─── Approval workflows API ───
echo "modules/ap/api/approval_workflows.php\n";
$wf = file_get_contents(__DIR__ . '/../modules/ap/api/approval_workflows.php');
$assert('GET lists workflows + rules',                strpos($wf, 'rules: [...]') !== false || strpos($wf, "ap_approval_workflow_rules") !== false);
$assert('POST creates with name + rules',             strpos($wf, "INSERT INTO ap_approval_workflows") !== false);
$assert('PATCH replaces rules wholesale',             strpos($wf, 'DELETE FROM ap_approval_workflow_rules') !== false);
$assert('DELETE soft-deletes (is_active=0)',          strpos($wf, "SET is_active = 0") !== false);
$assert('writes via transaction',                     strpos($wf, 'beginTransaction()') !== false);
$assert('PHP parses cleanly',                         $lint(__DIR__ . '/../modules/ap/api/approval_workflows.php'));

// ─── Bill approvals API ───
echo "modules/ap/api/bill_approvals.php\n";
$ba = file_get_contents(__DIR__ . '/../modules/ap/api/bill_approvals.php');
$assert('GET ?inbox=1 returns pending steps',         strpos($ba, 'inbox') !== false && strpos($ba, "a.state            = 'pending'") !== false);
$assert('?action=submit fans out from rules',         strpos($ba, "action === 'submit'") !== false  // JS-style; real check next
                                                   || strpos($ba, "\$action === 'submit'") !== false);
$assert('submit picks default workflow',              strpos($ba, "is_default DESC") !== false);
$assert('submit bracket query :a >= min_amount',      strpos($ba, ':a >= min_amount') !== false);
$assert('approve action advances chain',              strpos($ba, "approve") !== false && strpos($ba, "state = 'pending'") !== false);
$assert('reject sets bill.status=disputed',           strpos($ba, "SET status = 'disputed'") !== false);
$assert('all steps approved → bill.status=approved',  strpos($ba, "SET status = 'approved'") !== false);
$assert('uses approved_by_user_id (not approved_by)', strpos($ba, 'approved_by_user_id') !== false);
$assert('blocks step N if N-1 still pending',         strpos($ba, 'A prior step is still pending') !== false);
$assert('PHP parses cleanly',                         $lint(__DIR__ . '/../modules/ap/api/bill_approvals.php'));

// ─── Vendor portal API ───
echo "modules/ap/api/vendor_portal.php\n";
$vp = file_get_contents(__DIR__ . '/../modules/ap/api/vendor_portal.php');
$assert('?action=invite generates token',             strpos($vp, "action === 'invite'") !== false || strpos($vp, "\$action === 'invite'") !== false);
$assert('invite stores SHA-256 token_hash only',      strpos($vp, "hash('sha256', \$token)") !== false);
$assert('invite returns magic_link',                  strpos($vp, "'magic_link'") !== false);
$assert('?action=redeem opens session',               strpos($vp, "action === 'redeem'") !== false);
$assert('redeem sets HttpOnly cf_vp_sid cookie',      strpos($vp, "cf_vp_sid") !== false && strpos($vp, "'httponly' => true") !== false);
$assert('?action=me returns vendor + bills + payments',
                                                      strpos($vp, "action === 'me'") !== false
                                                   && strpos($vp, "'bills'") !== false
                                                   && strpos($vp, "'payments'") !== false);
$assert('me requires session cookie',                 strpos($vp, "Not authenticated as vendor") !== false);
$assert('PHP parses cleanly',                         $lint(__DIR__ . '/../modules/ap/api/vendor_portal.php'));

// ─── 1099 printable ───
echo "modules/ap/api/1099.php (printable)\n";
$ten99 = file_get_contents(__DIR__ . '/../modules/ap/api/1099.php');
$assert('GET ?action=print emits HTML',               strpos($ten99, "action === 'print'") !== false
                                                   && strpos($ten99, 'Content-Type: text/html') !== false);
$assert('renders PAYER + RECIPIENT blocks',           strpos($ten99, 'render1099NecHtml') !== false);
$assert('Form 1099-NEC heading',                      strpos($ten99, 'Form 1099-NEC') !== false);
$assert('Box 1 nonemployee compensation',             strpos($ten99, 'Box 1. Nonemployee compensation') !== false);
$assert('print toolbar + window.print()',             strpos($ten99, 'window.print()') !== false);
$assert('escapes HTML via h() helper',                strpos($ten99, 'function h($s)') !== false);
$assert('print is gated by ap.1099.view',             strpos($ten99, "'ap.1099.view'") !== false);
$assert('PHP parses cleanly',                         $lint(__DIR__ . '/../modules/ap/api/1099.php'));

// ─── UI ───
echo "AP UI (Approvals + Vendor Portal)\n";
$apMod = file_get_contents(__DIR__ . '/../modules/ap/ui/APModule.jsx');
$assert('APModule navItems include Approvals',        strpos($apMod, "label: 'Approvals'") !== false);
$assert('APModule routes approvals',                  strpos($apMod, '<Route path="approvals"') !== false);

$ap_ui = file_get_contents(__DIR__ . '/../modules/ap/ui/Approvals.jsx');
$assert('Approvals page has Inbox tab',               strpos($ap_ui, 'data-testid="ap-approvals-tab-inbox"') !== false);
$assert('Approvals page has Workflows admin tab',     strpos($ap_ui, 'data-testid="ap-approvals-tab-workflows"') !== false);
$assert('Inbox decide → approve / reject',            strpos($ap_ui, "decide(r.bill_id, 'approve')") !== false
                                                   && strpos($ap_ui, "decide(r.bill_id, 'reject')") !== false);
$assert('WorkflowEditor has step / min$ / max$ / approver',
                                                      strpos($ap_ui, "Step") !== false
                                                   && strpos($ap_ui, 'placeholder="Min $"') !== false
                                                   && strpos($ap_ui, "Pick approver") !== false);

$vp_ui = file_get_contents(__DIR__ . '/../dashboard/src/pages/VendorPortal.jsx');
$assert('VendorPortal calls /vendor_portal.php?action=me',
                                                      strpos($vp_ui, "/api/ap/vendor_portal.php?action=me") !== false);
$assert('VendorPortal renders bills + payments',      strpos($vp_ui, 'data-testid="vendor-portal-bills-table"') !== false
                                                   && strpos($vp_ui, 'data-testid="vendor-portal-payments-table"') !== false);
$assert('VendorPortal shows expired-link state',      strpos($vp_ui, 'data-testid="vendor-portal-no-session"') !== false);

$app = file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');
$assert('App route /vendor/portal',                   strpos($app, '<Route path="/vendor/portal"') !== false);

$vList = file_get_contents(__DIR__ . '/../modules/ap/ui/VendorsList.jsx');
$assert('Vendors list has Portal invite button',     strpos($vList, 'PortalInviteButton') !== false
                                                   && strpos($vList, 'ap-vendor-portal-invite-${vendor.id}') !== false);

$led = file_get_contents(__DIR__ . '/../modules/ap/ui/Ledger1099.jsx');
$assert('1099 ledger has Print button',               strpos($led, 'data-testid="ap-1099-print"') !== false);
$assert('1099 print opens new window',                strpos($led, "window.open(`/api/ap/1099.php?action=print") !== false);

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
