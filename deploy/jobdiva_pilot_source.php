<?php
/** Bounded read-only exact-assignment discovery. Transport and secrets stay with the caller. */
declare(strict_types=1);
require_once __DIR__ . '/../core/jobdiva/assignment_identity.php';
require_once __DIR__ . '/../core/jobdiva/assignment_contract.php';

/** Conservative contact comparison only; no inferred country, address or identity. */
function fullCorePilotPersonValue(mixed $value, string $field): array
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

/** The person payload is private; only the fixed status report may be logged. */
function fullCoreJobdivaReadPerson(string $candidate, callable $request): array
{
    if (!preg_match('/^[1-9][0-9]{0,17}$/D',$candidate)) throw new InvalidArgumentException('Exact candidate ID required');
    $report=['status'=>'source_unavailable','source_refreshed'=>false,'record_writes'=>false,
        'exact_person_verified'=>false,'http_status'=>null,'provider_calls'=>[]];
    $finish=static fn(array $r,?array $person=null):array=>['report'=>$r,'person'=>$person];
    $read=static function(string $label,string $method,string $path,?array $body,?array $query)use($request,&$report):array{
        try{$response=$request($method,$path,$body,$query);}
        catch(Throwable){$report['status']='transport_failed';$report['http_status']=null;
            $report['provider_calls'][]=['operation'=>$label,'http_status'=>null];return [];}
        $report['http_status']=is_int($response['status']??null)?$response['status']:null;
        $report['provider_calls'][]=['operation'=>$label,'http_status'=>$report['http_status']];
        return $report['http_status']===200?jobdivaAssignmentRowsFromResponse($response['body']??null):[];
    };
    $rows=$read('candidate_search','POST','/apiv2/jobdiva/searchCandidate',['candidateId'=>$candidate],null);
    // Existing ID-based mirror route, narrowed to this one candidate. Never
    // broaden an authorization failure, service failure or conflicting result.
    if(in_array($report['http_status'],[404,405],true)||($report['http_status']===200&&$rows===[]))
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
            $normalized=fullCorePilotPersonValue($value,$field);
            $invalid=$invalid||$normalized['status']==='unrecognized';
            if($normalized['status']==='present')$values[]=$normalized['value'];
        }
        $values=array_values(array_unique($values));
        $fields[$field]=['status'=>$invalid?'unrecognized':(count($values)>1?'ambiguous':($values?'present':'missing')),
            'value'=>!$invalid&&count($values)===1?$values[0]:null];
    }
    $report['status']='exact_person_observed';$report['source_refreshed']=true;$report['exact_person_verified']=true;
    $report['fields']=array_map(static fn(array $field):string=>$field['status'],$fields);
    return $finish($report,['candidate_id'=>$candidate,'fields'=>$fields]);
}

function fullCoreJobdivaDetailIdentity(array $row, array $ids): array
{
    $labels = ['placement'=>['startid','assignmentid'], 'person'=>['candidateid','employeeid'], 'job'=>['jobid']];
    $observed = array_fill_keys(array_keys($labels), []);
    foreach (jobdivaAssignmentContractEntries([$row]) as $entry) {
        foreach ($labels as $entity=>$keys) {
            if (in_array($entry['norm_key'], $keys, true)) $observed[$entity][] = trim((string)$entry['value']);
        }
    }
    $result=[];
    foreach ($observed as $entity=>$values) {
        $values=array_values(array_unique($values));
        $result[$entity]=$values===[$ids[$entity]]?'exact_match':($values===[]?'missing':(count($values)>1?'ambiguous':'mismatch'));
    }
    return $result;
}

function fullCoreJobdivaDetailHasExactIdentity(array $row, array $ids): bool
{
    return array_values(array_unique(fullCoreJobdivaDetailIdentity($row,$ids)))===['exact_match'];
}

function fullCoreJobdivaStartHasExactIdentity(array $row, array $ids): bool
{
    if (jobdivaAssignmentRowId($row)!==$ids['placement']
        || jobdivaAssignmentIdentityValues($row,true)!==[$ids['placement']]) return false;
    // The searchStart generic ID is unambiguous only after the structural check above.
    $row['START_ID']=$ids['placement'];
    return fullCoreJobdivaDetailHasExactIdentity($row,$ids);
}

function fullCoreJobdivaDetailDiagnostics(array $rows, array $ids): array
{
    return array_map(static function($row)use($ids):array {
        if (!is_array($row)) return ['shape'=>'non_record'];
        $sections=[];
        foreach (['BILLING','SALARY','JOB'] as $section) {
            $sectionRows=jobdivaAssignmentContractSectionRows([$row],$section);
            if ($sectionRows!==[]) {
                $sections[$section]=['rows'=>count($sectionRows),'generic_id_matches_selected_assignment'=>false];
                foreach ($sectionRows as $sectionRow) {
                    if (jobdivaAssignmentIdentityPluck($sectionRow,['id'])===$ids['placement']) {
                        $sections[$section]['generic_id_matches_selected_assignment']=true;
                    }
                }
            }
        }
        return ['identity'=>fullCoreJobdivaDetailIdentity($row,$ids),'sections'=>$sections];
    },$rows);
}

/** Counts and fixed reason codes only: never serialize provider values or field names. */
function fullCoreJobdivaStartDiagnostics(array $rows, array $ids): array
{
    $result=['parsed_rows'=>count($rows),'rows_with_expected_id'=>0,'rows_with_expected_person'=>0,
        'rows_with_expected_job'=>0,'rows_with_expected_person_and_job'=>0,'expected_id_checks'=>[]];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $person=jobdivaAssignmentIdentityPluck($row,['candidate id','candidateId','employeeId']);
        $job=jobdivaAssignmentIdentityPluck($row,['job id','jobId']);
        if ($person===$ids['person']) $result['rows_with_expected_person']++;
        if ($job===$ids['job']) $result['rows_with_expected_job']++;
        if ($person===$ids['person'] && $job===$ids['job']) $result['rows_with_expected_person_and_job']++;
        // Diagnostic only: generic ID presence must not confer assignment eligibility.
        if (!in_array($ids['placement'],jobdivaAssignmentIdentityValues($row,true),true)) continue;
        $result['rows_with_expected_id']++;
        $structural=jobdivaAssignmentStructuralEvidence($row);
        $lifecycle=jobdivaAssignmentLifecycleEvidence($row,'searchStart:criteria_exact');
        $reason=$lifecycle['reason']??'';
        $reason=in_array($reason,['qualified_assignment_state','rejected_assignment_state',
            'offer_not_accepted','unqualified_assignment_state','missing_assignment_state'],true)?$reason:'unknown';
        $result['expected_id_checks'][]=[
            'assignment_identity_unambiguous'=>jobdivaAssignmentIdentityValues($row,true)===[$ids['placement']],
            'assignment_row_recognized'=>jobdivaAssignmentRowId($row)===$ids['placement'],
            'person_echo'=>$person===''?'missing':($person===$ids['person']?'exact_match':'mismatch'),
            'job_echo'=>$job===''?'missing':($job===$ids['job']?'exact_match':'mismatch'),
            'start_date_present'=>$structural['start_date']!=='',
            'lifecycle_reason'=>$reason,
        ];
    }
    return $result;
}

/** Private return contains a contract; only its comparison report may be logged. */
function fullCoreJobdivaReadPilot(array $ids, callable $request): array
{
    foreach (['person','placement','job'] as $entity) {
        $id = $ids[$entity] ?? null;
        if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id) || strlen($id) > strlen((string)PHP_INT_MAX)
            || (strlen($id) === strlen((string)PHP_INT_MAX) && strcmp($id, (string)PHP_INT_MAX) > 0)) {
            throw new InvalidArgumentException('Exact supported numeric JobDiva pilot identifiers required');
        }
    }
    $report = ['status'=>'source_unavailable','source_refreshed'=>false,'record_writes'=>false,
        'provider_calls'=>[], 'exact_assignment_verified'=>false, 'exact_detail_verified'=>false,
        'import_authorized'=>false];
    $call = static function(string $label, string $method, string $path, ?array $body, ?array $query) use ($request, &$report): array {
        try { $response = $request($method, $path, $body, $query); }
        catch (Throwable $e) {
            $report['provider_calls'][] = ['operation'=>$label, 'status'=>'transport_failed'];
            return [];
        }
        $status = $response['status'] ?? null;
        $callReport=['operation'=>$label, 'http_status'=>is_int($status)?$status:null];
        $body=$response['body']??null;
        $rows=$status===200?jobdivaAssignmentRowsFromResponse($body):[];
        if ($status===200) {
            $callReport['response_shape']=is_array($body)?(array_is_list($body)?'list':'object'):(is_string($body)?'text':'other');
            $callReport['parsed_rows']=count($rows);
            if (is_array($body)) {
                foreach (['data','items','starts','records','results'] as $key) {
                    if (isset($body[$key]) && is_array($body[$key])) { $callReport['recognized_envelope']=$key;break; }
                }
            }
        }
        $report['provider_calls'][]=$callReport;
        return $rows;
    };
    $starts = $call('search_exact_start','POST','/apiv2/jobdiva/searchStart',
        ['jobId'=>(int)$ids['job'], 'candidateid'=>(int)$ids['person'], 'maxreturned'=>100, 'offset'=>0], null);
    $report['start_diagnostics']=fullCoreJobdivaStartDiagnostics($starts,$ids);
    $exact = [];
    foreach ($starts as $row) {
        if (!is_array($row) || !fullCoreJobdivaStartHasExactIdentity($row,$ids)) continue;
        $exact[] = $row;
    }
    if (count($exact) !== 1) {
        $report['status'] = count($exact) > 1 ? 'assignment_ambiguous' : 'exact_assignment_unavailable';
        return ['report'=>$report, 'contract'=>null];
    }
    $report['exact_assignment_verified'] = true;
    // Reading a known source record is separate from authorizing an import.
    // Preserve the source lifecycle hold; never synthesize a trusted/eligible marker.
    $start=$exact[0];
    $lifecycle=jobdivaAssignmentLifecycleEvidence($start,'searchStart:criteria_exact');
    $report['start_lifecycle']=['qualified'=>(bool)$lifecycle['qualified'],'reason'=>$lifecycle['reason']];
    $rows = $call('assignment_detail_by_start','GET','/apiv2/bi/EmployeeAssignmentRecordsDetail',null,
        ['startId'=>$ids['placement'],'userFieldsName'=>'']);
    $report['detail_diagnostics']['by_start']=fullCoreJobdivaDetailDiagnostics($rows,$ids);
    $select = static fn(array $values):array => array_values(array_filter($values,
        static fn($row):bool => is_array($row) && fullCoreJobdivaDetailHasExactIdentity($row,$ids)));
    $matching = $select($rows);
    if ($matching === [] && (($report['provider_calls'][1]['http_status'] ?? 0) === 200
        || in_array($report['provider_calls'][1]['http_status'] ?? 0, [400,404], true))) {
        $rows = $call('assignment_detail_by_employee','GET','/apiv2/bi/EmployeeAssignmentRecordsDetail',null,
            ['employeeId'=>$ids['person'],'userFieldsName'=>'']);
        $report['detail_diagnostics']['by_employee']=fullCoreJobdivaDetailDiagnostics($rows,$ids);
        $matching = $select($rows);
    }
    if (count($matching) !== 1) {
        $report['status'] = count($matching) > 1 ? 'assignment_detail_ambiguous' : 'exact_assignment_detail_unavailable';
        return ['report'=>$report, 'contract'=>null];
    }
    $contract = jobdivaAssignmentContractBuild($matching,$start,$ids['placement']);
    if (($contract['start_id'] ?? null) !== $ids['placement']) {
        $report['status'] = 'contract_identity_mismatch';
        return ['report'=>$report,'contract'=>null];
    }
    $report['status'] = 'exact_source_observed';
    $report['source_refreshed'] = true;
    $report['exact_detail_verified'] = true;
    $report['contract_lifecycle'] = [
        'status'=>in_array($contract['placement_status'] ?? '', ['active','pending_start','draft','ended'], true)
            ? $contract['placement_status'] : 'unresolved',
        'billing_approved'=>$contract['approved'] ?? null,
        'salary_approved'=>$contract['salary_approved'] ?? null,
        'actual_start'=>$contract['actual_start'] ?? null,
        'actual_end'=>$contract['actual_end'] ?? null,
        'billing_closed'=>$contract['closed'] ?? null,
        'salary_closed'=>$contract['salary_closed'] ?? null,
    ];
    $report['observed_at'] = gmdate('c');
    return ['report'=>$report, 'contract'=>$contract];
}
