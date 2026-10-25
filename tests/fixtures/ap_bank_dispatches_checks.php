<?php
/** Called by the isolated allocation MariaDB fixture; uses its original-bill seed and both SQL sessions. */
function exerciseApBankDispatches(PDO $pdo,PDO $other,callable $seed,callable $check,callable $fails,callable $guard,string $date): void
{
    require_once __DIR__.'/../../core/ap_bank_dispatches.php';
    foreach([1,2] as $_)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../../core/migrations/180_ap_bank_dispatches.sql')) as $sql)$pdo->exec($sql);
    $opts=['tenant_id'=>1,'user_id'=>8,'effective_date'=>$date,'company_name'=>'Synthetic company','company_id'=>'1234567890','origin_routing'=>'021000021','service_class'=>'credits_only'];
    $item=static function(int $id)use($seed):array{
        return paymentRailsBuildItem($seed($id,1)+['routing'=>'021000021','account'=>'12345678','account_type'=>'checking','sec_code'=>'ccd','description'=>'AP-PAY']);
    };
    $dispatch=static fn(string $rail,array $items,callable $send)=>apBankDispatch($pdo,1,$rail,$items,$opts,$send);
    $nacha=paymentRailsGetDriver('nacha');$sent=0;
    $format=static function($items,$options)use($nacha,$pdo,&$sent):array{
        $sent++;if(!$pdo->inTransaction())throw new RuntimeException('Bank file must commit with its original claims');
        return $nacha->originate($items,$options);
    };
    $provider=static function($items,$options)use($pdo,$other,&$sent):array{
        $sent++;if($pdo->inTransaction())throw new RuntimeException('External provider IO inside SQL transaction');
        foreach($items as $it){$id=(int)substr($it['external_ref'],11);if(!apBankClaim($other,1,$id))throw new RuntimeException('Claim not committed before provider access');}
        return ['batch_id'=>$options['batch_id'],'status'=>'submitted','items'=>array_map(static fn($it)=>[
            'external_ref'=>$it['external_ref'],'rail_external_ref'=>'fixture-transfer-'.substr($it['external_ref'],11),'status'=>'pending'], $items)];
    };
    $one=$item(201);$two=$item(202);$before=$sent;
    $result=$dispatch('nacha',[$two,$one],$format);
    $check($sent===$before+1 && $result['ap_dispatch_recorded'] && count($result['items'])===2,'original bank file and both AP claims commit together');
    $again=$dispatch('nacha',[$one,$two],$format);
    $check($sent===$before+1 && apBankJson($again)===apBankJson($result),'identical reordered batch returns its original saved bytes without rerunning the formatter');
    $file=apBankDelivery($pdo,1,201,true);$view=apBankDelivery($pdo,1,202);
    $check(base64_decode($file['nacha_file_b64'])===$result['payload']['content']
        && $file['file_sha256']===hash('sha256',$result['payload']['content']) && count($view['payments'])===2,
        'either payment recovers the same full original batch and file hash');
    $check(!isset($view['nacha_file_b64']) && !str_contains(json_encode($view),'12345678')
        && !str_contains(json_encode($view),'021000021'),'saved-delivery review does not disclose bank instructions or file contents');
    $q=$pdo->query('SELECT request_ct,result_ct FROM ap_bank_dispatches LIMIT 1');$stored=$q->fetch(PDO::FETCH_ASSOC);
    $check(!str_contains($stored['request_ct'],'12345678') && !str_contains($stored['result_ct'],$result['payload']['content'])
        && str_contains((string)decryptField($stored['request_ct']),'12345678'),'bank instructions and original files are encrypted at rest');
    $check($fails(fn()=>apBankDelivery($pdo,2,201,true)),'foreign company cannot retrieve the bank batch or file');
    foreach([[$one],[$one,array_replace($two,['amount_cents'=>12400])]] as $changed)
        $check($fails(fn()=>$dispatch('nacha',$changed,$format)) && $sent===$before+1,'changed or incomplete batch cannot replace original bank instructions');
    $check($fails(fn()=>$dispatch('plaid_transfer',[$one,$two],$provider)) && $sent===$before+1,'claimed bank-file payments cannot be sent again online');
    $check($guard(201,'clear')===false && $fails(fn()=>$guard(201,'allocate')) && $fails(fn()=>$guard(201,'void')),
        'recorded file retains its claims while allowing only the existing explicit manual bank-clear workflow');
    $pdo->exec('UPDATE ap_payments SET rail_external_ref=NULL WHERE id=201');
    $check($fails(fn()=>purepayAllocationPrepare($pdo,1,201,$one,$date)) && $fails(fn()=>mpCreateFromApPayment(1,201,8))
        && $fails(fn()=>$guard(201,'clear')),'missing bank-file reverse link cannot be adopted by PurePay or Mercury or cleared');
    $fresh=$item(203);$before=$sent;
    $check($fails(fn()=>$dispatch('nacha',[$fresh,$one],$format)) && !apBankClaim($pdo,1,203) && $sent===$before,
        'overlapping mixed batch fails without partially claiming a fresh payment');
    $pdo->exec("CREATE TRIGGER fail_bank_file BEFORE UPDATE ON ap_bank_dispatches FOR EACH ROW
        BEGIN IF NEW.rail='nacha' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic result storage failure'; END IF; END");
    $check($fails(fn()=>$dispatch('nacha',[$fresh],$format)) && !apBankClaim($pdo,1,203)
        && !$pdo->query('SELECT rail_external_ref FROM ap_payments WHERE id=203')->fetchColumn(),
        'bank-file persistence failure rolls back every claim before a file can escape');
    $pdo->exec('DROP TRIGGER fail_bank_file');
    $result=$dispatch('nacha',[$fresh],$format);$check($result['ap_dispatch_recorded'],'rolled-back formatter attempt can prepare a fresh atomic file');
    $fresh=$item(204);$before=$sent;$secondCalls=0;
    $formatWithRace=static function($items,$options)use($other,$opts,$check,&$secondCalls,$format):array{
        $code=0;try{apBankDispatch($other,1,'plaid_transfer',$items,$opts,static function()use(&$secondCalls){$secondCalls++;return [];});}
        catch(PDOException $e){$code=(int)($e->errorInfo[1]??0);}
        $check($code===1205 && $secondCalls===0,'second SQL session cannot dispatch a payment while its bank file is being committed');
        return $format($items,$options);
    };
    $dispatch('nacha',[$fresh],$formatWithRace);
    $fresh=$item(205);$before=$sent;
    $lost=static function($items,$options)use($provider):array{$provider($items,$options);throw new RuntimeException('Synthetic provider reply lost');};
    $check($fails(fn()=>$dispatch('plaid_transfer',[$fresh],$lost)) && $sent===$before+1
        && apBankDelivery($pdo,1,205)['state']==='needs_review','lost online response preserves a visible durable reservation');
    $check($fails(fn()=>$dispatch('plaid_transfer',[$fresh],$provider)) && $sent===$before+1,
        'retry after a lost online response never calls the provider again');
    foreach(['clear','void','allocate'] as $action)$check($fails(fn()=>$guard(205,$action)),'uncertain online delivery prevents AP '.$action);
    $check($fails(fn()=>apBankDelivery($pdo,1,205,true)),'unknown online outcome cannot return a bank file');
    $pdo->exec('UPDATE ap_payments SET rail_external_ref=NULL WHERE id=205');
    $check($fails(fn()=>purepayAllocationPrepare($pdo,1,205,$fresh,$date)) && $fails(fn()=>mpCreateFromApPayment(1,205,8)),
        'unknown online claim remains exclusive even after loss of its AP reverse reference');
    $a=$item(206);$b=$item(207);$before=$sent;
    $partial=static function($items,$options)use($provider):array{
        $r=$provider($items,$options);$r['items'][1]['status']='failed';$r['items'][1]['rail_external_ref']=null;
        $r['items'][1]['error']='synthetic ambiguous network failure';return $r;
    };
    $partialResult=$dispatch('plaid_transfer',[$a,$b],$partial);
    $check($partialResult['status']==='unknown' && $partialResult['items'][1]['status']==='unknown'
        && str_starts_with($partialResult['items'][1]['rail_external_ref'],'core:ap:')
        && apBankClaim($other,1,206)['provider_ref']==='fixture-transfer-206','partial batch keeps the known original receipt and holds its unknown sibling');
    $dispatch('plaid_transfer',[$a,$b],$partial);
    $check($sent===$before+1 && count(apBankDelivery($pdo,1,207)['payments'])===2,'partial batch replay and review do not resend either payment');
    $fresh=$item(208);
    $badReceipt=static function($items,$options)use($provider):array{
        $r=$provider($items,$options);$r['items'][0]['rail_external_ref']='fixture-transfer-206';return $r;
    };
    $check($fails(fn()=>$dispatch('plaid_transfer',[$fresh],$badReceipt)) && !apBankClaim($pdo,1,208)['provider_ref']
        && apBankDelivery($pdo,1,208)['state']==='needs_review','one online receipt cannot be adopted into another AP payment');
    $fresh=$item(209);$before=$sent;
    $badIdentity=static function($items,$options)use($provider):array{$r=$provider($items,$options);$r['items'][0]['external_ref']='ap_payment:999999';return $r;};
    $check($fails(fn()=>$dispatch('plaid_transfer',[$fresh],$badIdentity)) && $sent===$before+1
        && apBankDelivery($pdo,1,209)['state']==='needs_review','wrong result identity holds the original payment instead of binding an unrelated response');
    $fresh=$item(210);$pdo->beginTransaction();$before=$sent;
    $check($fails(fn()=>$dispatch('plaid_transfer',[$fresh],$provider)) && $sent===$before,'caller transaction cannot defer an online claim until after provider IO');$pdo->rollBack();
    $pdo->exec('UPDATE ap_payments SET created_by_user_id=8 WHERE id=210');
    $check($fails(fn()=>$dispatch('nacha',[$fresh],$format)) && !apBankClaim($pdo,1,210),'creator cannot prepare the bank file through the shared dispatcher');
    $pdo->exec('UPDATE ap_payments SET created_by_user_id=NULL WHERE id=210');
    foreach([['currency'=>'CAD'],['amount_cents'=>12500.1],['recipient_full_name'=>'Wrong supplier']] as $change)
        $check($fails(fn()=>$dispatch('nacha',[array_replace($fresh,$change)],$format)) && !apBankClaim($pdo,1,210),
            'bank dispatch rejects changed currency, money or supplier before any durable attempt');
    $check($fails(fn()=>apBankDispatch($pdo,2,'nacha',[$fresh],array_replace($opts,['tenant_id'=>2]),$format))
        && $fails(fn()=>apBankDispatch($pdo,1,'nacha',[$fresh],array_replace($opts,['user_id'=>0]),$format)),
        'foreign company and absent operator cannot claim the original payment');
    $pdo->exec('UPDATE ap_bills SET pwp_status="awaiting_ar" WHERE id=2100');
    $check($fails(fn()=>$dispatch('nacha',[$fresh],$format)) && !apBankClaim($pdo,1,210),'bank file rechecks original bill release under the AP lock');
    $pdo->exec('UPDATE ap_bills SET pwp_status=NULL WHERE id=2100');
    $pdo->exec('UPDATE ap_bills SET entity_id=12 WHERE id=2100');
    $check($fails(fn()=>$dispatch('nacha',[$fresh],$format)) && !apBankClaim($pdo,1,210),'supplier bills in another legal entity cannot enter the bank file');
    $pdo->exec('UPDATE ap_bills SET entity_id=11 WHERE id=2100');
    $result=paymentRailsDispatch('ap',['tenant_id'=>1,'actor_user_id'=>8,'effective_date'=>$date,'disbursement_rail'=>'nacha'],
        ['nacha_company_id'=>'1234567890','nacha_origin_routing'=>'021000021'],[$fresh]);
    $check($result['ap_dispatch_recorded'] && apBankClaim($other,1,210)!==null,'actual AP helper uses the durable original-file path');
    $fresh=$item(211);purepayAllocationPrepare($pdo,1,211,$fresh,$date);$pdo->exec('UPDATE ap_payments SET rail_external_ref=NULL WHERE id=211');
    $check($fails(fn()=>$dispatch('nacha',[$fresh],$format)) && !apBankClaim($pdo,1,211),'historical PurePay claim blocks a new bank file despite a missing reverse link');
    $fresh=$item(212);mpCreateFromApPayment(1,212,8);$pdo->exec('UPDATE ap_payments SET rail_external_ref=NULL WHERE id=212');
    $check($fails(fn()=>$dispatch('nacha',[$fresh],$format)) && !apBankClaim($pdo,1,212),'historical Mercury draft blocks a new bank file despite a missing reverse link');
}
