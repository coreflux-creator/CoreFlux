<?php
/** Synthetic rows only. No application DB, credentials, startup or provider requests. */
declare(strict_types=1);
require_once __DIR__.'/../core/integrations/purepay_assignment_contract.php';
require_once __DIR__.'/../core/integrations/identity.php';

function purepayAssignmentFixtureRows(string $employment='w2'): array
{
    $placement=['id'=>901,'person_id'=>9001,'client_id'=>1,'billing_company_id'=>10,'end_client_company_id'=>11,'title'=>'Engineer / Montréal',
        'start_date'=>'2026-10-01','end_date'=>null,'actual_end_date'=>null,'status'=>'active','engagement_type'=>'w2',
        'billing_operating_cycle_id'=>1,'payroll_operating_cycle_id'=>2,'ap_operating_cycle_id'=>3,
        'external_id'=>'jd:fixture-901','jobdiva_job_id'=>'00702','end_client_name'=>'Synthetic Work Recipient',
        'staffing_job_id'=>7,'accounting_entity_id'=>9,'due_date'=>null,'worksite_country'=>'US','worksite_state'=>'NY',
        'branch'=>'North','service_line'=>'Staffing','workers_comp_class'=>'8810','department'=>null,'cost_center'=>'A-1','remote_policy'=>'hybrid'];
    $person=['id'=>9001,'first_name'=>'Synthetic','last_name'=>'Worker'];
    $party=['id'=>1,'source_ref'=>'placement:bill_to','source_type'=>'placement','role'=>'bill_to_client','display_name'=>'Synthetic Client',
        'company_id'=>10,'person_id'=>null,'user_id'=>null,'ap_vendor_id'=>null,'money_flow'=>'receivable','settlement_channel'=>'ar',
        'fee_basis'=>'none','fee_pct'=>null,'fee_flat'=>null,'payment_terms'=>'NET30','payment_terms_overridden'=>0,
        'pwp_enabled'=>0,'pwp_overridden'=>0,'operating_cycle_id'=>1,'cycle_overridden'=>0,
        'effective_from'=>null,'effective_to'=>null,'active'=>1,'vendor_default_terms'=>null,'vendor_default_pwp'=>0];
    $worker=array_replace($party,['id'=>2,'source_ref'=>'worker:9001','source_type'=>'worker','role'=>'worker','display_name'=>'Synthetic Worker',
        'company_id'=>null,'person_id'=>9001,'money_flow'=>'payable','settlement_channel'=>'payroll','fee_basis'=>'pay_rate','payment_terms'=>null,'operating_cycle_id'=>2]);
    $fee=array_replace($party,['id'=>3,'source_ref'=>'chain:1','source_type'=>'chain','role'=>'msp','display_name'=>'Synthetic MSP',
        'company_id'=>null,'ap_vendor_id'=>1,'money_flow'=>'payable','settlement_channel'=>'ap','fee_basis'=>'pct_bill',
        'fee_pct'=>'0.040000','payment_terms'=>'NET5','pwp_enabled'=>1,'operating_cycle_id'=>3]);
    $fixed=array_replace($fee,['id'=>4,'source_ref'=>'chain:2','role'=>'other_fee','fee_basis'=>'per_invoice','fee_pct'=>null,'fee_flat'=>'12.3456']);
    $parties=[$party,$worker,$fee,$fixed];$cycles=[];
    foreach([[1,'billing','monthly'],[2,'payroll','biweekly'],[3,'ap','semimonthly']] as [$id,$purpose,$cadence])$cycles[]=[
        'id'=>$id,'name'=>'Current '.$purpose,'purpose'=>$purpose,'cadence'=>$cadence,'anchor_date'=>'2026-10-15',
        'settlement_offset_days'=>5,'default_payment_terms'=>$purpose==='payroll'?null:'NET30','payroll_pay_cycle_id'=>null,
        'active'=>1,'source_system'=>'coreflux','external_id'=>null];
    $frozen=[];
    foreach($parties as $p) {
        $cycle=$cycles[$p['operating_cycle_id']-1];
        $p['cycle_name']='Approved '.$cycle['purpose'];$p['cycle_purpose']=$cycle['purpose'];$p['cycle_cadence']=$p['id']===1?'weekly':$cycle['cadence'];
        $p['resolved_pwp']=$p['settlement_channel']==='ap';$p['resolved_payment_terms']=$p['resolved_pwp']?'PWP_NET5':$p['payment_terms'];
        if($p['id']===3)$p['fee_pct']=0.035;
        $frozen[]=array_diff_key($p,array_flip(['payment_terms_overridden','pwp_overridden','cycle_overridden','active','vendor_default_terms','vendor_default_pwp','pwp_enabled']));
    }
    $base=['id'=>5,'tenant_id'=>1,'placement_id'=>901,'effective_from'=>'2026-09-01','effective_to'=>'2026-10-31',
        'pay_rate'=>'42.1250','bill_rate'=>'80.0000','pay_rate_unit'=>'hour','bill_rate_unit'=>'hour','currency'=>'USD',
        'ot_multiplier'=>'1.50','dt_multiplier'=>'2.00','approved_at'=>'2026-09-01 12:30:00','superseded_by'=>6,
        'bill_adder_pct'=>'0.010000','bill_adder_flat'=>null,'bill_discount_pct'=>null,'bill_discount_flat'=>'0.5000',
        'adder_pct'=>'0.0000','workers_comp_pct'=>null,'benefits_load_pct'=>null,'c2c_overhead_pct'=>null,
        'other_cost_per_hour'=>null,'other_cost_flat'=>'25.00','background_fee_total'=>'100.00'];
    $rates=[$base,array_replace($base,['id'=>6,'effective_from'=>'2026-11-01','effective_to'=>null,'pay_rate'=>'45.0000','superseded_by'=>null,'approved_at'=>'2026-10-20 12:30:00'])];
    foreach($rates as &$r)$r['economics_snapshot_json']=json_encode(['available'=>true,'rate_id'=>$r['id'],'contract_version'=>1,
        'currency'=>'USD','bill_rate'=>80.0,'pay_rate'=>(float)$r['pay_rate'],'invoice_bill_rate'=>80.3,
        'modeled_hourly_cost'=>50.0,'modeled_hourly_margin'=>30.3,'modeled_margin_pct'=>0.377335,'fixed_obligations'=>137.35,
        'employer_cost_rates'=>['adder_pct'=>0.0,'workers_comp_pct'=>0.005,'benefits_load_pct'=>0.0],
        'employer_cost_sources'=>['adder_pct'=>'placement_override','workers_comp_pct'=>'tenant_default','benefits_load_pct'=>'none'],
        'c2c_overhead_rate'=>0.0,'c2c_overhead_source'=>'none','contract_parties'=>$frozen],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    unset($r);
    if($employment==='c2c') {
        $placement['engagement_type']='c2c';
        $parties[1]=array_replace($parties[1],['source_ref'=>'corp:1','source_type'=>'corp','role'=>'c2c_vendor','display_name'=>'Synthetic Vendor',
            'ap_vendor_id'=>1,'settlement_channel'=>'ap','payment_terms'=>'NET5','pwp_enabled'=>1,'operating_cycle_id'=>3]);
        foreach($rates as &$r) {
            $snapshot=json_decode($r['economics_snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            $snapshot['contract_parties'][1]=array_replace($snapshot['contract_parties'][1],['source_ref'=>'corp:1','source_type'=>'corp','role'=>'c2c_vendor','display_name'=>'Synthetic Vendor',
                'ap_vendor_id'=>1,'settlement_channel'=>'ap','payment_terms'=>'NET5','resolved_payment_terms'=>'PWP_NET5','resolved_pwp'=>true,
                'operating_cycle_id'=>3,'cycle_name'=>'Approved ap','cycle_purpose'=>'ap','cycle_cadence'=>'semimonthly']);
            $snapshot['employer_cost_rates']=['adder_pct'=>0,'workers_comp_pct'=>0,'benefits_load_pct'=>0];
            $snapshot['employer_cost_sources']=['adder_pct'=>'none','workers_comp_pct'=>'none','benefits_load_pct'=>'none'];
            $snapshot['c2c_overhead_source']='source_waiver';
            $r['economics_snapshot_json']=json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        }
        unset($r);
    }
    $item=['id'=>11,'economic_party_id'=>3,'description'=>'Onboarding credit / 1e3 "quoted"','settlement_channel'=>'ap','direction'=>'credit',
        'amount'=>'12.34','currency'=>'USD','apply_on'=>'2026-10-02','taxable'=>0,'status'=>'active','source_system'=>'coreflux','source_external_id'=>null];
    $items=[$item,array_replace($item,['id'=>12,'economic_party_id'=>null,'settlement_channel'=>'none','amount'=>'9999999999.99','status'=>'void'])];
    return compact('placement','person','rates','parties','cycles','items');
}

function purepayAssignmentFixtureBuild(?array $rows=null,?string $previous=null): array
{
    $r=$rows??purepayAssignmentFixtureRows();
    return purepayAssignmentBuild($r['placement'],$r['person'],$r['rates'],$r['parties'],$r['cycles'],$r['items'],
        static fn(string $kind,int $id):array=>integrationRecordReference('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',1,$kind,['person'=>'P','placement'=>'PL','company'=>'CO','client'=>'CL','vendor'=>'V'][$kind].'-'.$id),$previous);
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    if(($argv[1]??'')==='--emit'){echo integrationDeliveryJson(purepayAssignmentFixtureBuild());exit;}
    if(($argv[1]??'')==='--emit-c2c'){echo integrationDeliveryJson(purepayAssignmentFixtureBuild(purepayAssignmentFixtureRows('c2c')));exit;}
    $count=0;$check=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;};
    $reject=static function(callable $f,string $label)use($check):void{try{$f();$failed=false;}catch(Throwable){$failed=true;}$check($failed,$label);};
    $body=purepayAssignmentFixtureBuild();$first=$body['terms'][0];
    $check(count($body['terms'])===2 && $first['start_date']==='2026-10-01' && $first['economics']['rate_effective_from']==='2026-09-01','Clip coverage, preserve rate range');
    $check($first['pay_rates']['overtime']==='63.187500','Exact derived overtime');
    $check($first['economics']['parties'][2]['fee_fraction']==='0.035000' && $body['current_commercial_context']['parties'][2]['fee_fraction']==='0.040000','Frozen and current fee remain distinct');
    $check($first['economics']['parties'][0]['cycle']['cadence']==='weekly' && $body['current_commercial_context']['cycles'][0]['cadence']==='monthly','Historic and current cycles remain distinct');
    $check($first['economics']['adjustments']['employer_load_override']==='0.000000' && $first['economics']['adjustments']['workers_comp_override']===null,'Zero waiver differs from inherited cost');
    $check(purepayAssignmentDecimal('1.2e-3')==='0.001200' && purepayAssignmentDecimal('1e2')==='100.000000','Exact exponent normalization');
    $check(purepayAssignmentSnapshotJson('{"n":1e-6,"text":"1e3 \\" 123","yes":true,"nil":null}')===['n'=>'1e-6','text'=>'1e3 " 123','yes'=>true,'nil'=>null],'JSON lexemes preserve strings and booleans');
    foreach([1.23,'0.0000001','-1','1e100','10000000000'] as $bad)$reject(static fn()=>purepayAssignmentDecimal($bad),'Reject loss or unsupported decimal');
    foreach(['gap','overlap','draft','company','missing_cost','wrong_rate','reversed'] as $change) {
        $r=purepayAssignmentFixtureRows();
        if($change==='gap')$r['rates'][1]['effective_from']='2026-11-02';
        if($change==='overlap')$r['rates'][1]['effective_from']='2026-10-31';
        if($change==='draft')$r['rates'][1]['approved_at']=null;
        if($change==='company')$r['placement']['billing_company_id']=12;
        if($change==='missing_cost')unset($r['rates'][0]['c2c_overhead_pct']);
        if($change==='wrong_rate')$r['rates'][0]['pay_rate']='43.0000';
        if($change==='reversed')$r['rates'][0]['effective_to']='2026-08-31';
        $reject(static fn()=>purepayAssignmentFixtureBuild($r),'Reject '.$change);
    }
    $check($body['work_context']['end_client']['number']==='CO-11'
        && $first['economics']['parties'][0]['roots']['company']['number']==='CO-10',
        'End client and bill-to company remain distinct through export');
    $r=purepayAssignmentFixtureRows();$r['placement']['end_client_company_id']=12;
    $changedRecipient=purepayAssignmentFixtureBuild($r);
    $check($changedRecipient['terms']===$body['terms'],'Changing the work recipient cannot retarget approved billing terms');
    $r=purepayAssignmentFixtureRows();
    foreach($r['rates'] as &$rate){$snapshot=json_decode($rate['economics_snapshot_json'],true);
        $snapshot['contract_parties'][0]['source_ref']='placement:end_client';
        $rate['economics_snapshot_json']=json_encode($snapshot);}
    unset($rate);
    $check(purepayAssignmentFixtureBuild($r)['terms'][0]['economics']['parties'][0]['roots']['company']['number']==='CO-10',
        'Legacy snapshot bridges only to its verified billing company');
    $r['placement']['billing_company_id']=11;
    $reject(static fn()=>purepayAssignmentFixtureBuild($r),'Legacy end-client coincidence cannot retarget the debtor');
    $r=purepayAssignmentFixtureRows();$old=$r['rates'][0];$old['id']=4;$old['effective_to']='2026-08-31';$old['superseded_by']=5;$r['rates'][]=$old;
    $check(purepayAssignmentFixtureBuild($r)===$body,'Proven same-day correction does not create a bogus period');
    $r=purepayAssignmentFixtureRows();$draft=$r['rates'][1];$draft['id']=9;$draft['approved_at']=null;$draft['pay_rate']='99.0000';$r['rates'][]=$draft;
    $check(purepayAssignmentFixtureBuild($r)===$body,'Unapproved rates stay outside the packet');
    $check(purepayAssignmentFixtureBuild(null,str_repeat('a',64))['source_revision']===$body['source_revision'],'Revision content independent of predecessor envelope');
    $r=purepayAssignmentFixtureRows();$r['parties'][2]['payment_terms']=null;$r['parties'][2]['vendor_default_terms']='NET10';
    $r['parties'][2]['pwp_enabled']=0;$r['parties'][2]['vendor_default_pwp']=1;
    $inherited=purepayAssignmentFixtureBuild($r)['current_commercial_context']['parties'][2];
    $check($inherited['payment_terms']===null && $inherited['resolved_payment_terms']==='PWP_NET10'
        && !$inherited['pwp_enabled'] && $inherited['paid_when_paid'],'Retain raw unset terms and raw PWP separately from inherited resolved settings');
    require_once __DIR__.'/../core/integrations/staffing_exchange_fields.php';
    $paths=0;
    foreach(staffingExchangeFieldMappings() as $providers)foreach($providers['purepayroll']??[] as $mapping) {
        if($mapping['route']!=='/api/v1/integrations/coreflux/assignments/inputs')continue;
        $nodes=[$body];
        foreach(explode('.',$mapping['path']) as $segment) {
            $list=str_ends_with($segment,'[]');$key=$list?substr($segment,0,-2):$segment;$next=[];
            foreach($nodes as $node) {
                if(!is_array($node) || !array_key_exists($key,$node))throw new RuntimeException('Dictionary path absent from actual packet: '.$mapping['path']);
                if($list)$next=array_merge($next,$node[$key]);else $next[]=$node[$key];
            }
            $nodes=$next;
        }
        $paths++;
    }
    $check($paths>80,'Commercial field inventory is checked against actual PHP output');
    echo "PASS $count commercial projection checks\n";
}
