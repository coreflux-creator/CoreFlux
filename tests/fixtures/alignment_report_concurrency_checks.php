<?php
/** Reports wait for committed/rolled-back policy and reference edits, without writes. */
foreach(['COMMIT','ROLLBACK']as$decision){
    foreach(['policy','client']as$change){
        [$a,$b,$placement,$original]=$projectionFixture('alignment-'.$change.'-'.$decision,$child);
        $beforeReport=jobdivaMappingAlignmentReport($child,['sample_limit'=>1]);
        $check($beforeReport['complete'],'Concurrent report fixture starts with complete checks');
        $missing=$beforeReport['relationships']['projector']['workflow_readiness']['missing_staffing_client'];
        $pdo->beginTransaction();
        if($change==='policy')$pdo->prepare('UPDATE tenant_module_scope SET scope_mode="isolated" WHERE tenant_id=? AND module_key="placements"')->execute([$child]);
        else$pdo->prepare('UPDATE placements SET client_id=NULL WHERE id=?')->execute([$placement]);
        $worker=$spawn(['mode'=>'native','entity'=>'alignment_report','workspace'=>$child]);
        $check($waiting($worker),'Alignment snapshot waits for '.$change.' '.$decision);
        if($decision==='COMMIT')$pdo->commit();else$pdo->rollBack();
        $before=$projectionRows();$finished=$finish($worker);$report=$finished['result']??[];
        $check(($finished['ok']??false)&&($report['complete']??false),'Alignment snapshot completes after '.$change.' '.$decision.': '.json_encode($finished));
        if($change==='policy'){
            $check($report['directory_owners']['placements']===($decision==='COMMIT'?$child:$tenant),'Alignment snapshot follows the '.$decision.' directory policy');
        }else{
            $check($report['relationships']['projector']['workflow_readiness']['missing_staffing_client']===$missing+($decision==='COMMIT'?1:0),'Alignment snapshot follows the '.$decision.' client reference');
        }
        $check($projectionRows()===$before,'Concurrent alignment report changes no graph or source data after '.$change.' '.$decision);
    }
}
