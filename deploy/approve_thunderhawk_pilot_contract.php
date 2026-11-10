<?php
/** Source-aligned commercial review in the isolated pilot; no production approval or posting. */
declare(strict_types=1);
const TH_CONTRACT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_CONTRACT_PACKET='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030/commercial-source.private.json';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--approve-isolated-contract'||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_CONTRACT_ROOT)exit(2);
require_once TH_CONTRACT_ROOT.'/modules/placements/lib/rate_approve.php';
require_once TH_CONTRACT_ROOT.'/core/tx_helpers.php';
require_once TH_CONTRACT_ROOT.'/core/rbac/permissions.php';
require_once TH_CONTRACT_ROOT.'/core/integrations/purepay_assignment_inputs.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND parent_id IS NULL AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'placements','admin')||realpath(TH_CONTRACT_PACKET)!==TH_CONTRACT_PACKET)exit(2);
$raw=(string)file_get_contents(TH_CONTRACT_PACKET);$s=json_decode(preg_replace('/^\xEF\xBB\xBF/','',$raw),true,512,JSON_THROW_ON_ERROR);
if($s['kind']!=='thunderhawk_commercial_source_20261030'||$s['read_only']!==true||(int)$s['placement']['id']!==864
    ||$s['placement']['external_id']!=='jd:57862243'||count($s['rates'])!==1||count($s['parties'])!==5||count($s['cycles'])!==2
    ||$s['one_time_items']!==[]||$s['rates'][0]['bill_rate']!=='98.0000'||$s['rates'][0]['pay_rate']!=='65.0000'
    ||$s['tenant_defaults']['w2_payroll_load_pct']!=='0.200000')throw new RuntimeException('Source commercial review differs');
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'placement_id'=>864,'source_sha256'=>hash('sha256',$raw),
    'production_approved'=>false,'payroll_or_financial_postings'=>false,'workers_comp_class_still_missing'=>true,
    'commission_currency_or_due_date_inferred'=>false];$phase='preflight';
try{
    setRequestTenantId(2002);$_SESSION['user']=['id'=>4,'role'=>'tenant_admin','tenant_id'=>2002];
    $pdo->beginTransaction();
    $rate=$pdo->query('SELECT * FROM placement_rates WHERE tenant_id=2002 AND placement_id=864 FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    if(count($rate)!==1||$rate[0]['approved_at']!==null||(int)$rate[0]['id']!==2
        ||$rate[0]['bill_rate']!=='98.0000'||$rate[0]['pay_rate']!=='65.0000')throw new RuntimeException('Pilot rate has already changed');
    if((int)$pdo->query('SELECT COUNT(*) FROM placement_economic_parties WHERE tenant_id=2002')->fetchColumn()!==0
        ||(int)$pdo->query('SELECT COUNT(*) FROM tenant_staffing_economics_defaults WHERE tenant_id=2002')->fetchColumn()!==0
        ||(int)$pdo->query('SELECT COUNT(*) FROM staffing_operating_cycles WHERE tenant_id=2002')->fetchColumn()!==0)
        throw new RuntimeException('Pilot economics already configured; inspect rather than replace');
    $phase='cycles';$cycles=[];
    foreach($s['cycles'] as $c){
        if((int)$c['active']!==1||$c['default_payment_terms']!==null)throw new RuntimeException('Expanded cycle review needed');
        $cycles[$c['id']]=placementEconomicsEnsureCycle(2002,$c['purpose'],$c['cadence'],$c['anchor_date'],$c['source_system'],$c['external_id'],$c['name']);
        if(!$cycles[$c['id']])throw new RuntimeException('Native cycle creation failed');
    }
    $phase='source_configuration';
    $fields=['client_bill_cycle','client_bill_cycle_anchor','vendor_pay_cycle','vendor_pay_cycle_anchor',
        'client_payment_terms_override','vendor_payment_terms_override','vendor_pwp_enabled'];
    $updates=array_intersect_key($s['placement'],array_flip($fields));
    foreach(['billing_operating_cycle_id','ap_operating_cycle_id','payroll_operating_cycle_id'] as $field){
        $source=$s['placement'][$field];if($source!==null&&!isset($cycles[$source]))throw new RuntimeException('Unmapped cycle');
        $updates[$field]=$source===null?null:$cycles[$source];
    }
    $pdo->prepare('UPDATE placements SET '.implode(',',array_map(static fn($k)=>$k.'=?',array_keys($updates))).' WHERE tenant_id=2002 AND id=864')->execute(array_values($updates));
    $defaults=$s['tenant_defaults'];
    if(array_keys($defaults)!==['w2_payroll_load_pct','w2_workers_comp_pct','w2_benefits_load_pct','c2c_overhead_pct'])throw new RuntimeException('Unexpected defaults');
    $pdo->prepare('INSERT INTO tenant_staffing_economics_defaults(tenant_id,w2_payroll_load_pct,w2_workers_comp_pct,w2_benefits_load_pct,c2c_overhead_pct,updated_by_user_id)
        VALUES(2002,?,?,?,?,4)')->execute(array_values($defaults));
    $fields=['ot_multiplier','dt_multiplier','adder_pct','bill_adder_pct','bill_adder_flat','bill_discount_pct','bill_discount_flat',
        'workers_comp_pct','benefits_load_pct','c2c_overhead_pct','other_cost_per_hour','other_cost_flat','background_fee_total'];
    $updates=array_intersect_key($s['rates'][0],array_flip($fields));
    if(count($updates)!==count($fields))throw new RuntimeException('Incomplete rate extension');
    $pdo->prepare('UPDATE placement_rates SET '.implode(',',array_map(static fn($k)=>$k.'=?',array_keys($updates))).' WHERE tenant_id=2002 AND id=2 AND approved_at IS NULL')->execute(array_values($updates));
    $phase='source_parties';$partyMap=[];
    foreach($s['parties'] as $party){
        if($party['user_id']!==null||$party['ap_vendor_id']!==null||(int)$party['active']!==1
            ||($party['company_id']!==null&&(int)$party['company_id']!==1902)
            ||($party['person_id']!==null&&(int)$party['person_id']!==358))throw new RuntimeException('Unresolved source party relationship');
        $sourceId=$party['id'];unset($party['id']);$cycle=$party['operating_cycle_id'];
        if($cycle!==null&&!isset($cycles[$cycle]))throw new RuntimeException('Unmapped party cycle');
        $party['operating_cycle_id']=$cycle===null?null:$cycles[$cycle];$party['created_by_user_id']=4;
        $id=placementEconomicsUpsertParty(2002,864,$party);if(!$id)throw new RuntimeException('Native party creation failed');
        $partyMap[$sourceId]=$id;
        $pdo->prepare('UPDATE placement_economic_parties SET payment_terms_overridden=?,pwp_overridden=?,cycle_overridden=? WHERE tenant_id=2002 AND id=?')
            ->execute([$party['payment_terms_overridden'],$party['pwp_overridden'],$party['cycle_overridden'],$id]);
    }
    $phase='native_rate_approval';
    $result=placementsRateApproveOne(2,['id'=>4,'role'=>'tenant_admin'],false,null);
    $model=$result['margin']['economics_snapshot'];
    if(abs((float)$model['modeled_hourly_cost']-78)>0.000001||abs((float)$model['modeled_hourly_margin']-20)>0.000001)
        throw new RuntimeException('Native economics differ from source rates and cost defaults');
    $phase='commercial_packet_validation';
    $source=purepayAssignmentInputSource($pdo,integrationInstanceId(),array_fill_keys(['placements','people','companies','ap'],2002),'PL-864');
    $body=$source['body'];
    if(count($body['terms'])!==1||$body['terms'][0]['pay_rates']['regular']!=='65.0000'||$body['terms'][0]['bill_rates']['regular']!=='98.0000'
        ||$body['start_date']!=='2026-05-18'||$body['end_date']!=='2027-03-20'||$body['work_context']['workers_comp_class']!==null)
        throw new RuntimeException('Commercial packet changed the approved source');
    $report+=['rate_id'=>2,'native_approval_passed'=>true,'bill_rate'=>'98.0000','pay_rate'=>'65.0000','employer_load_fraction'=>'0.200000',
        'modeled_hourly_cost'=>'78.000000','modeled_hourly_margin'=>'20.000000','cycles'=>$cycles,'source_party_mapping'=>$partyMap,
        'commercial_source_revision'=>$body['source_revision'],'term_periods'=>1,
        'informational_source_roles'=>array_column(array_values(array_filter($body['terms'][0]['economics']['parties'],static fn($p)=>$p['money_flow']==='informational')),'role')];
    platformAuditLogWrite(2002,4,'placement.pilot_contract.reviewed',864,$report,['source'=>'integration_pilot']);
    $pdo->commit();$report['passed']=true;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['phase']=$phase;
    $report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();
    if(!$e instanceof PDOException)$report['failure']=$e->getMessage();
}finally{setRequestTenantId(null);unset($_SESSION['user']);}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),"\n";
exit($report['passed']?0:1);
