<?php
/**
 * RBAC B1 backfill — migrate legacy user_tenants rows into the new
 * tenant_memberships + membership_module_access model.
 *
 * Idempotent. Safe to re-run after migration 055 has been applied.
 *
 * For each (user_id, tenant_id, role, status) row in user_tenants:
 *   1. Upsert a tenant_memberships row with persona_label='Primary',
 *      persona_type=<mapped role>, is_primary=1 when user_tenants.is_default=1.
 *   2. Upsert membership_module_access rows giving access to every module
 *      that getUserModules($role) returns. Access level:
 *        master_admin/tenant_admin/admin → 'admin'
 *        manager                         → 'write'
 *        employee/contractor             → 'read'
 *      sub_tenant_scope is left NULL (= all sub-tenants of the tenant).
 *
 * Usage:
 *   php scripts/backfill_memberships.php           # run for all tenants
 *   php scripts/backfill_memberships.php --tenant=5
 *   php scripts/backfill_memberships.php --dry-run
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/modules.php';

$opts     = getopt('', ['tenant::', 'dry-run']);
$tenantId = isset($opts['tenant']) ? (int) $opts['tenant'] : 0;
$dryRun   = isset($opts['dry-run']);

$pdo = getDB();

// Sanity: schema must be present.
try {
    $pdo->query('SELECT 1 FROM tenant_memberships LIMIT 1');
    $pdo->query('SELECT 1 FROM membership_module_access LIMIT 1');
} catch (\Throwable $e) {
    fwrite(STDERR, "Migration 055 not applied yet (tenant_memberships missing). Apply it first.\n");
    exit(2);
}

$where = '';
$bind  = [];
if ($tenantId > 0) { $where = ' WHERE tenant_id = :t '; $bind['t'] = $tenantId; }
$stmt = $pdo->prepare("SELECT user_id, tenant_id, role, status, is_default, last_active_at FROM user_tenants {$where} ORDER BY user_id, tenant_id");
$stmt->execute($bind);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

if (!$rows) { fwrite(STDOUT, "No user_tenants rows" . ($tenantId ? " for tenant {$tenantId}" : "") . ". Nothing to do.\n"); exit(0); }

$personaTypeFor = static function (string $role): string {
    return match (strtolower($role)) {
        'master_admin'  => 'master_admin',
        'tenant_admin'  => 'tenant_admin',
        'admin'         => 'admin',
        'manager'       => 'manager',
        'employee'      => 'employee',
        'contractor'    => 'contractor',
        'client'        => 'client',
        'vendor'        => 'vendor',
        default         => 'custom',
    };
};
$accessLevelFor = static function (string $role): string {
    return match (strtolower($role)) {
        'master_admin', 'tenant_admin', 'admin' => 'admin',
        'manager'                               => 'write',
        'employee', 'contractor'                => 'read',
        default                                 => 'read',
    };
};

$membershipUpsert = $pdo->prepare(
    'INSERT INTO tenant_memberships
        (user_id, tenant_id, persona_label, persona_type, is_primary, status, last_active_at, accepted_at)
     VALUES (:u, :t, :pl, :pt, :pr, :s, :la, NOW())
     ON DUPLICATE KEY UPDATE
        persona_type   = VALUES(persona_type),
        is_primary     = VALUES(is_primary),
        status         = VALUES(status),
        last_active_at = VALUES(last_active_at),
        updated_at     = NOW()'
);
$moduleUpsert = $pdo->prepare(
    'INSERT INTO membership_module_access (membership_id, module_key, access_level)
     VALUES (:m, :k, :a)
     ON DUPLICATE KEY UPDATE access_level = VALUES(access_level)'
);
$membershipLookup = $pdo->prepare(
    'SELECT id FROM tenant_memberships WHERE user_id = :u AND tenant_id = :t AND persona_label = :pl LIMIT 1'
);

$created = 0; $updated = 0; $modulesGranted = 0; $skipped = 0;
foreach ($rows as $r) {
    $uid    = (int) $r['user_id'];
    $tid    = (int) $r['tenant_id'];
    $role   = (string) ($r['role'] ?? 'employee');
    $status = (string) ($r['status'] ?? 'active');
    $isDef  = (int) ($r['is_default'] ?? 0) === 1 ? 1 : 0;
    $la     = $r['last_active_at'] ?? null;
    if (!in_array($status, ['active', 'pending', 'inactive'], true)) $status = 'suspended';
    if ($status === 'inactive') $status = 'suspended';   // map to new enum
    $personaType = $personaTypeFor($role);
    $accessLevel = $accessLevelFor($role);

    if ($dryRun) {
        fwrite(STDOUT, "[dry-run] user={$uid} tenant={$tid} → persona_type={$personaType} access={$accessLevel}\n");
        $skipped++;
        continue;
    }

    $membershipUpsert->execute([
        'u' => $uid, 't' => $tid, 'pl' => 'Primary',
        'pt' => $personaType, 'pr' => $isDef, 's' => $status, 'la' => $la,
    ]);
    $isCreate = $membershipUpsert->rowCount() === 1;   // 1 = INSERT, 2 = UPDATE on dup key
    if ($isCreate) $created++; else $updated++;

    $membershipLookup->execute(['u' => $uid, 't' => $tid, 'pl' => 'Primary']);
    $mid = (int) ($membershipLookup->fetchColumn() ?: 0);
    if (!$mid) { fwrite(STDERR, "lookup failed for user={$uid} tenant={$tid}\n"); continue; }

    // Resolve modules from the existing role and grant access.
    $modules = getUserModules($role);
    foreach ($modules as $m) {
        $key = (string) ($m['id'] ?? '');
        if ($key === '') continue;
        $moduleUpsert->execute(['m' => $mid, 'k' => $key, 'a' => $accessLevel]);
        $modulesGranted++;
    }
}

fwrite(STDOUT, sprintf(
    "Backfill done — memberships created=%d updated=%d, module grants=%d, dry-run skipped=%d (rows considered=%d)\n",
    $created, $updated, $modulesGranted, $skipped, count($rows)
));
exit(0);
