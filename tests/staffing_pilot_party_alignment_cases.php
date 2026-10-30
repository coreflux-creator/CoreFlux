<?php
/** Disposable MariaDB cases; the inspector itself must never reconcile or write. */
declare(strict_types=1);
require_once __DIR__.'/../deploy/staffing_pilot_party_alignment.php';

function testPilotPartyAlignment(PDO $pdo,array $pilot,callable $check): void
{
    $pilot['ap_directory_id']=11;
    $contract=['start_id'=>'start001','engagement_type'=>'w2','client_company_id'=>'companyAlpha',
        'end_client_company_id'=>'privateEndClient','end_client_company_name'=>'PRIVATE-END-CLIENT'];
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    try {
        $legacy=fullCoreStaffingPilotPartyAlignment($pdo,$pilot,$contract,'2026-10-25');
        $check($legacy['status']==='compared'&&$legacy['primary_labor_route']==='schema_unavailable'
            &&$legacy['subcontract_link']['current']==='schema_unavailable'
            &&$legacy['persisted_numbers']['subcontract_vendor']==='schema_unavailable',
            'party inspection runs read-only and keeps absent graph and corporate schemas explicit');
    } finally {$pdo->rollBack();}
    // DDL precedes transactions: MariaDB DDL would otherwise commit the fixture.
    $pdo->exec('ALTER TABLE placements ADD COLUMN engagement_type VARCHAR(32),ADD COLUMN end_client_name VARCHAR(200)');
    $pdo->exec('CREATE TABLE placement_corp_details(tenant_id BIGINT,placement_id BIGINT,company_id BIGINT,ap_vendor_id BIGINT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE ap_vendors_index(id BIGINT PRIMARY KEY,tenant_id BIGINT,company_id BIGINT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE placement_economic_parties(id BIGINT PRIMARY KEY,tenant_id BIGINT,placement_id BIGINT,
        role VARCHAR(40),person_id BIGINT,company_id BIGINT,ap_vendor_id BIGINT,settlement_channel VARCHAR(20),
        money_flow VARCHAR(20),active INT,effective_from DATE,effective_to DATE) ENGINE=InnoDB');
    $tables=['placements','people','companies','staffing_clients','placement_corp_details','ap_vendors_index',
        'placement_economic_parties','external_entity_mappings','core_record_numbers'];
    $snapshot=static function()use($pdo,$tables):array{
        $state=[];
        foreach($tables as $table) {
            $rows=array_map('json_encode',$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
            sort($rows,SORT_STRING);$state[$table]=$rows;
        }
        return $state;
    };
    $readCount=0;
    $read=static function(?array $source=null,?array $selected=null)use($pdo,$contract,$pilot,$snapshot,&$readCount):array{
        $before=$snapshot();
        $result=fullCoreStaffingPilotPartyAlignment($pdo,$selected??$pilot,$source??$contract,'2026-10-25');
        if($before!==$snapshot())throw new RuntimeException('Party inspector modified fixture records');
        $readCount++;
        return $result;
    };
    $variation=static function(string $sql,?array $source=null,?array $selected=null)use($pdo,$read):array{
        $pdo->exec('SAVEPOINT party_variation');
        try{$pdo->exec($sql);return $read($source,$selected);}
        finally{$pdo->exec('ROLLBACK TO SAVEPOINT party_variation');$pdo->exec('RELEASE SAVEPOINT party_variation');}
    };
    $original=$snapshot();$pdo->beginTransaction();
    try {
        $pdo->exec('INSERT INTO companies VALUES(79,11,NULL),(80,11,NULL)');
        $pdo->exec("INSERT INTO external_entity_mappings(tenant_id,source_system,internal_entity_type,internal_entity_id,external_id) VALUES
            (11,'jobdiva','company',79,'privateEndClient'),(11,'jobdiva','company',80,'privateSubcontract'),
            (12,'jobdiva','company',88,'privateEndClient')");
        $pdo->exec("UPDATE placements SET engagement_type='w2',end_client_company_id=79,end_client_name='PRIVATE-END-CLIENT' WHERE id=1");
        $pdo->exec('INSERT INTO ap_vendors_index VALUES(90,11,80),(91,11,77),(92,12,80)');
        $pdo->exec("INSERT INTO core_record_numbers VALUES(11,'client',77,'CL-77',77),(11,'company',79,'CO-79',79),
            (11,'company',80,'CO-80',80),(11,'vendor',90,'V-90',90)");
        $pdo->exec("INSERT INTO placement_economic_parties VALUES
            (1,11,1,'worker',1,NULL,NULL,'payroll','payable',1,'2026-05-18',NULL),
            (2,12,1,'worker',4,NULL,NULL,'payroll','payable',1,NULL,NULL),
            (3,11,1,'worker',1,NULL,NULL,'payroll','payable',1,'2027-01-01',NULL),
            (4,11,1,'worker',1,NULL,NULL,'payroll','payable',1,'2026-01-01','2026-10-24'),
            (5,11,1,'worker',1,NULL,NULL,'payroll','payable',0,NULL,NULL),
            (6,11,1,'commission',1,NULL,NULL,'payroll','payable',1,NULL,NULL),
            (7,11,5,'worker',1,NULL,NULL,'payroll','payable',1,NULL,NULL)");
        $base=$read();
        $check($base['end_client']===['source_evidence'=>'resolved','company'=>'matches_fresh_source','name'=>'matches_fresh_source'],
            'explicit end client matches its own company rather than the different billing client');
        $check($base['primary_labor_route']==='matches_source_identity_and_route',
            'current W2 worker route excludes foreign, inactive, future, expired and other-placement parties');
        $check($base['subcontract_link']['current']==='absent'&&$base['subcontract_link']['company']==='not_required_for_source_classification',
            'W2 source does not require a corporate vendor');
        $check($base['persisted_numbers']===['client'=>'allocated','end_client_company'=>'allocated',
            'subcontract_company'=>'reference_missing','subcontract_vendor'=>'reference_missing'],
            'persisted client and company numbers remain distinct from missing subcontract references');
        $check(!str_contains(json_encode($base),'privateEndClient')&&!str_contains(json_encode($base),'PRIVATE-END-CLIENT')
            &&!str_contains(json_encode($base),'CL-77')&&!str_contains(json_encode($base),'CO-79')&&!$base['records_changed'],
            'party report emits fixed statuses without private source names, IDs or number labels');
        $nameOnly=$contract;unset($nameOnly['end_client_company_id']);
        $nameResult=$read($nameOnly);
        $check($nameResult['end_client']['source_evidence']==='name_only'&&$nameResult['end_client']['company']==='source_identity_missing'
            &&$nameResult['end_client']['name']==='matches_fresh_source','matching end-client name cannot establish company identity');
        $billingOnly=$contract;unset($billingOnly['end_client_company_id'],$billingOnly['end_client_company_name']);
        $check($read($billingOnly)['end_client']['source_evidence']==='missing','billing company is never borrowed as end-client evidence');
        $ambiguous=$contract;$ambiguous['end_client_review']=['status'=>'ambiguous'];
        $check($read($ambiguous)['end_client']['company']==='source_identity_needs_review','conflicting explicit end-client evidence stays held');
        $check($variation('UPDATE placements SET end_client_company_id=77 WHERE id=1')['end_client']['company']==='differs_from_fresh_source',
            'a bill-to company incorrectly saved as end client is a difference');
        $check($variation('UPDATE placements SET end_client_company_id=NULL WHERE id=1')['end_client']['company']==='core_company_link_missing',
            'missing Core company link is distinct from a missing provider identity');
        $check($variation("UPDATE placements SET end_client_name='Local override' WHERE id=1")['end_client']['name']==='differs_from_fresh_source',
            'different local end-client name stays visible and is not overwritten');
        $caseId=$contract;$caseId['end_client_company_id']='privateendclient';
        $check($read($caseId)['end_client']['company']==='source_mapping_missing','opaque company IDs are case-sensitive');
        $check($read(null,array_replace($pilot,['company_directory_id'=>12]))['end_client']['company']==='source_company_directory_or_active_record_mismatch',
            'provider mapping cannot establish a company in another directory');
        $check($variation("UPDATE companies SET deleted_at='2026-01-01' WHERE id=79")['end_client']['company']==='source_company_directory_or_active_record_mismatch',
            'deleted companies cannot establish an end-client match');
        $check($variation("INSERT INTO external_entity_mappings(tenant_id,source_system,internal_entity_type,internal_entity_id,external_id)
            VALUES(11,'jobdiva','company',80,'privateEndClient')")['end_client']['company']==='source_mapping_ambiguous',
            'duplicate forward company mapping cannot choose the first row');
        $check($variation("INSERT INTO external_entity_mappings(tenant_id,source_system,internal_entity_type,internal_entity_id,external_id)
            VALUES(11,'jobdiva','company',79,'privateOtherClient')")['end_client']['company']==='source_mapping_ambiguous',
            'conflicting reverse company mapping prevents a match');
        $check($read(null,array_replace($pilot,['company_directory_id'=>null]))['end_client']['company']==='company_directory_not_selected',
            'company directory must be explicitly selected');
        $check($read(null,array_replace($pilot,['person_id'=>4]))['status']==='identity_not_verified',
            'party inspection retains the exact worker and placement identity gate');
        $check($read(array_replace($contract,['start_id'=>'other']))['status']==='contract_assignment_mismatch',
            'party inspection rejects a different source assignment');
        $invalidDate=false;try{fullCoreStaffingPilotPartyAlignment($pdo,$pilot,$contract,'2026-02-30');}
        catch(InvalidArgumentException $e){$invalidDate=true;}
        $check($invalidDate,'party inspection rejects an invalid as-of date');
        foreach([
            "person_id=4"=>'wrong worker',"company_id=80"=>'unexpected company',"ap_vendor_id=90"=>'unexpected AP vendor',
            "money_flow='receivable'"=>'wrong money flow',"settlement_channel='ap'"=>'wrong settlement channel',
        ] as $change=>$label)
            $check($variation('UPDATE placement_economic_parties SET '.$change.' WHERE id=1')['primary_labor_route']==='differs_from_source_identity_or_route',
                'W2 route rejects '.$label);
        $check($variation('UPDATE placement_economic_parties SET active=0 WHERE id=1')['primary_labor_route']==='current_labor_party_missing',
            'inactive worker does not establish a current labor route');
        $check($variation("INSERT INTO placement_economic_parties VALUES(8,11,1,'c2c_vendor',NULL,80,90,'ap','payable',1,NULL,NULL)")['primary_labor_route']==='ambiguous_current_labor_parties',
            'stale simultaneous C2C and W2 labor parties cannot be accepted');
        $check($variation("UPDATE placements SET engagement_type='c2c' WHERE id=1")['primary_labor_route']==='core_classification_differs_from_source',
            'source W2 cannot certify a Core C2C assignment');
        $conflict=$contract;$conflict['classification_review']=['status'=>'resolved','observations'=>[
            ['key'=>'employment_category','value'=>'W2'],['key'=>'engagement_type','value'=>'c2c']]];
        $check($read($conflict)['primary_labor_route']==='source_classification_needs_review',
            'recomputed classification conflicts cannot hide behind a cached resolved flag');
        foreach(['temp_to_perm','internal'] as $type)
            $check($variation("UPDATE placements SET engagement_type='$type' WHERE id=1",array_replace($contract,['engagement_type'=>$type]))['primary_labor_route']==='matches_source_identity_and_route',
                $type.' retains the native payroll route');
        $pdo->exec('INSERT INTO placement_corp_details VALUES(11,1,80,90),(12,1,88,92)');
        $stale=$read();
        $check($stale['subcontract_link']['current']==='present'&&$stale['subcontract_link']['company']==='not_required_for_source_classification',
            'stale W2 corporate facet is visible without becoming a required payee');
        $pdo->exec("UPDATE placements SET engagement_type='c2c' WHERE id=1");
        $pdo->exec("UPDATE placement_economic_parties SET role='c2c_vendor',person_id=NULL,company_id=80,ap_vendor_id=90,settlement_channel='ap' WHERE id=1");
        $c2c=array_replace($contract,['engagement_type'=>'c2c','corporation_id'=>'privateSubcontract']);
        $corporate=$read($c2c);
        $check($corporate['subcontract_link']===['current'=>'present','company'=>'matches_fresh_source','vendor'=>'matches_verified_company']
            &&$corporate['primary_labor_route']==='matches_source_identity_and_route',
            'C2C labor requires the exact mapped corporation and its own AP vendor');
        $check($corporate['persisted_numbers']['subcontract_company']==='allocated'&&$corporate['persisted_numbers']['subcontract_vendor']==='allocated',
            'corporate company and vendor numbers are checked in their explicit directories');
        $check($read($c2c,array_replace($pilot,['ap_directory_id'=>null]))['subcontract_link']['vendor']==='ap_directory_not_selected',
            'corporate AP directory cannot be inferred from another directory');
        $check($variation('UPDATE placement_corp_details SET ap_vendor_id=92 WHERE tenant_id=11',$c2c)['subcontract_link']['vendor']==='vendor_directory_or_record_mismatch',
            'foreign AP vendor cannot satisfy the local corporate facet');
        $check($variation('UPDATE placement_corp_details SET ap_vendor_id=91 WHERE tenant_id=11',$c2c)['subcontract_link']['vendor']==='differs_from_verified_company',
            'vendor attached to a different company is not the source payee');
        $check($variation('UPDATE placement_corp_details SET ap_vendor_id=NULL WHERE tenant_id=11',$c2c)['subcontract_link']['vendor']==='core_vendor_link_missing',
            'missing vendor reference cannot be replaced by company identity alone');
        $check($variation('UPDATE placement_economic_parties SET person_id=1 WHERE id=1',$c2c)['primary_labor_route']==='differs_from_source_identity_or_route',
            'corporate labor route rejects a contradictory individual payee');
        $check($variation('UPDATE placement_economic_parties SET ap_vendor_id=91 WHERE id=1',$c2c)['primary_labor_route']==='differs_from_source_identity_or_route',
            'graph vendor must equal the verified corporate facet');
        $duplicate=$variation('INSERT INTO placement_corp_details VALUES(11,1,77,91)',$c2c);
        $check($duplicate['subcontract_link']['company']==='core_link_ambiguous'
            &&$duplicate['persisted_numbers']['subcontract_company']==='core_link_ambiguous'
            &&$duplicate['persisted_numbers']['subcontract_vendor']==='core_link_ambiguous'
            &&$duplicate['primary_labor_route']==='differs_from_source_identity_or_route',
            'ambiguous corporate facets cannot select first-row payee or number evidence');
        foreach(['0'=> 'source_identity_missing','unknownSubcontract'=>'source_mapping_missing'] as $sourceId=>$expected)
            $check($read(array_replace($c2c,['corporation_id'=>(string)$sourceId]))['subcontract_link']['company']===$expected,
                'absent or unmapped source subcontract identity stays explicit');
        $check($read(array_replace($c2c,['corporation_id'=>['bad']]))['subcontract_link']['company']==='source_identity_invalid',
            'compound source company identity is rejected');
        $check($variation("UPDATE core_record_numbers SET canonical_record_id=99 WHERE entity_type='vendor'",$c2c)['persisted_numbers']['subcontract_vendor']==='alias_requires_review',
            'vendor number aliases cannot silently become canonical identity');
        $check($variation("UPDATE core_record_numbers SET record_number='CO-90' WHERE entity_type='vendor'",$c2c)['persisted_numbers']['subcontract_vendor']==='unrecognized_number',
            'company number prefix cannot masquerade as a vendor number');
        $check($variation("DELETE FROM core_record_numbers WHERE entity_type='vendor'",$c2c)['persisted_numbers']['subcontract_vendor']==='unallocated',
            'missing registry allocation is reported without allocating a number');
        $check($variation("INSERT INTO core_record_numbers VALUES(11,'vendor',90,'V-900',90)",$c2c)['persisted_numbers']['subcontract_vendor']==='ambiguous',
            'multiple registry rows cannot establish one stable vendor number');
        $check($variation('UPDATE placements SET client_id=88 WHERE id=1',$c2c)['persisted_numbers']['client']==='reference_outside_directory_or_inactive',
            'client numbers cannot borrow a foreign staffing client');
        $schema=['companies'=>['id','tenant_id'],'core_record_numbers'=>['tenant_id','entity_type','record_id','record_number','canonical_record_id']];
        $check(fullCorePilotPartyNumber($pdo,11,'company',79,$schema)==='schema_unavailable',
            'company number cannot certify an active reference without deletion-state schema');
        $pdo->exec("UPDATE placements SET engagement_type='1099' WHERE id=1");
        $pdo->exec("UPDATE placement_economic_parties SET role='worker',person_id=1,company_id=NULL WHERE id=1");
        $check($read(array_replace($contract,['engagement_type'=>'1099']))['primary_labor_route']==='individual_vendor_identity_needs_review',
            'individual AP route is not claimed as verified without person-to-vendor identity');
        $check($readCount>0,'all '.$readCount.' party variations preserve every source, worker, placement, company, vendor, graph and number row');
    } finally {$pdo->rollBack();}
    $check($snapshot()===$original,'party fixtures leave existing directory and source records unchanged');
}
