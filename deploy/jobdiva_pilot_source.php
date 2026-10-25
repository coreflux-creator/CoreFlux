<?php
/** Bounded read-only exact-assignment discovery. Transport and secrets stay with the caller. */
declare(strict_types=1);
require_once __DIR__ . '/../core/jobdiva/assignment_identity.php';
require_once __DIR__ . '/../core/jobdiva/assignment_contract.php';

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
    $report['observed_at'] = gmdate('c');
    return ['report'=>$report, 'contract'=>$contract];
}
