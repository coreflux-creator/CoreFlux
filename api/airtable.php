<?php
/**
 * Airtable integration — status, connect (PAT save), disconnect, ping,
 * list bases / tables, table-mapping CRUD, sync_now.
 *
 * Routes (all prefixed `/api/airtable/`):
 *   GET    status              — connection state + recent audit + mappings
 *   POST   connect             — body: { pat: "patXXXX...", workspace_label?: "" }
 *   POST   disconnect          — soft-disconnect (zeroes the ciphertext)
 *   POST   ping                — auth round-trip via /meta/whoami
 *   GET    list_bases          — proxied from /v0/meta/bases
 *   GET    list_tables         — ?base_id= → /v0/meta/bases/{base}/tables
 *   GET    mappings            — list all airtable_table_mappings rows
 *   POST   mapping_save        — upsert one row
 *   POST   mapping_delete      — body: { id }
 *   POST   sync_now            — body: { mapping_id }
 *
 * RBAC: read = `integrations.airtable.view`, write = `integrations.airtable.manage`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/airtable/client.php';
require_once __DIR__ . '/../core/airtable/sync.php';

$method = api_method();
$action = (string) (api_query('action') ?? '');
if ($action === '') {
    $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    if (preg_match('#/airtable/([a-z_-]+)\.php$#i', $path, $m)) {
        $action = strtolower($m[1]);
    }
}
$action = str_replace('-', '_', strtolower($action));

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

switch ($action) {
    case 'status': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.view');
        $row = airtableConnection($tid);
        $audit = getDB()->prepare(
            'SELECT id, action, base_id, table_id, direction, ok,
                    items_processed, items_skipped, items_failed,
                    detail, occurred_at
               FROM airtable_sync_audit
              WHERE tenant_id = :t
           ORDER BY occurred_at DESC
              LIMIT 25'
        );
        $audit->execute(['t' => $tid]);
        $rows = $audit->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['ok'] = (bool) (int) $r['ok'];
            $r['items_processed'] = (int) $r['items_processed'];
            $r['items_skipped']   = (int) $r['items_skipped'];
            $r['items_failed']    = (int) $r['items_failed'];
            if (!empty($r['detail'])) {
                $decoded = json_decode((string) $r['detail'], true);
                $r['detail'] = is_array($decoded) ? $decoded : null;
            }
        }
        unset($r);
        api_ok([
            'configured'     => airtableConfigured(),
            'connected'      => (bool) ($row && $row['status'] === 'active'),
            'status'         => $row['status'] ?? null,
            'pat_last4'      => $row['pat_last4'] ?? null,
            'workspace_label'=> $row['workspace_label'] ?? null,
            'scopes'         => $row['scopes'] ?? null,
            'last_probe_at'  => $row['last_probe_at']   ?? null,
            'last_probe_error' => $row['last_probe_error'] ?? null,
            'mappings'       => airtableMappingList($tid),
            'entities'       => AIRTABLE_INTERNAL_ENTITIES,
            'directions'     => AIRTABLE_MAPPING_DIRECTIONS,
            'audit'          => $rows,
        ]);
    }

    case 'connect': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $body = api_json_body();
        $pat   = (string) ($body['pat'] ?? '');
        $label = (string) ($body['workspace_label'] ?? '');
        try {
            $res = airtableSavePAT($tid, $pat, $label !== '' ? $label : null, $user['id'] ?? null);
        } catch (\Throwable $e) {
            api_error($e->getMessage(), 422);
        }
        api_ok(['ok' => true, 'last4' => $res['last4'], 'scopes' => $res['scopes']]);
    }

    case 'disconnect': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        airtableDisconnect($tid, $user['id'] ?? null);
        api_ok(['ok' => true]);
    }

    case 'ping': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        api_ok(airtablePing($tid, $user['id'] ?? null));
    }

    case 'list_bases': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        try {
            $bases = airtableListBases($tid);
        } catch (\Throwable $e) {
            api_error('Airtable error: ' . $e->getMessage(), 502);
        }
        api_ok(['bases' => $bases]);
    }

    case 'list_tables': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $baseId = (string) (api_query('base_id') ?? '');
        if ($baseId === '') api_error('base_id required', 422);
        try {
            $tables = airtableListTables($tid, $baseId);
        } catch (\Throwable $e) {
            api_error('Airtable error: ' . $e->getMessage(), 502);
        }
        api_ok(['base_id' => $baseId, 'tables' => $tables]);
    }

    case 'mappings': {
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.view');
        api_ok([
            'mappings'   => airtableMappingList($tid),
            'entities'   => AIRTABLE_INTERNAL_ENTITIES,
            'directions' => AIRTABLE_MAPPING_DIRECTIONS,
        ]);
    }

    case 'mapping_save': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $body = api_json_body();
        try {
            $row = airtableMappingUpsert($tid, $body, $user['id'] ?? null);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            api_error('mapping_save failed: ' . $e->getMessage(), 500);
        }
        api_ok(['mapping' => $row]);
    }

    case 'mapping_delete': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $body = api_json_body();
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) api_error('id required', 422);
        airtableMappingDelete($tid, $id, $user['id'] ?? null);
        api_ok(['ok' => true]);
    }

    case 'sync_now': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $body = api_json_body();
        $mid = (int) ($body['mapping_id'] ?? 0);
        if ($mid <= 0) api_error('mapping_id required', 422);
        try {
            $res = airtableSyncTable($tid, $mid, $user['id'] ?? null);
        } catch (\Throwable $e) {
            api_error('Sync failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'duplicate_targets': {
        // List candidate target tenants for a duplicate operation.
        // Returns every tenant the caller is authorised to manage,
        // annotated with whether each one has an active Airtable
        // connection. tenant-leak-allow: cross-tenant by design — scoped
        // by the caller's admin-tenant set above.
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $globalRole  = (string) ($ctx['global_role'] ?? $user['global_role'] ?? $user['role'] ?? 'employee');
        $isGlobalAdm = (bool)   ($ctx['is_global_admin'] ?? false);
        $allowed     = airtableUserAdminTenantSet((int) ($user['id'] ?? 0), $globalRole, $isGlobalAdm);
        if (!$allowed) api_ok(['targets' => []]);
        $place = implode(',', array_fill(0, count($allowed), '?'));
        $ids   = array_keys($allowed);
        $stmt = getDB()->prepare(
            "SELECT t.id, t.name, t.parent_id, t.tenant_type,
                    c.status AS connection_status, c.pat_last4
               FROM tenants t
          LEFT JOIN airtable_connections c ON c.tenant_id = t.id
              WHERE t.is_active = 1 AND t.id IN ($place)
           ORDER BY (t.parent_id IS NULL) DESC, t.name ASC"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $rid = (int) $r['id'];
            if ($rid === $tid) continue;   // never list the source
            $out[] = [
                'id'                => $rid,
                'name'              => (string) $r['name'],
                'parent_id'         => $r['parent_id'] ? (int) $r['parent_id'] : null,
                'tenant_type'       => (string) ($r['tenant_type'] ?? 'master'),
                'connection_status' => (string) ($r['connection_status'] ?? 'none'),
                'pat_last4'         => $r['pat_last4'] ?? null,
                'connected'         => ($r['connection_status'] ?? null) === 'active',
            ];
        }
        api_ok(['targets' => $out]);
    }

    case 'mapping_duplicate': {
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $body = api_json_body();
        $sourceId = (int) ($body['source_mapping_id'] ?? 0);
        $targets  = $body['target_tenant_ids'] ?? [];
        if ($sourceId <= 0)        api_error('source_mapping_id required', 422);
        if (!is_array($targets))   api_error('target_tenant_ids must be an array', 422);
        if (count($targets) === 0) api_error('target_tenant_ids must not be empty', 422);
        if (count($targets) > 100) api_error('target_tenant_ids may not exceed 100', 422);

        $globalRole  = (string) ($ctx['global_role'] ?? $user['global_role'] ?? $user['role'] ?? 'employee');
        $isGlobalAdm = (bool)   ($ctx['is_global_admin'] ?? false);
        try {
            $res = airtableMappingDuplicate(
                $tid, $sourceId, $targets,
                (int) ($user['id'] ?? 0), $globalRole, $isGlobalAdm
            );
        } catch (\RuntimeException $e) {
            api_error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            api_error('duplicate failed: ' . $e->getMessage(), 500);
        }
        api_ok($res);
    }
}

api_error('Unknown action: ' . $action, 400);
