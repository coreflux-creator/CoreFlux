<?php
/** Core review observations; never a command to approve source time or execute funds. */
declare(strict_types=1);
require_once __DIR__.'/core_contract.php';

const QUANTA_TIME_REVIEW_VERSION='2026-10-24.1';

function quantaTimeReviewBody(array $body): array
{
    $fields=['contract_version','assignment_id','assignment_version','source_entry_id','source_revision','core_tenant_id','core_revision','expected_version','components'];
    if(array_diff($fields,array_keys($body)) || array_diff(array_keys($body),$fields)
        || $body['contract_version']!==QUANTA_TIME_REVIEW_VERSION) throw new InvalidArgumentException('Complete time review publication required');
    foreach(['assignment_version','core_tenant_id','expected_version'] as $f)
        if(!is_int($body[$f]) || $body[$f]<($f==='expected_version'?0:1)) throw new InvalidArgumentException('Invalid review version or owner');
    foreach(['source_revision','core_revision','assignment_id','source_entry_id'] as $f) {
        $pattern=match($f){'assignment_id'=>'/^cfa_[a-f0-9]{64}$/D','source_entry_id'=>'/^[A-Za-z0-9_-]{1,128}$/D',default=>'/^[a-f0-9]{64}$/D'};
        if(!is_string($body[$f]) || !preg_match($pattern,$body[$f])) throw new InvalidArgumentException('Invalid time review identity');
    }
    if(!is_array($body['components']) || !array_is_list($body['components']) || !$body['components'] || count($body['components'])>7) throw new InvalidArgumentException('Complete time component list required');
    $ids=[];$components=[];
    foreach($body['components'] as $c) {
        if(!is_array($c) || count($c)!==3 || array_diff(array_keys($c),['entry_id','component','status'])
            || !is_int($c['entry_id']??null) || $c['entry_id']<=0
            || !in_array($c['component']??null,['regular','overtime','doubletime','pto_vacation','pto_holiday','pto_sick','pto_bereavement'],true)
            || !in_array($c['status']??null,['draft','pending_review','approved','rejected'],true)
            || isset($ids[$c['entry_id']]) || isset($components[$c['component']])) throw new InvalidArgumentException('Invalid or duplicate time component');
        $ids[$c['entry_id']]=true;$components[$c['component']]=true;
    }
    return $body;
}

function quantaTimeReviewExpected(string $workspace,array $body): array
{
    $body=quantaTimeReviewBody($body);
    if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$workspace)) throw new InvalidArgumentException('Invalid review workspace');
    $version=$body['expected_version']+1;unset($body['expected_version']);
    $states=array_column($body['components'],'status');
    $state=in_array('rejected',$states,true)?'needs_correction':(count(array_unique($states))===1 && $states[0]==='approved'?'accepted':'pending_review');
    return ['id'=>'cft_'.integrationDeliveryHash([$workspace,$body['assignment_id'],$body['source_entry_id']]),
        'tenant_id'=>$workspace,'assignment_id'=>$body['assignment_id'],'source_entry_id'=>$body['source_entry_id'],
        'version'=>$version,'publication'=>$body,'state'=>$state];
}

/** Exact Python receipt hash: sorted ASCII JSON with unescaped slashes. */
function quantaTimeReviewRequestHash(array $body): string
{
    return hash('sha256',json_encode(integrationDeliveryCanonical(quantaTimeReviewBody($body)),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
}
