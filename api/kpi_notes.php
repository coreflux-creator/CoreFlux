<?php
/**
 * Core API — Tenant KPI annotations.
 *
 *   GET  /api/kpi_notes.php             → { notes: { key1: {...}, key2: {...} } }
 *   POST /api/kpi_notes.php             → upsert one note
 *                                          body: { key, text }
 *   POST /api/kpi_notes.php?action=delete  → clear one note
 *                                          body: { key }
 *
 * Notes are tiny (≤280 chars) operator annotations attached to specific
 * KPI keys (e.g. "dso", "ar_outstanding"). Used by the Cash Cycle Health
 * tile today; the table is generic so any future "annotated number"
 * surface can reuse it.
 *
 * Permission: read = any authenticated user with `billing.view`;
 * write     = `billing.view` + `manager`/`admin` role (managers leave
 *             the context, line staff just read it).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

RBAC::requirePermission($user, 'billing.view');

$canWrite = function (array $u): bool {
    $role = (string) ($u['role'] ?? '');
    $g    = (string) ($u['global_role'] ?? '');
    return in_array($role, ['admin', 'manager'], true)
        || in_array($g,    ['master_admin', 'tenant_admin'], true);
};

if ($method === 'GET') {
    $pdo = getDB();
    $notes = [];
    try {
        $st = $pdo->prepare(
            'SELECT note_key, note_text, updated_by_user_id, updated_at
               FROM tenant_kpi_notes WHERE tenant_id = :t'
        );
        $st->execute(['t' => $tid]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $notes[(string) $r['note_key']] = [
                'text'       => (string) $r['note_text'],
                'updated_by' => $r['updated_by_user_id'] ? (int) $r['updated_by_user_id'] : null,
                'updated_at' => (string) $r['updated_at'],
            ];
        }
    } catch (\Throwable $_) { /* table not migrated yet — empty notes */ }
    api_ok(['notes' => $notes, 'can_write' => $canWrite($user)]);
}

if ($method === 'POST' && $action === '') {
    if (!$canWrite($user)) api_error('Manager role required to write KPI notes', 403);
    $body = api_json_body();
    api_require_fields($body, ['key', 'text']);
    $key  = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $body['key']));
    $text = trim((string) $body['text']);
    if ($key === '' || strlen($key) > 64) api_error('invalid key', 422);
    if (strlen($text) > 280)              api_error('text > 280 chars', 422);

    $pdo = getDB();
    if ($text === '') {
        // Empty text = delete the note. Less typing for the operator.
        $pdo->prepare('DELETE FROM tenant_kpi_notes WHERE tenant_id = :t AND note_key = :k')
            ->execute(['t' => $tid, 'k' => $key]);
        api_ok(['ok' => true, 'deleted' => true]);
    }

    $pdo->prepare(
        'INSERT INTO tenant_kpi_notes (tenant_id, note_key, note_text, updated_by_user_id)
         VALUES (:t, :k, :tx, :u)
         ON DUPLICATE KEY UPDATE note_text = VALUES(note_text), updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute(['t' => $tid, 'k' => $key, 'tx' => $text, 'u' => $user['id'] ?? null]);
    api_ok(['ok' => true, 'key' => $key, 'text' => $text]);
}

if ($method === 'POST' && $action === 'delete') {
    if (!$canWrite($user)) api_error('Manager role required', 403);
    $body = api_json_body();
    api_require_fields($body, ['key']);
    $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $body['key']));
    getDB()->prepare('DELETE FROM tenant_kpi_notes WHERE tenant_id = :t AND note_key = :k')
        ->execute(['t' => $tid, 'k' => $key]);
    api_ok(['ok' => true, 'deleted' => true]);
}

api_error('Method/action not allowed', 405);
