<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/jobdiva/person_review.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void {
    if (!$ok) throw new RuntimeException($label);
    $passed++; echo "PASS $label\n";
};
$fails=static function(callable $fn):bool {
    try {$fn(); return false;} catch (Throwable $e) {
        if (str_contains($e->getMessage(),'PRIVATE')) throw new RuntimeException('Provider detail exposed');
        return true;
    }
};
$candidate=['CANDIDATEID'=>'1001','first name'=>'Synthetic','last name'=>'Person',
    'primary email'=>'SYNTHETIC@example.test','phone 1'=>'+1 (555) 123-4567'];
$run=static function(array $responses,array &$calls,string $id='1001'):array {
    $calls=[];
    $caller=static function($tenant,$method,$path,$body,$query,$expectedAbsent)use(&$responses,&$calls):array {
        $calls[]=[$tenant,$method,$path,$body,$query,$expectedAbsent];
        if ($responses===[]) throw new LogicException('Unexpected extra source read');
        $next=array_shift($responses);
        if ($next instanceof Throwable) throw $next;
        return $next;
    };
    $transport=static fn($method,$path,$body,$query)=>jobdivaPersonReviewRequestSource(10,$method,$path,$body,$query,$caller);
    return jobdivaPersonReviewSource(10,$id,null,$transport);
};
$calls=[];
$profile=$run([[$candidate]],$calls);
$check($profile===['candidate_id'=>'1001','name'=>'Synthetic Person','email'=>'synthetic@example.test','phone'=>'+15551234567'],
    'native review recognizes BI primary contact aliases and keeps the exact source identity');
$check($calls===[[10,'POST','/apiv2/jobdiva/searchCandidate',['candidateId'=>'1001'],null,[404,405]]],
    'successful native search remains one tenant-scoped call');
foreach ([new JobdivaHttpException('PRIVATE',404),new JobdivaHttpException('PRIVATE',405),[],['data'=>[]]] as $empty) {
    $profile=$run([$empty,['data'=>[$candidate]]],$calls);
    $check($profile['candidate_id']==='1001'&&count($calls)===2,
        'absent or empty search reaches the exact BI candidate through the normal review');
    $check($calls[1]===[10,'GET','/apiv2/bi/CandidatesDetail',null,['candidateIds'=>'1001','userFieldsName'=>''],[]],
        'fallback retains tenant and one original candidate; BI failures still degrade normally');
}
foreach ([401,403,429,500] as $status) {
    $check($fails(function()use($run,$status,&$calls){$run([new JobdivaHttpException('PRIVATE', $status)],$calls);})&&count($calls)===1,
        'authorization, rate and service errors never broaden the lookup or leak response text');
}
$check($fails(function()use($run,&$calls){$run([new RuntimeException('PRIVATE HTTP 404')],$calls);})&&count($calls)===1,
    'a status-looking error message cannot trigger fallback');
foreach ([[$candidate,$candidate],[array_replace($candidate,['id'=>'999'])],
    [array_replace($candidate,['CANDIDATEID'=>true])],[['candidateId'=>'999','firstName'=>'PRIVATE']]] as $rows) {
    $check($fails(function()use($run,$rows,&$calls){$run([$rows],$calls);})&&count($calls)===1,
        'duplicate, wrong, invalid or conflicting identity stops without another lookup');
}
foreach ([[],[array_replace($candidate,['CANDIDATEID'=>'999'])],[$candidate,$candidate]] as $rows) {
    $check($fails(function()use($run,$rows,&$calls){$run([new JobdivaHttpException('PRIVATE',404),$rows],$calls);})&&count($calls)===2,
        'fallback must still prove exactly one original candidate');
}
foreach (['email'=>'other@example.test','phone'=>'+15550000000','firstName'=>'Another'] as $key=>$value) {
    $check($fails(fn()=>$run([[array_replace($candidate,[$key=>$value])]],$calls)),
        'native review rejects contradictory contact and name aliases');
}
$check($fails(fn()=>$run([[array_replace($candidate,['primary email'=>['PRIVATE']])]],$calls)),
    'structured contact evidence is not cast into a review');
$blank=['candidate_id'=>'1001','fullName'=>'Synthetic Person'];
$check($run([[$blank]],$calls)===['candidate_id'=>'1001','name'=>'Synthetic Person','email'=>'','phone'=>''],
    'full-name-only candidates and absent optional contacts remain reviewable');
$check($fails(fn()=>$run([[array_replace($blank,['name'=>'Other Person'])]],$calls)),
    'conflicting full-name aliases cannot choose the review identity');
$check($fails(fn()=>$run([[['id'=>'1001']]],$calls)), 'candidate without a name cannot be reviewed');
$check($fails(function()use($run,&$calls){$run([],$calls,'1001 OR 1=1');})&&$calls===[],
    'invalid source ID is rejected before the authenticated client');
$big='123456789012345678';
$check($run([[array_replace($candidate,['CANDIDATEID'=>$big])]],$calls,$big)['candidate_id']===$big
    &&$calls[0][3]===['candidateId'=>$big], 'large source IDs are preserved as strings');
foreach ([null,'PRIVATE malformed response',false] as $malformed) {
    $requests=0;
    $check($fails(function()use($malformed,&$requests) {
        jobdivaPersonReviewSource(10,'1001',null,static function()use($malformed,&$requests):array {
            $requests++; return ['status'=>200,'body'=>$malformed];
        });
    })&&$requests===1,'malformed search responses cannot be mistaken for an empty result');
}
$check($fails(fn()=>jobdivaPersonReviewSource(10,'1001',static fn()=>[],static fn()=>[])),
    'test dependencies cannot silently override one another');
echo "JobDiva native candidate source: $passed passed\n";
