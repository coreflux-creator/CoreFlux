<?php
/** Pure, non-executing projection of approved staffing economics and current configuration. */
declare(strict_types=1);
require_once __DIR__.'/delivery.php';

const PUREPAY_ASSIGNMENT_INPUT_VERSION = '2026-10-22.2';

function purepayAssignmentRequired(array $row,string $key): mixed
{
    if(!array_key_exists($key,$row))throw new RuntimeException('The placement source is missing '.$key.'. Refresh its commercial snapshot before export.');
    return $row[$key];
}

function purepayAssignmentId(mixed $value,bool $nullable=false): ?int
{
    if($value===null && $nullable)return null;
    if((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$value))throw new RuntimeException('A commercial source identity is invalid.');
    return (int)$value;
}

function purepayAssignmentText(mixed $value,bool $nullable=false,int $limit=255): ?string
{
    if($nullable && ($value===null || $value===''))return null;
    if(!is_string($value) || trim($value)!==$value || $value==='' || preg_match('/[\x00-\x1f\x7f]/',$value)
        || ($length=preg_match_all('/./us',$value))===false || $length>$limit)throw new RuntimeException('Review missing or invalid commercial text before export.');
    return $value;
}

function purepayAssignmentDate(mixed $value,bool $nullable=false): ?string
{
    if($value===null && $nullable)return null;
    $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
    if(!$date || $date->format('Y-m-d')!==$value || $value==='9999-12-31')throw new RuntimeException('A placement or commercial date is invalid.');
    return $value;
}

function purepayAssignmentBool(mixed $value): bool
{
    if(in_array($value,[true,1,'1'],true))return true;
    if(in_array($value,[false,0,'0'],true))return false;
    throw new RuntimeException('A commercial setting must be explicitly true or false.');
}

function purepayAssignmentChoice(mixed $value,array $choices): string
{
    if(!is_string($value) || !in_array($value,$choices,true))throw new RuntimeException('A source commercial value has no supported field mapping.');
    return $value;
}

/** Preserve JSON numeric lexemes before decoding; quoted text, booleans and null retain their types. */
function purepayAssignmentSnapshotJson(string $json): array
{
    if(strlen($json)>200000)throw new RuntimeException('The approved economic snapshot is too large.');
    json_decode($json,true,64,JSON_THROW_ON_ERROR);
    $quoted=preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/',
        static fn(array $m):string=>json_encode($m[0],JSON_THROW_ON_ERROR),$json);
    $data=json_decode($quoted,true,64,JSON_THROW_ON_ERROR);
    if(!is_array($data) || array_is_list($data))throw new RuntimeException('An approved economic snapshot is required.');
    return $data;
}

/** Exact decimal normalization, including exponent-form legacy JSON; never round away source precision. */
function purepayAssignmentDecimal(mixed $value,int $scale=6,int $digits=10,bool $signed=false,bool $nullable=false): ?string
{
    if($value===null && $nullable)return null;
    if(is_int($value))$value=(string)$value;
    if(!is_string($value) || strlen($value)>80 || !preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]{1,3}))?$/D',$value,$m))throw new RuntimeException('An economic decimal is invalid; floating-point inputs are not accepted.');
    $negative=$m[1]==='-';$fraction=$m[3]??'';$exponent=(int)($m[4]??0);
    if(abs($exponent)>40)throw new RuntimeException('An economic decimal exponent is outside the supported range.');
    $raw=$m[2].$fraction;$point=strlen($m[2])+$exponent;
    if($point<0){$raw=str_repeat('0',-$point).$raw;$point=0;}
    if($point>strlen($raw))$raw.=str_repeat('0',$point-strlen($raw));
    $whole=ltrim(substr($raw,0,$point),'0')?:'0';$fraction=substr($raw,$point);
    if(strlen($whole)>$digits || trim(substr($fraction,$scale),'0')!=='' || ($negative && !$signed))throw new RuntimeException('An economic decimal exceeds the supported precision or range.');
    $fraction=str_pad(substr($fraction,0,$scale),$scale,'0');
    return ($negative && trim($whole.$fraction,'0')!==''?'-':'').$whole.($scale?'.'.$fraction:'');
}

function purepayAssignmentMultiply(string $rate,?string $multiplier): ?string
{
    if($multiplier===null)return null;
    // SQL rate DECIMAL(10,4) and multiplier DECIMAL(4,2) fit exactly in a 64-bit integer product.
    $a=(int)str_replace('.','',purepayAssignmentDecimal($rate,4,6));
    $b=(int)str_replace('.','',purepayAssignmentDecimal($multiplier,2,2));
    $product=(string)($a*$b);$product=str_pad($product,7,'0',STR_PAD_LEFT);
    return substr($product,0,-6).'.'.substr($product,-6);
}

function purepayAssignmentTerms(?string $terms,bool $pwp=false): ?array
{
    if($terms===null)return null;
    if($terms==='DUE_ON_RECEIPT')return ['type'=>$pwp?'pwp':'net','days'=>0];
    if($terms==='PWP')return ['type'=>'pwp','days'=>0];
    if(!preg_match('/^(PWP_)?NET([0-9]{1,3})$/D',$terms,$m) || (int)$m[2]>365)throw new RuntimeException('Payment terms need an explicit supported mapping before export.');
    return ['type'=>$pwp || $m[1]!==''?'pwp':'net','days'=>(int)$m[2]];
}

function purepayAssignmentCycle(array $row,bool $current): array
{
    $result=['id'=>purepayAssignmentId($row['id']), 'name'=>purepayAssignmentText($row['name'],true),
        'purpose'=>$row['purpose']===null?null:purepayAssignmentChoice($row['purpose'],['billing','ap','payroll']),
        'cadence'=>$row['cadence']===null?null:purepayAssignmentChoice($row['cadence'],['weekly','biweekly','semimonthly','monthly','adhoc'])];
    if(!$current)return $result;
    $offset=purepayAssignmentRequired($row,'settlement_offset_days');
    if((!is_int($offset) && !is_string($offset)) || !preg_match('/^-?[0-9]{1,5}$/D',(string)$offset) || (int)$offset<-32768 || (int)$offset>32767)throw new RuntimeException('Invalid operating-cycle offset.');
    return $result+['anchor_date'=>purepayAssignmentDate($row['anchor_date'],true),'settlement_offset_days'=>(int)$offset,
        'default_payment_terms'=>purepayAssignmentText($row['default_payment_terms'],true),
        'payroll_pay_cycle_id'=>purepayAssignmentId($row['payroll_pay_cycle_id'],true),'active'=>purepayAssignmentBool($row['active']),
        'source_system'=>purepayAssignmentText($row['source_system'],true),'external_id'=>purepayAssignmentText($row['external_id'],true)];
}

/** Resolver receives a typed source ID and must return a canonical reference from an authorized owner. */
function purepayAssignmentParty(array $row,array $placement,callable $resolve,array $cycles,bool $approved): array
{
    $roots=['person'=>null,'client'=>null,'vendor'=>null,'company'=>null];
    foreach(['person'=>'person_id','vendor'=>'ap_vendor_id','company'=>'company_id'] as $kind=>$column) {
        $id=purepayAssignmentId(purepayAssignmentRequired($row,$column),true);
        if($id!==null)$roots[$kind]=$resolve($kind,$id);
    }
    $channel=purepayAssignmentChoice($row['settlement_channel'],['ar','ap','payroll','none']);
    $clientBasis=null;
    if($channel==='ar') {
        if(!empty($row['client_id'])){$roots['client']=$resolve('client',purepayAssignmentId($row['client_id']),purepayAssignmentId($row['company_id']));$clientBasis='approved_snapshot';}
        else {
            // Legacy snapshots lack CL identity. Only a verified bill-to company
            // can bridge that gap; the work recipient never establishes a debtor.
            if(!in_array($row['source_ref'],['placement:end_client','placement:bill_to'],true)
                || empty($row['company_id']) || (int)$row['company_id']!==(int)($placement['billing_company_id']??0))throw new RuntimeException('Review the historical bill-to identity before exporting this placement.');
            $roots['client']=$resolve('client',purepayAssignmentId($placement['client_id']));$clientBasis='current_placement';
        }
    }
    $cycleId=purepayAssignmentId($row['operating_cycle_id'],true);$cycle=null;
    if($cycleId!==null) {
        if($approved)$cycle=purepayAssignmentCycle(['id'=>$cycleId,'name'=>$row['cycle_name'],'purpose'=>$row['cycle_purpose'],'cadence'=>$row['cycle_cadence']],false);
        else {
            if(!isset($cycles[$cycleId]))throw new RuntimeException('An economic party refers to a missing operating cycle.');
            $cycle=array_intersect_key($cycles[$cycleId],array_flip(['id','name','purpose','cadence']));
        }
    }
    $terms=purepayAssignmentText($row['payment_terms'],true);
    if($approved){$resolved=purepayAssignmentText($row['resolved_payment_terms'],true);$pwp=purepayAssignmentBool($row['resolved_pwp']);}
    else {
        $effectiveTerms=in_array($channel,['ar','ap'],true)?($terms??purepayAssignmentText($row['vendor_default_terms']??null,true)??'NET30'):$terms;
        $pwp=$channel==='ap' && (purepayAssignmentBool($row['pwp_enabled']) || (!purepayAssignmentBool($row['pwp_overridden']) && purepayAssignmentBool($row['vendor_default_pwp']??false)) || str_starts_with($effectiveTerms??'','PWP'));
        purepayAssignmentTerms($effectiveTerms,$pwp);
        $resolved=$pwp && !str_starts_with($effectiveTerms??'','PWP')?(str_starts_with($effectiveTerms??'','NET')?'PWP_'.$effectiveTerms:'PWP'):$effectiveTerms;
    }
    $result=['id'=>purepayAssignmentId($row['id']),'source_ref'=>purepayAssignmentText($row['source_ref']),
        'source_type'=>purepayAssignmentChoice($row['source_type'],['placement','worker','chain','corp','referral','commission','manual','integration']),
        'role'=>purepayAssignmentText($row['role']),'display_name'=>purepayAssignmentText($row['display_name']),
        'roots'=>$roots,'client_identity_basis'=>$clientBasis,'source_user_id'=>purepayAssignmentId($row['user_id'],true),
        'money_flow'=>purepayAssignmentChoice($row['money_flow'],['receivable','payable','informational']),'settlement_channel'=>$channel,
        'fee_basis'=>purepayAssignmentChoice($row['fee_basis'],['none','pay_rate','portal_fee_pct','portal_fee_flat','per_hour','per_invoice','one_time','pct_bill','pct_margin','net_margin','gross_margin','bill_rate','flat']),
        'fee_fraction'=>purepayAssignmentDecimal($row['fee_pct'],6,2,false,true),'fee_amount'=>purepayAssignmentDecimal($row['fee_flat'],4,8,false,true),
        'payment_terms'=>$terms,'resolved_payment_terms'=>$resolved,'paid_when_paid'=>$pwp,'cycle'=>$cycle,
        'effective_from'=>purepayAssignmentDate($row['effective_from'],true),'effective_to'=>purepayAssignmentDate($row['effective_to'],true),
        'payment_terms_overridden'=>$approved?null:purepayAssignmentBool($row['payment_terms_overridden']),
        'pwp_enabled'=>$approved?null:purepayAssignmentBool($row['pwp_enabled']),
        'pwp_overridden'=>$approved?null:purepayAssignmentBool($row['pwp_overridden']),
        'cycle_overridden'=>$approved?null:purepayAssignmentBool($row['cycle_overridden']),
        'active'=>$approved?null:purepayAssignmentBool($row['active'])];
    $purpose=['ar'=>'billing','ap'=>'ap','payroll'=>'payroll'][$channel]??null;
    if(($channel==='ar' && ($result['money_flow']!=='receivable' || !$roots['client']))
        || ($channel==='ap' && ($result['money_flow']!=='payable' || !$roots['vendor']))
        || ($channel==='payroll' && ($result['money_flow']!=='payable' || !$roots['person']))
        || ($cycle && $cycle['purpose']!==null && $cycle['purpose']!==$purpose)
        || ($pwp && $channel!=='ap'))throw new RuntimeException('An economic party needs a matching identity, settlement channel and operating cycle.');
    if($result['effective_from'] && $result['effective_to'] && $result['effective_to']<$result['effective_from'])throw new RuntimeException('Economic party dates are reversed.');
    if((in_array($result['fee_basis'],['portal_fee_pct','pct_bill','pct_margin','net_margin','gross_margin','bill_rate'],true) && $result['fee_fraction']===null)
        || (in_array($result['fee_basis'],['portal_fee_flat','per_hour','per_invoice','one_time','flat'],true) && $result['fee_amount']===null))throw new RuntimeException('An economic fee is missing its explicit value.');
    return $result;
}

function purepayAssignmentEconomics(array $rate,array $placement,callable $resolve): array
{
    $snapshot=purepayAssignmentSnapshotJson((string)purepayAssignmentRequired($rate,'economics_snapshot_json'));
    if(($snapshot['available']??null)!==true || ($snapshot['contract_version']??null)!=='1' || (int)($snapshot['rate_id']??0)!==(int)$rate['id']
        || ($snapshot['currency']??null)!==$rate['currency'] || !is_array($snapshot['contract_parties']??null))throw new RuntimeException('Reconcile this approved rate with its frozen economic graph before export.');
    foreach(['pay_rate','bill_rate'] as $field)if(purepayAssignmentDecimal(purepayAssignmentRequired($snapshot,$field),4,6)!==purepayAssignmentDecimal($rate[$field],4,6))throw new RuntimeException('The approved base rate differs from its frozen graph.');
    $adjustments=[];
    foreach(['bill_adder_fraction'=>'bill_adder_pct','bill_adder_amount'=>'bill_adder_flat','bill_discount_fraction'=>'bill_discount_pct',
        'bill_discount_amount'=>'bill_discount_flat','employer_load_override'=>'adder_pct','workers_comp_override'=>'workers_comp_pct',
        'benefits_load_override'=>'benefits_load_pct','c2c_overhead_override'=>'c2c_overhead_pct','other_cost_per_hour'=>'other_cost_per_hour',
        'other_cost_fixed'=>'other_cost_flat','background_fee_total'=>'background_fee_total'] as $target=>$source) {
        $fraction=str_ends_with($source,'_pct');
        $adjustments[$target]=purepayAssignmentDecimal(purepayAssignmentRequired($rate,$source),6,$fraction?2:10,false,true);
    }
    $costs=[];
    foreach(['employer_load'=>'adder_pct','workers_comp'=>'workers_comp_pct','benefits_load'=>'benefits_load_pct'] as $target=>$source) {
        $costs[$target]=['fraction'=>purepayAssignmentDecimal(purepayAssignmentRequired($snapshot['employer_cost_rates']??[],$source),6,2),
            'source'=>purepayAssignmentChoice(purepayAssignmentRequired($snapshot['employer_cost_sources']??[],$source),['placement_override','tenant_default','none'])];
    }
    $costs['c2c_overhead']=['fraction'=>purepayAssignmentDecimal(purepayAssignmentRequired($snapshot,'c2c_overhead_rate'),6,2),
        'source'=>purepayAssignmentChoice(purepayAssignmentRequired($snapshot,'c2c_overhead_source'),['placement_override','tenant_default','source_waiver','none'])];
    $totals=[];
    foreach(['invoice_bill_rate'=>'invoice_bill_rate','hourly_cost'=>'modeled_hourly_cost','hourly_margin'=>'modeled_hourly_margin',
        'margin_fraction'=>'modeled_margin_pct','fixed_obligations'=>'fixed_obligations'] as $target=>$source) {
        $totals[$target]=purepayAssignmentDecimal(purepayAssignmentRequired($snapshot,$source),6,10,in_array($target,['hourly_margin','margin_fraction'],true));
    }
    $approved=purepayAssignmentText($rate['approved_at']);
    $approvalTime=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$approved);
    if(!$approvalTime || $approvalTime->format('Y-m-d H:i:s')!==$approved)throw new RuntimeException('The rate approval timestamp is invalid.');
    foreach(['employer_load'=>'employer_load_override','workers_comp'=>'workers_comp_override','benefits_load'=>'benefits_load_override','c2c_overhead'=>'c2c_overhead_override'] as $kind=>$override) {
        $cost=$costs[$kind];$value=$adjustments[$override];$applies=($kind==='c2c_overhead')===($placement['engagement_type']==='c2c');
        if((!$applies && ($cost['source']!=='none' || $cost['fraction']!=='0.000000'))
            || (in_array($cost['source'],['none','source_waiver'],true) && $cost['fraction']!=='0.000000')
            || ($applies && $value!==null && ($cost['source']!=='placement_override' || $cost['fraction']!==$value))
            || ($applies && $value===null && $cost['source']==='placement_override'))throw new RuntimeException('The approved overhead does not match its saved override and engagement type.');
    }
    $result=['rate_snapshot_id'=>purepayAssignmentId($rate['id']),'rate_revision'=>integrationDeliveryHash($rate),'approved_at'=>$approved,
        'rate_effective_from'=>purepayAssignmentDate($rate['effective_from']),'rate_effective_to'=>purepayAssignmentDate($rate['effective_to'],true),
        'overtime_multiplier'=>purepayAssignmentDecimal($rate['ot_multiplier'],2,2,false,true),
        'double_time_multiplier'=>purepayAssignmentDecimal($rate['dt_multiplier'],2,2,false,true),
        'adjustments'=>$adjustments,'resolved_costs'=>$costs,
        'parties'=>array_map(static fn(array $p):array=>purepayAssignmentParty($p,$placement,$resolve,[],true),$snapshot['contract_parties']),
        'modeled_totals'=>$totals];
    purepayAssignmentUniqueParties($result['parties']);
    if(!$result['parties'] || count($result['parties'])>100)throw new RuntimeException('The approved graph has no parties or exceeds the review limit.');
    return $result;
}

function purepayAssignmentUniqueParties(array $parties): void
{
    if(count(array_unique(array_column($parties,'id')))!==count($parties) || count(array_unique(array_column($parties,'source_ref')))!==count($parties))throw new RuntimeException('Economic party source identities are duplicated.');
}

/** Build from already scoped rows. The caller, never an API request, supplies the identity resolver. */
function purepayAssignmentBuild(array $placement,array $person,array $rates,array $parties,array $cycleRows,array $items,callable $resolve,?string $previous=null): array
{
    $start=purepayAssignmentDate($placement['start_date']);$end=purepayAssignmentDate($placement['actual_end_date']??$placement['end_date'],true);
    if($end!==null && $end<$start)throw new RuntimeException('Placement dates are reversed.');
    if((int)$placement['person_id']!==(int)$person['id'])throw new RuntimeException('The placed person changed.');
    $employment=purepayAssignmentChoice($placement['engagement_type'],['w2','c2c']);
    $status=purepayAssignmentChoice($placement['status'],['pending_start','active','ended']);
    if($status==='ended' && $end===null)throw new RuntimeException('An ended placement needs its end date.');
    $cycles=[];
    foreach($cycleRows as $row){$cycle=purepayAssignmentCycle($row,true);if(isset($cycles[$cycle['id']]))throw new RuntimeException('Duplicate cycle identity.');$cycles[$cycle['id']]=$cycle;}
    ksort($cycles,SORT_NUMERIC);
    usort($parties,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
    $current=['parties'=>array_map(static fn(array $p):array=>purepayAssignmentParty($p,$placement,$resolve,$cycles,false),$parties),'cycles'=>array_values($cycles)];
    purepayAssignmentUniqueParties($current['parties']);
    foreach(['billing','ap','payroll'] as $purpose) {
        $id=purepayAssignmentId($placement[$purpose.'_operating_cycle_id'],true);$current[$purpose.'_cycle_id']=$id;
        if($id!==null && (!isset($cycles[$id]) || $cycles[$id]['purpose']!==$purpose))throw new RuntimeException('A placement operating cycle is missing or has the wrong purpose.');
    }
    usort($items,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
    $current['one_time_items']=array_map(static fn(array $i):array=>[
        'id'=>purepayAssignmentId($i['id']),'economic_party_id'=>purepayAssignmentId($i['economic_party_id'],true),
        'description'=>purepayAssignmentText($i['description']),'settlement_channel'=>purepayAssignmentChoice($i['settlement_channel'],['ar','ap','payroll','none']),
        'direction'=>purepayAssignmentChoice($i['direction'],['charge','credit']),'amount'=>purepayAssignmentDecimal($i['amount'],2,10),
        'currency'=>purepayAssignmentChoice($i['currency'],['USD']),'apply_on'=>purepayAssignmentDate($i['apply_on']),
        'taxable'=>purepayAssignmentBool($i['taxable']),'status'=>purepayAssignmentChoice($i['status'],['active','void']),
        'source_system'=>purepayAssignmentText($i['source_system'],true),'source_external_id'=>purepayAssignmentText($i['source_external_id'],true)],$items);
    $partyById=array_column($current['parties'],null,'id');$itemIds=[];
    foreach($current['one_time_items'] as $item) {
        $id=$item['economic_party_id'];
        if(isset($itemIds[$item['id']]) || ($id!==null && !isset($partyById[$id]))
            || ($item['settlement_channel']!=='none' && ($id===null || $partyById[$id]['settlement_channel']!==$item['settlement_channel'])))throw new RuntimeException('A one-time item has a duplicate identity or no matching settlement party.');
        $itemIds[$item['id']]=true;
    }
    $terms=[];$primary=null;$next=$start;$approved=[];
    foreach($rates as $rate)if(!empty($rate['approved_at'])){$id=purepayAssignmentId($rate['id']);if(isset($approved[$id]))throw new RuntimeException('Duplicate rate identity.');$approved[$id]=$rate;}
    uasort($approved,static fn($a,$b)=>[$a['effective_from'],(int)$a['id']]<=>[$b['effective_from'],(int)$b['id']]);
    foreach($approved as $rate) {
        $from=purepayAssignmentDate($rate['effective_from']);$to=purepayAssignmentDate($rate['effective_to'],true);
        if($to!==null && $to<$from) {
            $replacement=$approved[(int)($rate['superseded_by']??0)]??null;
            if(!$replacement || $replacement['effective_from']!==$from || $to!==(new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d'))throw new RuntimeException('A reversed rate period has no approved same-day correction.');
            continue;
        }
        if(($to!==null && $to<$start) || ($end!==null && $from>$end))continue;
        $from=max($start,$from);$to=$end===null?$to:($to===null?$end:min($end,$to));
        if($from!==$next)throw new RuntimeException('Approved terms must cover the placement without gaps or overlaps.');
        $economics=purepayAssignmentEconomics($rate,$placement,$resolve);
        $billTo=array_values(array_filter($economics['parties'],static fn($p)=>$p['money_flow']==='receivable' && $p['settlement_channel']==='ar'));
        $labor=array_values(array_filter($economics['parties'],static fn($p)=>$p['money_flow']==='payable' && $p['fee_basis']==='pay_rate'));
        if(count($billTo)!==1 || count($labor)!==1 || $billTo[0]['roots']['client']!==$resolve('client',purepayAssignmentId($placement['client_id'])))throw new RuntimeException('Every approved period needs the same bill-to client and one labor payee.');
        $payee=$labor[0];
        if($employment==='w2' && ($payee['settlement_channel']!=='payroll' || $payee['roots']['person']!==$resolve('person',purepayAssignmentId($person['id']))))throw new RuntimeException('The approved wage payee differs from the placed employee.');
        if($employment==='c2c') {
            if($payee['settlement_channel']!=='ap' || !$payee['roots']['vendor'] || ($primary!==null && $primary!==$payee['roots']['vendor']))throw new RuntimeException('Review changes to the primary vendor before exporting this placement.');
            $primary=$payee['roots']['vendor'];
        }
        $term=['start_date'=>$from,'end_date'=>$to,'currency'=>purepayAssignmentChoice($rate['currency'],['USD']),
            'pay_rate_unit'=>purepayAssignmentChoice($rate['pay_rate_unit'],['hour','day','week','month','project']),
            'bill_rate_unit'=>purepayAssignmentChoice($rate['bill_rate_unit'],['hour','day','week','month','project'])];
        foreach(['pay','bill'] as $kind){$regular=purepayAssignmentDecimal($rate[$kind.'_rate'],4,6);$term[$kind.'_rates']=['regular'=>$regular,
            'overtime'=>purepayAssignmentMultiply($regular,$economics['overtime_multiplier']),'double_time'=>purepayAssignmentMultiply($regular,$economics['double_time_multiplier'])];}
        $term+=['client_terms'=>purepayAssignmentTerms($billTo[0]['resolved_payment_terms'],$billTo[0]['paid_when_paid']),
            'primary_vendor_terms'=>$employment==='c2c'?purepayAssignmentTerms($payee['resolved_payment_terms'],$payee['paid_when_paid']):null,
            'additional_vendor_costs'=>[],'economics'=>$economics];
        $terms[]=$term;$next=$to===null?null:(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
    }
    if(!$terms || $terms[array_key_last($terms)]['end_date']!==$end)throw new RuntimeException('Approved commercial terms do not cover the full placement.');
    $work=[];
    foreach(['external_id','jobdiva_job_id','end_client_name','worksite_country','worksite_state','branch','service_line','workers_comp_class','department','cost_center'] as $field)$work[$field]=purepayAssignmentText(purepayAssignmentRequired($placement,$field),true);
    foreach(['staffing_job_id','accounting_entity_id'] as $field)$work[$field]=purepayAssignmentId(purepayAssignmentRequired($placement,$field),true);
    $work['due_date']=purepayAssignmentDate($placement['due_date'],true);
    $work['end_client']=$placement['end_client_company_id']===null?null:$resolve('company',purepayAssignmentId($placement['end_client_company_id']));
    $work['remote_policy']=$placement['remote_policy']===null?null:purepayAssignmentChoice($placement['remote_policy'],['onsite','hybrid','remote']);
    $body=['contract_version'=>PUREPAY_ASSIGNMENT_INPUT_VERSION,'placement'=>$resolve('placement',purepayAssignmentId($placement['id'])),
        'person'=>$resolve('person',purepayAssignmentId($person['id'])),'client'=>$resolve('client',purepayAssignmentId($placement['client_id'])),
        'primary_vendor'=>$primary,'source_status'=>$status,'employment_type'=>$employment,
        'person_name'=>purepayAssignmentText(trim($person['first_name'].' '.$person['last_name']),false,200),
        'title'=>purepayAssignmentText($placement['title'],false,200),'start_date'=>$start,'end_date'=>$end,'terms'=>$terms,
        'current_commercial_context'=>$current,'work_context'=>$work];
    $body['source_revision']=integrationDeliveryHash($body);
    if($previous!==null && (!preg_match('/^[a-f0-9]{64}$/D',$previous) || $previous===$body['source_revision']))throw new RuntimeException('Choose a different, valid predecessor revision.');
    $body['previous_revision']=$previous;
    if(count($terms)>100 || count($parties)>100 || count($cycles)>100 || count($items)>100 || strlen(integrationDeliveryJson($body))>200000)throw new RuntimeException('This placement exceeds the supported commercial review size.');
    return $body;
}
