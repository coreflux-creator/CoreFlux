<?php
/** Native endpoint on the existing synthetic user's People write grant. */
(static function()use($pdo,$tenant,$request,$expect,$compensation):void{
    $pdo->prepare('INSERT INTO people_employees(tenant_id,employee_number,legal_first_name,legal_last_name) VALUES(?,"HTTP-COMPENSATION","Synthetic","Compensation")')->execute([$tenant]);$employee=(int)$pdo->lastInsertId();
    $query=static function(string $table)use($pdo,$tenant,$employee):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=? AND employee_id=? ORDER BY id');$q->execute([$tenant,$employee]);return $q->fetchAll(PDO::FETCH_ASSOC);};
    try{
        [$status,$context]=$request($compensation.'?employee_id='.$employee,null);
        $expect($status===200&&$context['compensation']===[]&&preg_match('/^[a-f0-9]{64}$/D',$context['history_revision']),'Native compensation GET exposes a revision for the employee history');
        $body=['employee_id'=>$employee,'pay_type'=>'hourly','pay_rate_cents'=>6500,'pay_frequency'=>'biweekly','currency'=>'USD','effective_from'=>'2026-10-01'];
        $expect($request($compensation,$body)[0]===422,'Native compensation requires the displayed history revision');
        $body['history_revision']=$context['history_revision'];
        [$status,$first]=$request($compensation,$body);
        $expect($status===201&&!$first['replayed']&&count($query('people_compensation'))===1,'Native compensation writes one audited rate on canonical schema');
        $before=[$query('people_compensation'),$query('people_change_log')];
        [$status,$again]=$request($compensation,$body);
        $expect($status===200&&$again['replayed']&&$again['id']===$first['id']&&$before===[$query('people_compensation'),$query('people_change_log')],'Native compensation exact retry preserves its result and audit count');
        $expect($request($compensation,array_replace($body,['pay_rate_cents'=>7000]))[0]===409,'Native changed retry is rejected');
        $expect($request($compensation,array_replace($body,['effective_from'=>'2026-11-01']))[0]===409,'Native stale compensation history is rejected');
        [$status,$context]=$request($compensation.'?employee_id='.$employee,null);
        $next=array_replace($body,['effective_from'=>'2026-11-01','pay_rate_cents'=>7000,'history_revision'=>$context['history_revision']]);
        $expect($request($compensation,$next)[0]===201,'Native reviewed next rate closes its predecessor and creates the new row');
        $rows=$query('people_compensation');
        $expect(count($rows)===2&&$rows[0]['effective_to']==='2026-10-31'&&$rows[1]['effective_to']===null&&count($query('people_change_log'))===3,'Native history and required audits are retained together');
        $expect($request($compensation.'?employee_id='.$employee,null,true,999)[0]===403,'Foreign company cannot read compensation through a pinned request');
        $expect($request($compensation,$next,true,999)[0]===403,'Foreign company cannot mutate compensation through a pinned request');
        [$status,$context]=$request($compensation.'?employee_id='.$employee,null);
        $expect($status===200&&(int)$context['compensation'][0]['pay_rate_cents']===7000&&$context['compensation'][1]['effective_to']==='2026-10-31','Native employee history shows newest first with the retained final old-rate day');
    }finally{
        foreach(['people_change_log','people_compensation']as$table)$pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=? AND employee_id=?')->execute([$tenant,$employee]);
        $pdo->prepare('DELETE FROM people_employees WHERE tenant_id=? AND id=?')->execute([$tenant,$employee]);
    }
})();
