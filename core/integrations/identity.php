<?php
/** Stable installation namespace, supplied independently for each deployment. */
declare(strict_types=1);

function integrationInstanceId(): ?string
{
    $id = defined('COREFLUX_INTEGRATION_INSTANCE_ID') ? constant('COREFLUX_INTEGRATION_INSTANCE_ID') : getenv('COREFLUX_INTEGRATION_INSTANCE_ID');
    if ($id === false || $id === null || $id === '') return null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $id)) {
        throw new RuntimeException('The permanent Core integration identity is invalid');
    }
    return $id;
}

/** Caller must resolve the authorized directory and its immutable number first. */
function integrationRecordReference(string $instanceId, int $directoryTenantId, string $type, string $number): array
{
    $prefix = ['person'=>'P','placement'=>'PL','company'=>'CO','client'=>'CL','vendor'=>'V'][$type] ?? null;
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $instanceId)
        || $directoryTenantId <= 0 || $prefix === null || strlen($number)>64
        || !preg_match('/^'.$prefix.'-[1-9][0-9]*$/D', $number)) {
        throw new InvalidArgumentException('A permanent Core instance, directory and typed record number are required');
    }
    return ['instance_id'=>$instanceId,'directory_tenant_id'=>$directoryTenantId,'entity_type'=>$type,'number'=>$number];
}
