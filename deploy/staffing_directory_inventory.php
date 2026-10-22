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
