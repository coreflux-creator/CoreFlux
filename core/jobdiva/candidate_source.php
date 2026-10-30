<?php
/** Exact candidate reads shared by identity review and private pilot inspection. */
declare(strict_types=1);
require_once __DIR__.'/assignment_identity.php';

/** Conservative contact comparison only; no inferred country, address or identity. */
function jobdivaCandidateCompareValue(mixed $value, string $field): array
{
    if ($value===null || $value==='') return ['status'=>'missing','value'=>null];
    if (!is_string($value) || strlen($value)>1024 || !preg_match('//u',$value)
        || preg_match('/[\x00-\x1f\x7f]/',$value)) return ['status'=>'unrecognized','value'=>null];
    $text=trim($value);
    if ($text==='' || strtolower($text)==='null') return ['status'=>'missing','value'=>null];
    if ($field==='email_primary') {
        if (!filter_var($text,FILTER_VALIDATE_EMAIL)) return ['status'=>'unrecognized','value'=>null];
        $text=strtolower($text);
    } elseif ($field==='phone_primary') {
        $text=preg_replace('/[(). -]/','',$text);
        if (!preg_match('/^\+?[0-9]{7,15}$/D',$text)) return ['status'=>'unrecognized','value'=>null];
    } else {
        $text=preg_replace('/ +/',' ',$text);
    }
    return ['status'=>'present','value'=>$text];
}

/** The person and row payloads are private; only the fixed status report may be logged. */
function jobdivaCandidateReadExact(string $candidate, callable $request): array
{
    if (!preg_match('/^[1-9][0-9]{0,17}$/D',$candidate)) throw new InvalidArgumentException('Exact candidate ID required');
    $report=['status'=>'source_unavailable','source_refreshed'=>false,'record_writes'=>false,
        'exact_person_verified'=>false,'http_status'=>null,'provider_calls'=>[]];
    $finish=static fn(array $r,?array $person=null,?array $row=null):array=>['report'=>$r,'person'=>$person,'row'=>$row];
    $emptyResult=false;
    $read=static function(string $label,string $method,string $path,?array $body,?array $query)use($request,&$report,&$emptyResult):array{
        $emptyResult=false;
        try{$response=$request($method,$path,$body,$query);}
        catch(Throwable){$report['status']='transport_failed';$report['http_status']=null;
            $report['provider_calls'][]=['operation'=>$label,'http_status'=>null];return [];}
        $report['http_status']=is_int($response['status']??null)?$response['status']:null;
        $report['provider_calls'][]=['operation'=>$label,'http_status'=>$report['http_status']];
        $body=$response['body']??null;
        $rows=$report['http_status']===200?jobdivaAssignmentRowsFromResponse($body):[];
        $emptyResult=$report['http_status']===200&&is_array($body)&&$rows===[];
        return $rows;
    };
    $rows=$read('candidate_search','POST','/apiv2/jobdiva/searchCandidate',['candidateId'=>$candidate],null);
    // Existing ID-based mirror route, narrowed to this one candidate. Never
    // broaden an authorization failure, service failure or conflicting result.
    if(in_array($report['http_status'],[404,405],true)||$emptyResult)
        $rows=$read('candidate_detail','GET','/apiv2/bi/CandidatesDetail',null,['candidateIds'=>$candidate,'userFieldsName'=>'']);
    if($report['http_status']!==200)return $finish($report);
    $report['parsed_rows']=count($rows);
    if(count($rows)!==1||!is_array($rows[0])){$report['status']='exact_person_unavailable';return $finish($report);}
    $row=$rows[0];$ids=[];
    foreach($row as $key=>$value){
        $key=strtolower(preg_replace('/[^a-z0-9]/i','',(string)$key));
        if(!in_array($key,['id','candidateid'],true))continue;
        if(!is_string($value)&&!is_int($value)){$report['status']='candidate_identity_invalid';return $finish($report);}
        $ids[]=(string)$value;
    }
    if(!$ids||array_values(array_unique($ids))!==[$candidate]){$report['status']='candidate_identity_mismatch';return $finish($report);}
    $fields=[];
    foreach(['first_name'=>['firstname','candidatefirstname'],'last_name'=>['lastname','candidatelastname'],
        'email_primary'=>['email','emailaddress','candidateemail','emailprimary','primaryemail'],
        'phone_primary'=>['phone','phonenumber','candidatephone','phoneprimary','phone1']] as $field=>$aliases){
        $values=[];$invalid=false;
        foreach($row as $key=>$value){
            if(!in_array(strtolower(preg_replace('/[^a-z0-9]/i','',(string)$key)),$aliases,true))continue;
            $normalized=jobdivaCandidateCompareValue($value,$field);
            $invalid=$invalid||$normalized['status']==='unrecognized';
            if($normalized['status']==='present')$values[]=$normalized['value'];
        }
        $values=array_values(array_unique($values));
        $fields[$field]=['status'=>$invalid?'unrecognized':(count($values)>1?'ambiguous':($values?'present':'missing')),
            'value'=>!$invalid&&count($values)===1?$values[0]:null];
    }
    $report['status']='exact_person_observed';$report['source_refreshed']=true;$report['exact_person_verified']=true;
    $report['fields']=array_map(static fn(array $field):string=>$field['status'],$fields);
    return $finish($report,['candidate_id'=>$candidate,'fields'=>$fields],$row);
}

/** Canonical review values; conflicting aliases must never pick the first contact. */
function jobdivaCandidateReviewProfile(array $read): array
{
    $person=$read['person']??null;
    if (!$person || empty($read['report']['exact_person_verified'])) {
        throw new RuntimeException('JobDiva could not verify exactly one candidate. Check the source ID and connection, then review again');
    }
    $values=[];
    foreach (['first_name','last_name','email_primary','phone_primary'] as $field) {
        $value=$person['fields'][$field]??[];
        if (!in_array($value['status']??null,['present','missing'],true)) {
            throw new RuntimeException('JobDiva returned conflicting or unrecognized candidate details. Review the source record');
        }
        $values[$field]=$value['value']??'';
    }
    $name=trim($values['first_name'].' '.$values['last_name']);
    if ($name==='') {
        $names=[];
        foreach ($read['row']??[] as $key=>$value) {
            if (!in_array(strtolower(preg_replace('/[^a-z0-9]/i','',(string)$key)),['candidatename','fullname','name'],true)) continue;
            $normalized=jobdivaCandidateCompareValue($value,'name');
            if ($normalized['status']==='unrecognized') throw new RuntimeException('JobDiva returned an unrecognized candidate name');
            if ($normalized['status']==='present') $names[]=$normalized['value'];
        }
        $names=array_values(array_unique($names));
        if (count($names)!==1) throw new RuntimeException('JobDiva returned no unambiguous candidate name to review');
        $name=$names[0];
    }
    return ['candidate_id'=>$person['candidate_id'],'name'=>$name,'email'=>$values['email_primary'],'phone'=>$values['phone_primary']];
}

