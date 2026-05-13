<?php
/**
 * CSV mapping presets — saved header→field maps that turn recurring
 * tenant imports (monthly ADP payroll, weekly Bullhorn placement dump,
 * QuickBooks vendor refresh) into one-click reruns.
 *
 *   GET    /api/admin/csv_mapping_presets?entity=people[&signature=…]  list
 *   POST   /api/admin/csv_mapping_presets                              create
 *   POST   /api/admin/csv_mapping_presets?action=use&id=…              bump used_count
 *   DELETE /api/admin/csv_mapping_presets?id=…                         remove
 *
 * Recognition is by `header_signature` = sha256(comma-joined, lowercased,
 * sorted headers). Stable across header reordering and case changes —
 * matches the EXACT same set of column names regardless of order.
 */
require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';

const CSV_PRESET_ENTITIES = [
    'people', 'ap_vendors', 'staffing_clients', 'placements', 'time',
    'ap_bills', 'billing_invoices', 'ap_payments', 'billing_payments',
];

function csvPresetSignature(array $headers): string
{
    $norm = array_map(fn($h) => strtolower(trim((string) $h)), $headers);
    sort($norm, SORT_STRING);
    return hash('sha256', implode(',', $norm));
}

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? null;

// LIST — filter by entity + optional signature for "exact match on this CSV"
if ($method === 'GET') {
    $entity    = (string) ($_GET['entity'] ?? '');
    $signature = (string) ($_GET['signature'] ?? '');
    if ($entity !== '' && !in_array($entity, CSV_PRESET_ENTITIES, true)) {
        api_error('Unknown entity: ' . $entity, 400);
    }
    $where = ['tenant_id = :tenant_id'];
    $params = [];
    if ($entity)    { $where[] = 'entity = :e';            $params['e']   = $entity; }
    if ($signature) { $where[] = 'header_signature = :sig'; $params['sig'] = $signature; }

    $rows = scopedQuery(
        'SELECT id, entity, name, header_signature, column_map, source_headers,
                used_count, last_used_at, created_at, updated_at
           FROM csv_mapping_presets
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY last_used_at IS NULL, last_used_at DESC, used_count DESC, name ASC',
        $params
    );
    // Decode JSON columns so the UI can use them directly.
    foreach ($rows as &$r) {
        $r['column_map']     = is_string($r['column_map'])     ? json_decode($r['column_map'],     true) : $r['column_map'];
        $r['source_headers'] = is_string($r['source_headers']) ? json_decode($r['source_headers'], true) : $r['source_headers'];
    }
    api_ok(['rows' => $rows]);
}

// USE — bump used_count + last_used_at when the user applies a preset.
if ($method === 'POST' && $action === 'use') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) api_error('id required', 400);
    $preset = scopedFind('SELECT id FROM csv_mapping_presets WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$preset) api_error('Preset not found', 404);
    getDB()->prepare(
        'UPDATE csv_mapping_presets
            SET used_count = used_count + 1,
                last_used_at = NOW()
          WHERE id = :id AND tenant_id = :t'
    )->execute(['id' => $id, 't' => $tid]);
    api_ok(['updated' => true]);
}

// CREATE
if ($method === 'POST') {
    $body = api_json_body();
    $entity = (string) ($body['entity'] ?? '');
    $name   = trim((string) ($body['name'] ?? ''));
    $map    = $body['column_map']     ?? null;
    $hdrs   = $body['source_headers'] ?? null;

    if (!in_array($entity, CSV_PRESET_ENTITIES, true)) api_error('Unknown entity: ' . $entity, 400);
    if ($name === '')                                  api_error('name is required', 400);
    if (!is_array($map))                               api_error('column_map must be an object', 400);
    if (!is_array($hdrs) || !$hdrs)                    api_error('source_headers must be a non-empty array', 400);

    $signature = csvPresetSignature($hdrs);

    // Upsert by (tenant, entity, name) so repeated saves with the same
    // name overwrite rather than create duplicates.
    $existing = scopedFind(
        'SELECT id FROM csv_mapping_presets WHERE tenant_id = :tenant_id AND entity = :e AND name = :n',
        ['e' => $entity, 'n' => $name]
    );
    if ($existing) {
        scopedUpdate('csv_mapping_presets', (int) $existing['id'], [
            'header_signature' => $signature,
            'column_map'       => json_encode($map),
            'source_headers'   => json_encode(array_values($hdrs)),
        ]);
        api_ok(['id' => (int) $existing['id'], 'updated' => true]);
    }

    $newId = scopedInsert('csv_mapping_presets', [
        'entity'             => $entity,
        'name'               => $name,
        'header_signature'   => $signature,
        'column_map'         => json_encode($map),
        'source_headers'     => json_encode(array_values($hdrs)),
        'created_by_user_id' => $user['id'] ?? null,
    ]);
    api_ok(['id' => $newId, 'created' => true]);
}

// DELETE
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) api_error('id required', 400);
    $existing = scopedFind('SELECT id FROM csv_mapping_presets WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$existing) api_error('Preset not found', 404);
    getDB()->prepare('DELETE FROM csv_mapping_presets WHERE id = :id AND tenant_id = :t')
        ->execute(['id' => $id, 't' => $tid]);
    api_ok(['deleted' => true]);
}

api_error('Method not allowed', 405);
