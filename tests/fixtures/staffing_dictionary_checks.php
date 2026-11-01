<?php
/** Compare dictionary paths with real validator/projection output, not another metadata fixture. */
declare(strict_types=1);
require_once __DIR__.'/../../core/integrations/staffing_workflow_fields.php';

function staffingDictionaryLeafPaths(mixed $value, string $path = ''): array
{
    if (!is_array($value)) return [$path];
    $paths=[];
    foreach ($value as $key=>$child) {
        $next=array_is_list($value) ? $path.'[]' : ($path==='' ? (string)$key : $path.'.'.$key);
        $paths=array_merge($paths,staffingDictionaryLeafPaths($child,$next));
    }
    return array_values(array_unique($paths));
}

function staffingDictionaryAssertPayload(string $workflow, string $provider, array $payload, string $prefix = ''): void
{
    $rows=array_values(array_filter(staffingWorkflowPayloadFields([]), static fn($row)=>$row['workflow']===$workflow));
    $described=array_column(array_column($rows,'remote_contracts'),$provider);
    $missing=array_diff(staffingDictionaryLeafPaths($payload,$prefix),array_column($described,'path'));
    if ($missing) throw new RuntimeException('Undocumented '.$workflow.' fields: '.implode(', ',$missing));
    foreach ($rows as $row) {
        if ($row['core_schema_status']!=='container_unverified' || $row['write_policy']!=='specialized_workflow_only'
            || $row['remote_contracts'][$provider]['sync_enabled_by_dictionary']!==false) {
            throw new RuntimeException('Dictionary incorrectly grants authority or claims a missing storage column');
        }
    }
}
