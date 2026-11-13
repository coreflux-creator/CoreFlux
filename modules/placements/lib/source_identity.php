<?php
/** Provider identity is independent of a retained display/external number. */
declare(strict_types=1);
require_once __DIR__.'/source_owner.php';

function placementJobdivaSourceIdentity(PDO $pdo,array $placement): ?array
{
    if(!$pdo->inTransaction()||empty($placement['id'])||empty($placement['tenant_id']))throw new LogicException('Source identity requires the locked placement transaction.');
    $family=placementJobdivaSourceFamily($pdo,(int)$placement['tenant_id'],(int)$placement['id']);
    $placement=$family['placement'];$links=$family['merged']?($family['primary']?[$family['primary']]:[]):$family['links'];
    if(count($links)>1)throw new RuntimeException('This placement has competing JobDiva links. Review its source identity before changing its rules.',409);
    $display=trim((string)($placement['external_id']??''));
    if(!$links){
        if($family['merged'])return null;
        if(!str_starts_with($display,'jd:')||trim(substr($display,3))==='')return null;
        return ['source_workspace_id'=>null,'start_id'=>substr($display,3),'mapping_id'=>null,'mapping_external_id'=>null,'provenance'=>'legacy_exact'];
    }
    $link=$links[0];$external=trim((string)$link['external_id']);$start=str_starts_with($external,'jd:')?substr($external,3):$external;
    if($start===''||(!$family['merged']&&str_starts_with($display,'jd:')&&substr($display,3)!==$start))throw new RuntimeException('The retained placement number conflicts with its JobDiva link. Review the exact source identity.',409);
    return ['source_workspace_id'=>(int)$link['tenant_id'],'start_id'=>$start,'mapping_id'=>(int)$link['id'],
        'mapping_external_id'=>(string)$link['external_id'],'provenance'=>$family['merged']?'reviewed_merge':'saved_link']
        +($family['merged']?['physical_placement_id'=>(int)$link['internal_entity_id'],'source_owner'=>$family['source_owner']]:[]);
}
