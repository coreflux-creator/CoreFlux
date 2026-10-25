<?php
/** Inspect the existing sync's legal-entity default without selecting or changing an employer. */
declare(strict_types=1);
require_once __DIR__.'/staffing_directory_inventory.php';

function fullCorePilotEntityInheritance(PDO $pdo, array $pilot, int $accountingOwner): array
{
    $report=['status'=>'identity_not_verified','records_changed'=>false,
        'native_sync_rule'=>'first_active_entity_by_id_in_effective_accounting_directory',
        'can_follow_existing_sync_rule'=>false];
    $identity=fullCoreStaffingPilotInventory($pdo,$pilot);
    if ($identity['status']!=='observed') return $report;
    foreach (['person','placement','job'] as $kind) {
        if (($identity['jobdiva_identity'][$kind]['status'] ?? null)!=='exact_match') return $report;
    }
    if ($accountingOwner!==$pilot['accounting_directory_id']) {
        $report['status']='effective_accounting_directory_mismatch'; return $report;
    }
    $report['accounting_directory_verified']=true;
    $columns=static function(string $table)use($pdo):array{
        $s=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t');
        $s->execute(['t'=>$table]);return $s->fetchAll(PDO::FETCH_COLUMN);
    };
    foreach (['accounting_entities'=>['id','tenant_id','active','code','legal_name'],
        'placements'=>['accounting_entity_id','coreflux_overridden_fields']] as $table=>$needed) {
        if (array_diff($needed,$columns($table))) { $report['status']='schema_unavailable';return $report; }
    }
    $s=$pdo->prepare('SELECT COUNT(*) FROM accounting_entities WHERE tenant_id=:t AND active=1');
    $s->execute(['t'=>$accountingOwner]);$count=(int)$s->fetchColumn();
    $report['active_entity_count']=$count;
    if ($count!==1) {
        $report['status']=$count===0?'active_entity_setup_missing':'multiple_active_entities'; return $report;
    }
    $s=$pdo->prepare('SELECT id,code,legal_name FROM accounting_entities WHERE tenant_id=:t AND active=1 ORDER BY id LIMIT 1');
    $s->execute(['t'=>$accountingOwner]);$entity=$s->fetch(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT accounting_entity_id,coreflux_overridden_fields FROM placements
        WHERE id=:p AND tenant_id=:t AND person_id=:person AND deleted_at IS NULL');
    $s->execute(['p'=>$pilot['placement_id'],'t'=>$pilot['tenant_id'],'person'=>$pilot['person_id']]);
    $placement=$s->fetch(PDO::FETCH_ASSOC);
    if (!$entity||!$placement) { $report['status']='record_changed_during_inspection';return $report; }
    $report['existing_default']=['id'=>(int)$entity['id'],'code'=>$entity['code'],'legal_name'=>$entity['legal_name']];
    $report['placement_reference']=$identity['employer_references']['placement'];
    $report['person_reference']=$identity['employer_references']['person'];
    $report['placement_matches_default']=(int)$placement['accounting_entity_id']===(int)$entity['id'];
    $report['entity_override']='unreadable';
    $raw=$placement['coreflux_overridden_fields'];
    $overrides=$raw===null||$raw===''?[]:json_decode((string)$raw);
    if (is_array($overrides)&&array_is_list($overrides)&&!array_filter($overrides,static fn($v)=>!is_string($v))) {
        $report['entity_override']=in_array('accounting_entity_id',$overrides,true)?'present':'absent';
    }
    $report['status']='unique_existing_default';
    $report['can_follow_existing_sync_rule']=$report['placement_reference']==='empty'&&$report['entity_override']==='absent';
    return $report;
}
