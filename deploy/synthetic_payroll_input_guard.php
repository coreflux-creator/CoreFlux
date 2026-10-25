<?php
declare(strict_types=1);

const SYNTHETIC_PAYROLL_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const SYNTHETIC_PAYROLL_ORG='org_purepay_demo';
const SYNTHETIC_PAYROLL_EMPLOYEE='emp_core_c8af23472bb476f0cdd78341daffaac0b601bea851434a50a61436b5cbe615a3';
const SYNTHETIC_PAYROLL_ASSIGNMENT='asg_core_0a33a112da5a16fd7d1ed5105e02368300ff7266efc49214933955327002921e';

/** Only the approved eight-hour demo may pass this one-use acceptance runner. */
function syntheticPayrollInputGuard(array $payload): void
{
    $body=$payload['body']??[];$parts=$body['components']??[];
    if(($payload['organization_id']??null)!==SYNTHETIC_PAYROLL_ORG
        ||integrationDeliveryCanonical($payload['owners']??[])!==['people'=>999,'placements'=>999,'time'=>999]
        ||($body['contract_version']??null)!==PUREPAY_PAYROLL_INPUT_VERSION
        ||($body['instance_id']??null)!==SYNTHETIC_PAYROLL_INSTANCE||($body['time_tenant_id']??null)!==999
        ||($body['period_start']??null)!=='2026-10-19'||($body['period_end']??null)!=='2026-10-19'
        ||!array_is_list($parts)||count($parts)!==1)throw new RuntimeException('Demo payroll packet boundary differs');
    $c=$parts[0];
    foreach(['person'=>'P-15','placement'=>'PL-13'] as $kind=>$number){
        $ref=integrationRecordReference(SYNTHETIC_PAYROLL_INSTANCE,999,$kind,$number);
        if(integrationDeliveryHash($c[$kind]??[])!==integrationDeliveryHash($ref)
            ||($c[$kind.'_link_id']??null)!=='cfl_'.integrationDeliveryHash([SYNTHETIC_PAYROLL_ORG,$ref]))
            throw new RuntimeException('Demo payroll record link differs');
    }
    if(($c['employee_id']??null)!==SYNTHETIC_PAYROLL_EMPLOYEE||($c['assignment_id']??null)!==SYNTHETIC_PAYROLL_ASSIGNMENT)
        throw new RuntimeException('Demo payroll provider identity differs');
    foreach(['time_entry_id'=>1,'work_date'=>'2026-10-19','category'=>'regular_billable','hours'=>'8.00',
        'source_system'=>'quanta','source_entry_id'=>'d9caaf9a-877b-4f59-89d9-c2b71983a676','source_component'=>'regular',
        'source_workspace_id'=>'0598d4ef-3075-4289-9714-c93f904aa85d'] as $key=>$value)
        if(($c['source'][$key]??null)!==$value)throw new RuntimeException('Demo payroll source differs');
    foreach(['snapshot_id'=>1,'pay_rate'=>'30.0000','pay_rate_unit'=>'hour','currency'=>'USD','multiplier'=>'1.00'] as $key=>$value)
        if(($c['rate'][$key]??null)!==$value)throw new RuntimeException('Demo frozen pay rate differs');
    foreach([$c['source']['revision']??null,$c['source']['source_revision']??null,$c['rate']['revision']??null] as $hash)
        if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new RuntimeException('Demo payroll provenance missing');
}

/** Receiving a packet is only a review snapshot, never permission to apply or run it. */
function syntheticPayrollInputReadbackGuard(array $remote,array $body): void
{
    purepayPayrollInputReceipt($remote,SYNTHETIC_PAYROLL_ORG,$body);
    if(integrationDeliveryHash($remote['packet']??[])!==integrationDeliveryHash($body)
        ||($remote['can_apply']??null)!==false||($remote['source_approval']??null)!=='snapshot_only')
        throw new RuntimeException('Provider review or held state differs');
    $earnings=$remote['earnings_preview']??[];$components=$earnings['components']??[];
    if(($earnings['version']??null)!==PUREPAY_PAYROLL_INPUT_VERSION||($earnings['complete']??null)!==true
        ||($earnings['currency']??null)!=='USD'||($earnings['gross']??null)!=='240.00'||($earnings['issues']??null)!==[]
        ||!array_is_list($components)||count($components)!==1)
        throw new RuntimeException('Provider earnings do not match frozen source');
    $expected=['time_entry_id'=>1,'employee_id'=>SYNTHETIC_PAYROLL_EMPLOYEE,'assignment_id'=>SYNTHETIC_PAYROLL_ASSIGNMENT,
        'currency'=>'USD','amount'=>'240.00','issue'=>null];
    if(integrationDeliveryHash($components[0])!==integrationDeliveryHash($expected))throw new RuntimeException('Provider earnings attribution differs');
}
