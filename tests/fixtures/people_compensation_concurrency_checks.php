<?php
/** Independent connections wait on the actual dated-compensation writer. */
(static function()use($pdo,$tenant,$actor,$spawn,$waiting,$finish,$check):void{
    $pdo->prepare('INSERT INTO people_employees(tenant_id,employee_number,legal_first_name,legal_last_name) VALUES(?,"COMPENSATION-RACE","Synthetic","Compensation race")')->execute([$tenant]);$employee=(int)$pdo->lastInsertId();
    try{
        $base=['pay_type'=>'hourly','pay_rate_cents'=>6500,'pay_frequency'=>'biweekly','currency'=>'USD','effective_from'=>'2026-10-01'];
        $history=peopleCompensationHistory($pdo,$tenant,$employee);$input=['mode'=>'native','entity'=>'compensation','workspace'=>$tenant,'employee_id'=>$employee,'actor'=>$actor,'values'=>$base,'revision'=>$history['history_revision']];
        $pdo->beginTransaction();$first=peopleCompensationAppend($pdo,$tenant,$employee,$actor,$base,$history['history_revision']);
        $worker=$spawn($input);$check($waiting($worker),'Concurrent compensation retry waits on the actual employee/history transaction');
        $pdo->commit();$result=$finish($worker);
        $check($result['ok']&&$result['result']['replayed']&&$result['result']['id']===$first['id'],'Concurrent exact compensation retry returns the committed original receipt');
        $history=peopleCompensationHistory($pdo,$tenant,$employee);$next=array_replace($base,['effective_from'=>'2026-11-01','pay_rate_cents'=>7000]);
        $pdo->beginTransaction();peopleCompensationAppend($pdo,$tenant,$employee,$actor,$next,$history['history_revision']);
        $worker=$spawn(array_replace($input,['values'=>array_replace($next,['pay_rate_cents'=>8000]),'revision'=>$history['history_revision']]));
        $check($waiting($worker),'Conflicting compensation writer waits on the pending current rate');
        $pdo->commit();$result=$finish($worker);
        $check(!$result['ok']&&(int)$result['code']===409&&(int)peopleCompensationActive($pdo,$tenant,$employee,'2026-11-01')['pay_rate_cents']===7000,'Concurrent changed compensation cannot replace the committed rate');
        $history=peopleCompensationHistory($pdo,$tenant,$employee);$next=array_replace($base,['effective_from'=>'2026-12-01','pay_rate_cents'=>7500]);
        $pdo->beginTransaction();peopleCompensationAppend($pdo,$tenant,$employee,$actor,$next,$history['history_revision']);
        $worker=$spawn(array_replace($input,['values'=>$next,'revision'=>$history['history_revision']]));
        $check($waiting($worker),'Compensation writer waits while a predecessor transaction may roll back');
        $pdo->rollBack();$result=$finish($worker);
        $check($result['ok']&&!$result['result']['replayed']&&(int)peopleCompensationActive($pdo,$tenant,$employee,'2026-11-30')['pay_rate_cents']===7000&&(int)peopleCompensationActive($pdo,$tenant,$employee,'2026-12-01')['pay_rate_cents']===7500,'Waiting compensation writer succeeds after rollback and preserves date continuity');
    }finally{
        if($pdo->inTransaction())$pdo->rollBack();
        foreach(['people_change_log','people_compensation']as$table)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=? AND employee_id=?')->execute([$tenant,$employee]);
        $pdo->prepare('DELETE FROM people_employees WHERE tenant_id=? AND id=?')->execute([$tenant,$employee]);
    }
})();
