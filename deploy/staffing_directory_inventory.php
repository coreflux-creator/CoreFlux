<?php
/** Read-only workspace/directory evidence; no person details or credentials. */
declare(strict_types=1);

function fullCoreStaffingDirectoryInventory(PDO $pdo, array $workspaceNames): array
{
    if (!$workspaceNames || count($workspaceNames) > 10) throw new InvalidArgumentException('Name the pilot workspaces explicitly');
    foreach ($workspaceNames as $name) if (!is_string($name) || trim($name) === '') throw new InvalidArgumentException('Invalid workspace name');
    $columns = static function (string $table) use ($pdo): array {
        $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t');
        $s->execute(['t'=>$table]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    };
    $needs = ['tenants'=>['id','name','parent_id','tenant_type','is_active'],
        'tenant_module_scope'=>['tenant_id','module_key','scope_mode'],
        'people'=>['id','tenant_id','deleted_at'], 'placements'=>['id','tenant_id','person_id','deleted_at'],
        'external_entity_mappings'=>['tenant_id','source_system','internal_entity_type','internal_entity_id']];
    $missing = [];
    foreach ($needs as $table=>$required) if (array_diff($required,$columns($table))) $missing[] = $table;
    if (in_array('tenants',$missing,true)) return ['status'=>'unavailable','missing_schema'=>$missing,'workspaces'=>[]];
    $s = $pdo->prepare('SELECT id,name,parent_id,tenant_type,is_active FROM tenants WHERE name IN (' . implode(',',array_fill(0,count($workspaceNames),'?')) . ') ORDER BY id');
    $s->execute($workspaceNames); $workspaces=$s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($workspaces as &$workspace) {
        $tenant=(int)$workspace['id']; $parent=(int)($workspace['parent_id']??0);
        $workspace['configured_scopes']=null;
        if (!in_array('tenant_module_scope',$missing,true)) {
            $s=$pdo->prepare('SELECT module_key,scope_mode FROM tenant_module_scope WHERE tenant_id=:t ORDER BY module_key');
            $s->execute(['t'=>$tenant]); $workspace['configured_scopes']=$s->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        // Report configured policy verbatim. Missing overrides are not asserted as defaults.
        $workspace['scope_note']='Absent overrides use deployed module defaults; this inventory does not infer or change them.';
        $workspace['record_directories']=[];
        foreach (array_unique(array_filter([$tenant,$parent])) as $owner) {
            $entry=['tenant_id'=>$owner,'people'=>null,'placements'=>null];
            foreach (['people','placements'] as $table) {
                if (in_array($table,$missing,true)) continue;
                $s=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE tenant_id=:t AND deleted_at IS NULL');
                $s->execute(['t'=>$owner]); $entry[$table]=(int)$s->fetchColumn();
            }
            $workspace['record_directories'][]=$entry;
        }
        $workspace['placement_person_directories']=null;
        if (!array_intersect(['placements','people'],$missing)) {
            $s=$pdo->prepare('SELECT pe.tenant_id AS person_directory,COUNT(*) AS placement_count,
                SUM(CASE WHEN pe.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS deleted_people
                FROM placements p LEFT JOIN people pe ON pe.id=p.person_id
                WHERE p.tenant_id=:t AND p.deleted_at IS NULL GROUP BY pe.tenant_id ORDER BY pe.tenant_id');
            $s->execute(['t'=>$tenant]); $workspace['placement_person_directories']=$s->fetchAll(PDO::FETCH_ASSOC);
        }
        $workspace['jobdiva_person_mapping_directories']=null;
        if (!array_intersect(['external_entity_mappings','people'],$missing)) {
            $s=$pdo->prepare('SELECT pe.tenant_id AS person_directory,COUNT(*) AS mapping_count,
                SUM(CASE WHEN pe.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS deleted_people
                FROM external_entity_mappings m LEFT JOIN people pe ON pe.id=m.internal_entity_id
                WHERE m.tenant_id=:t AND m.source_system="jobdiva" AND m.internal_entity_type="person"
                GROUP BY pe.tenant_id ORDER BY pe.tenant_id');
            $s->execute(['t'=>$tenant]); $workspace['jobdiva_person_mapping_directories']=$s->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    unset($workspace);
    return ['status'=>$missing?'partial':'available','missing_schema'=>$missing,'workspaces'=>$workspaces];
}

/** Inspect one explicitly selected pilot pair without returning worker data or source payloads. */
function fullCoreStaffingPilotInventory(PDO $pdo, array $pilot): array
{
    foreach (['tenant_id','person_directory_id','person_id','placement_id'] as $key) {
        if (!is_int($pilot[$key] ?? null) || $pilot[$key] <= 0) throw new InvalidArgumentException('Explicit positive pilot identities required');
    }
    if (!is_string($pilot['workspace_name'] ?? null) || trim($pilot['workspace_name']) === '') throw new InvalidArgumentException('Explicit workspace name required');
    foreach (['person','placement'] as $entity) {
        $expected = $pilot['jobdiva_ids'][$entity] ?? null;
        if (!is_string($expected) || $expected === '' || strlen($expected) > 128 || preg_match('/[\x00-\x20\x7f]/', $expected)) {
            throw new InvalidArgumentException('Explicit opaque JobDiva identities required');
        }
    }
    $columns = [];
    foreach (['tenants','placements','people','external_entity_mappings','core_record_numbers'] as $table) {
        $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t');
        $s->execute(['t'=>$table]); $columns[$table] = $s->fetchAll(PDO::FETCH_COLUMN);
    }
    $missing = [];
    foreach (['tenants'=>['id','name','is_active'], 'placements'=>['id','tenant_id','person_id','deleted_at'],
        'people'=>['id','tenant_id','deleted_at']] as $table=>$required) {
        if (array_diff($required,$columns[$table])) $missing[] = $table;
    }
    $result = ['status'=>'unavailable','tenant_id'=>$pilot['tenant_id'],'placement_id'=>$pilot['placement_id'],
        'person_id'=>$pilot['person_id'],'person_directory_id'=>$pilot['person_directory_id'],'missing_schema'=>$missing,
        'note'=>'Presence and exact identity evidence only; this does not approve employment, compensation, routing or delivery.'];
    if ($missing) return $result;
    $s = $pdo->prepare('SELECT name,is_active FROM tenants WHERE id=:id');
    $s->execute(['id'=>$pilot['tenant_id']]); $workspace = $s->fetch(PDO::FETCH_ASSOC);
    if (!$workspace || $workspace['name'] !== $pilot['workspace_name'] || (int)$workspace['is_active'] !== 1) {
        $result['status'] = 'workspace_identity_mismatch'; return $result;
    }
    $s = $pdo->prepare('SELECT person_id FROM placements WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL');
    $s->execute(['id'=>$pilot['placement_id'],'t'=>$pilot['tenant_id']]); $person = $s->fetchColumn();
    if ($person === false) { $result['status'] = 'placement_not_found'; return $result; }
    if ((int)$person !== $pilot['person_id']) { $result['status'] = 'person_identity_mismatch'; return $result; }
    $s = $pdo->prepare('SELECT COUNT(*) FROM people WHERE id=:id AND tenant_id=:t AND deleted_at IS NULL');
    $s->execute(['id'=>$pilot['person_id'],'t'=>$pilot['person_directory_id']]);
    if ((int)$s->fetchColumn() !== 1) { $result['status'] = 'person_directory_or_active_record_mismatch'; return $result; }

    $result['status'] = 'observed';
    // Only fixed, reviewed column names enter SQL. Return presence, never values.
    $result['placement_fields'] = [];
    foreach (['accounting_entity_id','branch','workers_comp_class','client_id','end_client_company_id',
        'job_id','worksite_country','worksite_state','engagement_type','start_date','end_date','status'] as $field) {
        if (!in_array($field,$columns['placements'],true)) { $result['placement_fields'][$field] = 'column_missing'; continue; }
        $present = str_ends_with($field,'_id') ? '`'.$field.'` > 0' : "TRIM(CAST(`$field` AS CHAR)) <> ''";
        $s = $pdo->prepare('SELECT CASE WHEN '.$present.' THEN 1 ELSE 0 END FROM placements WHERE id=:id AND tenant_id=:t AND person_id=:p AND deleted_at IS NULL');
        $s->execute(['id'=>$pilot['placement_id'],'t'=>$pilot['tenant_id'],'p'=>$pilot['person_id']]);
        $result['placement_fields'][$field] = (int)$s->fetchColumn() === 1 ? 'present' : 'empty';
    }
    $mappingAvailable = !array_diff(['tenant_id','source_system','internal_entity_type','internal_entity_id','external_id'],$columns['external_entity_mappings']);
    $numberAvailable = !array_diff(['tenant_id','entity_type','record_id','record_number','canonical_record_id'],$columns['core_record_numbers']);
    foreach (['person','placement'] as $entity) {
        $id = $pilot[$entity.'_id'];
        $result['jobdiva_identity'][$entity] = ['status'=>'schema_unavailable'];
        if ($mappingAvailable) {
            $s = $pdo->prepare('SELECT internal_entity_id,external_id FROM external_entity_mappings
                WHERE tenant_id=:t AND source_system="jobdiva" AND internal_entity_type=:e
                AND (internal_entity_id=:id OR BINARY external_id=BINARY :external)');
            $s->execute(['t'=>$pilot['tenant_id'],'e'=>$entity,'id'=>$id,'external'=>$pilot['jobdiva_ids'][$entity]]);
            $mappings = $s->fetchAll(PDO::FETCH_ASSOC);
            $matches = count($mappings) === 1 && (int)$mappings[0]['internal_entity_id'] === $id
                && $mappings[0]['external_id'] === $pilot['jobdiva_ids'][$entity];
            $result['jobdiva_identity'][$entity] = ['status'=>$matches?'exact_match':($mappings?'mismatch_or_ambiguous':'missing')];
        }
        $result['persisted_numbers'][$entity] = 'schema_unavailable';
        if ($numberAvailable) {
            $owner = $entity === 'person' ? $pilot['person_directory_id'] : $pilot['tenant_id'];
            $s = $pdo->prepare('SELECT record_number,canonical_record_id FROM core_record_numbers WHERE tenant_id=:t AND entity_type=:e AND record_id=:id');
            $s->execute(['t'=>$owner,'e'=>$entity,'id'=>$id]); $number = $s->fetch(PDO::FETCH_ASSOC);
            $result['persisted_numbers'][$entity] = !$number || trim($number['record_number']) === '' ? 'unallocated'
                : ((int)$number['canonical_record_id'] === $id ? 'allocated' : 'alias_requires_review');
        }
    }
    return $result;
}
