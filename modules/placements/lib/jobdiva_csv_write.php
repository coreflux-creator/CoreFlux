<?php
/** Selected CSV reconciliation and required audit share the exact reviewed transaction. */
declare(strict_types=1);
require_once __DIR__.'/jobdiva_reconciliation.php';
require_once __DIR__.'/../../../core/jobdiva/sync.php';

function jobdivaReconciliationApply(PDO $pdo,int $workspace,?int $actor,string $csv,?array $columnMap,array $selected,string $token): array
{
    if($pdo!==getDB())throw new LogicException('CSV apply must use the application database.');
    if($csv===''||strlen($csv)>25*1024*1024||substr_count($csv,"\n")>5001)throw new InvalidArgumentException('Provide a CSV with at most 5,000 rows and 25 MB.',422);
    $selected=array_values(array_unique(array_map(static fn($id)=>jobdivaAssignmentIdentityNormaliseId((string)$id),$selected)));
    if(!$selected||count($selected)>500||array_filter($selected,static fn($id)=>!preg_match('/^\d+$/D',$id)))throw new InvalidArgumentException('Select between 1 and 500 exact Start IDs.',422);
    return JobdivaStoredReviewTransaction::run(static function()use($pdo,$workspace,$actor,$csv,$columnMap,$selected,$token):array{
        $plan=jobdivaReconciliationBuildPlan($pdo,$workspace,null,$csv,$columnMap);
        if($token===''||!hash_equals($plan['dry_run_token'],$token))throw new RuntimeException('The data, sharing or Core records changed after preview. Refresh the CSV review.',409);
        $rows=array_column($plan['rows'],null,'start_id');$scope=$plan['__context'];$owner=$scope['placement_owner'];
        foreach($selected as$start)if(empty($rows[$start]['selectable']))throw new RuntimeException('Select only applicable Start IDs from this exact preview.',409);
        $result=['created'=>0,'updated'=>0,'rate_drafts'=>0,'mapping_writes'=>0,'applied'=>[]];
        foreach($selected as$start){
            $row=$rows[$start];$patch=$row['placement_patch'];$id=(int)($row['placement_id']??0);
            $before=$id?jobdivaPlacementProjectionAuditSnapshot($owner,$id,true):[];
            $company=$row['__company'];
            if($company['requested']&&!$company['held']){
                $companyId=$company['external_id']===''?(int)$company['id']:jobdivaAssignmentPartyCompany($workspace,(string)$company['name'],$company['external_id'],null,
                    $id?($before['placement']['end_client_company_id']?(int)$before['placement']['end_client_company_id']:null):null,
                    (string)($before['placement']['end_client_name']??''),[],$actor);
                if(!$companyId||(!$company['create']&&$companyId!==(int)$company['id']))throw new RuntimeException('The reviewed company identity changed.',409);
                if($company['create']||array_key_exists('end_client_company_id',$patch))$patch['end_client_company_id']=$companyId;
            }
            if($row['is_create']){
                $patch+=['tenant_id'=>$owner,'created_by_user_id'=>$actor];
                $pdo->prepare('INSERT INTO placements (`'.implode('`,`',array_keys($patch)).'`) VALUES('.implode(',',array_fill(0,count($patch),'?')).')')->execute(array_values($patch));
                $id=(int)$pdo->lastInsertId();$result['created']++;
            }else{
                if($patch){
                    $sets=array_map(static fn($field)=>'`'.$field.'`=?',array_keys($patch));
                    $pdo->prepare('UPDATE placements SET '.implode(',',$sets).' WHERE tenant_id=? AND id=? AND deleted_at IS NULL')->execute([...array_values($patch),$owner,$id]);
                }
                $result['updated']++;
            }
            if($row['rate_plan']){
                $rate=$row['rate_plan'];$values=$rate['payload'];
                if($rate['action']==='update_draft'){
                    $sets=array_map(static fn($field)=>'`'.$field.'`=?',array_keys($values));
                    $pdo->prepare('UPDATE placement_rates SET '.implode(',',$sets).' WHERE tenant_id=? AND placement_id=? AND id=? AND approved_at IS NULL')->execute([...array_values($values),$owner,$id,$rate['rate_id']]);
                }else{
                    $values+=['tenant_id'=>$owner,'placement_id'=>$id,'created_by_user_id'=>$actor,'ot_multiplier'=>1.5,'dt_multiplier'=>2.0];
                    $pdo->prepare('INSERT INTO placement_rates (`'.implode('`,`',array_keys($values)).'`) VALUES('.implode(',',array_fill(0,count($values),'?')).')')->execute(array_values($values));
                }
                $result['rate_drafts']++;
            }
            // CSV evidence supplements the original provider snapshot; sparse
            // columns must not discard saved assignment-contract facets.
            $external=(string)($row['__mapping']['external_id']??$start);
            $mapping=mappingFindInternal($workspace,'jobdiva','placement',$external);
            $snapshot=$mapping&&$mapping['payload_snapshot']!==null?json_decode($mapping['payload_snapshot'],true,512,JSON_THROW_ON_ERROR):[];
            if(!is_array($snapshot))throw new RuntimeException('Review the saved assignment source snapshot.',409);
            $snapshot['__cf_jobdiva_source_object']='assignment';$snapshot['__cf_jobdiva_assignment_id']=$start;
            $snapshot['__cf_csv_reconciliation']=$row['source'];
            mappingBindStable($workspace,'jobdiva','placement',$external,$id,$snapshot,'pull',$actor);$result['mapping_writes']++;
            if(!empty($row['source']['candidate_id'])&&$row['person']['matched_by']==='candidate_id'&&empty($row['person']['evidence']['link'])){
                mappingBindStable($workspace,'jobdiva','person',$row['source']['candidate_id'],(int)$row['person']['id'],['candidateId'=>$row['source']['candidate_id']],'pull',$actor);$result['mapping_writes']++;
            }
            $economics=placementEconomicsReconcile($owner,$id);
            if(empty($economics['available'])||$economics['errors'])throw new RuntimeException('Review the assignment economic graph: '.implode('; ',$economics['errors']??[]),409);
            $after=jobdivaPlacementProjectionAuditSnapshot($owner,$id,true);
            companySourceAudit($pdo,$workspace,$owner,'placements',$id,'placement.jobdiva_reconciliation.applied',array_column($row['changes'],'field'),$actor,[
                'start_id'=>$start,'row_number'=>$row['row_number'],'mode'=>$row['is_create']?'create':'update',
                'protected_fields'=>array_column($row['protected_changes'],'field'),'rate_action'=>$row['rate_plan']['action']??null,
                'before'=>$before,'after'=>$after,'csv_sha256'=>hash('sha256',$csv),'dry_run_token'=>$token]);
            $result['applied'][]=['start_id'=>$start,'placement_id'=>$id,'mode'=>$row['is_create']?'create':'update'];
        }
        return$result;
    });
}
