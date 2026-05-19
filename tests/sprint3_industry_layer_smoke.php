<?php
/**
 * Sprint 3 — Industry Layer 1 (Staffing) + Push primitive — static smoke.
 *
 *   php -d zend.assertions=1 /app/tests/sprint3_industry_layer_smoke.php
 *
 * Verifies:
 *   1. Push outbox migration + push_service.php exports + log driver path.
 *   2. People worker_class migration adds column + index.
 *   3. people/lib/worker_class.php — class enum + routing helpers.
 *   4. AP migration creates approval_policies + evaluations + vendor_risk + evidence_bundles.
 *   5. ap/lib/approval_router.php exports + chain JSON shape.
 *   6. ap/lib/vendor_risk.php exports + score-to-level math.
 *   7. ap/lib/evidence_bundle.php exports.
 *   8. 3 new AP API endpoints exist + parse + RBAC guards use real perms.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/push_service.php';
require_once __DIR__ . '/../modules/people/lib/worker_class.php';
require_once __DIR__ . '/../modules/ap/lib/approval_router.php';
require_once __DIR__ . '/../modules/ap/lib/vendor_risk.php';

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};

echo "Push primitive\n";
$pushSql = (string) file_get_contents(__DIR__ . '/../core/migrations/018_push_outbox.sql');
$assert('migration file present',                strlen($pushSql) > 0);
$assert('CREATE tenant_push_outbox',             stripos($pushSql, 'CREATE TABLE IF NOT EXISTS tenant_push_outbox') !== false);
foreach (['device_id','title','body','data_json','category','deep_link','driver','status','attempts','source_module','source_event'] as $c) {
    $assert("tenant_push_outbox.{$c}", stripos($pushSql, $c) !== false);
}
$assert("driver enum has log/apns/fcm",          stripos($pushSql, "ENUM('log','apns','fcm')") !== false);
$assert("status enum",                           stripos($pushSql, "ENUM('queued','sending','delivered','failed','suppressed')") !== false);

$pushSrc = (string) file_get_contents(__DIR__ . '/../core/push_service.php');
foreach (['pushSendToUser','pushSendToTenant','pushDispatchOutbox','_pushDriverLog','_pushDriverApns','_pushDriverFcm','_pushPickDriver'] as $fn) {
    $assert("push_service exports {$fn}", stripos($pushSrc, "function {$fn}(") !== false);
}
$assert('push_service.php parses',               $lint(__DIR__ . '/../core/push_service.php'));
$assert('default driver constant = log',         defined('PUSH_DRIVER_LOG') && PUSH_DRIVER_LOG === 'log');

// Driver pick — pure logic, no DB needed.
$assert('pick driver: ios + apns_token + APNs unset → log',
    _pushPickDriver('ios', 'tok', '') === 'log');
$assert('pick driver: android + fcm_token + FCM unset → log',
    _pushPickDriver('android', '', 'tok') === 'log');
$assert('pick driver: web + no fcm_token → log',
    _pushPickDriver('web', '', '') === 'log');

echo "\nC1 worker_class\n";
$wcSql = (string) file_get_contents(__DIR__ . '/../modules/people/migrations/007_worker_class.sql');
$assert('migration present',                     strlen($wcSql) > 0);
foreach (['worker_class','employee','w2_temp','contractor_1099','c2c','eor','referral','vendor_backed'] as $c) {
    $assert("migration mentions {$c}", stripos($wcSql, $c) !== false);
}
$assert('idempotent column add (information_schema)', stripos($wcSql, 'information_schema.COLUMNS') !== false);
$assert('idempotent index add',                  stripos($wcSql, 'idx_people_tenant_worker_class') !== false);

$assert('PEOPLE_WORKER_CLASSES constant',        defined('PEOPLE_WORKER_CLASSES') && in_array('contractor_1099', PEOPLE_WORKER_CLASSES, true));
$assert('routing: employee → payroll',           peopleWorkerClassRouting('employee') === ['payroll']);
$assert('routing: w2_temp → payroll + ar',       peopleWorkerClassRouting('w2_temp') === ['payroll','ar']);
$assert('routing: contractor_1099 → ap + ar',    peopleWorkerClassRouting('contractor_1099') === ['ap','ar']);
$assert('routing: c2c → ap + ar',                peopleWorkerClassRouting('c2c') === ['ap','ar']);
$assert('routing: referral → ap',                peopleWorkerClassRouting('referral') === ['ap']);
$assert('routing: unknown → payroll fallback',   peopleWorkerClassRouting('weird') === ['payroll']);
$assert('isW2: employee=true',                   peopleWorkerClassIsW2('employee') === true);
$assert('isW2: contractor_1099=false',           peopleWorkerClassIsW2('contractor_1099') === false);
$assert('isBillable: employee=false',            peopleWorkerClassIsBillable('employee') === false);
$assert('isBillable: w2_temp=true',              peopleWorkerClassIsBillable('w2_temp') === true);
$assert('label: contractor_1099',                peopleWorkerClassLabel('contractor_1099') === '1099 Contractor');

echo "\nC2/C3/C4 — AP migrations\n";
$apSql = (string) file_get_contents(__DIR__ . '/../modules/ap/migrations/016_approval_policies_risk_evidence.sql');
foreach (['ap_approval_policies','ap_approval_policy_evaluations','ap_vendor_risk','ap_bill_evidence_bundles'] as $tbl) {
    $assert("migration creates {$tbl}", stripos($apSql, "CREATE TABLE IF NOT EXISTS {$tbl}") !== false);
}
foreach (['priority','entity_id','vendor_type','min_amount','max_amount','min_risk_level','gl_account_code','chain_json','quorum','sla_hours','active'] as $c) {
    $assert("ap_approval_policies.{$c}", stripos($apSql, $c) !== false);
}
$assert('vendor_risk enum: none/low/medium/high', stripos($apSql, "ENUM('none','low','medium','high')") !== false);
foreach (['risk_score','factors_json','requires_manual_review','last_evaluated_at'] as $c) {
    $assert("ap_vendor_risk.{$c}", stripos($apSql, $c) !== false);
}
foreach (['timesheet_period_ids_json','placement_ids_json','approval_trail_json','payroll_run_ids_json','audit_hash','bundle_summary_json'] as $c) {
    $assert("ap_bill_evidence_bundles.{$c}", stripos($apSql, $c) !== false);
}

echo "\nC2 router lib\n";
$rt = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/approval_router.php');
foreach (['apEvaluateApprovalPolicy','apRouteBillForApproval','_apPolicyMatches'] as $fn) {
    $assert("approval_router exports {$fn}", stripos($rt, "function {$fn}(") !== false);
}
$assert('approval_router.php parses',            $lint(__DIR__ . '/../modules/ap/lib/approval_router.php'));
$assert('router fires push on match',            stripos($rt, 'pushSendToUser(') !== false);
$assert('router writes evaluation log',          stripos($rt, 'INSERT INTO ap_approval_policy_evaluations') !== false);

// Pure-PHP test of _apPolicyMatches.
$pol = ['entity_id' => null, 'vendor_type' => null, 'min_amount' => 1000, 'max_amount' => null, 'min_risk_level' => null, 'gl_account_code' => null];
$assert('policy matches: amount above min',      _apPolicyMatches($pol, 1, 5000.0, '1099', null, 'none') === true);
$assert('policy rejects: amount below min',      _apPolicyMatches($pol, 1, 500.0,  '1099', null, 'none') === false);
$polE = ['entity_id' => 7, 'vendor_type' => null, 'min_amount' => null, 'max_amount' => null, 'min_risk_level' => null, 'gl_account_code' => null];
$assert('policy rejects: entity mismatch',       _apPolicyMatches($polE, 8, 100.0, null, null, 'none') === false);
$assert('policy matches: entity exact',          _apPolicyMatches($polE, 7, 100.0, null, null, 'none') === true);
$polR = ['entity_id' => null, 'vendor_type' => null, 'min_amount' => null, 'max_amount' => null, 'min_risk_level' => 'medium', 'gl_account_code' => null];
$assert('policy matches: risk = high (>= medium)', _apPolicyMatches($polR, 1, 1.0, null, null, 'high')   === true);
$assert('policy rejects: risk = low (< medium)',   _apPolicyMatches($polR, 1, 1.0, null, null, 'low')    === false);

echo "\nC3 risk lib\n";
$vr = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/vendor_risk.php');
foreach (['apVendorRiskFor','apVendorRiskRecompute','apVendorRiskDefault'] as $fn) {
    $assert("vendor_risk exports {$fn}", stripos($vr, "function {$fn}(") !== false);
}
$assert('vendor_risk.php parses',                $lint(__DIR__ . '/../modules/ap/lib/vendor_risk.php'));
$assert('threshold constants',                   defined('AP_VENDOR_RISK_THRESHOLD_HIGH') && AP_VENDOR_RISK_THRESHOLD_HIGH === 50);
$assert('default returns level=none',            apVendorRiskDefault()['level'] === 'none');

echo "\nC4 evidence lib\n";
$ev = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/evidence_bundle.php');
foreach (['apBuildEvidenceBundle','apGetEvidenceBundle','_apEvidenceTimesheetPeriodIds','_apEvidencePlacementIds','_apEvidenceApprovalTrail','_apEvidencePayrollRunIds'] as $fn) {
    $assert("evidence_bundle exports {$fn}", stripos($ev, "function {$fn}(") !== false);
}
$assert('evidence_bundle.php parses',            $lint(__DIR__ . '/../modules/ap/lib/evidence_bundle.php'));
$assert('evidence stores audit_hash',            stripos($ev, "hash('sha256'") !== false);

echo "\nAPI endpoints\n";
$apis = [
    'modules/ap/api/approval_policies.php' => "rbac_legacy_require(\$user, 'ap.bills.approve_admin')",
    'modules/ap/api/vendor_risk.php'       => "rbac_legacy_require(\$user, 'ap.view')",
    'modules/ap/api/bill_evidence.php'     => "rbac_legacy_require(\$user, 'ap.view')",
];
foreach ($apis as $rel => $expectedGuard) {
    $p = __DIR__ . '/../' . $rel;
    $assert("API exists: {$rel}",   is_file($p));
    $assert("API parses: {$rel}",   $lint($p));
    $src = (string) file_get_contents($p);
    $assert("API uses real RBAC perm: {$rel}", stripos($src, $expectedGuard) !== false);
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
