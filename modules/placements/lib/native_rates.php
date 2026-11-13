<?php
/** Native rate edits and approval share current directory, placement and rate locks. */
declare(strict_types=1);
require_once __DIR__.'/company_references.php';
require_once __DIR__.'/ownership.php';

function placementRateWithLockedRows(int $workspace, ?int $placementId, ?int $rateId, callable $write): mixed
{
    return placementNativeDirectoryWrite($workspace,(int)placementsGraphTenantId($workspace),static function(array $scope,PDO $pdo)use($placementId,$rateId,$write):mixed{
        if($placementId===null){
            // Discovery only. Re-read the rate under the placement lock below.
            $q=$pdo->prepare('SELECT placement_id FROM placement_rates WHERE tenant_id=? AND id=?');$q->execute([$scope['owner'],$rateId]);$placementId=(int)$q->fetchColumn();
            if(!$placementId)throw new RuntimeException('Rate not found',404);
        }
        return placementWithLockedRecord($scope['owner'],$placementId,static function(array $placement,PDO $pdo)use($scope,$rateId,$write):mixed{
            $q=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND placement_id=? ORDER BY id FOR UPDATE');$q->execute([$scope['owner'],$placement['id']]);$rates=$q->fetchAll(PDO::FETCH_ASSOC);
            if($rateId!==null&&!in_array($rateId,array_map('intval',array_column($rates,'id')),true))throw new RuntimeException('Rate not found on this placement',404);
            return $write($placement,$rates,$scope,$pdo);
        });
    });
}

function placementRateDraftNative(int $workspace,int $placementId,array $data,int $actor,?int $replaceId=null): array
{
    if($actor<=0)throw new RuntimeException('An authenticated rate editor is required.',403);
    $allowed=['effective_from','effective_to','bill_rate','bill_rate_unit','pay_rate','pay_rate_unit','currency','ot_multiplier','dt_multiplier','adder_pct','bill_adder_pct','bill_adder_flat','bill_discount_pct','bill_discount_flat','workers_comp_pct','benefits_load_pct','c2c_overhead_pct','other_cost_per_hour','other_cost_flat','background_fee_total'];
    $data=array_intersect_key($data,array_flip($allowed));
    foreach(['effective_from','effective_to']as$field){
        $value=$data[$field]??null;
        if($field==='effective_to'&&($value===null||$value==='')){$data[$field]=null;continue;}
        $date=is_string($value)?DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
        if(!$date||$date->format('Y-m-d')!==$value)throw new InvalidArgumentException('Use a valid rate effective date.',422);
    }
    if($data['effective_to']!==null&&$data['effective_to']<$data['effective_from'])throw new InvalidArgumentException('The rate end date precedes its start.',422);
    foreach(['bill_rate','pay_rate']as$field)if(!isset($data[$field])||!is_numeric($data[$field])||!is_finite((float)$data[$field])||(float)$data[$field]<0)throw new InvalidArgumentException('Use non-negative bill and pay rates.',422);
    foreach(['bill_rate_unit','pay_rate_unit']as$field)if(!in_array($data[$field]??'hour',['hour','day','week','month','project'],true))throw new InvalidArgumentException('Choose a valid rate unit.',422);
    if(!preg_match('/^[A-Z]{3}$/D',(string)($data['currency']??'USD')))throw new InvalidArgumentException('Use a three-letter currency code.',422);
    return placementRateWithLockedRows($workspace,$placementId,$replaceId,static function(array $placement,array $rates,array $scope,PDO $pdo)use($data,$actor,$replaceId):array{
        $before=null;$data['created_by_user_id']=$actor;
        if($replaceId!==null){
            $before=array_column($rates,null,'id')[$replaceId];
            if($before['approved_at']!==null)throw new RuntimeException('Approved rates are locked; create a correction instead',409);
            $data['created_by_user_id']=$before['created_by_user_id']?:$actor;
            $q=$pdo->prepare('UPDATE placement_rates SET '.implode(',',array_map(static fn($f)=>'`'.$f.'`=?',array_keys($data))).' WHERE tenant_id=? AND placement_id=? AND id=? AND approved_at IS NULL');$q->execute([...array_values($data),$scope['owner'],$placement['id'],$replaceId]);$id=$replaceId;
        }else{
            $data=['tenant_id'=>$scope['owner'],'placement_id'=>$placement['id']]+$data;
            $q=$pdo->prepare('INSERT INTO placement_rates (`'.implode('`,`',array_keys($data)).'`) VALUES('.implode(',',array_fill(0,count($data),'?')).')');$q->execute(array_values($data));$id=(int)$pdo->lastInsertId();
        }
        $owned=placementOwnershipFields($placement['coreflux_overridden_fields']??null);$owned=array_values(array_unique([...$owned,'rates']));
        $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE tenant_id=? AND id=?')->execute([json_encode($owned,JSON_THROW_ON_ERROR),$scope['owner'],$placement['id']]);
        $q=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$scope['owner'],$id]);$after=$q->fetch(PDO::FETCH_ASSOC);
        companySourceAudit($pdo,$scope['workspace'],$scope['owner'],'placements',(int)$placement['id'],$replaceId!==null?'placement.rate.draft_updated':'placement.rate.drafted',['rates'],$actor,['rate_id'=>$id,'before'=>$before,'after'=>$after,'ownership_before'=>placementOwnershipFields($placement['coreflux_overridden_fields']??null),'ownership_after'=>$owned]);
        return ['id'=>$id,'updated'=>$replaceId!==null];
    });
}

function placementRateApproveLocked(int $workspace,int $rateId,array $user,bool $isCorrection,?string $reason): array
{
    if(empty($user['id']))throw new RuntimeException('An authenticated approver is required.',403);
    if($reason!==null&&strlen($reason)>1000)throw new InvalidArgumentException('The correction reason is too long.',422);
    return placementRateWithLockedRows($workspace,null,$rateId,static function(array $placement,array $rates,array $scope,PDO $pdo)use($rateId,$user,$isCorrection,$reason):array{
        $rate=array_column($rates,null,'id')[$rateId];
        if($rate['approved_at']!==null)throw new RuntimeException('Rate already approved',409);
        $blocker=placementsRateApprovalBlocker($rate);if($blocker!==null)throw new RuntimeException($blocker,422);
        $autoCorrection=(bool)array_filter($rates,static fn($r)=>$r['approved_at']!==null);
        $isCorrection=$isCorrection||$autoCorrection;
        if($isCorrection&&trim((string)$reason)==='')$reason=$autoCorrection?'Rate update (auto-detected supersede of prior approved row)':'Manual correction (no reason provided)';
        $margin=placementEconomicsApprovalSnapshot($scope['owner'],$rate);
        $q=$pdo->prepare('UPDATE placement_rates SET effective_to=DATE_SUB(?, INTERVAL 1 DAY),superseded_by=? WHERE tenant_id=? AND placement_id=? AND id<>? AND approved_at IS NOT NULL AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)');
        $q->execute([$rate['effective_from'],$rateId,$scope['owner'],$placement['id'],$rateId,$rate['effective_from'],$rate['effective_from']]);$closed=$q->rowCount();
        $q=$pdo->prepare('UPDATE placement_rates SET approved_by_user_id=?,approved_at=NOW(),adjusted_bill_rate=?,net_to_vendor=?,economics_snapshot_json=?,is_correction=?,correction_reason=? WHERE tenant_id=? AND placement_id=? AND id=? AND approved_at IS NULL');
        $q->execute([$user['id'],$margin['adjusted_bill_rate'],$margin['net_to_vendor'],json_encode($margin['economics_snapshot'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$isCorrection?1:0,$reason,$scope['owner'],$placement['id'],$rateId]);
        if($q->rowCount()!==1)throw new RuntimeException('The rate changed before approval. Refresh and review it again.',409);
        $q=$pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND placement_id=? ORDER BY id FOR UPDATE');$q->execute([$scope['owner'],$placement['id']]);$after=$q->fetchAll(PDO::FETCH_ASSOC);
        $receipt=['rate_id'=>$rateId,'effective_from'=>$rate['effective_from'],'is_correction'=>$isCorrection,'correction_reason'=>$reason,'superseded_count'=>$closed,'before'=>$rates,'after'=>$after];
        companySourceAudit($pdo,$scope['workspace'],$scope['owner'],'placements',(int)$placement['id'],'placement.rate.approved',['rates'],(int)$user['id'],$receipt);
        if($closed>0)companySourceAudit($pdo,$scope['workspace'],$scope['owner'],'placements',(int)$placement['id'],'placement.rate.superseded',['rates'],(int)$user['id'],$receipt);
        return ['margin'=>$margin,'superseded_count'=>$closed,'auto_correction'=>$autoCorrection];
    });
}
