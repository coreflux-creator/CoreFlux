<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../modules/payroll/lib/export.php';
require_once __DIR__.'/../core/export_templates.php';
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$rejects=static function(callable $fn,string $text):bool{try{$fn();return false;}catch(RuntimeException $e){if(!str_contains($e->getMessage(),$text))throw $e;return true;}};
$map=static fn(array $fields):array=>['column_mappings'=>array_map(static fn($f)=>['kind'=>'field','source_field'=>$f],$fields)];
$source=[['earnings_basis'=>'approved_time']];
$check(payrollExportDollars('9007199254740993')==='90071992547409.93' && payrollExportDollars((string)PHP_INT_MIN)==='-92233720368547758.08'
    && payrollExportDollars(1)==='0.01' && payrollExportDollars('-000')==='0.00','dollar formatting retains exact large, signed and sub-dollar cents');
$check($rejects(fn()=>payrollExportDollars('1.2'),'invalid') && $rejects(fn()=>payrollExportAdd(PHP_INT_MAX,1),'supported range'),
    'invalid or overflowing cents fail explicitly');
$check($rejects(fn()=>payrollExportAssertTemplate($map(['regular_hours']),$source),'omits approved rates'),
    'an arbitrary custom hours template is guarded without relying on its name');
$fields=['regular_hours','gross_pay_cents','earnings_basis','earnings_components_json','source_time_json','source_time_sha256','computation_sha256'];
payrollExportAssertTemplate($map($fields),$source);$check(true,'complete source-aware hours mapping is accepted');
foreach (array_slice($fields,1) as $field) {
    $check($rejects(fn()=>payrollExportAssertTemplate($map(array_values(array_diff($fields,[$field]))),$source),'omits approved rates'),
        'source-aware hours mapping requires '.$field);
}
$fixed=$map($fields);$fixed['column_mappings'][3]=['kind'=>'fixed','output_header'=>'Earnings details','fixed_value'=>'[]'];
$check($rejects(fn()=>payrollExportAssertTemplate($fixed,$source),'omits approved rates'),'a fixed column cannot impersonate calculated earning evidence');
payrollExportAssertTemplate($map(['person_number','net_pay_dollars']),$source);
payrollExportAssertTemplate($map(['regular_hours']),[['earnings_basis'=>'compensation']]);
$check(true,'net reports remain available and compensation hours retain the existing template option');
foreach (['bank_account_number','bank_routing_number','bank_account_type'] as $field) {
    $check($rejects(fn()=>payrollExportAssertTemplate($map([$field]),$source),'reviewed bank instructions'),'bank projection cannot invent '.$field);
}
$check($rejects(fn()=>payrollExportAssertTemplate($map(['employee_external_id']),$source),'provider-specific'),'employee numbers are never exported as provider IDs');
$template=['delimiter'=>',','quote_char'=>'"','has_header_row'=>1,'column_mappings'=>[
    ['position'=>1,'kind'=>'field','source_field'=>'detail','output_header'=>'Details'],
    ['position'=>2,'kind'=>'fixed','fixed_value'=>'reviewed mapping','output_header'=>'Label'],
]];
$stream=fopen('php://temp','w+');$value=json_encode(['name'=>'Comma, "Quote"','rate'=>'65.0050'],JSON_THROW_ON_ERROR);
exportTemplateRenderResolvedToStream($template,[['detail'=>$value]],$stream);rewind($stream);
$header=fgetcsv($stream,0,',','"','\\');$row=fgetcsv($stream,0,',','"','\\');fclose($stream);
$check($header===['Details','Label'] && $row===[$value,'reviewed mapping'],'resolved CSV rendering retains exact JSON and fixed columns');
echo "$checks payroll export checks passed\n";
