<?php
/** Provider observations keep their original workspace and physical placement identity. */
declare(strict_types=1);
require_once __DIR__.'/navigation.php';
require_once __DIR__.'/../../../core/integrations/entity_mappings.php';

function placementSourceRead(int $workspace,int $placement,callable $reader): array
{
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$placement,$reader):array{
        $identity=placementNavigation($workspace,(string)$placement);
        if (!$identity) throw new RuntimeException('Placement source history is unavailable in this directory.',404);
        return $reader($identity);
    },true);
}

function placementSourceMappingViews(int $workspace,string $action,int $placement,string $source='',string $external=''): array
{
    if (!in_array($action,['list_for_internal','find_internal','find_external'],true)) throw new InvalidArgumentException('Unknown mapping view.',422);
    if ($action!=='list_for_internal'&&$source==='') throw new InvalidArgumentException('Choose a source system.',422);
    if ($action==='find_internal'&&$external==='') throw new InvalidArgumentException('Use the complete external ID.',422);
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$action,$placement,$source,$external):array{
        $selected=null;
        if ($action==='find_internal') {
            $selected=mappingFindInternal($workspace,$source,'placement',$external);
            if (!$selected) return ['mapping'=>null];
            $placement=(int)$selected['internal_entity_id'];
        }
        return placementSourceRead($workspace,$placement,static function(array $identity)use($workspace,$placement,$action,$source,$selected):array{
            $ids=$identity['merged']?[$identity['source']['id']]:array_column($identity['family'],'id');$rows=[];
            foreach ($ids as $id) foreach (mappingListForInternal($workspace,'placement',(int)$id) as $row) {
                $row['id']=(int)$row['id'];$row['internal_entity_id']=(int)$row['internal_entity_id'];
                $row['canonical_internal_id']=$identity['current']['id'];$rows[]=$row;
            }
            usort($rows,static fn($a,$b)=>$a['id']<=>$b['id']);
            if ($action==='list_for_internal') return ['entity_type'=>'placement','internal_id'=>$placement,'canonical_internal_id'=>$identity['current']['id'],'mappings'=>$rows];
            $matches=array_values(array_filter($rows,static fn($row)=>$row['source_system']===$source&&($selected===null||$row['id']===(int)$selected['id'])));
            return ['mapping'=>count($matches)===1?$matches[0]:null,'mappings'=>$matches,'requires_source_selection'=>count($matches)>1,'canonical_internal_id'=>$identity['current']['id']];
        });
    },true);
}
