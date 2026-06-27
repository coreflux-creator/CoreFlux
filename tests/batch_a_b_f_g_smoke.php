<?php
/**
 * batch_a_b_f_g_smoke.php
 *
 * Combined smoke for the four-item batch requested by the operator:
 *   (a) Auto-built CoreFlux Assignment-screen clone — placement_schema
 *       endpoint + AssignmentSchemaPreview React page.
 *   (b) Reports Overhaul (minimal first pass) — reusable
 *       GlDetailDrilldown component wired against the existing
 *       /api/gl_detail.php endpoint.
 *   (f) mailerSend → Resend driver — wiring was already complete;
 *       added /api/admin/mail_status.php diagnostic endpoint so the
 *       operator can verify the driver is recognised without sending
 *       a test email.
 *   (g) RBAC B3/B4 UI — already shipped in RbacMembershipsAdmin.jsx
 *       with the copy-permissions workflow; confirms it's still wired.
 *
 * Run:  php -d zend.assertions=1 tests/batch_a_b_f_g_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "Batch (a,b,f,g) smoke\n";
echo "=====================\n";

// -- (a) Assignment-screen clone --------------------------------------
echo "\n(a) Auto-built Assignment-screen clone\n";
$ps = "$root/api/admin/integrations/placement_schema.php";
$a('placement_schema endpoint exists', file_exists($ps));
$psSrc = (string) @file_get_contents($ps);
$a('endpoint RBAC-gated by tenant_admin.integrations',
    str_contains($psSrc, "rbac_legacy_require(\$user, 'tenant_admin.integrations')"));
$a('endpoint declares canonical JobDiva section list (placement/person/company/contact)',
    str_contains($psSrc, "'jobdiva' => [")
    && str_contains($psSrc, "'entity_type' => 'placement'")
    && str_contains($psSrc, "'entity_type' => 'person'")
    && str_contains($psSrc, "'entity_type' => 'company'")
    && str_contains($psSrc, "'entity_type' => 'contact'")
    && !str_contains($psSrc, "'entity_type' => 'jobdiva_customer'"));
$a('endpoint skips object/array intermediate nodes',
    str_contains($psSrc, "if (\$type === 'object' || \$type === 'array') continue"));
$a('endpoint reads canonical JobDiva field index list',
    str_contains($psSrc, 'jobdivaCanonicalPayloadFieldIndexList($tid, (string) $sect[\'entity_type\'], 500)'));
$a('endpoint integration arg validated against [a-z0-9_]{1,40}',
    str_contains($psSrc, "preg_match('/^[a-z0-9_]{1,40}\$/', \$integration)"));

$asp = "$root/dashboard/src/pages/AssignmentSchemaPreview.jsx";
$a('AssignmentSchemaPreview.jsx exists', file_exists($asp));
$aspSrc = (string) @file_get_contents($asp);
$a('page calls placement_schema endpoint',
    str_contains($aspSrc, '/api/admin/integrations/placement_schema.php?integration='));
$a('page surfaces total-fields counter',
    str_contains($aspSrc, 'data-testid="assignment-schema-total-fields"'));
$a('page renders one section per entity with stable testids',
    str_contains($aspSrc, 'data-testid={`assignment-schema-section-${s.key}`}')
    && str_contains($aspSrc, 'data-entity-type={s.entity_type}')
    && str_contains($aspSrc, 'data-field-count={s.field_count || 0}'));
$a('page renders one card per field with stable testid + sample value',
    str_contains($aspSrc, 'data-testid={`assignment-schema-field-${s.key}-${f.path}`}'));
$a('empty section CTAs point operator at the Studio',
    str_contains($aspSrc, 'data-testid={`assignment-schema-empty-${s.key}`}')
    && str_contains($aspSrc, '/admin/integrations/field-map/studio'));

// Confirm sidebar + route wiring in AdminModule.
$am = (string) file_get_contents("$root/dashboard/src/pages/AdminModule.jsx");
$a('AdminModule imports AssignmentSchemaPreview',
    str_contains($am, "import AssignmentSchemaPreview from './AssignmentSchemaPreview'"));
$a('AdminModule mounts /integrations/assignment-schema route',
    str_contains($am, 'path="/integrations/assignment-schema"'));
$a('AdminModule sidebar lists Assignment schema',
    str_contains($am, "label: 'Assignment schema'")
    && str_contains($am, "to: '/admin/integrations/assignment-schema'"));

// -- (b) Reports Overhaul foundation ----------------------------------
echo "\n(b) GlDetailDrilldown reusable component\n";
$drill = "$root/dashboard/src/components/GlDetailDrilldown.jsx";
$a('component file exists', file_exists($drill));
$drillSrc = (string) @file_get_contents($drill);
$a('component default-exports a single function',
    str_contains($drillSrc, 'export default function GlDetailDrilldown('));
$a('component fetches /api/gl_detail.php with account + dates',
    str_contains($drillSrc, '/api/gl_detail.php')
    && str_contains($drillSrc, 'account_id')
    && str_contains($drillSrc, 'account_code'));
$a('component exposes stable testids for opening/total/ending',
    str_contains($drillSrc, 'data-testid="gl-drilldown-opening"')
    && str_contains($drillSrc, 'data-testid="gl-drilldown-total-debit"')
    && str_contains($drillSrc, 'data-testid="gl-drilldown-total-credit"')
    && str_contains($drillSrc, 'data-testid="gl-drilldown-ending"'));
$a('component renders one row per line with je_id testid + open-link',
    str_contains($drillSrc, 'data-testid={`gl-drilldown-row-${ln.je_id}`}')
    && str_contains($drillSrc, 'data-testid={`gl-drilldown-open-${ln.je_id}`}'));
$a('clicking the backdrop closes the modal',
    str_contains($drillSrc, 'onClick={onClose}'));
$a('underlying /api/gl_detail.php endpoint still present',
    file_exists("$root/api/gl_detail.php"));

// -- (f) mail_status diagnostic ---------------------------------------
echo "\n(f) Mail diagnostic endpoint\n";
$ms = "$root/api/admin/mail_status.php";
$a('mail_status endpoint exists', file_exists($ms));
$msSrc = (string) @file_get_contents($ms);
$a('endpoint RBAC-gated by tenant_admin.integrations',
    str_contains($msSrc, "rbac_legacy_require(\$user, 'tenant_admin.integrations')"));
$a('endpoint reads RESEND_API_KEY from env + define()',
    str_contains($msSrc, "getenv('RESEND_API_KEY')")
    && str_contains($msSrc, "defined('RESEND_API_KEY')"));
$a('response surfaces resend_configured boolean',
    str_contains($msSrc, "'resend_configured'"));
$a('response leaks ONLY a short key hint, never the full key',
    str_contains($msSrc, 'substr($resendKey, 0, 5)'));
$a('response includes a hint string for missing-key path',
    str_contains($msSrc, "RESEND_API_KEY is not set"));
$a('response pulls last 5 mail_outbox rows',
    str_contains($msSrc, 'FROM mail_outbox'));

// Confirm the actual Resend driver is still wired as default when key present.
$mb = (string) file_get_contents("$root/core/mail_bootstrap.php");
$a('mail_bootstrap uses ResendDriver when RESEND_API_KEY set',
    str_contains($mb, 'ResendDriver') && str_contains($mb, 'RESEND_API_KEY'));

// -- (g) RBAC B3/B4 UI -----------------------------------------------
echo "\n(g) RBAC memberships admin\n";
$rb = "$root/dashboard/src/pages/RbacMembershipsAdmin.jsx";
$a('RbacMembershipsAdmin.jsx exists', file_exists($rb));
$rbSrc = (string) @file_get_contents($rb);
$a('copy-permissions UX present',
    str_contains($rbSrc, 'Copy permissions'));
$a('AdminModule mounts the page',
    str_contains($am, 'RbacMembershipsAdmin'));

// -- PHP syntax -------------------------------------------------------
echo "\nPHP syntax\n";
foreach ([
    "$root/api/admin/integrations/placement_schema.php",
    "$root/api/admin/mail_status.php",
] as $php) {
    $lint = shell_exec('php -l ' . escapeshellarg($php) . ' 2>&1');
    $a('php -l ' . basename($php),
        str_contains((string) $lint, 'No syntax errors detected'));
}

echo "\n=====================\n";
echo "Batch (a,b,f,g) smoke: $pass ✓ / $fail ✗\n";
echo "=====================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
