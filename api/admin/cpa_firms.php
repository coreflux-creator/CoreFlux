<?php
/**
 * /api/admin/cpa_firms.php — CPA-firm ↔ client-tenant link admin endpoint.
 *
 *   GET    /api/admin/cpa_firms.php                       → list firm-managed clients
 *          ?status=active|pending|paused|ended            (optional filter)
 *   GET    /api/admin/cpa_firms.php?id=N                  → one row
 *   GET    /api/admin/cpa_firms.php?action=portfolio      → CURRENT user's portfolio
 *                                                          (all firms they're a member of)
 *   POST   /api/admin/cpa_firms.php?action=save           body: link payload
 *   POST   /api/admin/cpa_firms.php?action=end            body: { id }
 *   DELETE /api/admin/cpa_firms.php?id=N                  → hard delete
 *
 * Auth:
 *   - portfolio: any authenticated user (filtered to their own memberships).
 *   - all others: master_admin / tenant_admin of the active tenant (which
 *     must be the firm tenant), or a platform global admin.
 *
 * The "firm tenant" is the active tenant on the session — operators flip
 * into the firm tenant before managing its client list.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/rbac/cpa_firms.php';

$method = api_method();
$action = (string) (api_query('action') ?? '');

// ─────────────────────────────────────────────────────── portfolio (user-scoped)
// Allowed for any authenticated user — the service filters to their
// memberships, so a user with no firm-side membership simply gets [].
if ($method === 'GET' && $action === 'portfolio') {
    $ctx    = api_require_auth(false); // platform-mode allowed
    $userId = (int) ($ctx['user']['id'] ?? 0);
    if (!$userId) api_error('No authenticated user', 401);

    try {
        $portfolio = CpaFirmService::portfolioForUser($userId);
    } catch (\Throwable $e) {
        // Schema-not-ready (migration 100 unapplied) — surface zero rows
        // instead of a 5xx so the SPA doesn't error-banner a fresh tenant.
        $portfolio = [];
    }
    // Group by firm for the UI; keep the flat array under `links` for direct consumers.
    $byFirm = [];
    foreach ($portfolio as $row) {
        $fid = (int) $row['firm_tenant_id'];
        if (!isset($byFirm[$fid])) {
            $byFirm[$fid] = [
                'firm_tenant_id' => $fid,
                'firm_name'      => (string) ($row['firm_name'] ?? ''),
                'firm_slug'      => $row['firm_slug'] ?? null,
                'firm_persona'   => $row['firm_persona'] ?? null,
                'clients'        => [],
            ];
        }
        $byFirm[$fid]['clients'][] = $row;
    }
    api_ok(['firms' => array_values($byFirm), 'links' => $portfolio]);
}

// ─────────────────────────────────────────────────────── admin-only surface
$ctx      = api_require_auth();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
$actorId  = (int) ($ctx['user']['id'] ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
if (!$isGlobalAdmin && !in_array($role, ['master_admin', 'tenant_admin'], true)) {
    api_error('Forbidden — firm admin only', 403);
}

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

try {
    $pdo->query('SELECT 1 FROM cpa_firm_client_links LIMIT 0');
} catch (\Throwable $_) {
    api_error('Migration 100_rbac_cpa_personas_and_profiles.sql has not been applied yet.', 503);
}

// ─────────────────────────────────────────────────────── GET (list / get one)
if ($method === 'GET') {
    $id = (int) (api_query('id') ?? 0);
    if ($id > 0) {
        $row = CpaFirmService::getForFirm($id, $tenantId);
        if (!$row) api_error('Link not found', 404);
        api_ok(['link' => $row]);
    }
    $status = (string) (api_query('status') ?? '');
    $rows = CpaFirmService::listClientsForFirm($tenantId, $status !== '' ? $status : null);
    api_ok(['links' => $rows]);
}

// ─────────────────────────────────────────────────────── POST save (upsert)
if ($method === 'POST' && $action === 'save') {
    $body = api_json_body();
    try {
        $result = CpaFirmService::upsert($body, $tenantId, $actorId);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        api_error('Save failed: ' . $e->getMessage(), 500);
    }
    if (is_array($result)) {
        // Bulk-seat happened — surface the per-row outcomes.
        api_ok(['id' => (int) $result['id'], 'saved' => true, 'seeded' => $result['seeded']], 201);
    }
    api_ok(['id' => (int) $result, 'saved' => true], 201);
}

// ─────────────────────────────────────────────────────── POST end (soft)
if ($method === 'POST' && $action === 'end') {
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if (!$id) api_error('id is required', 422);
    $ok = CpaFirmService::endLink($id, $tenantId, $actorId);
    if (!$ok) api_error('Link not found in this firm', 404);
    api_ok(['id' => $id, 'ended' => true]);
}

// ─────────────────────────────────────────────────────── DELETE (hard)
if ($method === 'DELETE') {
    $id = (int) (api_query('id') ?? 0);
    if (!$id) api_error('id is required', 422);
    $ok = CpaFirmService::deleteLink($id, $tenantId, $actorId);
    if (!$ok) api_error('Link not found in this firm', 404);
    api_ok(['id' => $id, 'deleted' => true]);
}

api_error('Method not allowed', 405);
