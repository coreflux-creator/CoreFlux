<?php
/** Read-only navigation keeps the requested historical placement separate from the current one. */
declare(strict_types=1);
require_once __DIR__.'/identity.php';
require_once __DIR__.'/../../../core/jobdiva/stored_review.php';
require_once __DIR__.'/../../people/lib/company_relationships.php';

function placementNavigation(int $workspace,string $requested,bool $financial=false): ?array
{
    return JobdivaStoredReviewTransaction::run(static function()use($workspace,$requested,$financial):?array{
        $pdo=getDB();$owner=companyRelationshipScope($pdo,$workspace,'placements')['owner'];
        if(preg_match('/^PL-[1-9][0-9]*$/D',$requested))$identity=placementNumberIdentityResolve($pdo,$owner,$requested,true);
        elseif(preg_match('/^[1-9][0-9]*$/D',$requested)&&(string)(int)$requested===$requested)$identity=placementIdentityResolve($pdo,$owner,(int)$requested,true);
        else throw new InvalidArgumentException('Use a complete placement ID or placement number.',422);
        if(!$identity)return null;
        $safe=static fn(array $p,string $number):array=>['id'=>(int)$p['id'],'placement_number'=>$number,'title'=>$p['title'],
            'status'=>$p['status'],'start_date'=>$p['start_date'],'end_date'=>$p['end_date'],'actual_end_date'=>$p['actual_end_date'],
            'engagement_type'=>$p['engagement_type'],'person_id'=>(int)$p['person_id'],'archived'=>$p['deleted_at']!==null];
        $q=$pdo->prepare('SELECT record_id,record_number FROM core_record_numbers WHERE tenant_id=? AND entity_type="placement" AND canonical_record_id=? ORDER BY record_id FOR UPDATE');
        $q->execute([$owner,$identity['id']]);$family=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as$number){
            $member=placementIdentityResolve($pdo,$owner,(int)$number['record_id'],true);
            if(!$member||$member['id']!==$identity['id'])throw new RuntimeException('Review the incomplete placement history before opening this number.',409);
            $family[]=$safe($member['source_placement'],$number['record_number']);
        }
        $rates=[];
        if($financial){
            $q=$pdo->prepare('SELECT id,effective_from,effective_to,bill_rate,pay_rate,bill_rate_unit,pay_rate_unit,currency,approved_at FROM placement_rates WHERE tenant_id=? AND placement_id=? ORDER BY effective_from,id FOR UPDATE');
            $q->execute([$owner,$identity['source_id']]);$rates=$q->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['requested'=>$requested,'merged'=>$identity['merged'],'source'=>$safe($identity['source_placement'],$identity['source_number']),
            'current'=>$safe($identity['placement'],$identity['placement_number']),'family'=>$family,'can_view_financial_history'=>$financial,'source_rates'=>$rates];
    },true);
}
