<?php
/** Read-only calculation exports. Payment instructions are a separate reviewed artifact. */
declare(strict_types=1);
require_once __DIR__.'/calculation_review.php';
require_once __DIR__.'/source_earnings.php';
require_once __DIR__.'/../../people/lib/employment_links.php';
require_once __DIR__.'/../../../core/record_numbers.php';

function payrollExportDollars(int|string $cents): string
{
    $text=(string)$cents;
    if (!preg_match('/^-?[0-9]+$/D',$text)) throw new RuntimeException('Payroll cents are invalid.',409);
    $negative=str_starts_with($text,'-');
    $digits=ltrim($negative?substr($text,1):$text,'0');
    if ($digits==='') return '0.00';
    $digits=str_pad($digits,3,'0',STR_PAD_LEFT);
    return ($negative?'-':'').substr($digits,0,-2).'.'.substr($digits,-2);
}

function payrollExportAdd(int $left,int $right): int
{
    if (($right>0 && $left>PHP_INT_MAX-$right) || ($right<0 && $left<PHP_INT_MIN-$right)) {
        throw new RuntimeException('Payroll export total is outside the supported range.',409);
    }
    return $left+$right;
}

/** Run lock also serializes the native computation, approval and export reads. */
function payrollExportRows(int $tenant,int $runId): array
{
    if ($tenant<=0 || $tenant!==(int)currentTenantId() || $runId<=0) {
        throw new RuntimeException('Choose a payroll run in the current workspace.',409);
    }
    $pdo=getDB();$ownsTransaction=!$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $run=payrollCalculationRun($tenant,$runId,true);
        $review='historical_unverified';
        if ((int)$run['computation_revision']>0) {
            payrollCalculationAssert($run,true);
            $review='awaiting_review';
            if (in_array($run['status'],['approved','paid'],true) || !empty($run['approved_at'])) {
                payrollCalculationAssertApproved($run);
                $review='approved';
            }
        }
        $owner=peopleEmploymentOwner($tenant);
        $q=$pdo->prepare('SELECT * FROM payroll_line_items WHERE tenant_id=? AND run_id=? ORDER BY id FOR UPDATE');
        $q->execute([$tenant,$runId]);$lines=$q->fetchAll(PDO::FETCH_ASSOC);$out=[];
        if (count($lines)!==(int)$run['employee_count']) throw new RuntimeException('Payroll employee lines do not match the run. Review the calculation before exporting.',409);
        $grossTotal=0;$netTotal=0;
        foreach ($lines as $line) {
            $q=$pdo->prepare('SELECT id,employee_number,legal_first_name,legal_last_name,work_email,personal_email
                FROM people_employees WHERE tenant_id=? AND id=?');
            $q->execute([$owner,$line['employee_id']]);$employee=$q->fetch(PDO::FETCH_ASSOC);
            if (!$employee) throw new RuntimeException('A payroll employee is unavailable in the current People directory. Review the company ownership before exporting.',409);
            $q=$pdo->prepare('SELECT l.person_id,n.record_number,p.id AS linked_person_id
                FROM people_employment_links l
                LEFT JOIN people p ON p.tenant_id=l.tenant_id AND p.id=l.person_id
                LEFT JOIN core_record_numbers n ON n.tenant_id=l.tenant_id AND n.entity_type="person" AND n.record_id=l.person_id
                WHERE l.tenant_id=? AND l.employee_id=?');
            $q->execute([$owner,$line['employee_id']]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
            if (count($links)>1 || ($links && (!$links[0]['linked_person_id'] || !$links[0]['record_number']))) {
                throw new RuntimeException('The payroll employee person identity needs review before exporting.',409);
            }
            $link=$links[0]??[];
            $q=$pdo->prepare('SELECT id,code,hours,rate_cents,amount_cents,taxable,source_rate,source_multiplier,
                    source_entry_ids_json,source_snapshot_hash
                FROM payroll_earnings WHERE tenant_id=? AND line_item_id=? ORDER BY id FOR UPDATE');
            $q->execute([$tenant,$line['id']]);$earnings=$q->fetchAll(PDO::FETCH_ASSOC);
            $hours=array_fill_keys(['regular','overtime','double_time','pto','holiday','sick'],0);
            $supplement=['bonus'=>0,'commission'=>0,'reimbursement'=>0];$components=[];$total=0;
            foreach ($earnings as $earning) {
                $code=(string)$earning['code'];$amount=(int)$earning['amount_cents'];
                $total=payrollExportAdd($total,$amount);
                if (isset($hours[$code])) $hours[$code]=payrollSourceAdd($hours[$code],payrollSourceDecimal4($earning['hours']??'0','Earning hours'));
                $bucket=match($code) {
                    'bonus','spot_bonus','signing_bonus'=>'bonus',
                    'commission','referral'=>'commission',
                    'reimbursement','expense'=>'reimbursement',default=>null,
                };
                if ($bucket!==null) $supplement[$bucket]=payrollExportAdd($supplement[$bucket],$amount);
                $components[]=array_merge($earning,[
                    'amount_cents'=>(string)$earning['amount_cents'],
                    'amount_dollars'=>payrollExportDollars($earning['amount_cents']),
                ]);
            }
            $source=(string)($line['source_time_json']??'');$sourceHash=(string)($line['source_time_sha256']??'');
            if ($line['earnings_basis']==='approved_time') {
                if ((int)$run['computation_revision']<=0 || !$link || !$source || !hash_equals(hash('sha256',$source),$sourceHash)) {
                    throw new RuntimeException('Approved payroll source evidence or person identity is incomplete. Recompute and review before exporting.',409);
                }
                $receipt=json_decode($source,true,512,JSON_THROW_ON_ERROR);
                if (!is_array($receipt['sources']??null) || !$receipt['sources']) throw new RuntimeException('Approved payroll source evidence is incomplete.',409);
                foreach ($receipt['sources'] as $fact) {
                    if ((int)($fact['tenant_id']??0)!==$tenant || (int)($fact['person_id']??0)!==(int)$link['person_id']) {
                        throw new RuntimeException('Approved payroll source identity no longer matches the employee. Review the saved employment link.',409);
                    }
                }
            }
            if ($total!==(int)$line['gross_cents']) throw new RuntimeException('Payroll earnings do not match the saved gross amount. Review the calculation before exporting.',409);
            $grossTotal=payrollExportAdd($grossTotal,(int)$line['gross_cents']);
            $netTotal=payrollExportAdd($netTotal,(int)$line['net_cents']);
            $out[]=[
                'run_id'=>(string)$runId,'run_status'=>$run['status'],'line_item_id'=>(string)$line['id'],
                'pay_date'=>$run['pay_date'],'period_start'=>$run['period_start'],'period_end'=>$run['period_end'],
                'calculation_review_status'=>$review,'computation_revision'=>(string)$run['computation_revision'],
                'computation_sha256'=>(string)($run['computation_sha256']??''),
                'employee_id'=>(string)$employee['id'],'employee_number'=>$employee['employee_number'],
                'employee_external_id'=>'','person_id'=>isset($link['person_id'])?(string)$link['person_id']:'',
                'person_number'=>$link['record_number']??'','people_owner_tenant_id'=>(string)$owner,
                'employee_first_name'=>$employee['legal_first_name'],'employee_last_name'=>$employee['legal_last_name'],
                'employee_email'=>$employee['work_email']?:($employee['personal_email']??''),
                'regular_hours'=>payrollSourceDecimalString($hours['regular']),
                'overtime_hours'=>payrollSourceDecimalString($hours['overtime']),
                'doubletime_hours'=>payrollSourceDecimalString($hours['double_time']),
                'pto_hours'=>payrollSourceDecimalString($hours['pto']),
                'holiday_hours'=>payrollSourceDecimalString($hours['holiday']),
                'sick_hours'=>payrollSourceDecimalString($hours['sick']),
                'earnings_basis'=>$line['earnings_basis'],
                'earnings_components_json'=>json_encode($components,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
                'source_time_json'=>$source,'source_time_sha256'=>$sourceHash,
                'gross_pay_cents'=>(string)$line['gross_cents'],'gross_pay_dollars'=>payrollExportDollars($line['gross_cents']),
                'net_pay_cents'=>(string)$line['net_cents'],'net_pay_dollars'=>payrollExportDollars($line['net_cents']),
                'bonus_dollars'=>payrollExportDollars($supplement['bonus']),
                'commission_dollars'=>payrollExportDollars($supplement['commission']),
                'reimbursement_dollars'=>payrollExportDollars($supplement['reimbursement']),
                'bank_account_number'=>'','bank_routing_number'=>'','bank_account_type'=>'',
            ];
        }
        if ($grossTotal!==(int)$run['gross_total_cents'] || $netTotal!==(int)$run['net_total_cents']) {
            throw new RuntimeException('Payroll line totals do not match the run. Review the calculation before exporting.',409);
        }
        if ($ownsTransaction) $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Custom mappings must not turn source earnings into an hours-only repricing file. */
function payrollExportAssertTemplate(array $template,array $rows): void
{
    $fields=[];
    foreach ($template['column_mappings']??[] as $mapping) {
        if (($mapping['kind']??'field')!=='fixed') $fields[(string)($mapping['source_field']??'')]=true;
    }
    if (array_intersect(['bank_account_number','bank_routing_number','bank_account_type'],array_keys($fields))) {
        throw new RuntimeException('This calculation has no reviewed bank instructions. Use the payment workflow; current employee bank details cannot substitute for reviewed payment instructions.',409);
    }
    if (isset($fields['employee_external_id'])) throw new RuntimeException('This dataset has no provider-specific employee mapping. Select Core employee number or Core person number instead; neither is an external provider ID.',409);
    if (!array_intersect(['regular_hours','overtime_hours','doubletime_hours','pto_hours','holiday_hours','sick_hours'],array_keys($fields))) return;
    foreach ($rows as $row) {
        if (($row['earnings_basis']??'')!=='approved_time') continue;
        $required=['earnings_basis','earnings_components_json','source_time_json','source_time_sha256','computation_sha256'];
        if (array_diff($required,array_keys($fields)) || (!isset($fields['gross_pay_cents']) && !isset($fields['gross_pay_dollars']))) {
            throw new RuntimeException('This hours template omits approved rates and amounts. Choose Payroll calculation audit or include earnings basis, earnings details, approved time receipt and fingerprint, calculation fingerprint, and gross pay. Provider hours imports cannot preserve this calculation.',409);
        }
    }
}
