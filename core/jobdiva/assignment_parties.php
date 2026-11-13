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

/** Only company-qualified IDs on the selected assignment identify these parties. */
function jobdivaAssignmentRoleCompanyId(array $payload, string $role): string
{
    $prefixes = match ($role) {
        'msp' => ['msp', 'managedserviceprovider'],
        'prime_vendor' => ['primevendor'],
        'sub_vendor' => ['subvendor', 'subcontractor', 'secondaryvendor'],
        'referrer' => ['referrer', 'referralvendor', 'referralpartner', 'referralagency'],
        default => throw new InvalidArgumentException('Unsupported assignment company role.'),
    };
    $ids = [];
    foreach ([$payload, $payload['_jd_start'] ?? [], $payload['start'] ?? [], $payload['Start'] ?? [], $payload['_jd_contract'] ?? []] as $record) {
        if (!is_array($record) || array_is_list($record)) continue;
        foreach ($record as $key => $value) {
            $key = jobdivaAssignmentContractNormaliseKey((string) $key);
            foreach ($prefixes as $prefix) {
                if ($key === $prefix . 'companyid') {
                    if ($value !== null && !is_string($value) && !is_int($value)) throw new RuntimeException('Unrecognized assignment company ID. Review the source party.',409);
                    $id = jobdivaAssignmentCompanyId($value);
                    if ($id !== '') $ids[$id] = $id;
                } elseif ($key === $prefix . 'company' && is_array($value) && !array_is_list($value)) {
                    foreach ($value as $nestedKey => $nestedValue) {
                        if (!in_array(jobdivaAssignmentContractNormaliseKey((string) $nestedKey), ['id', 'companyid'], true)) continue;
                        if ($nestedValue !== null && !is_string($nestedValue) && !is_int($nestedValue)) throw new RuntimeException('Unrecognized assignment company ID. Review the source party.',409);
                        $id = jobdivaAssignmentCompanyId($nestedValue);
                        if ($id !== '') $ids[$id] = $id;
                    }
                }
            }
        }
    }
    if (count($ids) > 1) throw new RuntimeException('Conflicting JobDiva company IDs for ' . str_replace('_', ' ', $role) . '. Review the source assignment.', 409);
    return $ids ? (string) array_values($ids)[0] : '';
}

/** Names label the selected party; unrelated Starts, jobs and contacts are not evidence. */
function jobdivaAssignmentPartyName(array $payload, array $aliases): string
{
    $keys = array_map('jobdivaAssignmentContractNormaliseKey',$aliases);
    $names = [];
    foreach ([$payload,$payload['_jd_start'] ?? [],$payload['start'] ?? [],$payload['Start'] ?? [],$payload['_jd_contract'] ?? []] as $record) {
        if (!is_array($record) || array_is_list($record)) continue;
        foreach ($record as $key => $value) {
            if (!in_array(jobdivaAssignmentContractNormaliseKey((string) $key),$keys,true)) continue;
            if (is_array($value) && !array_is_list($value)) {
                $values = [];
                foreach ($value as $nestedKey => $nestedValue) {
                    if (in_array(jobdivaAssignmentContractNormaliseKey((string) $nestedKey),['name','companyname','legalname'],true)) $values[] = $nestedValue;
                }
            } else $values = [$value];
            foreach ($values as $candidate) {
                if (!is_string($candidate)) continue;
                $candidate = trim($candidate);
                if ($candidate !== '' && strtolower($candidate) !== 'null') $names[strtolower($candidate)] = $candidate;
            }
        }
    }
    if (count($names) > 1) throw new RuntimeException('The assignment contains conflicting company names. Review the source party.',409);
    return $names ? (string) array_values($names)[0] : '';
}
