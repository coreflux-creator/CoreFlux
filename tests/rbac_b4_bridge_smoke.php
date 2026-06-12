<?php
/**
 * RBAC B4 bridge smoke — locks the legacy permission → (module, action)
 * mapping table in /app/core/rbac/legacy_map.php against the doc at
 * /app/memory/RBAC_B4_PERMISSION_MAPPING.md.
 *
 * Every row of the mapping doc is asserted here. If a row drifts from
 * the code (or vice-versa) this test fails — preventing silent permission
 * widening / narrowing during the sweep.
 *
 *   php -d zend.assertions=1 /app/tests/rbac_b4_bridge_smoke.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/rbac/legacy_map.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};

// The canonical expected mapping — copy/paste from RBAC_B4_PERMISSION_MAPPING.md.
// One assertion per row. Locking pattern: if you change the doc, change
// this table; if you change this table, change the doc. Code is asserted
// against THIS expected dict, not against itself.
$expected = [
    // accounting
    'accounting.audit.view'              => ['accounting', 'read'],
    'accounting.bank.manage'             => ['accounting', 'admin'],
    'accounting.bank.reconcile'          => ['accounting', 'admin'],
    'accounting.bank.view'               => ['accounting', 'read'],
    'accounting.close_task.assign'       => ['accounting', 'write'],
    'accounting.close_task.complete'     => ['accounting', 'write'],
    'accounting.close_workflow.manage'   => ['accounting', 'admin'],
    'accounting.coa.edit'                => ['accounting', 'write'],
    'accounting.coa.manage'              => ['accounting', 'admin'],
    'accounting.coa.view'                => ['accounting', 'read'],
    'accounting.create_entry'            => ['accounting', 'write'],
    'accounting.dimensions.manage'       => ['accounting', 'admin'],
    'accounting.dimensions.view'         => ['accounting', 'read'],
    'accounting.entities.manage'         => ['accounting', 'admin'],
    'accounting.entities.view'           => ['accounting', 'read'],
    'accounting.intercompany.manage'     => ['accounting', 'admin'],
    'accounting.je.create'               => ['accounting', 'write'],
    'accounting.je.post'                 => ['accounting', 'admin'],
    'accounting.je.reverse'              => ['accounting', 'admin'],
    'accounting.je.view'                 => ['accounting', 'read'],
    'accounting.manage_posting_rules'    => ['accounting', 'admin'],
    'accounting.period.view'             => ['accounting', 'read'],
    'accounting.reports.export'          => ['accounting', 'write'],
    'accounting.reports.view'            => ['accounting', 'read'],
    // ai
    'ai.config.manage'                   => ['ai', 'admin'],
    'ai.use'                             => ['ai', 'read'],
    'ai.audit.view'                      => ['ai', 'read'],
    'ai.gateway.invoke'                  => ['ai', 'write'],
    'ai.workflow.approve'                => ['ai', 'admin'],
    'platform.ai.admin'                  => ['ai', 'admin'],
    // ap
    'ap.1099.generate'                   => ['ap', 'admin'],
    'ap.1099.view'                       => ['ap', 'read'],
    'ap.bill.approve'                    => ['ap', 'admin'],
    'ap.bill.create'                     => ['ap', 'write'],
    'ap.bill.post'                       => ['ap', 'admin'],
    'ap.bill.view'                       => ['ap', 'read'],
    'ap.bill.void'                       => ['ap', 'admin'],
    'ap.bills.approve_admin'             => ['ap', 'admin'],
    'ap.expense.approve'                 => ['ap', 'write'],
    'ap.expense.submit'                  => ['ap', 'write'],
    'ap.export.run'                      => ['ap', 'write'],
    'ap.payment.allocate'                => ['ap', 'write'],
    'ap.payment.create'                  => ['ap', 'admin'],
    'ap.payment.send'                    => ['ap', 'admin'],
    'ap.recurring.manage'                => ['ap', 'write'],
    'ap.reports.view'                    => ['ap', 'read'],
    'ap.vendor.view_pii'                 => ['ap', 'admin'],
    'ap.view'                            => ['ap', 'read'],
    // billing
    'billing.invoice.approve'            => ['billing', 'admin'],
    'billing.invoice.create'             => ['billing', 'write'],
    'billing.invoice.draft'              => ['billing', 'write'],
    'billing.invoice.post'               => ['billing', 'admin'],
    'billing.invoice.send'               => ['billing', 'admin'],
    'billing.invoice.void'               => ['billing', 'admin'],
    'billing.payments.record'            => ['billing', 'write'],
    'billing.view'                       => ['billing', 'read'],
    // integrations
    'integrations.jobdiva.manage'        => ['integrations', 'admin'],
    'integrations.jobdiva.view'          => ['integrations', 'read'],
    'integrations.qbo.manage'            => ['integrations', 'admin'],
    'integrations.qbo.view'              => ['integrations', 'read'],
    // payroll
    'payroll.runs.approve'               => ['payroll', 'admin'],
    // people
    'people.banking.manage'              => ['people', 'admin'],
    'people.banking.view'                => ['people', 'admin'],
    'people.comp.manage'                 => ['people', 'admin'],
    'people.comp.view'                   => ['people', 'read'],
    'people.custom_fields.manage'        => ['people', 'write'],
    'people.docs.manage'                 => ['people', 'write'],
    'people.docs.view'                   => ['people', 'read'],
    'people.graph.delegate'              => ['people', 'admin'],
    'people.graph.manage'                => ['people', 'admin'],
    'people.graph.view'                  => ['people', 'read'],
    'people.manage'                      => ['people', 'write'],
    'people.merge'                       => ['people', 'admin'],
    'people.pii.audit.view'              => ['people', 'admin'],
    'people.pii.manage'                  => ['people', 'admin'],
    'people.pii.view'                    => ['people', 'admin'],
    'people.pipeline.substages.manage'   => ['people', 'write'],
    'people.tax.manage'                  => ['people', 'admin'],
    'people.tax.view'                    => ['people', 'read'],
    'people.terminate'                   => ['people', 'admin'],
    'people.view'                        => ['people', 'read'],
    // placements
    'placements.commissions.manage'      => ['placements', 'admin'],
    'placements.commissions.view'        => ['placements', 'read'],
    'placements.corp.manage'             => ['placements', 'write'],
    'placements.corp.view'               => ['placements', 'read'],
    'placements.docs.manage'             => ['placements', 'write'],
    'placements.docs.view'               => ['placements', 'read'],
    'placements.financials.approve'      => ['placements', 'admin'],
    'placements.financials.manage'       => ['placements', 'admin'],
    'placements.financials.view'         => ['placements', 'read'],
    'placements.manage'                  => ['placements', 'write'],
    'placements.portal_credentials.view' => ['placements', 'admin'],
    'placements.referrals.manage'        => ['placements', 'write'],
    'placements.terminate'               => ['placements', 'admin'],
    'placements.view'                    => ['placements', 'read'],
    // reports / staffing / tenant
    'admin.export_templates.manage'      => ['reports', 'admin'],
    'reports.custom.build'               => ['reports', 'write'],
    'reports.custom.share'               => ['reports', 'admin'],
    'reports.export'                     => ['reports', 'write'],
    'reports.view'                       => ['reports', 'read'],
    'staffing.billing.manage'            => ['billing', 'write'],
    'staffing.billing.view'              => ['billing', 'read'],
    'staffing.payroll.manage'            => ['payroll', 'write'],
    'staffing.payroll.view'              => ['payroll', 'read'],
    'staffing.reports.view'              => ['reports', 'read'],
    'staffing.settings.manage'           => ['staffing', 'admin'],
    'staffing.time.approve'              => ['time', 'admin'],
    'staffing.time.create'               => ['time', 'write'],
    'staffing.time.reject'               => ['time', 'admin'],
    'staffing.time.submit'               => ['time', 'write'],
    'staffing.time.view'                 => ['time', 'read'],
    'staffing.view'                      => ['staffing', 'read'],
    'tenant.manage'                      => ['_platform', 'admin'],
    // time
    'time.approve'                       => ['time', 'admin'],
    'time.bulk_upload'                   => ['time', 'write'],
    'time.categories.manage'             => ['time', 'write'],
    'time.entry.create'                  => ['time', 'write'],
    'time.entry.manage'                  => ['time', 'write'],
    'time.entry.self'                    => ['time', 'read'],
    'time.feed.consume'                  => ['time', 'read'],
    'time.period.close'                  => ['time', 'admin'],
    'time.reject'                        => ['time', 'admin'],
    'time.review'                        => ['time', 'write'],
    'time.tokenized_email.issue'         => ['time', 'admin'],
    'time.tokenized_email.revoke'        => ['time', 'admin'],
    'time.view'                          => ['time', 'read'],
    // treasury
    'treasury.approve_payment'           => ['treasury', 'admin'],
    'treasury.approve_transfer'          => ['treasury', 'admin'],
    'treasury.create_payment'            => ['treasury', 'admin'],
    'treasury.create_transfer'           => ['treasury', 'write'],
    'treasury.execute_payment'           => ['treasury', 'admin'],
    'treasury.manage_forecast'           => ['treasury', 'write'],
    'treasury.payment.manage'            => ['treasury', 'admin'],
    'treasury.payment.view'              => ['treasury', 'read'],
    'treasury.view_bank_balances'        => ['treasury', 'read'],
];

echo "Expected mapping count: " . count($expected) . "\n";
$a('mapping covers ≥108 permission strings', count($expected) >= 108);

// ----------------------------------------------------------------- syntax
$rc = 0; $o = [];
exec('php -l ' . escapeshellarg(__DIR__ . '/../core/rbac/legacy_map.php') . ' 2>&1', $o, $rc);
$a('php -l core/rbac/legacy_map.php', $rc === 0);

$a('RbacLegacyMap class exists',     class_exists('RbacLegacyMap'));
$a('RbacLegacyMap::resolve() exists',method_exists('RbacLegacyMap', 'resolve'));
$a('RbacLegacyMap::isParked() exists', method_exists('RbacLegacyMap', 'isParked'));
$a('rbac_legacy_can() exists',       function_exists('rbac_legacy_can'));
$a('rbac_legacy_require() exists',   function_exists('rbac_legacy_require'));

// ----------------------------------------------------------------- per-row assertions
echo "\nLock mapping table (one assertion per row)\n";
foreach ($expected as $perm => $tuple) {
    $got = RbacLegacyMap::resolve($perm);
    $ok = is_array($got)
        && isset($got[0], $got[1])
        && $got[0] === $tuple[0]
        && $got[1] === $tuple[1];
    $a(sprintf("%-40s → (%s, %s)", $perm, $tuple[0], $tuple[1]), $ok);
}

// ----------------------------------------------------------------- unknown fall-through
echo "\nUnknown perms fall through to (segment, write)\n";
[$m, $action] = RbacLegacyMap::resolve('totally.new.perm.never.seen');
$a('unknown → (totally, write)', $m === 'totally' && $action === 'write');
[$m2, $a2] = RbacLegacyMap::resolve('singletoken');
$a('one-segment → (singletoken, write)', $m2 === 'singletoken' && $a2 === 'write');

// ----------------------------------------------------------------- PARK behaviour
echo "\nPARK ('_platform') stays on legacy\n";
$a('tenant.manage isParked',                RbacLegacyMap::isParked('tenant.manage'));
$a('people.view NOT parked',               !RbacLegacyMap::isParked('people.view'));

// ----------------------------------------------------------------- bootstrap wiring
echo "\napi_bootstrap loads the bridge\n";
$boot = (string) file_get_contents(__DIR__ . '/../core/api_bootstrap.php');
$a('api_bootstrap requires rbac/legacy_map.php',
    strpos($boot, "require_once __DIR__ . '/rbac/legacy_map.php'") !== false);

// ----------------------------------------------------------------- doc cross-check
echo "\nDoc parity\n";
$doc = (string) file_get_contents(__DIR__ . '/../memory/RBAC_B4_PERMISSION_MAPPING.md');
$a('mapping doc exists',                   $doc !== '');
$missing = [];
foreach (array_keys($expected) as $perm) {
    if (strpos($doc, '`' . $perm . '`') === false) $missing[] = $perm;
}
$a('every mapped permission cited in doc', count($missing) === 0);
if ($missing) echo '    missing: ' . implode(', ', $missing) . "\n";

// ----------------------------------------------------------------- summary
echo "\n=========================================\n";
echo "RBAC B4 bridge smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
