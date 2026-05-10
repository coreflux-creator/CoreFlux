<?php
/**
 * /api/sub_tenant_setup_checklist.php
 *
 * Computes a 30-day onboarding checklist for the active tenant. Each item
 * is checked by inspecting actual data ("does this tenant have a CoA?"
 * vs polling a stored flag) so the list self-heals as the user configures
 * the tenant.
 *
 *   GET  /api/sub_tenant_setup_checklist.php
 *        → { tenant_id, age_days, dismissed, items: [...], complete: bool }
 *
 *   POST /api/sub_tenant_setup_checklist.php?action=dismiss
 *        → marks `tenants.setup_checklist_dismissed_at = NOW()` so the
 *          dashboard widget hides itself.
 *
 * Visibility rules (frontend):
 *   - Only render when tenant is < 30 days old AND not dismissed AND not
 *     already 100% complete.
 *
 * Permissions: any authenticated user of the tenant. Dismiss requires
 * tenant_admin or master_admin.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$role     = $ctx['role'] ?? 'employee';
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$pdo = getDB();

// Make sure the column exists. Idempotent — failed `ADD COLUMN` is safe to
// swallow. Done lazily here instead of a migration so legacy DBs auto-heal.
try {
    $pdo->exec("ALTER TABLE tenants ADD COLUMN setup_checklist_dismissed_at TIMESTAMP NULL DEFAULT NULL");
} catch (\Throwable $_) { /* already exists */ }

if (api_method() === 'POST') {
    $action = (string) ($_GET['action'] ?? '');
    if ($action !== 'dismiss') api_error('Unknown action', 400);
    if (!in_array($role, ['master_admin','tenant_admin','admin'], true)) {
        api_error('Forbidden — admin only', 403);
    }
    $upd = $pdo->prepare('UPDATE tenants SET setup_checklist_dismissed_at = NOW() WHERE id = :id');
    $upd->execute(['id' => $tenantId]);
    api_ok(['dismissed' => true]);
}

// GET — compute checklist.
$stmt = $pdo->prepare('SELECT id, name, slug, primary_color, logo_url, created_at, setup_checklist_dismissed_at FROM tenants WHERE id = :id');
$stmt->execute(['id' => $tenantId]);
$tenant = $stmt->fetch();
if (!$tenant) api_error('Tenant not found', 404);

$createdAt = strtotime((string) $tenant['created_at']);
$ageDays   = $createdAt ? (int) floor((time() - $createdAt) / 86400) : 0;
$dismissed = !empty($tenant['setup_checklist_dismissed_at']);

// Each item: id, label, description, done (bool), action_label, action_href.
$items = [];

// Branding ----------------------------------------------------------------
$items[] = [
    'id'            => 'branding.logo',
    'label'         => 'Upload your logo',
    'description'   => 'Personalize the SPA header for your team.',
    'done'          => !empty($tenant['logo_url']),
    'action_label'  => 'Upload',
    'action_href'   => '/admin/tenants',
];
$items[] = [
    'id'            => 'branding.color',
    'label'         => 'Set a brand color',
    'description'   => 'Buttons + brand bar pick this up.',
    'done'          => !empty($tenant['primary_color']) && $tenant['primary_color'] !== '#2563eb',
    'action_label'  => 'Pick',
    'action_href'   => '/admin/tenants',
];

// Users -------------------------------------------------------------------
$cnt = function (string $sql, array $params = []) use ($pdo, $tenantId): int {
    try {
        $params['tid'] = $tenantId;
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $row = $s->fetch();
        return (int) ($row['c'] ?? 0);
    } catch (\Throwable $_) { return 0; }
};

$userCount = $cnt("SELECT COUNT(*) c FROM user_tenants WHERE tenant_id = :tid AND status = 'active'");
$items[] = [
    'id'           => 'users.invited',
    'label'        => 'Invite at least one teammate',
    'description'  => "Currently {$userCount} active member" . ($userCount === 1 ? '' : 's') . '.',
    'done'         => $userCount >= 2,
    'action_label' => 'Invite',
    'action_href'  => '/admin/users',
];

// Accounting --------------------------------------------------------------
$coaCount = $cnt('SELECT COUNT(*) c FROM accounting_accounts WHERE tenant_id = :tid');
$items[] = [
    'id'           => 'accounting.coa',
    'label'        => 'Seed your Chart of Accounts',
    'description'  => $coaCount > 0 ? "{$coaCount} accounts configured." : 'Required before journal entries.',
    'done'         => $coaCount >= 10,
    'action_label' => 'Set up',
    'action_href'  => '/modules/accounting/chart-of-accounts',
];

$bankCount = $cnt('SELECT COUNT(*) c FROM accounting_bank_accounts WHERE tenant_id = :tid');
$items[] = [
    'id'           => 'accounting.bank',
    'label'        => 'Connect a bank account',
    'description'  => $bankCount > 0 ? "{$bankCount} connected." : 'Powers reconciliation + cash forecasting.',
    'done'         => $bankCount >= 1,
    'action_label' => 'Connect',
    'action_href'  => '/modules/accounting/bank-rec',
];

// Approvals ---------------------------------------------------------------
$apprCount = $cnt('SELECT COUNT(*) c FROM approval_policies WHERE tenant_id = :tid');
$items[] = [
    'id'           => 'approvals.policy',
    'label'        => 'Define an approval policy',
    'description'  => $apprCount > 0 ? "{$apprCount} polic" . ($apprCount === 1 ? 'y' : 'ies') . " configured." : 'Required to route AP bills + expenses.',
    'done'         => $apprCount >= 1,
    'action_label' => 'Configure',
    'action_href'  => '/modules/ap/approvals',
];

// Entities ----------------------------------------------------------------
$entityCount = $cnt('SELECT COUNT(*) c FROM entities WHERE tenant_id = :tid');
$items[] = [
    'id'           => 'entities.created',
    'label'        => 'Create your first legal entity',
    'description'  => $entityCount > 0 ? "{$entityCount} entit" . ($entityCount === 1 ? 'y' : 'ies') . "." : 'Needed for multi-entity reporting.',
    'done'         => $entityCount >= 1,
    'action_label' => 'Create',
    'action_href'  => '/modules/accounting/entities',
];

// People ------------------------------------------------------------------
$peopleCount = $cnt('SELECT COUNT(*) c FROM people WHERE tenant_id = :tid AND deleted_at IS NULL');
$items[] = [
    'id'           => 'people.added',
    'label'        => 'Add at least one person',
    'description'  => $peopleCount > 0 ? "{$peopleCount} on roster." : 'Workers, contractors, or candidates.',
    'done'         => $peopleCount >= 1,
    'action_label' => 'Add',
    'action_href'  => '/modules/people',
];

$totalItems = count($items);
$doneItems  = count(array_filter($items, fn($i) => $i['done']));
$pct        = $totalItems > 0 ? (int) round(($doneItems / $totalItems) * 100) : 0;

api_ok([
    'tenant_id'      => $tenantId,
    'tenant_name'    => $tenant['name'] ?? null,
    'age_days'       => $ageDays,
    'dismissed'      => $dismissed,
    'complete'       => $pct === 100,
    'completion_pct' => $pct,
    'done_count'     => $doneItems,
    'total_count'    => $totalItems,
    'items'          => $items,
    'visible'        => !$dismissed && $pct < 100 && $ageDays <= 30,
]);
