<?php
/**
 * Saved Treasury Scenario Presets.
 *
 * Tenant-scoped library of named what-if event lists.
 *
 *   GET    /api/treasury_scenario_presets.php           → list
 *   POST   /api/treasury_scenario_presets.php           → save / upsert
 *     {
 *       "id":          (optional, edit-in-place),
 *       "name":        "Fiscal year close push",
 *       "description": "Stretch AP, accelerate AR, hold cash" (optional, ≤500),
 *       "events":      [{kind, amount, date, label}, ...]   (≤50)
 *     }
 *   DELETE /api/treasury_scenario_presets.php?id=N      → remove
 *
 * RBAC: read = `treasury.payment.view`, write = `treasury.payment.manage`.
 *
 * Reuses the same event-shape contract as `/api/treasury_scenario.php`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();
$pdo  = getDB();

if ($method === 'GET') {
    rbac_legacy_require($user, 'treasury.payment.view');
    $stmt = $pdo->prepare(
        'SELECT id, name, description, events_json, created_by_user_id, created_at, updated_at
           FROM treasury_scenario_presets
          WHERE tenant_id = :t
          ORDER BY updated_at DESC, id DESC'
    );
    $stmt->execute(['t' => $tid]);
    $rows = [];
    while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $events = json_decode((string) $r['events_json'], true);
        $rows[] = [
            'id'                 => (int) $r['id'],
            'name'               => (string) $r['name'],
            'description'        => $r['description'],
            'events'             => is_array($events) ? $events : [],
            'created_by_user_id' => $r['created_by_user_id'] !== null ? (int) $r['created_by_user_id'] : null,
            'created_at'         => $r['created_at'],
            'updated_at'         => $r['updated_at'],
        ];
    }
    api_ok(['presets' => $rows]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'treasury.payment.manage');
    $body  = api_json_body();
    $id    = (int) ($body['id'] ?? 0) ?: null;
    $name  = trim((string) ($body['name'] ?? ''));
    $desc  = trim((string) ($body['description'] ?? ''));
    $rawEv = $body['events'] ?? [];

    if ($name === '')             api_error('name required', 422);
    if (strlen($name) > 120)      api_error('name max 120 chars', 422);
    if (strlen($desc) > 500)      api_error('description max 500 chars', 422);
    if (!is_array($rawEv))        api_error('events must be an array', 422);
    if (count($rawEv) === 0)      api_error('at least one event required', 422);
    if (count($rawEv) > 50)       api_error('Too many events — maximum 50 per preset', 422);

    // Validate every event with the same shape contract as the live
    // scenario endpoint. Dates are NOT clamped here — these presets are
    // re-applied later, often outside today's forecast window.
    $events = [];
    foreach ($rawEv as $idx => $e) {
        if (!is_array($e)) api_error("event #{$idx} malformed", 422);
        $kind   = (string) ($e['kind'] ?? '');
        $amount = round((float) ($e['amount'] ?? 0), 2);
        $date   = (string) ($e['date'] ?? '');
        $label  = trim((string) ($e['label'] ?? ''));
        if (!in_array($kind, ['inflow', 'outflow'], true)) api_error("event #{$idx}: kind must be 'inflow' or 'outflow'", 422);
        if ($amount <= 0) api_error("event #{$idx}: amount must be positive", 422);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) api_error("event #{$idx}: date must be YYYY-MM-DD", 422);
        $events[] = [
            'kind'   => $kind,
            'amount' => $amount,
            'date'   => $date,
            'label'  => $label !== '' ? $label : ucfirst($kind),
        ];
    }
    $eventsJson = json_encode($events, JSON_UNESCAPED_SLASHES);

    if ($id !== null) {
        // Edit-in-place.
        $stmt = $pdo->prepare(
            'UPDATE treasury_scenario_presets
                SET name = :n, description = :d, events_json = :e
              WHERE id = :id AND tenant_id = :t'
        );
        $stmt->execute([
            'n'  => $name,
            'd'  => $desc !== '' ? $desc : null,
            'e'  => $eventsJson,
            'id' => $id,
            't'  => $tid,
        ]);
        if ($stmt->rowCount() === 0) {
            // Either nothing changed or row not found. Confirm existence
            // so we return a clear 404 vs a 200-no-op.
            $check = $pdo->prepare('SELECT 1 FROM treasury_scenario_presets WHERE id = :id AND tenant_id = :t');
            $check->execute(['id' => $id, 't' => $tid]);
            if (!$check->fetchColumn()) api_error('Preset not found', 404);
        }
        api_ok(['preset' => ['id' => $id, 'name' => $name, 'description' => $desc !== '' ? $desc : null, 'events' => $events]]);
    }

    // Insert. ON DUPLICATE KEY collapses (tenant_id, name) so saving the
    // same name updates in place — operator never has to delete + retry.
    $stmt = $pdo->prepare(
        'INSERT INTO treasury_scenario_presets
             (tenant_id, name, description, events_json, created_by_user_id)
         VALUES (:t, :n, :d, :e, :u)
         ON DUPLICATE KEY UPDATE
             description = VALUES(description),
             events_json = VALUES(events_json)'
    );
    $stmt->execute([
        't' => $tid,
        'n' => $name,
        'd' => $desc !== '' ? $desc : null,
        'e' => $eventsJson,
        'u' => isset($user['id']) ? (int) $user['id'] : null,
    ]);
    $newId = (int) $pdo->lastInsertId();
    if ($newId === 0) {
        // Upsert hit the unique key — fetch the existing id.
        $f = $pdo->prepare('SELECT id FROM treasury_scenario_presets WHERE tenant_id = :t AND name = :n');
        $f->execute(['t' => $tid, 'n' => $name]);
        $newId = (int) ($f->fetchColumn() ?: 0);
    }
    api_ok(['preset' => ['id' => $newId, 'name' => $name, 'description' => $desc !== '' ? $desc : null, 'events' => $events]]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'treasury.payment.manage');
    $id = (int) (api_query('id') ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $stmt = $pdo->prepare(
        'DELETE FROM treasury_scenario_presets WHERE id = :id AND tenant_id = :t'
    );
    $stmt->execute(['id' => $id, 't' => $tid]);
    if ($stmt->rowCount() === 0) api_error('Preset not found', 404);
    api_ok(['ok' => true, 'id' => $id]);
}

api_error('Method not allowed', 405);
