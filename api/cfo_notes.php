<?php
/**
 * /api/cfo_notes — per-section notes a CFO user pins to a widget.
 *
 *   GET    /api/cfo_notes.php?view_id=N         → notes for that view (+ view-less notes)
 *   GET    /api/cfo_notes.php?widget_key=X      → just that widget's notes
 *   POST   /api/cfo_notes.php  { widget_key, body, view_id? }
 *   DELETE /api/cfo_notes.php?id=N
 *
 * Scope: notes are PER USER (private). No sharing in v1.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$userId   = (int) ($user['id'] ?? 0);
$tenantId = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$pdo    = getDB();
$method = api_method();
$id     = (int) api_query('id', 0);

if ($method === 'GET') {
    $viewId    = (int) api_query('view_id', 0);
    $widgetKey = (string) api_query('widget_key', '');
    $where  = ['tenant_id = :t', 'user_id = :u'];
    $params = ['t' => $tenantId, 'u' => $userId];
    if ($viewId)    { $where[] = '(view_id = :v OR view_id IS NULL)'; $params['v'] = $viewId; }
    if ($widgetKey !== '') { $where[] = 'widget_key = :w';            $params['w'] = $widgetKey; }
    $stmt = $pdo->prepare("SELECT id, widget_key, body, view_id, pinned, updated_at
                             FROM cfo_section_notes
                            WHERE " . implode(' AND ', $where) . "
                         ORDER BY updated_at DESC LIMIT 500");
    $stmt->execute($params);
    api_ok(['notes' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
}

if ($method === 'POST') {
    $body  = api_json_body();
    $key   = trim((string) ($body['widget_key'] ?? ''));
    $text  = trim((string) ($body['body']       ?? ''));
    $viewId= (int) ($body['view_id'] ?? 0) ?: null;
    if ($key === '')  api_error('widget_key required', 422);
    if ($text === '') api_error('body required',       422);
    if (mb_strlen($text) > 4000) api_error('note too long (max 4000)', 422);

    $pdo->prepare(
        "INSERT INTO cfo_section_notes (tenant_id, user_id, view_id, widget_key, body)
              VALUES (:t, :u, :v, :w, :b)"
    )->execute(['t' => $tenantId, 'u' => $userId, 'v' => $viewId, 'w' => $key, 'b' => $text]);
    api_ok(['id' => (int) $pdo->lastInsertId()], 201);
}

if ($method === 'DELETE' && $id) {
    $pdo->prepare("DELETE FROM cfo_section_notes
                    WHERE id = :id AND tenant_id = :t AND user_id = :u")
        ->execute(['id' => $id, 't' => $tenantId, 'u' => $userId]);
    api_ok(['id' => $id, 'deleted' => true]);
}

api_error('Method not allowed', 405);
