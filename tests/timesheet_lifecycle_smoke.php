<?php
/**
 * Smoke — Timesheet Lifecycle Resolver + UI wiring.
 *
 * Asserts:
 *   1. lib/lifecycle.php declares the resolver surface
 *   2. api/lifecycle.php is auth-gated and routes both actions
 *   3. The full downstream cascade (SQLite live exercise):
 *       timesheet → time_entry → AR invoice → billing JE → AR cash
 *                              → AP bill   → AP JE      → AP payment (with rail metadata)
 *                              → PWP audit events
 *   4. UI files exist + are wired (route, view-lifecycle link, PWP banner)
 *   5. Bundle integration: lifecycle component referenced in built bundle
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$libSrc = (string) @file_get_contents('/app/modules/staffing/lib/lifecycle.php');
$apiSrc = (string) @file_get_contents('/app/modules/staffing/api/lifecycle.php');
$uiPg   = (string) @file_get_contents('/app/modules/staffing/ui/TimesheetLifecycle.jsx');
$uiTl   = (string) @file_get_contents('/app/dashboard/src/components/TimesheetLifecycleTimeline.jsx');
$module = (string) @file_get_contents('/app/modules/staffing/ui/StaffingModule.jsx');
$tsDet  = (string) @file_get_contents('/app/modules/staffing/ui/TimesheetDetail.jsx');
$billDet= (string) @file_get_contents('/app/modules/ap/ui/BillDetail.jsx');

echo "\n1. lib/lifecycle.php exposes resolvers\n";
$a('staffingTimesheetLifecycle declared',
    str_contains($libSrc, 'function staffingTimesheetLifecycle(int $tenantId, int $timesheetId): array'));
$a('staffingTimeEntryLifecycle declared',
    str_contains($libSrc, 'function staffingTimeEntryLifecycle(int $tenantId, int $entryId): array'));
$a('walks accounting_events for accrual JEs',
    str_contains($libSrc, "ae.source_module = 'staffing'")
    && str_contains($libSrc, 'ae.source_record_id LIKE :pfx'));
$a('walks billing_invoice_lines via source_type=time_entry',
    str_contains($libSrc, "bil.source_type IN ('time_entry','time')"));
$a('walks ap_bill_lines via source_type=time_entry',
    str_contains($libSrc, "abl.source_type IN ('time_entry','time')"));
$a('resolves AP JE via source_module=ap',
    (bool) preg_match("/source_module\s*=\s*'ap'/", $libSrc));
$a('resolves billing JE via source_module=billing',
    (bool) preg_match("/source_module\s*=\s*'billing'/", $libSrc));
$a('returns AR cash via billing_payment_allocations',
    str_contains($libSrc, 'billing_payment_allocations'));
$a('returns AP cash + rail metadata via ap_payment_allocations',
    str_contains($libSrc, 'ap_payment_allocations')
    && str_contains($libSrc, 'disbursement_rail')
    && str_contains($libSrc, 'rail_external_ref'));
$a('returns PWP audit events',
    str_contains($libSrc, 'ap.bill.pwp.linked')
    && str_contains($libSrc, 'ap.bill.pwp.released'));
$a('returns summary rollups',
    str_contains($libSrc, "'revenue_billed'")
    && str_contains($libSrc, "'ar_collected'")
    && str_contains($libSrc, "'vendor_owed'")
    && str_contains($libSrc, "'vendor_paid'"));

echo "\n2. api/lifecycle.php is auth-gated and routes both actions\n";
$a('requires auth', str_contains($apiSrc, 'api_require_auth()'));
$a('requires staffing.timesheets.read', str_contains($apiSrc, "rbac_legacy_require(\$user, 'staffing.timesheets.read')"));
$a('routes action=timesheet', str_contains($apiSrc, "\$action === 'timesheet'"));
$a('routes action=entry',     str_contains($apiSrc, "\$action === 'entry'"));
$a('only GET method',         str_contains($apiSrc, "method !== 'GET'"));

echo "\n3. UI files exist & are wired\n";
$a('TimesheetLifecycle page exists', $uiPg !== '');
$a('Timeline component exists',      $uiTl !== '');
$a('page imports TimesheetLifecycleTimeline',
    str_contains($uiPg, 'TimesheetLifecycleTimeline'));
$a('page calls correct API path (timesheet)',
    str_contains($uiPg, "action=timesheet&id=\${id}"));
$a('page supports entry_id narrow',
    str_contains($uiPg, "action=entry&id=\${entryId}"));
$a('StaffingModule.jsx adds lifecycle route',
    str_contains($module, 'timesheets/:id/lifecycle')
    && str_contains($module, 'import TimesheetLifecycle'));
$a('TimesheetDetail surfaces the cascade link',
    str_contains($tsDet, 'timesheet-detail-view-lifecycle')
    && str_contains($tsDet, '/lifecycle'));
$a('Timeline renders summary rollups',
    str_contains($uiTl, 'lifecycle-stat-revenue-billed')
    && str_contains($uiTl, 'lifecycle-stat-vendor-paid'));
$a('Timeline renders all 4 steps',
    str_contains($uiTl, 'lifecycle-step-approval')
    && str_contains($uiTl, 'lifecycle-step-accruals')
    && str_contains($uiTl, 'lifecycle-step-ar')
    && str_contains($uiTl, 'lifecycle-step-ap'));
$a('Timeline shows rail dispatch metadata',
    str_contains($uiTl, 'disbursement_rail')
    && str_contains($uiTl, 'rail_external_ref'));
$a('Timeline shows PWP banner inline',
    str_contains($uiTl, 'lifecycle-pwp-banner-'));

echo "\n4. AP BillDetail surfaces the PWP banner\n";
$a('PWP banner present',
    str_contains($billDet, 'ap-bill-pwp-banner'));
$a('renders awaiting_ar variant',
    str_contains($billDet, 'ap-bill-pwp-awaiting'));
$a('renders triggered variant',
    str_contains($billDet, 'ap-bill-pwp-released'));
$a('links to AR invoice',
    str_contains($billDet, 'ap-bill-pwp-ar-link'));

echo "\n5. Live SQLite cascade exercise\n";

$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo'] = $pdo;

// Build the minimal schema the lifecycle resolver needs.
$pdo->exec("CREATE TABLE tenants (id INTEGER PRIMARY KEY)");
$pdo->exec("INSERT INTO tenants(id) VALUES (1)");
$pdo->exec("CREATE TABLE people (id INTEGER PRIMARY KEY, tenant_id INT, first_name TEXT, last_name TEXT, email_primary TEXT)");
$pdo->exec("INSERT INTO people(id,tenant_id,first_name,last_name,email_primary) VALUES (10,1,'Pat','Test','pat@test.com')");
$pdo->exec("CREATE TABLE placements (id INTEGER PRIMARY KEY, tenant_id INT, title TEXT, end_client_name TEXT)");
$pdo->exec("INSERT INTO placements(id,tenant_id,title,end_client_name) VALUES (100,1,'Engagement A','Acme Co')");

$pdo->exec("CREATE TABLE staffing_timesheets (
    id INTEGER PRIMARY KEY, tenant_id INT, person_id INT,
    period_start TEXT, period_end TEXT, status TEXT,
    total_hours REAL, submitted_at TEXT, approved_at TEXT, rejection_reason TEXT,
    created_at TEXT, updated_at TEXT
)");
$pdo->exec("INSERT INTO staffing_timesheets(id,tenant_id,person_id,period_start,period_end,status,total_hours,submitted_at,approved_at)
    VALUES (500,1,10,'2026-02-01','2026-02-07','approved',40.0,'2026-02-08 09:00:00','2026-02-08 11:00:00')");

$pdo->exec("CREATE TABLE time_entries (
    id INTEGER PRIMARY KEY, tenant_id INT, timesheet_id INT, placement_id INT, person_id INT,
    work_date TEXT, hour_type TEXT, hours REAL, billable INT, payable INT,
    description TEXT, status TEXT
)");
$pdo->exec("INSERT INTO time_entries(id,tenant_id,timesheet_id,placement_id,person_id,work_date,hour_type,hours,billable,payable,status)
    VALUES (700,1,500,100,10,'2026-02-03','regular',8.0,1,1,'approved')");
$pdo->exec("INSERT INTO time_entries(id,tenant_id,timesheet_id,placement_id,person_id,work_date,hour_type,hours,billable,payable,status)
    VALUES (701,1,500,100,10,'2026-02-04','regular',8.0,1,1,'approved')");

$pdo->exec("CREATE TABLE billing_invoices (
    id INTEGER PRIMARY KEY, tenant_id INT, invoice_number TEXT, client_name TEXT, currency TEXT,
    issue_date TEXT, due_date TEXT, period_start TEXT, period_end TEXT,
    subtotal REAL, tax_total REAL, total REAL, amount_paid REAL, amount_due REAL, status TEXT
)");
$pdo->exec("INSERT INTO billing_invoices(id,tenant_id,invoice_number,client_name,currency,issue_date,due_date,period_start,period_end,subtotal,tax_total,total,amount_paid,amount_due,status)
    VALUES (900,1,'INV-2026-0001','Acme Co','USD','2026-02-10','2026-03-10','2026-02-01','2026-02-07',1600,0,1600,1600,0,'paid')");

$pdo->exec("CREATE TABLE billing_invoice_lines (
    id INTEGER PRIMARY KEY, invoice_id INT, line_no INT, description TEXT,
    quantity REAL, unit_price REAL, total REAL, placement_id INT,
    source_type TEXT, source_ref_id INT
)");
$pdo->exec("INSERT INTO billing_invoice_lines(id,invoice_id,line_no,description,quantity,unit_price,total,placement_id,source_type,source_ref_id)
    VALUES (1,900,1,'Engagement A · 8h Feb 3',8,100,800,100,'time_entry',700)");
$pdo->exec("INSERT INTO billing_invoice_lines(id,invoice_id,line_no,description,quantity,unit_price,total,placement_id,source_type,source_ref_id)
    VALUES (2,900,2,'Engagement A · 8h Feb 4',8,100,800,100,'time_entry',701)");

$pdo->exec("CREATE TABLE billing_payments (
    id INTEGER PRIMARY KEY, tenant_id INT, received_at TEXT, method TEXT,
    amount REAL, source_system TEXT, external_id TEXT, client_name TEXT
)");
$pdo->exec("INSERT INTO billing_payments(id,tenant_id,received_at,method,amount,source_system,external_id,client_name)
    VALUES (300,1,'2026-02-15','wire',1600,'mercury','wire-xyz','Acme Co')");
$pdo->exec("CREATE TABLE billing_payment_allocations (
    id INTEGER PRIMARY KEY, payment_id INT, invoice_id INT, amount_applied REAL, applied_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("INSERT INTO billing_payment_allocations(payment_id,invoice_id,amount_applied) VALUES (300,900,1600)");

$pdo->exec("CREATE TABLE ap_bills (
    id INTEGER PRIMARY KEY, tenant_id INT, internal_ref TEXT, bill_number TEXT,
    vendor_name TEXT, vendor_type TEXT, bill_date TEXT, due_date TEXT,
    period_start TEXT, period_end TEXT, subtotal REAL, tax_total REAL,
    total REAL, amount_paid REAL, amount_due REAL, status TEXT,
    payment_terms TEXT, linked_ar_invoice_id INT, pwp_status TEXT, pwp_released_at TEXT, currency TEXT
)");
$pdo->exec("INSERT INTO ap_bills(id,tenant_id,internal_ref,bill_number,vendor_name,vendor_type,bill_date,due_date,period_start,period_end,subtotal,tax_total,total,amount_paid,amount_due,status,payment_terms,linked_ar_invoice_id,pwp_status,pwp_released_at,currency)
    VALUES (800,1,'BILL-2026-0001','VENDOR-A-001','Contractor LLC','c2c_corp','2026-02-10','2026-03-10','2026-02-01','2026-02-07',1200,0,1200,1200,0,'paid','PWP_NET10',900,'triggered','2026-02-15 12:00:00','USD')");

$pdo->exec("CREATE TABLE ap_bill_lines (
    id INTEGER PRIMARY KEY, bill_id INT, line_no INT, description TEXT,
    quantity REAL, unit_price REAL, total REAL, placement_id INT,
    source_type TEXT, source_ref_id INT, is_1099_eligible INT DEFAULT 0
)");
$pdo->exec("INSERT INTO ap_bill_lines(id,bill_id,line_no,description,quantity,unit_price,total,placement_id,source_type,source_ref_id)
    VALUES (1,800,1,'Engagement A · 8h Feb 3 pay',8,75,600,100,'time_entry',700)");
$pdo->exec("INSERT INTO ap_bill_lines(id,bill_id,line_no,description,quantity,unit_price,total,placement_id,source_type,source_ref_id)
    VALUES (2,800,2,'Engagement A · 8h Feb 4 pay',8,75,600,100,'time_entry',701)");

$pdo->exec("CREATE TABLE ap_payments (
    id INTEGER PRIMARY KEY, tenant_id INT, pay_date TEXT, method TEXT,
    amount REAL, reference TEXT, status TEXT,
    disbursement_rail TEXT, rail_external_ref TEXT, rail_status TEXT,
    rail_originated_at TEXT, sent_at TEXT, cleared_at TEXT
)");
$pdo->exec("INSERT INTO ap_payments(id,tenant_id,pay_date,method,amount,reference,status,disbursement_rail,rail_external_ref,rail_status,sent_at)
    VALUES (450,1,'2026-02-16','ach',1200,'payment-run','sent','mercury','mp_abc123','submitted','2026-02-16 09:00:00')");
$pdo->exec("CREATE TABLE ap_payment_allocations (
    id INTEGER PRIMARY KEY, payment_id INT, bill_id INT, amount_applied REAL, applied_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("INSERT INTO ap_payment_allocations(payment_id,bill_id,amount_applied) VALUES (450,800,1200)");

$pdo->exec("CREATE TABLE accounting_journal_entries (
    id INTEGER PRIMARY KEY, tenant_id INT, je_number TEXT, posting_date TEXT,
    source_module TEXT, source_ref_type TEXT, source_ref_id INT,
    status TEXT, total_debit REAL, memo TEXT
)");
$pdo->exec("INSERT INTO accounting_journal_entries(id,tenant_id,je_number,posting_date,source_module,source_ref_type,source_ref_id,status,total_debit,memo)
    VALUES (1001,1,'JE-2026-0001','2026-02-10','billing','billing_invoice',900,'posted',1600,'AR for INV-2026-0001')");
$pdo->exec("INSERT INTO accounting_journal_entries(id,tenant_id,je_number,posting_date,source_module,source_ref_type,source_ref_id,status,total_debit,memo)
    VALUES (1002,1,'JE-2026-0002','2026-02-10','ap','ap_bill',800,'posted',1200,'AP for BILL-2026-0001')");

$pdo->exec("CREATE TABLE accounting_events (
    id INTEGER PRIMARY KEY, tenant_id INT, entity_id INT, event_type TEXT,
    source_module TEXT, source_record_id TEXT, event_date TEXT, payload TEXT,
    status TEXT, posting_rule_id INT, journal_entry_id INT, error_message TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("INSERT INTO accounting_events(tenant_id,entity_id,event_type,source_module,source_record_id,event_date,status,journal_entry_id)
    VALUES (1,1,'staffing.worker_hours.approved','staffing','500:c2c','2026-02-08','posted',1001)");

$pdo->exec("CREATE TABLE audit_log (
    id INTEGER PRIMARY KEY, tenant_id INT, actor_user_id INT, event TEXT, target_id INT,
    meta_json TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("INSERT INTO audit_log(tenant_id,event,target_id,meta_json) VALUES (1,'staffing.timesheet.submitted',500,'{}')");
$pdo->exec("INSERT INTO audit_log(tenant_id,event,target_id,meta_json) VALUES (1,'staffing.timesheet.approved',500,'{}')");
$pdo->exec("INSERT INTO audit_log(tenant_id,event,target_id,meta_json) VALUES (1,'ap.bill.pwp.linked',800,'{\"ar_invoice_id\":900}')");
$pdo->exec("INSERT INTO audit_log(tenant_id,event,target_id,meta_json) VALUES (1,'ap.bill.pwp.released',800,'{\"ar_invoice_id\":900,\"new_due_date\":\"2026-02-25\"}')");

// Bridge SUT to our in-memory PDO + tenant context.
$GLOBALS['__cf_test_pdo'] = $pdo;
$GLOBALS['__cf_test_tenant_id'] = 1;
if (!function_exists('getDB')) {
    function getDB(): \PDO { return $GLOBALS['__cf_test_pdo']; }
}
if (!function_exists('currentTenantId')) {
    function currentTenantId(): int { return (int) $GLOBALS['__cf_test_tenant_id']; }
}
if (!function_exists('currentSubTenantId')) {
    function currentSubTenantId(): ?int { return null; }
}
if (!function_exists('scopedFind')) {
    function scopedFind(string $sql, array $params = []): ?array {
        $pdo = getDB();
        $params = array_merge(['tenant_id' => currentTenantId()], $params);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
if (!function_exists('scopedQuery')) {
    function scopedQuery(string $sql, array $params = []): array {
        $pdo = getDB();
        $params = array_merge(['tenant_id' => currentTenantId()], $params);
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}

// Load lib via eval — strip the tenant_scope require + the <?php tag so
// our local stubs of getDB/scopedFind/scopedQuery/currentTenantId stay
// authoritative (loading the real tenant_scope.php pulls core/db.php
// which requires MySQL drivers absent in the sandbox).
$libBody = (string) file_get_contents('/app/modules/staffing/lib/lifecycle.php');
$libBody = preg_replace("/require_once __DIR__ \\. '\\/\\.\\.\\/\\.\\.\\/\\.\\.\\/core\\/tenant_scope\\.php';/", '', $libBody);
$libBody = preg_replace('/^\s*<\?php/', '', $libBody);
$libBody = preg_replace('/declare\(strict_types=1\);/', '', $libBody);
eval($libBody);

$cascade = staffingTimesheetLifecycle(1, 500);
$a('cascade returns the timesheet header',
    ($cascade['timesheet']['id'] ?? null) == 500);
$a('cascade returns 2 entries',
    count($cascade['entries'] ?? []) === 2);
$a('cascade walks 1 accrual event',
    count($cascade['accrual_events'] ?? []) === 1);
$a('cascade resolves staffing accrual JE',
    ($cascade['accrual_events'][0]['je_number'] ?? null) === 'JE-2026-0001');
$a('cascade returns AR side',
    count($cascade['ar'] ?? []) === 1
    && ($cascade['ar'][0]['invoice']['invoice_number'] ?? null) === 'INV-2026-0001');
$a('cascade AR returns 2 lines (both entries)',
    count($cascade['ar'][0]['lines'] ?? []) === 2);
$a('cascade AR resolves billing JE',
    ($cascade['ar'][0]['je']['je_number'] ?? null) === 'JE-2026-0001');
$a('cascade AR resolves 1 payment',
    count($cascade['ar'][0]['payments'] ?? []) === 1
    && (float) $cascade['ar'][0]['payments'][0]['amount_applied'] === 1600.0);
$a('cascade returns AP side',
    count($cascade['ap'] ?? []) === 1
    && ($cascade['ap'][0]['bill']['internal_ref'] ?? null) === 'BILL-2026-0001');
$a('cascade AP resolves AP JE',
    ($cascade['ap'][0]['je']['je_number'] ?? null) === 'JE-2026-0002');
$a('cascade AP resolves vendor payment with rail metadata',
    count($cascade['ap'][0]['payments'] ?? []) === 1
    && $cascade['ap'][0]['payments'][0]['disbursement_rail'] === 'mercury'
    && $cascade['ap'][0]['payments'][0]['rail_external_ref'] === 'mp_abc123');
$a('cascade AP captures PWP linked + released events',
    count($cascade['ap'][0]['pwp_events'] ?? []) === 2);
$a('summary revenue_billed correct', (float) $cascade['summary']['revenue_billed'] === 1600.0);
$a('summary ar_collected correct',   (float) $cascade['summary']['ar_collected']   === 1600.0);
$a('summary vendor_owed correct',    (float) $cascade['summary']['vendor_owed']    === 1200.0);
$a('summary vendor_paid correct',    (float) $cascade['summary']['vendor_paid']    === 1200.0);
$a('approvals submitted_at captured',
    !empty($cascade['approvals']['submitted_at']));
$a('approvals audit_events present',
    count($cascade['approvals']['audit_events'] ?? []) === 2);

// Narrow to a single entry — confirm only the relevant artifacts ride along.
$entryCascade = staffingTimeEntryLifecycle(1, 700);
$a('entry-narrowed cascade returns focused_entry',
    ($entryCascade['focused_entry']['id'] ?? null) == 700);
$a('entry-narrowed AR still includes invoice (entry 700 referenced)',
    count($entryCascade['ar']) === 1);
$a('entry-narrowed AP still includes bill (entry 700 referenced)',
    count($entryCascade['ap']) === 1);

// Negative case: timesheet without downstream artifacts.
$pdo->exec("INSERT INTO staffing_timesheets(id,tenant_id,person_id,period_start,period_end,status,total_hours)
    VALUES (501,1,10,'2026-02-08','2026-02-14','draft',0)");
$emptyCascade = staffingTimesheetLifecycle(1, 501);
$a('empty timesheet returns 0 ar/ap rows',
    count($emptyCascade['ar']) === 0 && count($emptyCascade['ap']) === 0);
$a('empty timesheet summary is zero',
    (float) $emptyCascade['summary']['vendor_paid'] === 0.0);

echo "\n6. Vite bundle integration\n";
$dv = trim((string) @file_get_contents('/app/.deploy-version'));
$a('.deploy-version present', $dv !== '');
$bundleHashJs = '';
if (preg_match('/index-([a-zA-Z0-9_-]+)\.js/', $dv, $m)) $bundleHashJs = $m[0];
// The build output lives in /app/spa-assets/ (synced from dist via sync_bundle.sh).
$jsBundle = '';
foreach (['/app/spa-assets/', '/app/dashboard/dist/spa-assets/'] as $dir) {
    if ($bundleHashJs && is_file($dir . $bundleHashJs)) {
        $jsBundle = (string) @file_get_contents($dir . $bundleHashJs);
        break;
    }
}
$a('bundle includes lifecycle-step-approval testid',
    $jsBundle !== '' && (str_contains($jsBundle, 'lifecycle-step-approval')
                       || str_contains($jsBundle, 'timesheet-lifecycle-timeline')),
    $bundleHashJs ? "bundle={$bundleHashJs}" : 'no bundle hash resolved');

echo "\n— pass={$pass}  fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
