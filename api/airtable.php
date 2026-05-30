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

    case 'relink': {
        // Slice-2 — backfill internal_entity_id on existing
        // external_entity_mappings rows after the operator changes
        // linkage policy. Useful when switching from `none` (Slice-1
        // synthetic) to `external_id` or `match_column`.
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $body = api_json_body();
        $mid = (int) ($body['mapping_id'] ?? 0);
        if ($mid <= 0) api_error('mapping_id required', 422);
        try {
            $res = airtableRelinkExistingRows($tid, $mid, $user['id'] ?? null);
        } catch (\Throwable $e) {
            api_error('Relink failed: ' . $e->getMessage(), 502);
        }
        api_ok($res);
    }

    case 'link_stats': {
        // Aggregate linkage counts for one mapping. Powers the
        // linked/unmatched/ambiguous badge in AirtableSettings.
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.view');
        $mid = (int) (api_query('mapping_id') ?? 0);
        if ($mid <= 0) api_error('mapping_id required', 422);
        try {
            $stats = airtableLinkStats($tid, $mid);
        } catch (\Throwable $e) {
            api_error('Link stats failed: ' . $e->getMessage(), 500);
        }
        api_ok($stats);
    }

    case 'vault': {
        // Slice-3.1 — browse the integrations vault for one Airtable
        // mapping. Returns paginated external_entity_mappings rows
        // with their payload snapshots so operators can SEE what was
        // synced even when records aren't linked to a real CoreFlux
        // row (the common case for entity='generic',
        // link_strategy='none'). Powers the "Records vault" drill-down
        // surfaced in AirtableSettings beneath the Health panel.
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.view');
        $mid    = (int) (api_query('mapping_id') ?? 0);
        $limit  = max(1, min(200, (int) (api_query('limit')  ?? 50)));
        $offset = max(0, (int) (api_query('offset') ?? 0));
        $q      = trim((string) (api_query('q') ?? ''));
        if ($mid <= 0) api_error('mapping_id required', 422);
        $mapping = airtableMappingGet($tid, $mid);
        if (!$mapping) api_error('Mapping not found', 404);

        $params = ['t' => $tid, 'et' => $mapping['internal_entity']];
        $where  = "tenant_id = :t AND source_system = 'airtable' AND internal_entity_type = :et";
        if ($q !== '') {
            $where        .= " AND (external_id LIKE :q OR payload_snapshot LIKE :q)";
            $params['q']   = '%' . $q . '%';
        }
        // Count first (capped) so the UI can show "Showing 50 of 2,216".
        $stCount = getDB()->prepare("SELECT COUNT(*) FROM external_entity_mappings WHERE {$where}");
        $stCount->execute($params);
        $total = (int) $stCount->fetchColumn();

        $st = getDB()->prepare(
            "SELECT id, external_id, internal_entity_id, sync_status,
                    last_synced_at, last_seen_at, payload_snapshot
               FROM external_entity_mappings
              WHERE {$where}
           ORDER BY last_seen_at DESC, id DESC
              LIMIT {$limit} OFFSET {$offset}"
        );
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Decode payload + compute a field-key fingerprint per row so
        // the UI can show "12 fields" without re-stringifying the JSON.
        // Synthetic ids — when link_strategy='none' OR no real link —
        // get flagged so we don't pretend the row is linked.
        $fieldCounts = [];
        foreach ($rows as &$r) {
            $r['id']                 = (int) $r['id'];
            $r['internal_entity_id'] = (int) $r['internal_entity_id'];
            $payload = json_decode((string) ($r['payload_snapshot'] ?? '[]'), true);
            $r['payload_snapshot']   = is_array($payload) ? $payload : null;
            $r['field_count']        = is_array($payload)
                ? count(array_filter(array_keys($payload), fn ($k) => $k[0] !== '_'))
                : 0;
            $r['airtable_record_url'] = is_array($payload)
                ? ($payload['_airtable_record_url'] ?? null)
                : null;
            $r['is_stored_only']     = ((string) ($mapping['link_strategy'] ?? 'none')) === 'none';
            if (is_array($payload)) {
                foreach (array_keys($payload) as $k) {
                    if ($k[0] === '_') continue;
                    $fieldCounts[$k] = ($fieldCounts[$k] ?? 0) + 1;
                }
            }
        }
        unset($r);
        arsort($fieldCounts);
        $topFields = [];
        foreach ($fieldCounts as $k => $n) {
            $topFields[] = ['field' => $k, 'occurrences' => $n];
            if (count($topFields) >= 25) break;
        }

        api_ok([
            'mapping_id'      => $mid,
            'internal_entity' => $mapping['internal_entity'],
            'link_strategy'   => (string) ($mapping['link_strategy'] ?? 'none'),
            'is_stored_only'  => ((string) ($mapping['link_strategy'] ?? 'none')) === 'none',
            'total'           => $total,
            'limit'           => $limit,
            'offset'          => $offset,
            'q'               => $q,
            'rows'            => $rows,
            'top_fields'      => $topFields,
        ]);
    }

    case 'discover_tables': {
        // Slice-3.1 — bulk-discovery surface for operators who want to
        // sync more than just the one table they already configured.
        // Lists every Airtable base + table the PAT can read, with a
        // flag indicating which (base, table) tuples are already
        // mapped in CoreFlux. Powers the "Sync more tables" picker in
        // AirtableSettings.
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.view');
        try {
            $bases = airtableListBases($tid);
        } catch (\Throwable $e) {
            api_error('Failed to list bases: ' . $e->getMessage(), 502);
        }
        // Map existing (base_id, table_id) → mapping_id for fast lookup.
        $existing = [];
        foreach (airtableMappingList($tid) as $m) {
            $key = ($m['base_id'] ?? '') . '|' . ($m['table_id'] ?? '');
            $existing[$key] = (int) ($m['id'] ?? 0);
        }
        $out = [];
        $tableTotal   = 0;
        $tableMapped  = 0;
        foreach (($bases['bases'] ?? $bases) as $b) {
            $bid = (string) ($b['id'] ?? '');
            if ($bid === '') continue;
            $tables = [];
            try {
                $bt = airtableListTables($tid, $bid);
            } catch (\Throwable $e) {
                $tables = []; // base may be inaccessible; skip silently
                $out[] = [
                    'id'     => $bid,
                    'name'   => (string) ($b['name'] ?? $bid),
                    'tables' => [],
                    'error'  => substr($e->getMessage(), 0, 160),
                ];
                continue;
            }
            foreach (($bt['tables'] ?? []) as $t) {
                $tid_  = (string) ($t['id'] ?? '');
                if ($tid_ === '') continue;
                $tableTotal++;
                $key = $bid . '|' . $tid_;
                $isMapped = isset($existing[$key]);
                if ($isMapped) $tableMapped++;
                $tables[] = [
                    'id'           => $tid_,
                    'name'         => (string) ($t['name'] ?? $tid_),
                    'primary_field'=> (string) ($t['primaryFieldId'] ?? ''),
                    'field_count'  => is_array($t['fields'] ?? null) ? count($t['fields']) : 0,
                    'mapped'       => $isMapped,
                    'mapping_id'   => $isMapped ? $existing[$key] : null,
                ];
            }
            $out[] = [
                'id'     => $bid,
                'name'   => (string) ($b['name'] ?? $bid),
                'tables' => $tables,
            ];
        }
        api_ok([
            'bases'         => $out,
            'tables_total'  => $tableTotal,
            'tables_mapped' => $tableMapped,
        ]);
    }

    case 'mapping_save_bulk': {
        // Slice-3.1 — create multiple mappings in one shot from the
        // "Sync more tables" picker. Each row is independently upserted
        // so a single bad row doesn't break the rest.
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $body = api_json_body();
        $items = $body['items'] ?? [];
        if (!is_array($items) || count($items) === 0) api_error('items[] required', 422);
        if (count($items) > 50)                       api_error('items[] may not exceed 50', 422);
        $created = []; $skipped = []; $errors = [];
        foreach ($items as $i => $row) {
            try {
                $saved = airtableMappingUpsert($tid, $row, $user['id'] ?? null);
                $created[] = ['index' => $i, 'mapping_id' => (int) $saved['id'],
                              'base_id' => $saved['base_id'], 'table_id' => $saved['table_id']];
            } catch (\InvalidArgumentException $e) {
                $skipped[] = ['index' => $i, 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                $errors[] = ['index' => $i, 'error' => substr($e->getMessage(), 0, 200)];
            }
        }
        api_ok([
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
        ]);
    }

    case 'health': {
        // Slice-3 — tenant-wide health & troubleshooting summary.
        // Rolls up connection status, every mapping's linkage health,
        // recent sync errors, applied Studio field-mapping counts,
        // and a derived list of actionable troubleshooting hints.
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.view');

        $conn = airtableConnection($tid);
        $mappings = airtableMappingList($tid);

        $rollup = [
            'total_records'   => 0,
            'linked'          => 0,
            'stored_only'     => 0,
            'unmatched'       => 0,
            'ambiguous'       => 0,
            'errored'         => 0,
            'mappings'        => count($mappings),
            'mappings_off'    => 0,
            'mappings_unrun'  => 0,
            'mappings_failed' => 0,
            'mappings_stored_only' => 0,
        ];
        $perMapping = [];
        $hints = [];

        foreach ($mappings as $m) {
            $mid = (int) ($m['id'] ?? 0);
            $stats = ['linked' => 0, 'stored_only' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'stale' => 0, 'error' => 0, 'total' => 0];
            try { $stats = airtableLinkStats($tid, $mid); } catch (\Throwable $e) { /* mapping deletion race */ }
            $perMapping[] = [
                'id'              => $mid,
                'base_id'         => (string) ($m['base_id'] ?? ''),
                'base_name'       => (string) ($m['base_name'] ?? ''),
                'table_id'        => (string) ($m['table_id'] ?? ''),
                'table_name'      => (string) ($m['table_name'] ?? ''),
                'internal_entity' => (string) ($m['internal_entity'] ?? ''),
                'direction'       => (string) ($m['direction'] ?? 'pull'),
                'link_strategy'   => (string) ($m['link_strategy'] ?? 'none'),
                'last_sync_at'    => $m['last_sync_at']    ?? null,
                'last_sync_error' => $m['last_sync_error'] ?? null,
                'last_records'    => (int) ($m['last_records'] ?? 0),
                'stats'           => $stats,
                'health_pct'      => $stats['total'] > 0
                    ? (int) round(100 * $stats['linked'] / max(1, $stats['total']))
                    : null,
                'is_stored_only'  => ((string) ($m['link_strategy'] ?? 'none')) === 'none',
            ];
            $rollup['total_records'] += (int) $stats['total'];
            $rollup['linked']        += (int) $stats['linked'];
            $rollup['stored_only']   += (int) ($stats['stored_only'] ?? 0);
            $rollup['unmatched']     += (int) $stats['unmatched'];
            $rollup['ambiguous']     += (int) $stats['ambiguous'];
            $rollup['errored']       += (int) $stats['error'];
            if (($m['direction'] ?? 'pull') !== 'pull')                 $rollup['mappings_off']++;
            if (empty($m['last_sync_at']))                              $rollup['mappings_unrun']++;
            if (!empty($m['last_sync_error']))                          $rollup['mappings_failed']++;
            if (((string) ($m['link_strategy'] ?? 'none')) === 'none')  $rollup['mappings_stored_only']++;

            if (!empty($m['last_sync_error'])) {
                $hints[] = [
                    'severity' => 'error',
                    'code'     => 'sync_error',
                    'mapping_id' => $mid,
                    'message'  => 'Last sync for ' . ($m['table_name'] ?: $m['table_id']) .
                                  ' failed: ' . substr((string) $m['last_sync_error'], 0, 160),
                ];
            }
            if (($stats['ambiguous'] ?? 0) > 0) {
                $hints[] = [
                    'severity' => 'warn',
                    'code'     => 'ambiguous',
                    'mapping_id' => $mid,
                    'message'  => $stats['ambiguous'] . ' record(s) on ' .
                                  ($m['table_name'] ?: $m['table_id']) .
                                  ' matched multiple CoreFlux rows — visit Reconciliation to pick the right one.',
                ];
            }
            if (($stats['unmatched'] ?? 0) > 0 && ($m['link_strategy'] ?? 'none') !== 'none') {
                $hints[] = [
                    'severity' => 'warn',
                    'code'     => 'unmatched',
                    'mapping_id' => $mid,
                    'message'  => $stats['unmatched'] . ' record(s) on ' .
                                  ($m['table_name'] ?: $m['table_id']) .
                                  ' could not be matched to a CoreFlux row using ' .
                                  ($m['link_strategy'] ?? 'none') . '. Adjust the link match field or pick a different strategy.',
                ];
            }
            if (($m['link_strategy'] ?? 'none') === 'none' && (int) $stats['total'] > 0) {
                $hints[] = [
                    'severity' => 'info',
                    'code'     => 'stored_only',
                    'mapping_id' => $mid,
                    'message'  => ($m['table_name'] ?: $m['table_id']) .
                                  ' has ' . (int) $stats['total'] .
                                  ' record(s) stored in the integrations vault but never linked to a CoreFlux ' .
                                  $m['internal_entity'] . ' row (link_strategy=none). ' .
                                  'Browse them under "Records vault" below, or edit this mapping to pick a real entity + link strategy.',
                ];
            }
        }

        if (empty($conn) || ($conn['status'] ?? null) !== 'active') {
            array_unshift($hints, [
                'severity' => 'error',
                'code'     => 'not_connected',
                'mapping_id' => null,
                'message'  => 'Airtable is not connected — paste a PAT under Integration Settings → Airtable to begin syncing.',
            ]);
        }

        // Field-mapping coverage from the Studio (cross-entity rollup).
        $coverage = [];
        try {
            $st = getDB()->prepare(
                "SELECT internal_entity_type AS entity_type,
                        COUNT(*) AS field_mappings
                   FROM tenant_integration_field_map
                  WHERE tenant_id = :t
                    AND integration = 'airtable'
                    AND enabled = 1
               GROUP BY internal_entity_type"
            );
            $st->execute(['t' => $tid]);
            $coverage = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($coverage as &$c) { $c['field_mappings'] = (int) $c['field_mappings']; }
            unset($c);
        } catch (\Throwable $e) {
            // pre-migration tenants — skip silently
        }

        api_ok([
            'connected'        => (bool) ($conn && $conn['status'] === 'active'),
            'pat_last4'        => $conn['pat_last4']        ?? null,
            'workspace_label'  => $conn['workspace_label']  ?? null,
            'last_probe_at'    => $conn['last_probe_at']    ?? null,
            'last_probe_error' => $conn['last_probe_error'] ?? null,
            'rollup'           => $rollup,
            'per_mapping'      => $perMapping,
            'field_map_coverage' => $coverage,
            'hints'            => $hints,
        ]);
    }

    case 'unmatched': {
        // List unmatched (or ambiguous) external_entity_mappings rows
        // for one mapping — the reconciliation queue. Surfaces id,
        // external_id, payload preview, and last_seen_at. Caller can
        // then call mapping_link_manual to assign internal_entity_id.
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.view');
        $mid    = (int) (api_query('mapping_id') ?? 0);
        $status = (string) (api_query('status') ?? 'unmatched');
        $limit  = max(1, min(200, (int) (api_query('limit') ?? 50)));
        if ($mid <= 0)                                            api_error('mapping_id required', 422);
        if (!in_array($status, ['unmatched','ambiguous'], true))  api_error('status must be unmatched|ambiguous', 422);
        $mapping = airtableMappingGet($tid, $mid);
        if (!$mapping) api_error('Mapping not found', 404);
        $stmt = getDB()->prepare(
            "SELECT id, external_id, payload_snapshot,
                    sync_status, last_seen_at, last_synced_at
               FROM external_entity_mappings
              WHERE tenant_id = :t
                AND source_system = 'airtable'
                AND internal_entity_type = :et
                AND sync_status = :s
           ORDER BY last_seen_at DESC
              LIMIT {$limit}"
        );
        $stmt->execute(['t' => $tid, 'et' => $mapping['internal_entity'], 's' => $status]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $snap = json_decode((string) ($r['payload_snapshot'] ?? '[]'), true);
            $r['payload_snapshot'] = is_array($snap) ? $snap : null;
        }
        unset($r);
        api_ok([
            'mapping_id'     => $mid,
            'internal_entity'=> $mapping['internal_entity'],
            'status'         => $status,
            'rows'           => $rows,
        ]);
    }

    case 'link_manual': {
        // Manually link an unmatched/ambiguous external_entity_mappings
        // row to a specific CoreFlux internal_entity_id. Operator-
        // driven — used from the reconciliation queue UI.
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        $body = api_json_body();
        $rowId      = (int) ($body['mapping_row_id']      ?? 0);
        $internalId = (int) ($body['internal_entity_id']  ?? 0);
        if ($rowId <= 0 || $internalId <= 0) api_error('mapping_row_id + internal_entity_id required', 422);
        $st = getDB()->prepare(
            "UPDATE external_entity_mappings
                SET internal_entity_id = :iid,
                    sync_status = 'ok',
                    last_synced_at = NOW()
              WHERE id = :id
                AND tenant_id = :t
                AND source_system = 'airtable'"
        );
        $st->execute(['iid' => $internalId, 'id' => $rowId, 't' => $tid]);
        if ($st->rowCount() === 0) api_error('Row not found or wrong tenant', 404);
        airtableAudit($tid, 'link_manual', [
            'actor_user_id' => $user['id'] ?? null,
            'detail' => ['mapping_row_id' => $rowId, 'internal_entity_id' => $internalId],
        ]);
        api_ok(['ok' => true, 'mapping_row_id' => $rowId, 'internal_entity_id' => $internalId]);
    }

    case 'search_entities': {
        // Slice-4 — typeahead used by the Reconciliation Queue UI to
        // populate a "Link to existing CoreFlux row" dropdown when the
        // operator manually triages an unmatched vault row.
        if ($method !== 'GET') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.view');
        require_once __DIR__ . '/../core/airtable/sync_slice4.php';
        $entity = (string) (api_query('entity') ?? '');
        $q      = trim((string) (api_query('q') ?? ''));
        $limit  = max(1, min(50, (int) (api_query('limit') ?? 20)));
        if ($entity === '' || $q === '')                                     api_error('entity + q required', 422);
        if (!in_array($entity, AIRTABLE_INTERNAL_ENTITIES, true))            api_error('Unknown entity', 422);
        $results = airtableSearchInternalEntities($tid, $entity, $q, $limit);
        api_ok(['entity' => $entity, 'q' => $q, 'rows' => $results]);
    }

    case 'create_stub': {
        // Slice-4 — promote ONE unmatched vault row into a new CoreFlux
        // entity row. Returns the new internal_id; operator can then
        // see the row in its native module immediately.
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        require_once __DIR__ . '/../core/airtable/sync_slice4.php';
        $body  = api_json_body();
        $rowId = (int) ($body['mapping_row_id'] ?? 0);
        if ($rowId <= 0) api_error('mapping_row_id required', 422);
        // Fetch the vault row (tenant-scoped).
        $stmt = getDB()->prepare(
            "SELECT id, internal_entity_type, external_id, payload_snapshot
               FROM external_entity_mappings
              WHERE id = :id AND tenant_id = :t AND source_system = 'airtable'
              LIMIT 1"
        );
        $stmt->execute(['id' => $rowId, 't' => $tid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) api_error('Vault row not found', 404);
        $payload = json_decode((string) ($row['payload_snapshot'] ?? '[]'), true);
        if (!is_array($payload)) $payload = [];
        $newId = airtableCreateStubFromVault(
            $tid, (string) $row['internal_entity_type'], $payload,
            (string) $row['external_id'], $user['id'] ?? null
        );
        if ($newId === null) {
            api_error('Could not infer enough fields to create a stub for this row.', 422);
        }
        $upd = getDB()->prepare(
            "UPDATE external_entity_mappings
                SET internal_entity_id = :iid,
                    sync_status = 'ok',
                    last_synced_at = NOW()
              WHERE id = :id AND tenant_id = :t"
        );
        $upd->execute(['iid' => $newId, 'id' => $rowId, 't' => $tid]);
        api_ok([
            'ok'                 => true,
            'mapping_row_id'     => $rowId,
            'internal_entity_id' => $newId,
            'internal_entity'    => $row['internal_entity_type'],
        ]);
    }

    case 'promote_vault': {
        // Slice-4 — bulk-promote a mapping. Updates the mapping's
        // (entity, link_strategy, match_field), re-runs linkage across
        // every vault row, and optionally creates stubs for everything
        // still unmatched after re-linking. Returns the full rollup so
        // the UI can show "32 linked, 8 stubs created, 0 unmatched".
        if ($method !== 'POST') api_error('Method not allowed', 405);
        rbac_legacy_require($user, 'integrations.airtable.manage');
        require_once __DIR__ . '/../core/airtable/sync_slice4.php';
        $body = api_json_body();
        $mid  = (int) ($body['mapping_id'] ?? 0);
        if ($mid <= 0) api_error('mapping_id required', 422);
        $mapping = airtableMappingGet($tid, $mid);
        if (!$mapping) api_error('Mapping not found', 404);

        $previousEntity = (string) $mapping['internal_entity'];

        $policy = [
            'internal_entity'             => (string) ($body['internal_entity'] ?? $mapping['internal_entity']),
            'link_strategy'               => (string) ($body['link_strategy']   ?? 'external_id'),
            'link_match_airtable_field'   => (string) ($body['link_match_airtable_field']  ?? '') ?: null,
            'link_match_internal_column'  => (string) ($body['link_match_internal_column'] ?? '') ?: null,
            'link_unmatched_action'       => (string) ($body['link_unmatched_action']      ?? 'park'),
            '_previous_entity'            => $previousEntity,
        ];
        if (!in_array($policy['internal_entity'], AIRTABLE_INTERNAL_ENTITIES, true)) {
            api_error('Unknown internal_entity', 422);
        }
        if (!in_array($policy['link_strategy'], AIRTABLE_LINK_STRATEGIES, true)) {
            api_error('Unknown link_strategy', 422);
        }
        if (!in_array($policy['link_unmatched_action'], AIRTABLE_UNMATCHED_ACTIONS, true)) {
            api_error('Unknown unmatched_action', 422);
        }
        $createStubs = (bool) ($body['create_stubs'] ?? false);
        try {
            $rollup = airtablePromoteVaultMapping(
                $tid, $mid, $policy, $createStubs, $user['id'] ?? null
            );
        } catch (\Throwable $e) {
            api_error('Promote failed: ' . $e->getMessage(), 500);
        }
        api_ok($rollup);
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
