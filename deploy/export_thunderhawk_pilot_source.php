<?php
/** Bounded, read-only export for the authorized Thunderhawk pilot. Output contains contact data: save privately. */
declare(strict_types=1);
const TH_SOURCE_ROOT='/home/1516771.cloudwaysapps.com/grcudkpvcd/public_html';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--export-private-source'||realpath(TH_SOURCE_ROOT)!==TH_SOURCE_ROOT)
    throw new RuntimeException('Exact source host and explicit private export required');
require_once TH_SOURCE_ROOT.'/core/db.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='grcudkpvcd')throw new RuntimeException('Source database mismatch');
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();
try {
    $read=static function(string $table,array $fields,string $where,array $params=[])use($pdo):array {
        $s=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $s->execute([$table]);$available=$s->fetchAll(PDO::FETCH_COLUMN);
        $selected=array_values(array_intersect($fields,$available));
        if(!$selected)throw new RuntimeException('Source table unavailable: '.$table);
        $q=$pdo->prepare('SELECT '.implode(',',$selected).' FROM '.$table.' WHERE '.$where.' LIMIT 20');
        $q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);
    };
    $one=static function(array $rows):array {if(count($rows)!==1)throw new RuntimeException('Expected one exact source record');return $rows[0];};
    $tenant=$one($read('tenants',['id','name'],'id=2'));
    if($tenant['name']!=='Thunderhawk')throw new RuntimeException('Source company changed');
    $person=$one($read('people',['id','tenant_id','external_id','first_name','middle_name','last_name','preferred_name','email_primary','phone_primary','classification','employment_type','hire_date','status','source'],'id=358 AND tenant_id=2 AND deleted_at IS NULL'));
    $placement=$one($read('placements',['id','tenant_id','person_id','external_id','jobdiva_job_id','status','start_date','end_date','engagement_type','worksite_city','worksite_state','worksite_country','remote_policy','branch','service_line','workers_comp_class','department','cost_center','accounting_entity_id','title','end_client_name','end_client_company_id','client_id','staffing_job_id'],'id=864 AND tenant_id=2 AND person_id=358 AND deleted_at IS NULL'));
    if($person['external_id']!=='jd:18424007240614'||$placement['external_id']!=='jd:57862243'||$placement['jobdiva_job_id']!=='27926305')
        throw new RuntimeException('Selected JobDiva identities changed');
    $entity=$one($read('accounting_entities',['id','tenant_id','code','legal_name','base_currency','country','entity_type','accounting_basis','fiscal_year_start_month','active'],'id=? AND tenant_id=2',[$placement['accounting_entity_id']]));
    $client=$one($read('staffing_clients',['id','tenant_id','company_id','name','external_id','source_system','legal_name','billing_country','payment_terms_days','status'],'id=? AND tenant_id=2',[$placement['client_id']]));
    $job=$one($read('staffing_jobs',['id','tenant_id','client_id','company_id','title','status','external_id','source_system','department','location_city','location_state','location_country','remote_policy','opened_at','closed_at'],'id=? AND tenant_id=2',[$placement['staffing_job_id']]));
    $jobClient=$one($read('staffing_clients',['id','tenant_id','company_id','name','external_id','source_system','legal_name','billing_country','payment_terms_days','status'],'id=? AND tenant_id=2',[$job['client_id']]));
    $companyIds=array_values(array_unique(array_filter([(int)$client['company_id'],(int)$placement['end_client_company_id'],(int)$job['company_id'],(int)$jobClient['company_id']])));
    $companies=$read('companies',['id','tenant_id','name','legal_name','external_id','company_type','website','status','country','state','city'],
        'tenant_id=2 AND deleted_at IS NULL AND id IN ('.implode(',',array_fill(0,count($companyIds),'?')).')',$companyIds);
    if(count($companies)!==count($companyIds))throw new RuntimeException('Source company relationship is unresolved');
    $rates=$read('placement_rates',['id','tenant_id','placement_id','effective_from','effective_to','bill_rate','pay_rate','currency','bill_rate_unit','pay_rate_unit','bill_discount_pct','invoice_bill_rate','payroll_load_pct','workers_comp_pct','benefits_load_pct','c2c_overhead_pct','other_cost_per_hour','other_cost_flat','approved_at','approved_by_user_id'],
        'tenant_id=2 AND placement_id=864 AND superseded_by IS NULL');
    $mappings=$read('external_entity_mappings',['source_system','internal_entity_type','internal_entity_id','external_id'],
        "tenant_id=2 AND source_system='jobdiva' AND ((internal_entity_type='person' AND internal_entity_id=358) OR (internal_entity_type='placement' AND internal_entity_id=864) OR (internal_entity_type='job' AND internal_entity_id=1972) OR (internal_entity_type='client' AND internal_entity_id=1280) OR (internal_entity_type='company' AND internal_entity_id IN (".implode(',',array_fill(0,count($companyIds),'?')).')))', $companyIds);
    $out=['kind'=>'thunderhawk_core_source_20261030','read_only'=>true,'captured_at'=>gmdate('c'),
        'source_tenant'=>$tenant,'person'=>$person,'placement'=>$placement,'accounting_entity'=>$entity,
        'client'=>$client,'job_client'=>$jobClient,'job'=>$job,'companies'=>$companies,'rates'=>$rates,'jobdiva_mappings'=>$mappings,
        'limits'=>['Stored Core source, not a newly fetched JobDiva response.','Source approvals are preserved as evidence, not granted to the pilot.','No passwords, API credentials, bank details, SSNs or payroll history included.']];
    $pdo->rollBack();echo json_encode($out,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),"\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
