<?php
/** Draft missing source rates under the original workspace and current directory. */
declare(strict_types=1);
require_once __DIR__.'/sync.php';
require_once __DIR__.'/sync_placements.php';
require_once __DIR__.'/placement_write_context.php';
require_once __DIR__.'/../../modules/people/lib/company_source_ownership.php';

function jobdivaRepairPlacementSourceRate(int $workspace,int $placementId,?int $actor=null,?int $expectedMapping=null): array
{
    $pdo=getDB();
    try{
        return companyRelationshipAtomic($pdo,static function()use($pdo,$workspace,$placementId,$actor,$expectedMapping):array{
            $context=jobdivaPlacementWriteContext($pdo,$workspace);$owner=$context['placement_owner'];
            $q=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$owner,$placementId]);$placement=$q->fetch(PDO::FETCH_ASSOC);
            if(!$placement)throw new RuntimeException('The placement is unavailable in the current directory.',409);
            $skip=static fn(string $reason):array=>['id'=>$placementId,'status'=>'skipped','reason'=>$reason];
            $start=(string)$placement['start_date'];
            if($start===''||jobdivaContractProjectionDate($start)!==$start)throw new RuntimeException('Review the placement start date before repairing rates.',422);
            $q=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND placement_id=? ORDER BY id FOR UPDATE');$q->execute([$owner,$placementId]);$before=$q->fetchAll(PDO::FETCH_ASSOC);
            foreach($before as$rate){
                if($rate['approved_at']!==null&&$rate['effective_from']<=$start&&($rate['effective_to']===null||$rate['effective_to']>=$start))return$skip('approved_rate_covers_start');
            }
            foreach($before as$rate){
                if($rate['approved_at']!==null)continue;
                if(!empty($rate['created_by_user_id']))return$skip('native_draft_requires_review');
                if(abs((float)$rate['bill_rate']-(float)$rate['pay_rate'])>=0.0001)return$skip('existing_draft_requires_review');
            }
            $q=$pdo->prepare("SELECT * FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='placement' AND internal_entity_id=? ORDER BY id FOR UPDATE");$q->execute([$workspace,$placementId]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$links){if($expectedMapping!==null)throw new RuntimeException('The selected source link changed.',409);return$skip('missing_jobdiva_source_binding');}
            if(count($links)!==1)throw new RuntimeException('Review the competing assignment source identities before repairing rates.',409);
            $mapping=$links[0];if($expectedMapping!==null&&(int)$mapping['id']!==$expectedMapping)throw new RuntimeException('The selected source link changed.',409);
            $external=jobdivaAssignmentIdentityNormaliseId($mapping['external_id']);
            if(!preg_match('/^[0-9]+$/D',$external))throw new RuntimeException('Review the exact JobDiva Start identity.',409);
            $identity=jobdivaPlacementWriteIdentity($pdo,$context,$external,$placementId);
            if((int)($identity['mapping']['id']??0)!==(int)$mapping['id'])throw new RuntimeException('The retained Start identity changed.',409);
            if($mapping['sync_status']!=='ok')return$skip('source_needs_review');
            $payload=trim((string)$mapping['payload_snapshot'])===''?null:json_decode($mapping['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
            // An absent snapshot can be rebuilt only from this exact saved Start,
            // never by manufacturing an assignment from display IDs or job rates.
            if($payload===null){
                $q=$pdo->prepare("SELECT payload_snapshot,sync_status FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='jobdiva_assignment' AND external_id IN(?,?) ORDER BY id FOR UPDATE");$q->execute([$workspace,$external,'jd:'.$external]);$mirrors=$q->fetchAll(PDO::FETCH_ASSOC);
                if(count($mirrors)!==1||$mirrors[0]['sync_status']!=='ok')return$skip('exact_assignment_evidence_required');
                $payload=json_decode((string)$mirrors[0]['payload_snapshot'],true,512,JSON_THROW_ON_ERROR);
            }
            if(!is_array($payload)||array_is_list($payload)||empty(jobdivaAssignmentValidate($payload,$external)['valid']))throw new RuntimeException('The saved rate evidence is not this exact assignment.',409);
            $candidate=jobdivaAssignmentIdentityNormaliseId(jobdivaAssignmentIdentityPluck($payload,['candidateId','candidateID','candidate_id','candidate id','employeeId']));
            $personLink=jobdivaPlacementSourceLink($pdo,$workspace,'person',$candidate);
            if(!$personLink||$personLink['sync_status']!=='ok'||(int)$personLink['internal_entity_id']!==(int)$placement['person_id'])throw new RuntimeException('Review the assignment candidate link before repairing rates.',409);
            $q=$pdo->prepare('SELECT external_id,deleted_at FROM people WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$context['people_owner'],$placement['person_id']]);$person=$q->fetch(PDO::FETCH_ASSOC);
            if(!$person||$person['deleted_at']!==null||jobdivaPersonExternalIdConflicts((string)$person['external_id'],$candidate))throw new RuntimeException('The assignment worker identity needs review.',409);
            jobdivaPersonSourceWorkspaceCheck($pdo,$workspace,$context['people_owner'],(int)$placement['person_id']);
            $stats=[];$payload=jobdivaPlacementPayloadWithMirrors($workspace,$payload,$stats,$external,true);
            // Use current locked field mappings even if the enclosing native
            // activation transaction began before another editor committed.
            tenantIntegrationFieldMapFlushCache();
            $q=$pdo->prepare('SELECT internal_field,external_field,source_path,transform FROM tenant_integration_field_map WHERE tenant_id=? AND integration="jobdiva" AND entity_type="placement" AND enabled=1 FOR UPDATE');$q->execute([$workspace]);$map=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$map[$row['internal_field']]=['external_field'=>(string)$row['external_field'],'source_path'=>(string)($row['source_path']??''),'transform'=>(string)$row['transform']];
            $cache=&tenantIntegrationFieldMapCache();$cache[$workspace.'|jobdiva|placement']=$map;
            $payload=jobdivaClassificationPayloadForTenant($workspace,$payload);$engagement=jobdivaClassificationRequire($payload);
            if($engagement!==$placement['engagement_type'])return$skip('engagement_requires_review');
            $rateFields=['bill_rate','pay_rate','bill_rate_unit','pay_rate_unit','currency','rates','ot_multiplier','dt_multiplier','adder_pct','payroll_load_pct','background_fee_total','bill_adder_pct','bill_adder_flat','bill_discount_pct','bill_discount_flat','workers_comp_pct','benefits_load_pct','c2c_overhead_pct','other_cost_per_hour','other_cost_flat'];
            if(array_intersect($identity['owned_fields'],$rateFields))return$skip('core_owned_rates');
            // Validation cannot delete a malformed legacy draft or invent pay.
            if(!jobdivaSyncUpsertPlacementRates($owner,$placementId,$start,$payload,true,$workspace))return$skip('positive_source_rates_required');
            jobdivaSyncUpsertPlacementRates($owner,$placementId,$start,$payload,false,$workspace);
            $q=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND placement_id=? ORDER BY id FOR UPDATE');$q->execute([$owner,$placementId]);$after=$q->fetchAll(PDO::FETCH_ASSOC);
            if($before===$after)return$skip('already_current');
            companySourceAudit($pdo,$workspace,$owner,'placements',$placementId,'placement.rate.auto_drafted_from_source',$rateFields,$actor,[
                'source_system'=>'jobdiva','source_mapping_id'=>(int)$mapping['id'],'source_external_id'=>$mapping['external_id'],
                'candidate_source_external_id'=>$personLink['external_id'],'effective_from'=>$start,'mirror_stats'=>$stats,'before'=>$before,'after'=>$after,
            ]);
            return ['id'=>$placementId,'status'=>'drafted','reason'=>'source_rate_repaired'];
        });
    }finally{tenantIntegrationFieldMapFlushCache();}
}

function jobdivaMappingRepairSourceRateDrafts(int $tenantId,array $user,int $limit=500): array
{
    $pdo=getDB();$limit=max(1,min(5000,$limit));$summary=['checked'=>0,'drafted'=>0,'skipped'=>0,'failed'=>0,'reasons'=>[],'errors'=>[]];
    $q=$pdo->prepare("SELECT id,internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='placement' ORDER BY id LIMIT {$limit}");$q->execute([$tenantId]);
    foreach($q->fetchAll(PDO::FETCH_ASSOC)as$link){
        $summary['checked']++;
        try{$result=jobdivaRepairPlacementSourceRate($tenantId,(int)$link['internal_entity_id'],$user['id']??null,(int)$link['id']);$summary[$result['status']]++;$summary['reasons'][$result['reason']]=($summary['reasons'][$result['reason']]??0)+1;}
        catch(Throwable $e){$summary['failed']++;if(count($summary['errors'])<10)$summary['errors'][]='source link '.$link['id'].': '.$e->getMessage();}
    }
    return$summary;
}
