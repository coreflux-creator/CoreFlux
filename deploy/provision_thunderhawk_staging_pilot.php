<?php
/** Import a bounded source graph into a new, explicitly isolated Thunderhawk pilot. */
declare(strict_types=1);
const TH_PILOT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_PILOT_TENANT=2002;
const TH_PILOT_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const TH_PILOT_SOURCE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030/core-source.private.json';
$mode=$argv[1]??'';
if(PHP_SAPI!=='cli'||!in_array($mode,['--inspect','--apply'],true)
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_PILOT_ROOT
    ||is_link(TH_PILOT_SOURCE)||realpath(TH_PILOT_SOURCE)!==TH_PILOT_SOURCE)
    throw new RuntimeException('Exact staging host and private source packet required');
require_once TH_PILOT_ROOT.'/core/db.php';
require_once TH_PILOT_ROOT.'/core/integrations/identity.php';
require_once TH_PILOT_ROOT.'/core/memberships.php';
require_once TH_PILOT_ROOT.'/core/mail_bootstrap.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
    ||!defined('COREFLUX_STAGING')||COREFLUX_STAGING!==true||integrationInstanceId()!==TH_PILOT_INSTANCE
    ||cf_mail_bootstrap()->default_driver_name()!=='log'||cf_mail_bootstrap()->driver('resend')!==null)
    throw new RuntimeException('Staging identity and log-only mail are mandatory');
$raw=(string)file_get_contents(TH_PILOT_SOURCE);
$source=json_decode(preg_replace('/^\xEF\xBB\xBF/','',$raw),true,512,JSON_THROW_ON_ERROR);
if(($source['kind']??'')!=='thunderhawk_core_source_20261030'||($source['read_only']??false)!==true
    ||($source['source_tenant']['name']??'')!=='Thunderhawk'||(int)($source['source_tenant']['id']??0)!==2
    ||(int)($source['person']['id']??0)!==358||($source['person']['external_id']??'')!=='jd:18424007240614'
    ||(int)($source['placement']['id']??0)!==864||($source['placement']['external_id']??'')!=='jd:57862243'
    ||(int)($source['job']['id']??0)!==1972||($source['job']['external_id']??'')!=='27926305'
    ||(int)($source['placement']['person_id']??0)!==358||(int)($source['placement']['staffing_job_id']??0)!==1972
    ||!filter_var($source['person']['email_primary']??'',FILTER_VALIDATE_EMAIL)
    ||count($source['rates']??[])!==1||count($source['companies']??[])!==2)
    throw new RuntimeException('Bounded source identities differ');
$sourceHash=hash('sha256',$raw);
$rows=['companies'=>$source['companies'],'staffing_clients'=>[$source['job_client'],$source['client']],
    'staffing_jobs'=>[$source['job']],'people'=>[$source['person']],'placements'=>[$source['placement']]];
$expectedIds=['companies'=>[1080,1902],'staffing_clients'=>[97,1280],'staffing_jobs'=>[1972],'people'=>[358],'placements'=>[864]];
foreach($rows as $table=>$records){
    $ids=array_map(static fn($r)=>(int)$r['id'],$records);sort($ids);
    if($ids!==$expectedIds[$table])throw new RuntimeException('Unexpected source graph');
    foreach($records as $r)if((int)$r['tenant_id']!==2)throw new RuntimeException('Cross-company source record');
}
$companyIds=array_column($source['companies'],'id');
foreach([$source['job_client'],$source['client']] as $r)
    if(!in_array($r['company_id'],$companyIds,true))throw new RuntimeException('Unresolved company relationship');
if((int)$source['placement']['client_id']!==(int)$source['client']['id']
    ||(int)$source['job']['client_id']!==(int)$source['job_client']['id']
    ||!in_array($source['job']['company_id'],$companyIds,true)
    ||!in_array($source['placement']['end_client_company_id'],$companyIds,true))
    throw new RuntimeException('Unresolved staffing relationship');
$snapshot=static function()use($pdo):string {
    $data=[];
    foreach(['tenants','people','placements','companies','staffing_clients','staffing_jobs','placement_rates','quanta_connections','purepay_core_connections','tenant_memberships','user_tenants'] as $table){
        $scope=$table==='tenants'?'id':'tenant_id';
        $data[$table]=$pdo->query('SELECT * FROM '.$table.' WHERE '.$scope.'<>2002 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    $data['operator']=$pdo->query('SELECT * FROM users WHERE id=4')->fetchAll(PDO::FETCH_ASSOC);
    // Existing connections contain binary ciphertext. Hash locally without serializing it to output.
    return hash('sha256',serialize($data));
};
$report=['checked_at'=>gmdate('c'),'mode'=>$mode,'tenant_id'=>TH_PILOT_TENANT,
    'name'=>'Thunderhawk — isolated integration pilot','instance_id'=>TH_PILOT_INSTANCE,'source_sha256'=>$sourceHash,
    'production_changed'=>false,'source_approvals_imported'=>false,'notifications_sent'=>false,
    'payroll_or_financial_postings'=>false,'source_capture_is_fresh_jobdiva_fetch'=>false,
    'job_company_and_placement_bill_to_are_distinct'=>true,'workers_comp_class_missing'=>empty($source['placement']['workers_comp_class'])];
$locked=false;
try{
    $locked=(int)$pdo->query("SELECT GET_LOCK('thunderhawk_staging_pilot_2002',10)")->fetchColumn()===1;
    if(!$locked)throw new RuntimeException('Pilot provisioning is already running');
    $pdo->beginTransaction();
    if($pdo->query('SELECT id FROM tenants WHERE id=2002 FOR UPDATE')->fetchColumn())
        throw new RuntimeException('Pilot already exists; inspect its recorded state instead of overwriting');
    if(!$pdo->query("SELECT id FROM users WHERE id=4 AND email='stage-admin@coreflux.test' AND is_active=1")->fetchColumn())
        throw new RuntimeException('Staging operator differs');
    foreach($rows as $table=>$records){
        $ids=implode(',',array_column($records,'id'));
        if((int)$pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE id IN ('.$ids.')')->fetchColumn()!==0)
            throw new RuntimeException('An existing record uses a reserved pilot ID: '.$table);
    }
    $before=$snapshot();
    $report['preflight_passed']=true;
    if($mode==='--inspect'){$pdo->rollBack();$report['created']=false;}
    else{
        $insert=static function(string $table,array $row)use($pdo):int {
            $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
            if(array_diff(array_keys($row),$q->fetchAll(PDO::FETCH_COLUMN)))throw new RuntimeException('Unexpected target schema: '.$table);
            $columns=array_keys($row);
            $q=$pdo->prepare('INSERT INTO '.$table.' ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
            $q->execute(array_values($row));return (int)$pdo->lastInsertId();
        };
        $insert('tenants',['id'=>TH_PILOT_TENANT,'name'=>$report['name'],'slug'=>'thunderhawk-isolated-pilot-20261030',
            'parent_id'=>null,'tenant_type'=>'master','is_simulation'=>1,'is_active'=>1,'status'=>'active']);
        $entity=$source['accounting_entity'];unset($entity['id']);$entity['tenant_id']=TH_PILOT_TENANT;
        $entityId=$insert('accounting_entities',$entity);
        foreach($rows as $table=>$records)foreach($records as $r){
            $r['tenant_id']=TH_PILOT_TENANT;
            if($table==='placements')$r['accounting_entity_id']=$entityId;
            $insert($table,$r);
        }
        $rateIds=[];
        foreach($source['rates'] as $r){
            unset($r['id'],$r['approved_at'],$r['approved_by_user_id']);
            $r['tenant_id']=TH_PILOT_TENANT;$r['created_by_user_id']=4;
            $rateIds[]=$insert('placement_rates',$r);
        }
        foreach($source['jobdiva_mappings'] as $r){
            $type=$r['internal_entity_type'];$table=['person'=>'people','placement'=>'placements','company'=>'companies','job'=>'staffing_jobs','client'=>'staffing_clients'][$type]??null;
            if($r['source_system']!=='jobdiva'||!$table||!in_array((int)$r['internal_entity_id'],$expectedIds[$table],true))
                throw new RuntimeException('Unbounded source mapping');
            $r['tenant_id']=TH_PILOT_TENANT;$r['direction']='off';$r['sync_status']='stale';
            $r['last_error']='Isolated source snapshot; no live JobDiva connection has been configured.';
            $insert('external_entity_mappings',$r);
        }
        $_SESSION['user']=['id'=>4];
        $membership=provisionMembership(4,TH_PILOT_TENANT,'tenant_admin',
            ['is_primary'=>false,'persona_label'=>'Thunderhawk isolated pilot','status'=>'active']);
        unset($_SESSION['user']);
        $numbers=$pdo->query('SELECT entity_type,record_id,record_number FROM core_record_numbers WHERE tenant_id=2002 ORDER BY entity_type,record_id')->fetchAll(PDO::FETCH_ASSOC);
        $expected=['client'=>['97'=>'CL-97','1280'=>'CL-1280'],'company'=>['1080'=>'CO-1080','1902'=>'CO-1902'],
            'person'=>['358'=>'P-358'],'placement'=>['864'=>'PL-864']];
        if(count($numbers)!==6)throw new RuntimeException('Native numbering did not allocate the complete graph');
        foreach($numbers as $n)if(($expected[$n['entity_type']][(string)$n['record_id']]??null)!==$n['record_number'])
            throw new RuntimeException('Native stable number differs');
        if(!hash_equals($before,$snapshot()))throw new RuntimeException('Existing staging records changed');
        $report+=['created'=>true,'accounting_entity_id'=>$entityId,'rate_version_ids'=>$rateIds,
            'membership_id'=>$membership['membership_id'],'record_numbers'=>$numbers,'other_staging_records_unchanged'=>true];
        $pdo->prepare('INSERT INTO membership_audit (tenant_id,membership_id,action,actor_user_id,target_user_id,detail)
            VALUES (2002,?,"thunderhawk_pilot_bootstrap",4,4,?)')->execute([$membership['membership_id'],json_encode($report,JSON_THROW_ON_ERROR)]);
        $pdo->commit();
    }
    $report['passed']=true;
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failure_class']=get_class($e);
    // PDO messages may contain source contact values. Do not emit them.
    $report['failure']=$e instanceof PDOException?'Database constraint rejected the isolated import':$e->getMessage();
    $report['failure_line']=$e->getLine();
}finally{if($locked)$pdo->query("SELECT RELEASE_LOCK('thunderhawk_staging_pilot_2002')");}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
exit($report['passed']?0:1);
