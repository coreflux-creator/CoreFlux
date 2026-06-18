<?php
/**
 * Smoke — Engagements module (Phase 1).
 *
 * Locks:
 *   - Migration shape (engagements + engagement_milestones + audit log).
 *   - Manifest declares rbac_module_key=engagements.
 *   - Lib surface: create / list / get / update / archive / milestone CRUD.
 *   - API endpoints exist with auth + RBAC gates.
 *   - End-to-end SQLite mirror: create with 2 milestones → list shows 1 →
 *     status auto-advances to "completed" after both milestones are paid.
 *   - Money rollups stay consistent across status transitions.
 *   - Milestone state machine rejects illegal transitions.
 *
 * Run: php -d zend.assertions=1 /app/tests/engagements_module_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nEngagements module smoke\n";
echo "=========================\n\n";

// ─────── Migration ───────
echo "── migration 001_init.sql ──\n";
$migPath = '/app/modules/engagements/migrations/001_init.sql';
check('migration exists', is_file($migPath));
$mig = (string) file_get_contents($migPath);
check('declares engagements table',           str_contains($mig, 'CREATE TABLE IF NOT EXISTS engagements'));
check('declares engagement_milestones table', str_contains($mig, 'CREATE TABLE IF NOT EXISTS engagement_milestones'));
check('declares engagement_audit_log',        str_contains($mig, 'CREATE TABLE IF NOT EXISTS engagement_audit_log'));
check('engagement status ENUM(draft,active,completed,archived)',
    str_contains($mig, "ENUM('draft','active','completed','archived')"));
check('milestone status ENUM(pending,ready_to_invoice,invoiced,paid,cancelled)',
    str_contains($mig, "ENUM('pending','ready_to_invoice','invoiced','paid','cancelled')"));
check('engagements has tenant_id-scoped index',
    str_contains($mig, 'idx_engagements_tenant_status'));
check('milestones denormalises tenant_id (leak guard)',
    str_contains($mig, 'tenant_id') && str_contains($mig, 'idx_milestones_tenant'));

// ─────── Manifest ───────
echo "\n── manifest.php ──\n";
$manPath = '/app/modules/engagements/manifest.php';
check('manifest exists', is_file($manPath));
$manifest = require $manPath;
check('manifest id=engagements',                     ($manifest['id'] ?? null) === 'engagements');
check("manifest declares rbac_module_key='engagements'",
    ($manifest['rbac_module_key'] ?? null) === 'engagements');
check('manifest declares sidebar route',             !empty($manifest['sidebar_routes']));

// ─────── Lib surface ───────
echo "\n── lib/engagements.php ──\n";
$libPath = '/app/modules/engagements/lib/engagements.php';
check('lib exists', is_file($libPath));
$libSrc = (string) file_get_contents($libPath);
foreach ([
    'engagementsList','engagementsGet','engagementsCreate','engagementsUpdate',
    'engagementsArchive','engagementsMilestonesList','engagementsMilestoneCreate',
    'engagementsMilestoneUpdate','engagementsMilestoneAttachInvoice',
    'engagementsMilestoneMarkPaid','engagementsAudit',
] as $fn) {
    check("exports {$fn}()", str_contains($libSrc, "function {$fn}("));
}
check('archived engagements are read-only',
    str_contains($libSrc, "Archived engagements are read-only"));
check('milestone state machine explicit graph',
    str_contains($libSrc, "'ready_to_invoice', 'cancelled'") &&
    str_contains($libSrc, "'paid'             => []"));
check('rollups auto-set status=completed',
    str_contains($libSrc, "\$newStatus = 'completed'"));

// ─────── API endpoints ───────
echo "\n── api/ endpoints ──\n";
foreach ([
    '/app/modules/engagements/api/list.php',
    '/app/modules/engagements/api/detail.php',
    '/app/modules/engagements/api/milestones.php',
] as $ep) {
    check('endpoint exists: ' . basename($ep), is_file($ep));
    $src = (string) file_get_contents($ep);
    check('  ' . basename($ep) . ' calls api_require_auth', str_contains($src, 'api_require_auth()'));
}

// ─────── Live exercise (SQLite mirror) ───────
echo "\n── live exercise ──\n";

$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo = $GLOBALS['pdo'];

if (!function_exists('getDB')) { function getDB(): \PDO { return $GLOBALS['pdo']; } }

$pdo->exec("CREATE TABLE engagements (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, entity_id INT,
    client_name TEXT, project_name TEXT, description TEXT,
    currency TEXT DEFAULT 'USD', total_fee REAL DEFAULT 0,
    invoiced_amount REAL DEFAULT 0, paid_amount REAL DEFAULT 0,
    status TEXT DEFAULT 'draft', start_date TEXT, end_date TEXT,
    notes TEXT, metadata TEXT, archived_at TEXT,
    created_by_user_id INT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE engagement_milestones (
    id INTEGER PRIMARY KEY AUTOINCREMENT, engagement_id INT, tenant_id INT,
    sort_order INT DEFAULT 0, name TEXT, description TEXT,
    amount REAL DEFAULT 0, target_date TEXT,
    status TEXT DEFAULT 'pending', invoice_id INT,
    completed_at TEXT, invoiced_at TEXT, paid_at TEXT, notes TEXT,
    created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE engagement_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT, engagement_id INT, milestone_id INT,
    tenant_id INT, event TEXT, actor_user_id INT, meta_json TEXT, created_at TEXT)");

// Load the lib; strip require_once that pulls in core/db.php (we've
// stubbed getDB locally).
$libBody = preg_replace("/require_once __DIR__ \\. '\\/\\.\\.\\/\\.\\.\\/\\.\\.\\/core\\/db\\.php';/", '', $libSrc);
$libBody = preg_replace('/^\s*<\?php/', '', $libBody);
eval($libBody);

// Tenant 101 — happy-path: create engagement with 2 milestones.
$egId = engagementsCreate(101, [
    'client_name'  => 'Acme Inc',
    'project_name' => 'Q1 audit',
    'total_fee'    => 10000,
    'milestones'   => [
        ['name' => 'Kickoff',  'amount' => 4000, 'target_date' => '2026-03-01'],
        ['name' => 'Delivery', 'amount' => 6000, 'target_date' => '2026-04-01'],
    ],
], 7);
check('engagement created with id > 0',           $egId > 0);

$eg = engagementsGet(101, $egId);
check('engagement read-back matches',              $eg && $eg['client_name'] === 'Acme Inc');
check('milestones round-tripped (2)',              $eg && count($eg['milestones']) === 2);
check('milestones default to pending',
    $eg && array_unique(array_column($eg['milestones'], 'status')) === ['pending']);
check('initial status=draft',                       $eg && $eg['status'] === 'draft');

// Move first milestone through pending → ready_to_invoice → invoiced (with FK) → paid.
$msKickoff  = $eg['milestones'][0]['id'];
$msDelivery = $eg['milestones'][1]['id'];

engagementsMilestoneUpdate(101, $msKickoff, ['status' => 'ready_to_invoice'], 7);
$eg2 = engagementsGet(101, $egId);
check('kickoff advanced to ready_to_invoice', $eg2['milestones'][0]['status'] === 'ready_to_invoice');
check('completed_at stamped on ready_to_invoice', !empty($eg2['milestones'][0]['completed_at']));

engagementsMilestoneAttachInvoice(101, $msKickoff, 1001, 7);
$eg3 = engagementsGet(101, $egId);
check('kickoff status=invoiced after attach', $eg3['milestones'][0]['status'] === 'invoiced');
check('invoice_id linked',                     (int) $eg3['milestones'][0]['invoice_id'] === 1001);
check('invoiced_at stamped',                   !empty($eg3['milestones'][0]['invoiced_at']));
check('rollup: invoiced_amount = 4000',        (float) $eg3['invoiced_amount'] === 4000.0);
check('rollup: paid_amount = 0',               (float) $eg3['paid_amount'] === 0.0);

engagementsMilestoneMarkPaid(101, $msKickoff, 7);
$eg4 = engagementsGet(101, $egId);
check('kickoff status=paid',                   $eg4['milestones'][0]['status'] === 'paid');
check('paid_at stamped',                       !empty($eg4['milestones'][0]['paid_at']));
check('rollup: paid_amount = 4000',            (float) $eg4['paid_amount'] === 4000.0);

// Push delivery through to paid as well — engagement should auto-flip to completed.
engagementsMilestoneUpdate(101, $msDelivery, ['status' => 'ready_to_invoice'], 7);
engagementsMilestoneAttachInvoice(101, $msDelivery, 1002, 7);
engagementsMilestoneMarkPaid(101, $msDelivery, 7);
$eg5 = engagementsGet(101, $egId);
check('rollup: invoiced_amount = 10000',       (float) $eg5['invoiced_amount'] === 10000.0);
check('rollup: paid_amount = 10000',           (float) $eg5['paid_amount'] === 10000.0);
check('engagement auto-completed',             $eg5['status'] === 'completed');

// Illegal transition (pending → paid) is rejected.
$msIllegal = engagementsMilestoneCreate(101, $egId, ['name' => 'Bonus', 'amount' => 500], 7);
$threw = false;
try {
    engagementsMilestoneUpdate(101, $msIllegal, ['status' => 'paid'], 7);
} catch (\Throwable $e) { $threw = true; }
check('illegal transition (pending → paid) rejected', $threw);

// Archive locks further edits.
engagementsArchive(101, $egId, 7);
$eg6 = engagementsGet(101, $egId);
check('archived: status=archived',             $eg6['status'] === 'archived');
$threw2 = false;
try {
    engagementsUpdate(101, $egId, ['project_name' => 'tampering'], 7);
} catch (\Throwable $e) { $threw2 = true; }
check('archived engagement rejects updates',   $threw2);

// Tenant isolation — tenant 102 cannot see tenant 101's engagement.
$list102 = engagementsList(102, []);
check('engagementsList scoped to tenant',     count($list102) === 0);

// Audit log captured every transition.
$audit = (int) $pdo->query("SELECT COUNT(*) FROM engagement_audit_log WHERE tenant_id=101")->fetchColumn();
check('audit log recorded multi events',       $audit >= 5);

// List filters work.
$active   = engagementsList(101, ['status' => 'active']);
$archived = engagementsList(101, ['status' => 'archived']);
check('list filter status=active returns 0',   count($active) === 0);
check('list filter status=archived returns 1', count($archived) === 1);

echo "\nengagements_module smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
