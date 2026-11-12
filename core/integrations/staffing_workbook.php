<?php
/** Bounded, read-only XLSX evidence extraction. Never evaluates formulas or follows links. */
declare(strict_types=1);
require_once __DIR__.'/staffing_reconciliation_contract.php';

const STAFFING_WORKBOOK_VERSION='2026-11-12.1';
const STAFFING_WORKBOOK_MAX_BYTES=2097152;
const STAFFING_WORKBOOK_NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';

function staffingWorkbookFail(string $message='Choose a supported .xlsx workbook and exact source cells.'): never
{
    throw new InvalidArgumentException($message);
}

function staffingWorkbookFileName(string $name): string
{
    if($name===''||strlen($name)>200||preg_match('/[\x00-\x1f\x7f\\\\\/]/',$name)||!preg_match('/\.xlsx$/iD',$name))staffingWorkbookFail('Choose an .xlsx file without a directory path.');
    return$name;
}

/** Parts stay in memory. No archive path is ever extracted to the filesystem. */
function staffingWorkbookParts(string $bytes): array
{
    if(strlen($bytes)<4||strlen($bytes)>STAFFING_WORKBOOK_MAX_BYTES||substr($bytes,0,4)!=="PK\x03\x04")staffingWorkbookFail('The workbook must be an .xlsx file no larger than 2 MB.');
    if(!class_exists('ZipArchive')||!class_exists('DOMDocument'))throw new RuntimeException('Workbook reading is unavailable on this server.');
    $path=tempnam(sys_get_temp_dir(),'core-workbook-');
    if($path===false)throw new RuntimeException('Private workbook staging is unavailable.');
    $zip=new ZipArchive();$opened=false;
    try{
        if(!chmod($path,0600)||file_put_contents($path,$bytes)!==strlen($bytes))throw new RuntimeException('Private workbook staging failed.');
        if($zip->open($path,ZipArchive::RDONLY)!==true)staffingWorkbookFail('The workbook archive could not be read.');
        $opened=true;if($zip->numFiles>2000)staffingWorkbookFail('The workbook contains too many archive parts.');
        $parts=[];$total=0;
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);if(!$stat)staffingWorkbookFail();$name=$stat['name'];
            if(!is_string($name)||strlen($name)>255||preg_match('/[\x00-\x20\\\\]/',$name)||str_starts_with($name,'/')
                ||preg_match('~(^|/)\.\.?(/|$)~',$name)||isset($parts[$name])||($stat['encryption_method']??0)!==0)staffingWorkbookFail('The workbook contains an unsupported archive path or encrypted part.');
            $total+=(int)$stat['size'];if($total>33554432||(int)$stat['size']>8388608)staffingWorkbookFail('The expanded workbook is too large.');
            if(preg_match('~(?:vbaProject|activeX|embeddings)(?:[./]|$)~i',$name))staffingWorkbookFail('Workbooks with macros or embedded programs are not supported.');
            $parts[$name]=['size'=>(int)$stat['size'],'index'=>$i];
        }
        $result=[];
        foreach($parts as$name=>$part){
            if(!str_ends_with($name,'.xml')&&!str_ends_with($name,'.rels'))continue;
            $body=$zip->getFromIndex($part['index'],$part['size']+1);
            if(!is_string($body)||strlen($body)!==$part['size'])staffingWorkbookFail('A workbook part is incomplete.');
            $result[$name]=$body;
        }
        foreach(['[Content_Types].xml','xl/workbook.xml','xl/_rels/workbook.xml.rels']as$name)if(!isset($result[$name]))staffingWorkbookFail('This is not a supported Excel workbook.');
        if(stripos($result['[Content_Types].xml'],'macroEnabled')!==false)staffingWorkbookFail('Macro-enabled workbooks are not supported.');
        return$result;
    }finally{
        if($opened)$zip->close();
        if(is_file($path)&&!unlink($path))throw new RuntimeException('Private workbook staging cleanup failed.');
    }
}

function staffingWorkbookXml(string $bytes): DOMXPath
{
    if(str_contains($bytes,"\0")||stripos($bytes,'<!DOCTYPE')!==false||stripos($bytes,'<!ENTITY')!==false)staffingWorkbookFail('Workbook XML declarations or encoding are unsupported.');
    $previous=libxml_use_internal_errors(true);
    try{
        $doc=new DOMDocument();$doc->resolveExternals=false;$doc->substituteEntities=false;
        if(!$doc->loadXML($bytes,LIBXML_NONET)||$doc->doctype!==null)staffingWorkbookFail('A workbook XML part is invalid.');
        $xpath=new DOMXPath($doc);$xpath->registerNamespace('s',STAFFING_WORKBOOK_NS);
        $xpath->registerNamespace('p','http://schemas.openxmlformats.org/package/2006/relationships');
        return$xpath;
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
}

/** Numeric comparisons retain their distinct provider, worksheet and Core periods. */
function staffingWorkbookComparison(array $workbook,array $line,?string $providerCurrency,array $entries,array $rates,array $selectedPeriod): array
{
    $period=$workbook['period'];$relation=staffingReconciliationRelation($period['start'],$period['end'],$selectedPeriod);
    $providerRelation=staffingReconciliationRelation($line['service_start'],$line['service_end'],$period);
    $provider=staffingReconciliationDifference($line['amount'],$workbook['stated_bill_amount'],$providerCurrency,$workbook['currency'],true);
    // Equality of reviewed row values is useful, but unknown service dates do not prove a monetary reconciliation.
    if($providerRelation!=='exact')$provider['monetary_difference']=null;
    $providerHours=null;
    try{if($line['hours']!==null)$providerHours=purepayObservationMoney(staffingReconciliationScaled($line['hours'],2,8)-staffingReconciliationScaled($workbook['stated_hours'],2,4));}catch(InvalidArgumentException $e){}
    $common=$relation==='outside'?null:['start'=>max($period['start'],$selectedPeriod['start']),'end'=>min($period['end'],$selectedPeriod['end'])];
    $core=null;$hours=null;$blanks=null;$difference=null;
    if($common!==null){
        $sum=0;$blanks=0;foreach($workbook['days']as$d)if($d['date']>=$common['start']&&$d['date']<=$common['end']){
            if($d['hours']===null)$blanks++;else$sum+=staffingReconciliationScaled($d['hours'],2,2);
        }
        $hours=staffingReconciliationHours($sum);$core=staffingReconciliationTime($entries,$rates,$common);
        if($core['approved_hours']!==null)$difference=purepayObservationMoney($sum-staffingReconciliationScaled($core['approved_hours'],2,8));
    }
    return['worksheet_relation_to_selection'=>$relation,'provider_relation_to_worksheet'=>$providerRelation,
        'provider_vs_worksheet_amount'=>$provider,'provider_vs_worksheet_hours_difference'=>$providerHours,
        'core_comparison_period'=>$common,'worksheet_known_hours_in_common_period'=>$hours,'blank_days_in_common_period'=>$blanks,
        'core_approved_hours_in_common_period'=>$core['approved_hours']??null,'worksheet_minus_core_hours'=>$difference,
        'time_entry_allocation_proven'=>false,'original_client_approval_proven'=>false];
}

function staffingWorkbookIndex(array $parts): array
{
    $book=staffingWorkbookXml($parts['xl/workbook.xml']);$rels=staffingWorkbookXml($parts['xl/_rels/workbook.xml.rels']);$targets=[];$sheets=[];
    foreach($rels->query('/p:Relationships/p:Relationship')as$r){
        $id=$r->getAttribute('Id');if($id===''||isset($targets[$id]))staffingWorkbookFail('Workbook relationship identities are ambiguous.');
        $target=$r->getAttribute('Target');
        if($r->getAttribute('TargetMode')==='External'){$targets[$id]=null;continue;}
        $target=str_starts_with($target,'/')?substr($target,1):'xl/'.$target;
        if(!preg_match('~^xl/[A-Za-z0-9_./-]+$~D',$target)||preg_match('~(^|/)\.\.?(/|$)~',$target))staffingWorkbookFail('An internal workbook relationship is unsupported.');
        $targets[$id]=['path'=>$target,'type'=>$r->getAttribute('Type')];
    }
    foreach($book->query('/s:workbook/s:sheets/s:sheet')as$sheet){
        $name=$sheet->getAttribute('name');$id=$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');$target=$targets[$id]??null;
        if($name===''||isset($sheets[$name])||!is_array($target)||!str_ends_with($target['type'],'/worksheet')||!isset($parts[$target['path']]))staffingWorkbookFail('A worksheet identity or source is unavailable.');
        $sheets[$name]=$target['path'];
    }
    if(!$sheets||count($sheets)>100)staffingWorkbookFail('The workbook worksheet list is unsupported.');
    $props=$book->query('/s:workbook/s:workbookPr');$date1904=$props->length?in_array($props->item(0)->getAttribute('date1904'),['1','true'],true):false;
    return['sheets'=>$sheets,'date_system'=>$date1904?'1904':'1900'];
}

function staffingWorkbookCellAddress(string $address): array
{
    if(!preg_match('/^([A-Z]{1,3})([1-9][0-9]{0,6})$/D',$address,$m))staffingWorkbookFail('Use exact Excel cell addresses, such as G232.');
    $column=0;foreach(str_split($m[1])as$c)$column=$column*26+ord($c)-64;
    if($column>16384||(int)$m[2]>1048576)staffingWorkbookFail('A source cell is outside an Excel worksheet.');
    return['column'=>$column,'row'=>(int)$m[2]];
}

function staffingWorkbookColumn(int $column): string
{
    $text='';while($column>0){$column--;$text=chr(65+$column%26).$text;$column=intdiv($column,26);}return$text;
}

function staffingWorkbookRange(string $range): array
{
    if(count($parts=explode(':',$range))!==2)staffingWorkbookFail('Choose a single-row cell range, such as I234:AL234.');
    $start=staffingWorkbookCellAddress($parts[0]);$end=staffingWorkbookCellAddress($parts[1]);
    if($start['row']!==$end['row']||$end['column']<$start['column']||$end['column']-$start['column']>30)staffingWorkbookFail('Source ranges must cover one row of at most 31 days.');
    $cells=[];for($c=$start['column'];$c<=$end['column'];$c++)$cells[]=staffingWorkbookColumn($c).$start['row'];return$cells;
}

function staffingWorkbookSelection(array $selection): array
{
    $keys=['sheet','month_cell','identity_cell','day_header_range','hours_range','hours_total_cell','bill_rate_cell','bill_total_cell','currency_cell'];
    if(array_diff(array_keys($selection),$keys)||array_diff($keys,array_keys($selection)))staffingWorkbookFail('The workbook cell selection is incomplete.');
    if(!is_string($selection['sheet'])||$selection['sheet']===''||strlen($selection['sheet'])>124||preg_match('/[\x00-\x1f\x7f]/',$selection['sheet']))staffingWorkbookFail('Choose the exact worksheet name.');
    foreach($keys as$key){
        if($key==='sheet'||($key==='currency_cell'&&$selection[$key]===null))continue;
        if(!is_string($selection[$key]))staffingWorkbookFail();
        if(str_ends_with($key,'_range'))staffingWorkbookRange($selection[$key]);else staffingWorkbookCellAddress($selection[$key]);
    }
    return$selection;
}

/** Only selected cells are returned; formulas are retained as text and never executed. */
function staffingWorkbookCells(array $parts,string $path,array $addresses): array
{
    $shared=[];
    if(isset($parts['xl/sharedStrings.xml'])){
        $xml=staffingWorkbookXml($parts['xl/sharedStrings.xml']);
        foreach($xml->query('/s:sst/s:si')as$item){
            $value='';foreach($xml->query('./s:t|./s:r/s:t',$item)as$t)$value.=$t->textContent;
            if(strlen($value)>65536||count($shared)>=100000)staffingWorkbookFail('Workbook text is too large.');$shared[]=$value;
        }
    }
    $xml=staffingWorkbookXml($parts[$path]);$wanted=array_fill_keys($addresses,true);$cells=[];
    foreach($xml->query('/s:worksheet/s:sheetData/s:row/s:c')as$c){
        $address=$c->getAttribute('r');if(!isset($wanted[$address]))continue;
        if(isset($cells[$address]))staffingWorkbookFail('A selected source cell occurs more than once.');
        $type=$c->getAttribute('t')?:'n';$values=$xml->query('./s:v',$c);$formulas=$xml->query('./s:f',$c);
        if($values->length>1||$formulas->length>1)staffingWorkbookFail('A source cell is ambiguous.');
        $value=$values->length?$values->item(0)->textContent:null;$formula=$formulas->length?$formulas->item(0)->textContent:null;
        if($type==='s'){
            if(!is_string($value)||!preg_match('/^(0|[1-9][0-9]*)$/D',$value)||!array_key_exists((int)$value,$shared))staffingWorkbookFail('A source text reference is unavailable.');$value=$shared[(int)$value];
        }elseif($type==='inlineStr'){
            $value='';foreach($xml->query('./s:is/s:t|./s:is/s:r/s:t',$c)as$t)$value.=$t->textContent;
        }elseif(!in_array($type,['n','d','str'],true))staffingWorkbookFail('A selected source cell contains an error, boolean or unsupported value.');
        if(($value!==null&&strlen($value)>65536)||($formula!==null&&strlen($formula)>8192))staffingWorkbookFail('A selected source cell is too large.');
        if($formula!==null&&($value===null||$value===''))staffingWorkbookFail('A selected formula has no saved result. Save a calculated workbook first.');
        $cells[$address]=['cell'=>$address,'type'=>$type,'value'=>$value,'formula'=>$formula];
    }
    foreach($addresses as$address)if(!isset($cells[$address]))$cells[$address]=['cell'=>$address,'type'=>'blank','value'=>null,'formula'=>null];
    ksort($cells);return$cells;
}

function staffingWorkbookMonth(array $cell,string $dateSystem): string
{
    $value=$cell['value'];
    if($cell['type']==='d'&&is_string($value)&&preg_match('/^(\d{4}-\d{2}-\d{2})(?:T00:00:00(?:\.0+)?Z?)?$/D',$value,$m))$date=$m[1];
    elseif($cell['type']==='n'&&is_string($value)&&preg_match('/^(0|[1-9][0-9]{0,6})(?:\.0+)?$/D',$value)){
        $serial=(int)$value;if($serial>2958465||($dateSystem==='1900'&&($serial===0||$serial===60)))staffingWorkbookFail('The source month is not a valid Excel date.');
        $base=new DateTimeImmutable($dateSystem==='1904'?'1904-01-01':'1899-12-31');
        $days=$dateSystem==='1900'&&$serial>60?$serial-1:$serial;$date=$base->modify('+'.$days.' days')->format('Y-m-d');
    }else staffingWorkbookFail('The month cell must contain an actual Excel date, not a period label.');
    if(!purepayObservationDate($date)||substr($date,8)!=='01')staffingWorkbookFail('The month cell must contain the first calendar day of the source month.');
    return$date;
}

function staffingWorkbookNumber(array $cell,int $scale,int $digits): int
{
    if($cell['type']!=='n'||!is_string($cell['value']))staffingWorkbookFail('A selected numeric source cell is blank or contains text.');
    return staffingReconciliationScaled($cell['value'],$scale,$digits);
}

function staffingWorkbookRead(string $bytes,string $name,array $selection): array
{
    $name=staffingWorkbookFileName($name);$selection=staffingWorkbookSelection($selection);
    $parts=staffingWorkbookParts($bytes);$index=staffingWorkbookIndex($parts);
    $path=$index['sheets'][$selection['sheet']]??null;if($path===null)staffingWorkbookFail('The selected worksheet is absent from this workbook.');
    $headers=staffingWorkbookRange($selection['day_header_range']);$hours=staffingWorkbookRange($selection['hours_range']);
    if(count($headers)!==count($hours))staffingWorkbookFail('Day and hour ranges must have the same width.');
    $addresses=[...$headers,...$hours];foreach($selection as$key=>$value)if(str_ends_with($key,'_cell')&&$value!==null)$addresses[]=$value;
    $cells=staffingWorkbookCells($parts,$path,array_values(array_unique($addresses)));
    $month=staffingWorkbookMonth($cells[$selection['month_cell']],$index['date_system']);$date=new DateTimeImmutable($month);$dayCount=(int)$date->format('t');
    if(count($headers)!==$dayCount)staffingWorkbookFail('The selected day range must cover every calendar day of the source month.');
    $identity=$cells[$selection['identity_cell']]['value'];if(!is_string($identity)||trim($identity)===''||strlen($identity)>300)staffingWorkbookFail('Choose the cell identifying this workbook row.');
    $days=[];$sum=0;$blank=0;
    foreach($headers as$i=>$header){
        if(staffingWorkbookNumber($cells[$header],0,2)!==$i+1)staffingWorkbookFail('The selected day headers must run from 1 through the last day of the month.');
        $cell=$cells[$hours[$i]];$value=null;
        if($cell['value']===null||$cell['value']==='')$blank++;
        else{$value=staffingWorkbookNumber($cell,2,2);if($value>2400)staffingWorkbookFail('A daily source value exceeds 24 hours.');$sum+=$value;}
        $days[]=['date'=>$date->modify('+'.$i.' days')->format('Y-m-d'),'header_cell'=>$header,'hours_cell'=>$hours[$i],
            'hours'=>$value===null?null:staffingReconciliationHours($value)];
    }
    $statedHours=staffingWorkbookNumber($cells[$selection['hours_total_cell']],2,4);
    $rate=staffingWorkbookNumber($cells[$selection['bill_rate_cell']],4,6);
    $statedAmount=staffingWorkbookNumber($cells[$selection['bill_total_cell']],2,12);
    $amount=intdiv($sum*$rate+5000,10000);$currency=null;
    if($selection['currency_cell']!==null){$currency=$cells[$selection['currency_cell']]['value'];if(!is_string($currency)||!preg_match('/^[A-Z]{3}$/D',$currency))staffingWorkbookFail('A currency cell must state an explicit three-letter currency code.');}
    return['contract_version'=>STAFFING_WORKBOOK_VERSION,'file_name'=>$name,'file_sha256'=>hash('sha256',$bytes),'file_bytes'=>strlen($bytes),
        'selection'=>$selection,'date_system'=>$index['date_system'],'row_identity'=>$identity,
        'period'=>['start'=>$month,'end'=>$date->format('Y-m-t')],'days'=>$days,'cells'=>$cells,
        'numeric_hours_sum'=>staffingReconciliationHours($sum),'stated_hours'=>staffingReconciliationHours($statedHours),
        'hours_difference'=>purepayObservationMoney($statedHours-$sum),'blank_days'=>$blank,'blank_means_zero'=>false,
        'stated_bill_amount'=>purepayObservationMoney($statedAmount),'calculated_bill_amount'=>purepayObservationMoney($amount),
        'bill_amount_difference'=>purepayObservationMoney($statedAmount-$amount),'currency'=>$currency,
        'calculation_basis'=>'stored_cell_values_not_recalculated','original_client_approval_proven'=>false,
        'provider_dates_changed'=>false,'time_entries_matched'=>false,'cash_delivery_proven'=>false];
}
