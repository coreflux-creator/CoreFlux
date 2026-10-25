<?php
/** Bounded read-only exact-assignment discovery. Transport and secrets stay with the caller. */
declare(strict_types=1);
require_once __DIR__ . '/../core/jobdiva/assignment_identity.php';
require_once __DIR__ . '/../core/jobdiva/assignment_contract.php';

function fullCoreJobdivaDetailHasExactIdentity(array $row, array $ids): bool
{
    $labels = ['placement'=>['startid','assignmentid'], 'person'=>['candidateid','employeeid'], 'job'=>['jobid']];
    $observed = array_fill_keys(array_keys($labels), []);
    foreach (jobdivaAssignmentContractEntries([$row]) as $entry) {
        foreach ($labels as $entity=>$keys) {
            if (in_array($entry['norm_key'], $keys, true)) $observed[$entity][] = trim((string)$entry['value']);
        }
    }
    foreach ($observed as $entity=>$values) {
        if (array_values(array_unique($values)) !== [$ids[$entity]]) return false;
    }
    return true;
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
        'provider_calls'=>[], 'exact_assignment_verified'=>false, 'exact_detail_verified'=>false];
    $call = static function(string $label, string $method, string $path, ?array $body, ?array $query) use ($request, &$report): array {
        try { $response = $request($method, $path, $body, $query); }
        catch (Throwable $e) {
            $report['provider_calls'][] = ['operation'=>$label, 'status'=>'transport_failed'];
            return [];
        }
        $status = $response['status'] ?? null;
        $report['provider_calls'][] = ['operation'=>$label, 'http_status'=>is_int($status)?$status:null];
        return $status === 200 ? jobdivaAssignmentRowsFromResponse($response['body'] ?? null) : [];
    };
    $starts = $call('search_exact_start','POST','/apiv2/jobdiva/searchStart',
        ['jobId'=>(int)$ids['job'], 'candidateid'=>(int)$ids['person'], 'maxreturned'=>100, 'offset'=>0], null);
    $exact = [];
    foreach ($starts as $row) {
        if (!is_array($row) || jobdivaAssignmentRowId($row) !== $ids['placement']) continue;
        if (jobdivaAssignmentIdentityPluck($row,['candidate id','candidateId','employeeId']) !== $ids['person']
            || jobdivaAssignmentIdentityPluck($row,['job id','jobId']) !== $ids['job']) continue;
        if (empty(jobdivaAssignmentValidate($row,$ids['placement'],'searchStart:criteria_exact')['valid'])) continue;
        $exact[] = $row;
    }
    if (count($exact) !== 1) {
        $report['status'] = count($exact) > 1 ? 'assignment_ambiguous' : 'exact_assignment_unavailable';
        return ['report'=>$report, 'contract'=>null];
    }
    $report['exact_assignment_verified'] = true;
    $start = jobdivaAssignmentMarkVerified($exact[0],$ids['placement'],'searchStart:criteria_exact');
    $rows = $call('assignment_detail_by_start','GET','/apiv2/bi/EmployeeAssignmentRecordsDetail',null,
        ['startId'=>$ids['placement'],'userFieldsName'=>'']);
    $select = static fn(array $values):array => array_values(array_filter($values,
        static fn($row):bool => is_array($row) && fullCoreJobdivaDetailHasExactIdentity($row,$ids)));
    $matching = $select($rows);
    if ($matching === [] && (($report['provider_calls'][1]['http_status'] ?? 0) === 200
        || in_array($report['provider_calls'][1]['http_status'] ?? 0, [400,404], true))) {
        $rows = $call('assignment_detail_by_employee','GET','/apiv2/bi/EmployeeAssignmentRecordsDetail',null,
            ['employeeId'=>$ids['person'],'userFieldsName'=>'']);
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
