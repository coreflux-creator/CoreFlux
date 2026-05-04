<?php
/**
 * /api/sub_tenant_analytics.php — fleet-view stats for the master tenant.
 *
 * Returns a JSON snapshot used by the SPA dashboard widget:
 *   {
 *     parent_tenant_id, parent_name,
 *     active_sub_tenants, total_sub_tenants,
 *     last_active_sub: { id, name, last_active_at } | null,
 *     posted_this_month_cents,
 *     ar_outstanding_cents,
 *     by_sub: [ { id, name, je_count, posted_cents, last_je_at } ]
 *   }
 *
 * GET only. Requires the active tenant to be a `master`. Available to
 * tenant_admin or master_admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/sub_tenants.php';

$ctx       = api_require_auth();
$user      = $ctx['user'];
$role      = $ctx['role'];
$tenantId  = $ctx['tenant_id'];

$me = subTenantLookup((int)$tenantId);
if (!$me) api_error('Tenant not found', 404);

// Resolve master parent: if active is a sub, walk up; if master, use self.
$parentId = $me['tenant_type'] === 'master'
    ? (int) $me['id']
    : (int) ($me['parent_id'] ?? 0);
if (!$parentId) api_error('Active tenant has no master parent', 400);

if ($role !== 'master_admin') {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT role FROM user_tenants
          WHERE user_id = :u AND tenant_id = :t AND status = 'active' LIMIT 1"
    );
    $stmt->execute(['u' => $user['id'] ?? 0, 't' => $parentId]);
    $r = $stmt->fetch();
    if (!$r || !in_array($r['role'], ['tenant_admin','master_admin'], true)) {
        api_error('Forbidden — only master_admin or master tenant_admin', 403);
    }
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$parent = subTenantLookup($parentId);

// Sub-tenants under this master.
$stmt = $pdo->prepare(
    "SELECT id, name, is_active, primary_color, created_at
       FROM tenants
      WHERE parent_id = :p AND tenant_type = 'sub'
   ORDER BY is_active DESC, name ASC"
);
$stmt->execute(['p' => $parentId]);
$subs = $stmt->fetchAll();

$activeCount = 0;
foreach ($subs as $s) if ((int)$s['is_active'] === 1) $activeCount++;

// Last-active sub-tenant across all users (proxy for "most recently used").
$lastActive = null;
if ($subs) {
    $ids = array_column($subs, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT t.id, t.name, MAX(ut.last_active_at) AS last_active_at
           FROM user_tenants ut JOIN tenants t ON t.id = ut.tenant_id
          WHERE ut.tenant_id IN ($place) AND ut.last_active_at IS NOT NULL
       GROUP BY t.id, t.name
       ORDER BY last_active_at DESC LIMIT 1"
    );
    $stmt->execute($ids);
    $lastActive = $stmt->fetch() ?: null;
}

// Per-sub posting roll-up (this month).
$ymStart = date('Y-m-01');
$bySub = [];
$postedTotal = 0;

if ($subs && _stTableExists($pdo, 'accounting_journal_entries')) {
    foreach ($subs as $s) {
        $row = $pdo->prepare(
            "SELECT COUNT(*) AS je_count,
                    COALESCE(SUM(total_debit), 0) AS posted,
                    MAX(posted_at) AS last_je_at
               FROM accounting_journal_entries
              WHERE tenant_id = :t AND status = 'posted'
                AND posting_date >= :ym"
        );
        $row->execute(['t' => $s['id'], 'ym' => $ymStart]);
        $r = $row->fetch();
        $bySub[] = [
            'id'           => (int) $s['id'],
            'name'         => $s['name'],
            'is_active'    => (int) $s['is_active'],
            'je_count'     => (int) ($r['je_count'] ?? 0),
            'posted_cents' => (int) round(((float)($r['posted'] ?? 0)) * 100),
            'last_je_at'   => $r['last_je_at'] ?? null,
        ];
        $postedTotal += (int) round(((float)($r['posted'] ?? 0)) * 100);
    }
} else {
    foreach ($subs as $s) {
        $bySub[] = [
            'id' => (int)$s['id'], 'name' => $s['name'],
            'is_active' => (int)$s['is_active'],
            'je_count' => 0, 'posted_cents' => 0, 'last_je_at' => null,
        ];
    }
}

// AR outstanding (sum of unpaid invoice balances across all sub-tenants).
$arOutstandingCents = 0;
if ($subs && _stTableExists($pdo, 'billing_invoices')) {
    $ids = array_column($subs, 'id');
    $place = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total - amount_paid), 0)
           FROM billing_invoices
          WHERE tenant_id IN ($place)
            AND status IN ('approved','sent','partially_paid')"
    );
    $stmt->execute($ids);
    $arOutstandingCents = (int) round(((float)$stmt->fetchColumn()) * 100);
}

api_ok([
    'parent_tenant_id'        => $parentId,
    'parent_name'             => $parent['name'] ?? null,
    'total_sub_tenants'       => count($subs),
    'active_sub_tenants'      => $activeCount,
    'last_active_sub'         => $lastActive,
    'posted_this_month_cents' => $postedTotal,
    'ar_outstanding_cents'    => $arOutstandingCents,
    'by_sub'                  => $bySub,
    'as_of'                   => date('c'),
]);

function _stTableExists(PDO $pdo, string $name): bool {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :n LIMIT 1'
        );
        $stmt->execute(['n' => $name]);
        return $cache[$name] = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$name] = false;
    }
}
