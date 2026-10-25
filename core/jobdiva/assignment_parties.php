<?php
/** Explicit recipient-of-work evidence, independent of the assignment bill-to company. */
declare(strict_types=1);
// Loaded by assignment_contract.php, whose entry parser this helper uses.

function jobdivaAssignmentEndClientEvidence(array $payload): array
{
    $contract=is_array($payload['_jd_contract']??null)?$payload['_jd_contract']:[];
    if (isset($contract['end_client_review']) && is_array($contract['end_client_review'])) {
        $review=$contract['end_client_review'];
        return ['status'=>$review['status']??'ambiguous','external_id'=>$contract['end_client_company_id']??'',
            'name'=>$contract['end_client_company_name']??'','source'=>'assignment_detail_explicit_end_client'];
    }
    $records=[];
    if (array_key_exists('end_client_company_id',$contract)||array_key_exists('end_client_company_name',$contract)) $records[]=$contract;
    foreach ([$payload,$payload['_jd_start']??[],$payload['start']??[],$payload['Start']??[]] as $row) {
        if (!is_array($row)||array_is_list($row)) continue;
        // Only direct explicit fields and explicit end-client objects in the selected Start.
        $selected=[];
        foreach($row as $key=>$value) {
            if (str_starts_with(jobdivaAssignmentContractNormaliseKey((string)$key),'endclient')) $selected[$key]=$value;
        }
        if ($selected) $records[]=$selected;
    }
    return jobdivaAssignmentExplicitEndClient($records)+['source'=>'start_explicit_end_client'];
}

/** Reject contradictory explicit aliases rather than picking a company by traversal order. */
function jobdivaAssignmentExplicitEndClient(array $records): array
{
    $ids=[];$names=[];
    foreach(jobdivaAssignmentContractEntries($records) as $entry) {
        $key=$entry['norm_key'];$path=strtolower($entry['path']);
        $nested=(bool)preg_match('/(?:^|\.)(?:end_?client(?:_?company)?|end client(?: company)?)\.[^.]+$/i',$path);
        if (in_array($key,['endclientcompanyid','endclientid'],true)||($nested&&in_array($key,['id','companyid'],true))) {
            $id=jobdivaAssignmentCompanyId($entry['value']);
            if ($id!=='') $ids[$id]=$id;
        }
        if (in_array($key,['endclientcompanyname','endclientname','endclient'],true)||($nested&&in_array($key,['name','companyname','legalname'],true))) {
            if (is_scalar($entry['value'])) {
                $name=trim((string)$entry['value']);
                if ($name!==''&&strtolower($name)!=='null') $names[strtolower($name)]=$name;
            }
        }
    }
    $status=count($ids)>1||count($names)>1?'ambiguous':($ids?($names?'resolved':'identity_only'):($names?'name_only':'missing'));
    return ['status'=>$status,'external_id'=>count($ids)===1?(string)array_values($ids)[0]:'',
        'name'=>count($names)===1?(string)array_values($names)[0]:''];
}
