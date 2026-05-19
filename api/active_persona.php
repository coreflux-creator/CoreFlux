<?php
/**
 * /api/active_persona.php — RBAC B5 persona toggle.
 *
 *   GET  /api/active_persona.php
 *        → { active_persona_id, personas: [{ id, persona_label, persona_type,
 *                                            is_primary, status }, ...] }
 *
 *        Lists every active/pending membership the current user holds in
 *        the current tenant. Powers the SPA header dropdown — only
 *        rendered when ≥2 personas exist (single-persona users are the
 *        common case and shouldn't see any extra chrome).
 *
 *   POST /api/active_persona.php  { persona_id }
 *        → { active_persona_id, persona }
 *
 *        Writes $_SESSION['active_persona_id']. api_require_auth() picks
 *        it up on the next request and hydrates $ctx['membership_id'] /
 *        $ctx['persona_type'] from that specific row, which the new RBAC
 *        resolver uses for every can() check.
 *
 *        Persona must belong to (current user, current tenant) and be
 *        in status='active' — otherwise 404/403.
 *
 * Auth: any authenticated user (no admin gate — picking your own persona
 *       is allowed for everyone).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$userId   = (int) ($user['id'] ?? 0);
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if (!$userId || !$tenantId) api_error('No active session', 401);
if (!class_exists('RBACResolver')) api_error('RBAC resolver not loaded', 500);

$method = api_method();

if ($method === 'GET') {
    $personas = RBACResolver::memberships($userId, $tenantId);
    $activeId = getActivePersonaId();

    // If no explicit persona is selected but the user has memberships,
    // surface the resolver's default pick (primary → most-recent → first)
    // so the header dropdown always shows a current selection.
    if (!$activeId) {
        $auto = RBACResolver::activeMembership($userId, $tenantId);
        if ($auto && isset($auto['id'])) $activeId = (int) $auto['id'];
    }

    $rows = array_map(static function (array $p): array {
        return [
            'id'              => (int) $p['id'],
            'persona_label'   => (string) ($p['persona_label'] ?? ''),
            'persona_type'    => (string) ($p['persona_type']  ?? ''),
            'is_primary'      => (int) ($p['is_primary'] ?? 0) === 1,
            'status'          => (string) ($p['status'] ?? ''),
            'last_active_at'  => $p['last_active_at'] ?? null,
        ];
    }, $personas);

    api_ok([
        'active_persona_id' => $activeId,
        'personas'          => $rows,
    ]);
}

if ($method === 'POST') {
    $body = api_json_body();
    api_require_fields($body, ['persona_id']);
    $personaId = (int) $body['persona_id'];

    if (!setActivePersona($personaId)) {
        api_error('Persona not found or not active for current user/tenant', 404);
    }

    // Mirror the new persona's persona_type back onto $_SESSION['user']['role']
    // *immediately*. session.php reads $_SESSION['user']['role'] directly
    // (not through api_require_auth()), so without this the post-switch
    // reload would render the previous persona's sidebar + module list
    // until the user clicked some other /api/* endpoint. Tiny session
    // write, big "feels instant" UX win.
    $row = RBACResolver::activeMembership($userId, $tenantId, $personaId);
    if ($row && isset($row['persona_type']) && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['user']['role'] = (string) $row['persona_type'];
    }

    // Audit the persona switch so the SoD log captures it.
    RBACResolver::auditMembership($personaId, 'persona_switched', $userId, [
        'tenant_id' => $tenantId,
    ]);
    // Reset the resolver's per-request cache so the next can() call sees
    // the new persona immediately (within this same request lifecycle —
    // future requests already pick it up from the session).
    RBACResolver::resetCache();

    api_ok([
        'active_persona_id' => $personaId,
        'persona'           => $row ? [
            'id'            => (int) $row['id'],
            'persona_label' => (string) ($row['persona_label'] ?? ''),
            'persona_type'  => (string) ($row['persona_type']  ?? ''),
            'is_primary'    => (int) ($row['is_primary'] ?? 0) === 1,
        ] : null,
    ]);
}

if ($method === 'DELETE') {
    clearActivePersona();
    api_ok(['cleared' => true]);
}

api_error('Method not allowed', 405);
