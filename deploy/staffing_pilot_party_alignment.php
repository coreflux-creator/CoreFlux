<?php
/** Exact pilot party comparisons. Fixed statuses only, no graph reconciliation or writes. */
declare(strict_types=1);
require_once __DIR__.'/staffing_pilot_alignment.php';

function fullCorePilotPartyCompany(PDO $pdo,array $pilot,mixed $sourceId,mixed $currentId,array $schema): string
{
    $owner=$pilot['company_directory_id']??null;
    if (!is_int($owner)||$owner<=0) return 'company_directory_not_selected';
    foreach (['companies'=>['id','tenant_id','deleted_at'],
        'external_entity_mappings'=>['tenant_id','source_system','internal_entity_type','internal_entity_id','external_id']] as $table=>$needed)
        if (array_diff($needed,$schema[$table]??[])) return 'schema_unavailable';
    if ($sourceId!==null&&!is_string($sourceId)&&!is_int($sourceId)) return 'source_identity_invalid';
    $sourceId=jobdivaAssignmentCompanyId($sourceId);
    if ($sourceId==='') return 'source_identity_missing';
    if (strlen($sourceId)>128||preg_match('/[\x00-\x1f\x7f]/',$sourceId)) return 'source_identity_invalid';
    $s=$pdo->prepare('SELECT internal_entity_id,external_id FROM external_entity_mappings
        WHERE tenant_id=:t AND source_system="jobdiva" AND internal_entity_type="company" AND BINARY external_id=BINARY :e');
    $s->execute(['t'=>$pilot['tenant_id'],'e'=>$sourceId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1) return $rows?'source_mapping_ambiguous':'source_mapping_missing';
    $mapped=(int)$rows[0]['internal_entity_id'];
    $s=$pdo->prepare('SELECT external_id FROM external_entity_mappings WHERE tenant_id=:t
        AND source_system="jobdiva" AND internal_entity_type="company" AND internal_entity_id=:i');
    $s->execute(['t'=>$pilot['tenant_id'],'i'=>$mapped]);
    if ($s->fetchAll(PDO::FETCH_COLUMN)!==[$sourceId]) return 'source_mapping_ambiguous';
    $s=$pdo->prepare('SELECT COUNT(*) FROM companies WHERE id=:i AND tenant_id=:t AND deleted_at IS NULL');
    $s->execute(['i'=>$mapped,'t'=>$owner]);
    if ((int)$s->fetchColumn()!==1) return 'source_company_directory_or_active_record_mismatch';
    if (!$currentId) return 'core_company_link_missing';
    return (int)$currentId===$mapped?'matches_fresh_source':'differs_from_fresh_source';
}

function fullCorePilotPartyNumber(PDO $pdo,int $owner,string $kind,mixed $id,array $schema): string
{
    if (!$id) return 'reference_missing';
    $definitions=['company'=>['companies','CO-'],'client'=>['staffing_clients','CL-'],'vendor'=>['ap_vendors_index','V-']];
    [$table,$prefix]=$definitions[$kind];
    $required=$kind==='company'?['id','tenant_id','deleted_at']:['id','tenant_id'];
    if (array_diff(['tenant_id','entity_type','record_id','record_number','canonical_record_id'],$schema['core_record_numbers']??[])
        ||array_diff($required,$schema[$table]??[])) return 'schema_unavailable';
    $active=$kind==='company'?' AND deleted_at IS NULL':'';
    $s=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE id=:i AND tenant_id=:t'.$active);
    $s->execute(['i'=>$id,'t'=>$owner]);
    if ((int)$s->fetchColumn()!==1) return 'reference_outside_directory_or_inactive';
    $s=$pdo->prepare('SELECT record_number,canonical_record_id FROM core_record_numbers WHERE tenant_id=:t AND entity_type=:k AND record_id=:i');
    $s->execute(['t'=>$owner,'k'=>$kind,'i'=>$id]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1) return $rows?'ambiguous':'unallocated';
    if ((int)$rows[0]['canonical_record_id']!==(int)$id) return 'alias_requires_review';
    return preg_match('/^'.preg_quote($prefix,'/').'[1-9][0-9]*$/D',(string)$rows[0]['record_number'])?'allocated':'unrecognized_number';
}

function fullCoreStaffingPilotPartyAlignment(PDO $pdo,array $pilot,array $contract,string $asOf): array
{
    $result=['status'=>'identity_not_verified','records_changed'=>false,'as_of'=>$asOf,
        'scope'=>'Explicit end-client identity, subcontract links, current primary labor routing and persisted company/client/vendor numbers.',
        'limits'=>['Name agreement alone does not establish a company or payee identity.',
            'Current graph routing does not establish approved compensation, payroll readiness or payment authorization.',
            'Referral, commission, additional parties and custom values need separate review.']];
    if (fullCorePilotAlignedValue($asOf,'start_date')!==$asOf) throw new InvalidArgumentException('Explicit calendar date required');
    $identity=fullCoreStaffingPilotInventory($pdo,$pilot);
    if ($identity['status']!=='observed') return $result;
    foreach (['person','placement','job'] as $kind)
        if (($identity['jobdiva_identity'][$kind]['status']??null)!=='exact_match') return $result;
    if (($contract['start_id']??null)!==$pilot['jobdiva_ids']['placement']) return array_replace($result,['status'=>'contract_assignment_mismatch']);
    $schema=[];
    foreach (['placements','companies','staffing_clients','placement_corp_details','ap_vendors_index',
        'placement_economic_parties','external_entity_mappings','core_record_numbers'] as $table) {
        $s=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t');
        $s->execute(['t'=>$table]);$schema[$table]=$s->fetchAll(PDO::FETCH_COLUMN);
    }
    $selected=array_values(array_intersect(['id','client_id','end_client_company_id','end_client_name','engagement_type'],$schema['placements']));
    $s=$pdo->prepare('SELECT '.implode(',',$selected).' FROM placements WHERE id=:i AND tenant_id=:t AND person_id=:p AND deleted_at IS NULL');
    $s->execute(['i'=>$pilot['placement_id'],'t'=>$pilot['tenant_id'],'p'=>$pilot['person_id']]);$placement=$s->fetch(PDO::FETCH_ASSOC);
    if (!$placement) return $result;
    $result['status']='compared';
    $end=jobdivaAssignmentEndClientEvidence(['_jd_contract'=>$contract]);
    $endStatus=in_array($end['status'],['resolved','identity_only','name_only','missing','ambiguous'],true)?$end['status']:'unrecognized';
    $result['end_client']=['source_evidence'=>$endStatus,
        'company'=>!array_key_exists('end_client_company_id',$placement)?'core_column_unavailable':
            (in_array($endStatus,['ambiguous','unrecognized'],true)?'source_identity_needs_review':
                fullCorePilotPartyCompany($pdo,$pilot,$end['external_id'],$placement['end_client_company_id'],$schema)),
        'name'=>'source_name_missing'];
    if (in_array($endStatus,['ambiguous','unrecognized'],true)) $result['end_client']['name']='source_name_needs_review';
    elseif (($end['name']??'')!=='') {
        $result['end_client']['name']=!array_key_exists('end_client_name',$placement)?'core_column_unavailable':
            (trim((string)$placement['end_client_name'])===''?'core_name_missing':
                (trim((string)$placement['end_client_name'])===$end['name']?'matches_fresh_source':'differs_from_fresh_source'));
    }
    $corp=[];$corpAvailable=!array_diff(['tenant_id','placement_id','company_id','ap_vendor_id'],$schema['placement_corp_details']);
    if ($corpAvailable) {
        $s=$pdo->prepare('SELECT company_id,ap_vendor_id FROM placement_corp_details WHERE tenant_id=:t AND placement_id=:p LIMIT 2');
        $s->execute(['t'=>$pilot['tenant_id'],'p'=>$pilot['placement_id']]);$corp=$s->fetchAll(PDO::FETCH_ASSOC);
    }
    $result['subcontract_link']=['current'=>!$corpAvailable?'schema_unavailable':(count($corp)>1?'ambiguous':(!$corp?'absent':
        (!empty($corp[0]['company_id'])||!empty($corp[0]['ap_vendor_id'])?'present':'empty'))),'company'=>'source_classification_needs_review','vendor'=>'not_compared'];
    $classification=jobdivaClassificationFromPayload(['_jd_contract'=>$contract]);
    $type=$classification['status']==='resolved'?$classification['engagement_type']:null;
    if ($type!==null&&$type!=='c2c') $result['subcontract_link']['company']='not_required_for_source_classification';
    elseif ($type==='c2c') {
        $result['subcontract_link']['company']=!$corpAvailable?'schema_unavailable':(count($corp)>1?'core_link_ambiguous':
            fullCorePilotPartyCompany($pdo,$pilot,$contract['corporation_id']??null,$corp[0]['company_id']??null,$schema));
        $owner=$pilot['ap_directory_id']??null;
        if (!is_int($owner)||$owner<=0) $result['subcontract_link']['vendor']='ap_directory_not_selected';
        elseif (array_diff(['id','tenant_id','company_id'],$schema['ap_vendors_index'])) $result['subcontract_link']['vendor']='schema_unavailable';
        elseif ($result['subcontract_link']['company']!=='matches_fresh_source') $result['subcontract_link']['vendor']='company_identity_not_verified';
        elseif (empty($corp[0]['ap_vendor_id'])) $result['subcontract_link']['vendor']='core_vendor_link_missing';
        else {
            $s=$pdo->prepare('SELECT company_id FROM ap_vendors_index WHERE id=:i AND tenant_id=:t');
            $s->execute(['i'=>$corp[0]['ap_vendor_id'],'t'=>$owner]);$vendor=$s->fetchAll(PDO::FETCH_COLUMN);
            $result['subcontract_link']['vendor']=count($vendor)!==1?'vendor_directory_or_record_mismatch':
                ((int)$vendor[0]===(int)$corp[0]['company_id']?'matches_verified_company':'differs_from_verified_company');
        }
    }
    $result['primary_labor_route']='schema_unavailable';
    if (!array_diff(['id','tenant_id','placement_id','role','person_id','company_id','ap_vendor_id','settlement_channel','money_flow','active','effective_from','effective_to'],$schema['placement_economic_parties'])) {
        if ($type===null) $result['primary_labor_route']='source_classification_needs_review';
        elseif (!array_key_exists('engagement_type',$placement)) $result['primary_labor_route']='core_classification_unavailable';
        elseif (jobdivaClassificationValue($placement['engagement_type'])!==$type) $result['primary_labor_route']='core_classification_differs_from_source';
        elseif (!in_array($type,['w2','temp_to_perm','internal','c2c','1099'],true)) $result['primary_labor_route']='requires_separate_engagement_review';
        else {
            $s=$pdo->prepare('SELECT role,person_id,company_id,ap_vendor_id,settlement_channel,money_flow FROM placement_economic_parties
                WHERE tenant_id=:t AND placement_id=:p AND active=1 AND role IN ("worker","c2c_vendor")
                AND (effective_from IS NULL OR effective_from<=:start) AND (effective_to IS NULL OR effective_to>=:end) ORDER BY id LIMIT 2');
            $s->execute(['t'=>$pilot['tenant_id'],'p'=>$pilot['placement_id'],'start'=>$asOf,'end'=>$asOf]);$parties=$s->fetchAll(PDO::FETCH_ASSOC);
            if (count($parties)!==1) $result['primary_labor_route']=$parties?'ambiguous_current_labor_parties':'current_labor_party_missing';
            else {
                $party=$parties[0];$matched=$party['money_flow']==='payable';
                if ($type==='c2c') $matched=$matched&&$party['role']==='c2c_vendor'&&$party['settlement_channel']==='ap'&&empty($party['person_id'])
                    &&$result['subcontract_link']['vendor']==='matches_verified_company'
                    &&(int)$party['company_id']===(int)$corp[0]['company_id']&&(int)$party['ap_vendor_id']===(int)$corp[0]['ap_vendor_id'];
                else $matched=$matched&&$party['role']==='worker'&&(int)$party['person_id']===$pilot['person_id']
                    &&$party['settlement_channel']===($type==='1099'?'ap':'payroll')&&empty($party['company_id'])
                    &&($type==='1099'||empty($party['ap_vendor_id']));
                $result['primary_labor_route']=!$matched?'differs_from_source_identity_or_route':
                    ($type==='1099'?'individual_vendor_identity_needs_review':'matches_source_identity_and_route');
            }
        }
    }
    $result['persisted_numbers']=[];
    foreach (['client'=>[$pilot['tenant_id'],'client',$placement['client_id']??null],
        'end_client_company'=>[$pilot['company_directory_id']??null,'company',$placement['end_client_company_id']??null],
        'subcontract_company'=>[$pilot['company_directory_id']??null,'company',$corp[0]['company_id']??null],
        'subcontract_vendor'=>[$pilot['ap_directory_id']??null,'vendor',$corp[0]['ap_vendor_id']??null]] as $role=>[$owner,$kind,$id]) {
        if (str_starts_with($role,'subcontract_')&&(!$corpAvailable||count($corp)>1))
            $result['persisted_numbers'][$role]=!$corpAvailable?'schema_unavailable':'core_link_ambiguous';
        elseif ($role==='client'&&!array_key_exists('client_id',$placement)
            ||$role==='end_client_company'&&!array_key_exists('end_client_company_id',$placement))
            $result['persisted_numbers'][$role]='core_column_unavailable';
        else $result['persisted_numbers'][$role]=!is_int($owner)||$owner<=0?'directory_not_selected':fullCorePilotPartyNumber($pdo,$owner,$kind,$id,$schema);
    }
    return $result;
}
