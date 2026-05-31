<?php
/**
 * /app/core/airtable/sync_push.php
 *
 * Slice 4.1 — push direction for Airtable mappings.
 *
 * The pull worker (sync.php) hands every Airtable record off to
 * mappingUpsert() and lets the studio mapper write CoreFlux columns.
 * The push worker is the inverse: it iterates CoreFlux rows of the
 * mapping's `internal_entity` and writes them to Airtable using the
 * mapping's `reverse_field_map` (CoreFlux column → Airtable field).
 *
 * Linkage:
 *   - Look up the existing Airtable record id via
 *     external_entity_mappings (source_system='airtable',
 *     internal_entity_type=$entity, internal_entity_id=$row.id).
 *   - If found       → PATCH /v0/{base}/{table}/{record_id}
 *   - If not found   → branch on $mapping['push_unmatched_action']:
 *       'create_new'  POST /v0/{base}/{table} and INSERT the
 *                     external_entity_mappings linkage row.
 *       'update_only' skip + log under 'still_unmatched'.
 *       'error'       record as failure.
 *
 * Push throughput is rate-limited at the airtableCall layer (5 rps
 * per base). For massive backfills the cron should run with a small
 * sleep between rows.
 */
declare(strict_types=1);

require_once __DIR__ . '/sync.php';

/**
 * Per-entity push descriptor:
 *   table       — CoreFlux table the entity lives in.
 *   id_col      — primary id column on that table (always 'id').
 *   touched_col — the timestamp column the push worker uses to find
 *                 rows updated since the last push run.
 *   columns     — whitelist of safe-to-read column names.
 */
const AIRTABLE_PUSH_ENTITY_TABLES = [
    'placement' => ['table' => 'placements',        'touched_col' => 'updated_at', 'columns' => ['id','external_id','placement_external_id','first_name','last_name','job_title','status','start_date','end_date','updated_at']],
    'contact'   => ['table' => 'people',            'touched_col' => 'updated_at', 'columns' => ['id','email_primary','first_name','last_name','phone_primary','updated_at']],
    'company'   => ['table' => 'companies',         'touched_col' => 'updated_at', 'columns' => ['id','name','website','industry','updated_at']],
    'customer'  => ['table' => 'companies',         'touched_col' => 'updated_at', 'columns' => ['id','name','website','industry','updated_at']],
    'vendor'    => ['table' => 'ap_vendors_index',  'touched_col' => 'updated_at', 'columns' => ['id','vendor_name','vendor_email','tax_id_last4','updated_at']],
];

/**
 * Push CoreFlux rows for one mapping into Airtable.
 *
 * @param int  $tenantId
 * @param int  $mappingId
 * @param array{since?:string,limit?:int} $opts
 * @return array rollup
 */
function airtablePushMapping(int $tenantId, int $mappingId, array $opts = []): array
{
    $mapping = airtableMappingGet($tenantId, $mappingId);
    if (!$mapping) throw new \RuntimeException('Mapping not found');
    if (!in_array($mapping['direction'], ['push', 'both'], true)) {
        throw new \RuntimeException("Mapping direction='{$mapping['direction']}' does not allow push");
    }

    $entity = (string) $mapping['internal_entity'];
    $desc   = AIRTABLE_PUSH_ENTITY_TABLES[$entity] ?? null;
    if (!$desc) {
        throw new \RuntimeException("Entity '{$entity}' has no push descriptor (push not supported for note/task/opportunity/generic).");
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $desc['table'])) {
        throw new \RuntimeException('Push descriptor table name failed allowlist');
    }

    $reverseMapRaw = $mapping['reverse_field_map'] ?? null;
    if (is_array($reverseMapRaw)) {
        $reverseMap = $reverseMapRaw;
    } else {
        $reverseMap = json_decode((string) ($reverseMapRaw ?? 'null'), true);
    }
    if (!is_array($reverseMap) || empty($reverseMap)) {
        throw new \RuntimeException('reverse_field_map is empty — set CoreFlux→Airtable column mappings before running push.');
    }
    $unmatched = (string) ($mapping['push_unmatched_action'] ?? 'create_new');

    // Iterate CoreFlux rows. Limit by `since` (last_push_at) and a hard cap.
    $since = $opts['since'] ?? $mapping['last_push_at'] ?? null;
    $limit = max(1, min(2000, (int) ($opts['limit'] ?? 500)));
    $select = implode(', ', array_map(fn ($c) => "`{$c}`", $desc['columns']));
    $sql = "SELECT {$select} FROM `{$desc['table']}` WHERE tenant_id = :t";
    $params = ['t' => $tenantId];
    if ($since) {
        $sql .= " AND `{$desc['touched_col']}` >= :s";
        $params['s'] = $since;
    }
    $sql .= " ORDER BY `{$desc['touched_col']}` ASC LIMIT {$limit}";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $pushed = 0; $updated = 0; $created = 0;
    $skippedUnmatched = 0; $errored = 0;
    $errors = [];

    $base  = $mapping['base_id'];
    $table = $mapping['table_id'];

    $stmtFindLink = getDB()->prepare(
        "SELECT external_id FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'airtable'
            AND internal_entity_type = :et
            AND internal_entity_id = :id
          LIMIT 1"
    );

    $stmtInsertLink = getDB()->prepare(
        "INSERT INTO external_entity_mappings
            (tenant_id, source_system, source_id_field, external_id,
             internal_entity_type, internal_entity_id,
             sync_status, payload_snapshot, last_synced_at, last_seen_at)
         VALUES (:t, 'airtable', 'record_id', :ext, :et, :iid,
                 'ok', :snap, NOW(), NOW())"
    );

    foreach ($rows as $row) {
        try {
            // Build the Airtable fields payload from reverse_field_map.
            $fields = [];
            foreach ($reverseMap as $coreflux_col => $airtable_field) {
                if (!is_string($airtable_field) || $airtable_field === '') continue;
                if (!array_key_exists($coreflux_col, $row))                continue;
                $v = $row[$coreflux_col];
                if ($v === null || $v === '')                              continue;
                $fields[(string) $airtable_field] = $v;
            }
            if (empty($fields)) continue;

            $stmtFindLink->execute([
                't'  => $tenantId,
                'et' => $entity,
                'id' => (int) $row['id'],
            ]);
            $linkedRecId = $stmtFindLink->fetchColumn();

            if ($linkedRecId) {
                // PATCH existing record.
                airtableCallWithBody(
                    $tenantId, 'PATCH',
                    "/v0/{$base}/{$table}/" . rawurlencode((string) $linkedRecId),
                    ['fields' => $fields]
                );
                $updated++;
            } else {
                // Branch on push_unmatched_action.
                if ($unmatched === 'update_only') {
                    $skippedUnmatched++;
                    continue;
                }
                if ($unmatched === 'error') {
                    $errored++;
                    if (count($errors) < 10) {
                        $errors[] = "row_id={$row['id']} has no linked Airtable record (push_unmatched_action=error)";
                    }
                    continue;
                }
                // create_new — POST new record.
                $resp = airtableCallWithBody(
                    $tenantId, 'POST',
                    "/v0/{$base}/{$table}",
                    ['records' => [['fields' => $fields]]]
                );
                $newRec = (array) ($resp['records'][0] ?? []);
                $newId  = (string) ($newRec['id'] ?? '');
                if ($newId === '') {
                    throw new \RuntimeException('Airtable POST returned no record id: ' . json_encode($resp));
                }
                // Register the linkage so subsequent pushes UPDATE
                // instead of CREATE.
                $snap = json_encode([
                    '_airtable_record_url' => 'https://airtable.com/'
                        . rawurlencode($base) . '/' . rawurlencode($table) . '/' . rawurlencode($newId),
                    '_airtable_pushed_at' => date('Y-m-d H:i:s'),
                    '_pushed_fields'      => $fields,
                ]);
                $stmtInsertLink->execute([
                    't'    => $tenantId,
                    'ext'  => $newId,
                    'et'   => $entity,
                    'iid'  => (int) $row['id'],
                    'snap' => $snap,
                ]);
                $created++;
            }
            $pushed++;
        } catch (\Throwable $e) {
            $errored++;
            if (count($errors) < 10) {
                $errors[] = 'row_id=' . $row['id'] . ': ' . substr($e->getMessage(), 0, 200);
            }
        }
    }

    // Persist push-run metadata + audit log.
    $updMapping = getDB()->prepare(
        "UPDATE airtable_table_mappings
            SET last_push_at      = NOW(),
                last_push_error   = :err,
                last_push_records = :n
          WHERE id = :id AND tenant_id = :t"
    );
    $updMapping->execute([
        'err' => $errors ? substr(implode(' | ', $errors), 0, 1000) : null,
        'n'   => $pushed,
        'id'  => $mappingId,
        't'   => $tenantId,
    ]);

    $rollup = [
        'scanned'           => count($rows),
        'pushed'            => $pushed,
        'updated'           => $updated,
        'created'           => $created,
        'skipped_unmatched' => $skippedUnmatched,
        'errored'           => $errored,
        'errors'            => $errors,
        'mapping_id'        => $mappingId,
        'since'             => $since,
    ];

    airtableAudit($tenantId, 'push', [
        'base_id' => $mapping['base_id'], 'table_id' => $mapping['table_id'],
        'items_processed' => $pushed,
        'detail' => $rollup,
    ]);

    return $rollup;
}
