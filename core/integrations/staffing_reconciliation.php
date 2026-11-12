<?php
/** Placement/period evidence reader. No imports, source reservations, ledger entries, or execution. */
declare(strict_types=1);
require_once __DIR__.'/staffing_reconciliation_contract.php';
require_once __DIR__.'/purepay_financial_line_links.php';
require_once __DIR__.'/purepay_observations.php';

function staffingReconciliationRead(PDO $pdo,int $tenant,string $instance,array $owners,string $number,string $start,string $end,?string $runId=null,?int $before=null): array
{
    $period=staffingReconciliationPeriod($start,$end);$owners=purepayObservationOwners($owners);
    if($tenant<=0||!preg_match('/^PL-[1-9][0-9]*$/D',$number)||($before!==null&&$before<=0)
        ||($runId!==null&&!purepayObservationToken($runId,true)))throw new InvalidArgumentException('Choose a placement, work period and optional saved payroll run.');
    if($pdo->inTransaction())throw new LogicException('Comparison requires its own consistent read.');
    $binding=purepayFinancialRequire($pdo,$tenant);
    $context=purepayFinancialObservationContext($tenant,$instance,$binding);
    $access=$runId===null?null:purepayObservationAccess($pdo,$tenant,$instance);
    if($access!==null&&($access['review']['organization']['id']!==$context['organization_id']||purepayCoreApiBase()!==$context['api_base']))
        throw new RuntimeException('Financial and payroll connections refer to different provider companies.');
    $lineOwners=['people'=>$owners['people'],'placements'=>$owners['placements']];
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    try{
        $target=purepayFinancialLineTarget($pdo,$instance,$lineOwners,$number);
        $q=$pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND person_id=? AND work_date BETWEEN ? AND ? ORDER BY work_date,id LIMIT 1001');
        $q->execute([$owners['time'],$target['person_id'],$start,$end]);$personEntries=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($personEntries)>1000)throw new RuntimeException('Choose a shorter period to compare the complete time detail.');
        $entries=array_values(array_filter($personEntries,static fn($e)=>(int)$e['placement_id']===$target['placement_id']));
        $rateIds=array_values(array_unique(array_filter(array_column($entries,'rate_snapshot_id'))));$rates=[];
        if($rateIds){
            $q=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND placement_id=? AND id IN ('.implode(',',array_fill(0,count($rateIds),'?')).')');
            $q->execute([$owners['placements'],$target['placement_id'],...$rateIds]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r)$rates[(int)$r['id']]=$r;
        }
        $time=staffingReconciliationTime($entries,$rates,$period);
        $otherEntries=count(array_filter($personEntries,static fn($e)=>(int)$e['placement_id']!==$target['placement_id']&&empty($e['superseded_by_id'])));
        $sql='SELECT l.id FROM purepay_financial_line_links l WHERE l.tenant_id=? AND l.connection_id=?
            AND l.placement_tenant_id=? AND l.placement_id=?
            AND l.id=(SELECT MAX(n.id) FROM purepay_financial_line_links n WHERE n.tenant_id=l.tenant_id AND n.connection_id=l.connection_id AND n.subject_hash=l.subject_hash)';
        $params=[$tenant,$context['connection_id'],$owners['placements'],$target['placement_id']];
        if($before!==null){$sql.=' AND l.id<?';$params[]=$before;}
        $q=$pdo->prepare($sql.' ORDER BY l.id DESC LIMIT 51');$q->execute($params);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        $more=count($ids)>50;$ids=array_slice($ids,0,50);$financial=[];
        foreach($ids as$id){
            $q=$pdo->prepare('SELECT * FROM purepay_financial_line_links WHERE tenant_id=? AND connection_id=? AND id=?');
            $q->execute([$tenant,$context['connection_id'],$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('Association history changed. Read it again.');
            $saved=purepayFinancialLineSaved($row,$context,$lineOwners);$link=$saved['saved'];
            if(integrationDeliveryHash($link['target']['placement'])!==integrationDeliveryHash($target['placement'])
                ||integrationDeliveryHash($link['target']['person'])!==integrationDeliveryHash($target['person']))
                throw new RuntimeException('The historical association differs from the current placement identity.');
            $capture=purepayFinancialLineCapture($pdo,$tenant,$context,$link['capture_id']);
            $source=purepayFinancialLineSource($capture,$link['source']['line_id']);
            if(purepayFinancialObservationJson($source)!==purepayFinancialObservationJson($link['source']))
                throw new RuntimeException('The association and its saved document disagree.');
            $line=$source['line'];$observation=$capture['observation'];$document=$observation['document'];
            $relation=staffingReconciliationRelation($line['service_start'],$line['service_end'],$period);
            $comparable=in_array($relation,['exact','within'],true);
            $subset=$comparable?staffingReconciliationTime($entries,$rates,['start'=>$line['service_start'],'end'=>$line['service_end']]):null;
            $coreAmount=$subset===null?null:$subset[$source['kind']==='invoice'?'bill_amount':'pay_amount'];
            $q=$pdo->prepare('SELECT MAX(id) FROM purepay_financial_observations WHERE tenant_id=? AND connection_id=? AND document_kind=? AND document_id=?');
            $q->execute([$tenant,$context['connection_id'],$source['kind'],$source['document_id']]);
            $latestCapture=(int)$q->fetchColumn();
            $financial[]=['association_id'=>$saved['id'],'reviewed_at'=>$saved['reviewed_at'],'capture_id'=>$link['capture_id'],
                'captured_at'=>$source['observed_at'],'newer_capture_available'=>$latestCapture>$link['capture_id'],
                'kind'=>$source['kind'],'document_id'=>$source['document_id'],'line_id'=>$source['line_id'],
                'description'=>$line['description']??null,'item_name'=>$line['item_name']??null,
                'service_start'=>$line['service_start'],'service_end'=>$line['service_end'],'period_relation'=>$relation,
                'hours'=>$line['hours'],'amount'=>$line['amount'],'currency'=>$source['document_currency'],
                'core_approved_hours'=>$subset===null?null:$subset[$source['kind']==='invoice'?'billable_hours':'payable_hours'],
                'core_amount'=>$coreAmount,'core_currency'=>$subset['currency']??null,
                'comparison'=>staffingReconciliationDifference($line['amount'],$coreAmount,$source['document_currency'],$subset['currency']??null,$comparable),
                'document_payment_evidence'=>['scope'=>'whole_document_not_allocated_to_this_line',
                    'outgoing_payments'=>$observation['outgoing_payments'],'collections'=>$observation['collections'],
                    'recorded_receipts'=>$document['recorded_receipts'],'quickbooks_receipts'=>$document['quickbooks_receipts'],
                    'invoice_line_splits'=>$document['invoice_line_splits']],
                'time_entry_allocation_proven'=>false,'cash_delivery_proven'=>false];
        }
        $payroll=['status'=>$runId===null?'not_selected':'no_saved_capture','run_id'=>$runId,'lines'=>[]];
        if($runId!==null){
            $payContext=['tenant_id'=>$tenant,'instance_id'=>$instance,'owners'=>$owners,'connection_id'=>(int)$access['connection']['id'],
                'organization_id'=>$access['review']['organization']['id'],'api_base'=>purepayCoreApiBase()];
            $q=$pdo->prepare('SELECT * FROM payroll_native_observations WHERE tenant_id=? AND connection_id=? AND run_id=? ORDER BY id DESC LIMIT 1');
            $q->execute([$tenant,$payContext['connection_id'],$runId]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if($row){
                $capture=purepayObservationSaved($row,$payContext);$observation=$capture['observation'];$employees=[];
                foreach($capture['comparison']['people']as$p)
                    if($p['link_status']==='linked'&&integrationDeliveryHash($p['person'])===integrationDeliveryHash($target['person']))$employees[]=$p['employee_id'];
                $lines=array_values(array_filter($observation['lines'],static fn($l)=>in_array($l['employee_id'],$employees,true)));
                $relation=staffingReconciliationRelation($observation['period_start'],$observation['period_end'],$period);
                $payroll=['status'=>$lines?'saved_person_evidence':'no_reviewed_person_link','capture_id'=>(int)$row['id'],'saved_at'=>$row['created_at'],
                    'observed_at'=>$observation['observed_at'],'run_id'=>$runId,'period_start'=>$observation['period_start'],'period_end'=>$observation['period_end'],
                    'period_relation'=>$relation,'pay_date'=>$observation['pay_date'],'provider_status'=>$observation['status'],
                    'currency'=>$observation['currency'],'other_placement_entries'=>$otherEntries,'lines'=>[]];
                foreach($lines as$line){
                    $comparable=$relation==='exact'&&$otherEntries===0&&count($lines)===1;
                    $payroll['lines'][]=['line_index'=>$line['line_index'],'employee_id'=>$line['employee_id'],'hours'=>$line['hours'],'ot_hours'=>$line['ot_hours'],
                        'amounts'=>$line['amounts'],'source_time_lineage'=>$line['source_time_lineage'],
                        'core_pay_amount'=>$comparable?$time['pay_amount']:null,
                        'comparison'=>staffingReconciliationDifference($line['amounts']['gross'],$time['pay_amount'],$observation['currency'],$time['currency'],$comparable),
                        'placement_allocation_proven'=>false,'cash_delivery_proven'=>false];
                }
            }
        }
        $pdo->rollBack();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    if(purepayFinancialRequire($pdo,$tenant)['binding_hash']!==$binding['binding_hash'])throw new RuntimeException('The financial connection changed. Read again.');
    if($access!==null)purepayCoreLinkAccessUnchanged($pdo,$tenant,$access);
    $report=['tenant_id'=>$tenant,'placement'=>$target['placement'],'person'=>$target['person'],'person_name'=>$target['person_name'],
        'period'=>$period,'read_at'=>gmdate('c'),'time'=>$time,'financial_lines'=>$financial,'payroll'=>$payroll,
        'next_before'=>$more?(int)end($ids):null,'financial_coverage'=>'reviewed_placement_associations_only',
        'provider_refreshed'=>false,'financial_postings'=>false,'execution_authority'=>false];
    if(strlen(json_encode($report,JSON_THROW_ON_ERROR))>2000000)throw new RuntimeException('This comparison is too large. Review the saved document evidence separately.');
    return $report;
}
