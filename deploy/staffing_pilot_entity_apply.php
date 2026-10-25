<?php
/** Bounded, audited inheritance of an existing entity; never approve compensation. */
declare(strict_types=1);
require_once __DIR__.'/staffing_pilot_entity_inheritance.php';

/** Use the caller's existing connection; never bootstrap candidate runtime against live data. */
function fullCorePilotEntityAudit(PDO $pdo,array $pilot,?int $before,int $after,string $requestId,string $commit): bool
{
    if (!$pdo->inTransaction()||!preg_match('/^[a-f0-9]{40}$/D',$commit)
        ||!preg_match('/^thunderhawk-pilot-entity-[0-9]{6,20}$/D',$requestId)) return false;
    $cols=$pdo->query('SHOW COLUMNS FROM audit_log')->fetchAll(PDO::FETCH_COLUMN);
    $event=in_array('event',$cols,true)?'event':(in_array('action',$cols,true)?'action':null);
    $target=in_array('target_id',$cols,true)?'target_id':(in_array('entity_id',$cols,true)?'entity_id':null);
    if (!$event||!$target||array_diff(['tenant_id','meta_json'],$cols)) return false;
    $meta=['request_id'=>$requestId,'before'=>['accounting_entity_id'=>$before],'after'=>['accounting_entity_id'=>$after],
        'candidate_commit'=>$commit,'source'=>'jobdiva-pilot-alignment','object_type'=>'placement','actor_type'=>'system',
        'authorization'=>'User requested following the existing sync alignment for this selected pilot placement.',
        'rates_approved'=>false,'person_changed'=>false];
    $s=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=:t AND `'.$target.'`=:p
        AND `'.$event.'`=:e AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.request_id"))=:r');
    $query=['t'=>$pilot['tenant_id'],'p'=>$pilot['placement_id'],'e'=>'placements.entity_inherited','r'=>$requestId];
    $s->execute($query);if ($s->fetchColumn()!==false) return false;
    $values=['tenant_id'=>$pilot['tenant_id'],$target=>$pilot['placement_id'],$event=>'placements.entity_inherited',
        'meta_json'=>json_encode($meta,JSON_THROW_ON_ERROR)];
    foreach (['actor_user_id'=>null,'user_id'=>null,'actor_type'=>'system','object_type'=>'placement','entity'=>'placement',
        'event'=>'placements.entity_inherited','action'=>'placements.entity_inherited',
        'target_id'=>$pilot['placement_id'],'entity_id'=>$pilot['placement_id'],
        'before_json'=>json_encode($meta['before'],JSON_THROW_ON_ERROR),'after_json'=>json_encode($meta['after'],JSON_THROW_ON_ERROR),
        'request_id'=>$requestId,'source'=>'jobdiva-pilot-alignment'] as $column=>$value) {
        if (in_array($column,$cols,true)) $values[$column]=$value;
    }
    $columns=array_keys($values);$slots=array_map(static fn($c)=>':'.$c,$columns);
    if (in_array('created_at',$cols,true)) { $columns[]='created_at';$slots[]='NOW()'; }
    $pdo->prepare('INSERT INTO audit_log (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',$slots).')')->execute($values);
    $s->execute($query);$rows=$s->fetchAll(PDO::FETCH_COLUMN);
    return count($rows)===1&&json_decode($rows[0],true,512,JSON_THROW_ON_ERROR)===$meta;
}

function fullCorePilotEntityProtectedState(PDO $pdo,array $pilot): array
{
    $s=$pdo->prepare('SELECT * FROM people WHERE id=:p AND tenant_id=:t');
    $s->execute(['p'=>$pilot['person_id'],'t'=>$pilot['person_directory_id']]);$person=$s->fetch(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT * FROM placement_rates WHERE placement_id=:p AND tenant_id=:t ORDER BY id');
    $s->execute(['p'=>$pilot['placement_id'],'t'=>$pilot['tenant_id']]);
    return ['person'=>$person,'rates'=>$s->fetchAll(PDO::FETCH_ASSOC)];
}

function fullCorePilotApplyEntityInheritance(PDO $pdo,array $pilot,array $expectedEntity,callable $resolveOwner,callable $audit): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('A separate transaction is required');
    if (!is_int($expectedEntity['id']??null)||$expectedEntity['id']<=0
        ||!is_string($expectedEntity['code']??null)||!is_string($expectedEntity['legal_name']??null)) {
        throw new InvalidArgumentException('Reviewed existing entity required');
    }
    foreach (['placements','audit_log','people','placement_rates','tenants','tenant_module_scope','accounting_entities','external_entity_mappings'] as $table) {
        $s=$pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
        $s->execute(['t'=>$table]);
        if ($s->fetchColumn()!=='InnoDB') throw new RuntimeException('Transactional pilot tables required');
    }
    // Scope/default/mapping reads and the one-row update share one serializable transaction.
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT * FROM placements WHERE id=:p AND tenant_id=:t AND person_id=:person AND deleted_at IS NULL FOR UPDATE');
        $s->execute(['p'=>$pilot['placement_id'],'t'=>$pilot['tenant_id'],'person'=>$pilot['person_id']]);
        $before=$s->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new RuntimeException('Reviewed placement unavailable');
        $review=fullCorePilotEntityInheritance($pdo,$pilot,(int)$resolveOwner());
        if ($review['status']!=='unique_existing_default'||($review['existing_default']??null)!==$expectedEntity) {
            throw new RuntimeException('Reviewed entity or source identity changed');
        }
        if ($review['placement_matches_default']) {
            $pdo->rollBack();return ['status'=>'already_aligned','record_writes'=>false,'audit_written'=>false];
        }
        if (!$review['can_follow_existing_sync_rule']) throw new RuntimeException('Existing entity or override must be preserved');
        $protected=fullCorePilotEntityProtectedState($pdo,$pilot);
        $s=$pdo->prepare('UPDATE placements SET accounting_entity_id=:e WHERE id=:p AND tenant_id=:t AND person_id=:person
            AND deleted_at IS NULL AND (accounting_entity_id IS NULL OR accounting_entity_id=0)');
        $s->execute(['e'=>$expectedEntity['id'],'p'=>$pilot['placement_id'],'t'=>$pilot['tenant_id'],'person'=>$pilot['person_id']]);
        if ($s->rowCount()!==1) throw new RuntimeException('Placement changed before inheritance');
        $s=$pdo->prepare('SELECT * FROM placements WHERE id=:p AND tenant_id=:t');
        $s->execute(['p'=>$pilot['placement_id'],'t'=>$pilot['tenant_id']]);$after=$s->fetch(PDO::FETCH_ASSOC);
        if (!$after||(int)$after['accounting_entity_id']!==$expectedEntity['id']) throw new RuntimeException('Entity update was not retained');
        $left=$before;$right=$after;
        foreach(['accounting_entity_id','updated_at'] as $field){unset($left[$field],$right[$field]);}
        if ($left!==$right) throw new RuntimeException('Unrelated placement fields changed');
        if ($audit($before['accounting_entity_id'],$expectedEntity['id'])!==true) throw new RuntimeException('Verified audit receipt required');
        if (fullCorePilotEntityProtectedState($pdo,$pilot)!==$protected) throw new RuntimeException('Person or rate records changed');
        $pdo->commit();
        return ['status'=>'aligned','record_writes'=>true,'placement_fields_changed'=>['accounting_entity_id'],
            'audit_written'=>true,'person_changed'=>false,'rates_approved'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();throw $e;
    }
}
