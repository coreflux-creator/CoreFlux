<?php
/** Original time entries are read by physical placement and original workspace. */
declare(strict_types=1);
require_once __DIR__.'/navigation.php';

function placementTimeHistory(int $workspace,string $requested,int $before=0,int $limit=50): array
{
    if ($before<0 || $limit<1 || $limit>200) throw new InvalidArgumentException('Use a valid history page.',422);
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$requested,$before,$limit):array{
        $identity=placementNavigation($workspace,$requested);
        if (!$identity) throw new RuntimeException('Placement history is unavailable in this directory.',404);
        $pdo=getDB();$values=[$workspace,$identity['source']['id']];$cursor='';
        if ($before>0) { $cursor=' AND id<?';$values[]=$before; }
        $q=$pdo->prepare('SELECT id,work_date,category,hour_type,hours,status,billable,payable,approved_at,timesheet_id,rate_snapshot_id,description
            FROM time_entries WHERE tenant_id=? AND placement_id=?'.$cursor.' ORDER BY id DESC LIMIT '.($limit+1).' FOR UPDATE');
        $q->execute($values);$entries=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($entries)>$limit;$entries=array_slice($entries,0,$limit);
        foreach ($entries as &$entry) { $entry['id']=(int)$entry['id'];$entry['timesheet_id']=$entry['timesheet_id']===null?null:(int)$entry['timesheet_id']; }
        unset($entry);
        return ['workspace_id'=>$workspace,'placement_id'=>$identity['source']['id'],'placement_number'=>$identity['source']['placement_number'],
            'current_placement_id'=>$identity['current']['id'],'merged'=>$identity['merged'],'entries'=>$entries,
            'next_before_id'=>$more?(int)$entries[count($entries)-1]['id']:null];
    },true);
}
