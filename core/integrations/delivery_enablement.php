<?php
/** Optional company boundary for operational integration delivery flags. */
declare(strict_types=1);

function integrationDeliveryFlagEnabled(string $name): bool
{
    return defined($name) ? constant($name)===true : getenv($name)==='1';
}

function integrationDeliveryTenantAllowed(?int $tenant): bool
{
    $configured=defined('COREFLUX_INTEGRATION_DELIVERY_TENANTS');
    $raw=$configured?constant('COREFLUX_INTEGRATION_DELIVERY_TENANTS'):getenv('COREFLUX_INTEGRATION_DELIVERY_TENANTS');
    // Existing installations retain their feature-flag behavior until configured.
    if(!$configured&&$raw===false)return true;
    if(!is_string($raw))return false;
    if($tenant===null||$tenant<=0||trim($raw)==='')return false;
    $ids=array_map('trim',explode(',',$raw));
    if(count($ids)>128)return false;
    foreach($ids as$id){
        if(!preg_match('/^[1-9][0-9]*$/D',$id)
            ||filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false)return false;
    }
    return in_array((string)$tenant,$ids,true);
}
