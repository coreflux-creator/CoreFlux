<?php
/**
 * People SPEC alignment smoke test
 *
 * Validates static contracts that don't require a live MySQL connection:
 *   - Manifest declares all SPEC §4 permissions and SPEC §7 audit events
 *   - SPEC migration SQL parses + creates expected tables
 *   - All SPEC-aligned API endpoint files exist and parse
 *   - All SPEC-aligned UI components exist
 *   - Lib functions expose the contracts other modules will rely on
 *
 * For DB-level integration (RBAC enforcement, encryption roundtrips on
 * banking, merge re-pointing, etc.) we'll use the testing agent against
 * Cloudways MySQL once deployed. This smoke test is the local gatekeeper.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/ModuleRegistry.php';
require_once __DIR__ . '/../modules/people/lib/people.php';

$pass = 0;
$fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}\n"; $fail++; }
};

// ─── Manifest contract (SPEC §4 + §7) ───
echo "Manifest contract\n";
$reg = ModuleRegistry::reset(__DIR__ . '/../modules');
$people = $reg->getModule('people');
$assert('module registered',                        $people !== null);
$assert("manifest id = 'people'",                   ($people['id'] ?? null) === 'people');
$assert('manifest name set',                        !empty($people['name']));

$expectedPerms = [
    'people.view','people.manage','people.terminate','people.merge',
    'people.pii.view','people.pii.manage','people.pii.audit.view',
    'people.tax.view','people.tax.manage',
    'people.banking.view','people.banking.manage',
    'people.docs.view','people.docs.manage',
    'people.graph.view','people.graph.manage','people.graph.delegate',
    'people.custom_fields.manage','people.pipeline.substages.manage',
];
$declared = array_keys($people['permissions'] ?? []);
foreach ($expectedPerms as $p) {
    $assert("permission declared: {$p}", in_array($p, $declared, true));
}

$expectedEvents = [
    'people.created','people.updated','people.terminated','people.merged',
    'people.pii.viewed','people.banking.viewed','people.banking.updated',
    'people.tax.updated','people.document.uploaded','people.document.deleted',
    'people.pipeline.stage_added','people.custom_field.defined','people.custom_field.value_set',
    'people.pipeline.substage.created','people.pipeline.substage.updated','people.pipeline.substage.deactivated',
    'people.graph.actor_linked','people.graph.organization.created',
    'people.graph.team.upserted','people.graph.role.upserted',
    'people.graph.relationship.created','people.graph.responsibility.assigned',
    'people.graph.delegation.created','people.graph.delegation.revoked',
    'people.graph.permission.granted','people.graph.permission.revoked','people.graph.permission.checked',
    'people.graph.approval_policy.upserted','people.graph.approval_rule.created',
    'people.graph.resolved',
];
foreach ($expectedEvents as $ev) {
    $assert("audit event declared: {$ev}", in_array($ev, $people['audit_events'] ?? [], true));
}

$expectedActions = ['directory','pipeline','documents','graph','custom_fields','audit_pii'];
$actionRoutes = array_column($people['actions'] ?? [], 'route');
foreach ($expectedActions as $a) {
    $assert("action route declared: {$a}", in_array($a, $actionRoutes, true));
}

// ─── Migration SQL — referenced tables present in file ───
echo "\nMigration SQL — SPEC §3 tables\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/people/migrations/003_spec_alignment.sql');
$assert('migration file exists + non-empty', strlen($sql) > 0);
$expectedTables = [
    'people','people_emergency_contacts','people_skills','people_skill_taxonomy',
    'people_documents','people_banking','people_tax',
    'people_pipeline_stages','tenant_pipeline_substages',
    'people_custom_field_defs','people_custom_field_values','people_pii_access_log',
];
foreach ($expectedTables as $t) {
    $assert("CREATE TABLE for {$t}", strpos($sql, "CREATE TABLE IF NOT EXISTS {$t}") !== false
                                  || strpos($sql, "CREATE TABLE IF NOT EXISTS `{$t}`") !== false);
}
$assert("classification ENUM has all 7 values",
    strpos($sql, "ENUM('w2','1099','c2c','temp','perm','candidate','alumni')") !== false);
$assert("status ENUM matches SPEC",
    strpos($sql, "ENUM('active','bench','inactive','do_not_rehire')") !== false);
$assert("pipeline stages ENUM has all 9 values",
    strpos($sql, "ENUM('sourced','screened','submitted','interview','offer','placed','bench','terminated','rejected')") !== false);

// ─── API endpoint files — exist + parse ───
echo "\nAPI endpoint files\n";
$expectedApi = [
    'people.php','skills.php','documents.php','pipeline.php',
    'emergency_contacts.php','custom_fields.php','custom_field_values.php',
    'merge.php','audit_pii.php','banking.php','tax.php',
];
foreach ($expectedApi as $f) {
    $path = __DIR__ . "/../modules/people/api/{$f}";
    $assert("api/{$f} exists", is_file($path));
    if (is_file($path)) {
        $output = []; $rc = 0;
        @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
        $assert("api/{$f} parses",  $rc === 0);
    }
}

// ─── UI components — exist ───
echo "\nReact UI files\n";
$expectedUI = [
    'PeopleModule.jsx','Directory.jsx','PersonCreate.jsx','PersonDetail.jsx',
    'Pipeline.jsx','DocumentVault.jsx','PeopleGraph.jsx','CustomFields.jsx','PIIAuditLog.jsx',
];
foreach ($expectedUI as $f) {
    $assert("ui/{$f} exists",  is_file(__DIR__ . "/../modules/people/ui/{$f}"));
}

// ─── Lib contract ───
echo "\nlib/people.php contract\n";
$libFns = ['peopleSafeFields','peoplePIIFields','peopleGet','peopleGetWithPII','peopleList',
           'peopleLogPIIAccess','peoplePipelineHistory','peopleSkills','peopleDocuments',
           'peopleCustomFieldDefs','peopleCustomFieldValues'];
foreach ($libFns as $fn) {
    $assert("lib function exists: {$fn}",  function_exists($fn));
}
$safe = peopleSafeFields();
$pii  = peoplePIIFields();
$assert("safe fields don't include dob",          strpos($safe, 'dob') === false);
$assert("safe fields don't include ssn_last4",    strpos($safe, 'ssn_last4') === false);
$assert("PII fields include dob",                 strpos($pii, 'dob') !== false);
$assert("PII fields include ssn_last4",           strpos($pii, 'ssn_last4') !== false);
$assert("PII fields include home_address_line1",  strpos($pii, 'home_address_line1') !== false);

// ─── Legacy preservation (HARD_RULES R1) ───
echo "\nLegacy preserved (R1)\n";
$legacy = glob(__DIR__ . '/../legacy/people_pre_spec_*');
$assert("legacy copy exists",  is_array($legacy) && count($legacy) >= 1);
if ($legacy) {
    $assert("legacy api/employees.php preserved",
        is_file($legacy[0] . '/api/employees.php'));
    $assert("legacy migrations/001_init.sql preserved",
        is_file($legacy[0] . '/migrations/001_init.sql'));
}

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
