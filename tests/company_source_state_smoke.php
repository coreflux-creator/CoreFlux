<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../modules/people/lib/company_source_ownership.php';
$checks=0;
foreach(['companies','company_contacts']as$table){
    $state=companySourceState(['coreflux_source_state'=>null],$table);
    if(!$state['legacy_unreviewed']||$state['source']!==null||$state['owned']!==companySourceFields($table))throw new RuntimeException('Unknown legacy ownership must preserve native values');$checks++;
    foreach([
        ['version'=>2],['owned'=>[['name']]],['owned'=>['tenant_id']],['proposed'=>['coreflux_source_state'=>'replacement']],
        ['proposed'=>['phone'=>['invalid']]],['source'=>['system'=>'jobdiva','workspace_id'=>0,'external_id'=>'1']],
        ['source'=>['system'=>'other','workspace_id'=>1,'external_id'=>'1']],['legacy_unreviewed'=>'false'],
    ]as$patch){
        $rejected=false;try{companySourceState(['coreflux_source_state'=>json_encode(array_replace($state,$patch),JSON_THROW_ON_ERROR)],$table);}catch(Throwable){$rejected=true;}
        if(!$rejected)throw new RuntimeException('Malformed field ownership was accepted');$checks++;
    }
    $state['source']=['system'=>'jobdiva','workspace_id'=>10,'external_id'=>'source-1'];$state['owned']=['phone'];$state['proposed']=['phone'=>null];
    if(companySourceState(['coreflux_source_state'=>json_encode($state,JSON_THROW_ON_ERROR)],$table)!==$state)throw new RuntimeException('Explicit null proposal or exact source identity was lost');$checks++;
}
echo "Company source ownership state: $checks passed\n";
