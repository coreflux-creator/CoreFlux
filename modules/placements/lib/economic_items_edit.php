<?php
/** Native one-time items share the placement lock with source and settlement writers. */
declare(strict_types=1);
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/economics.php';

function placementEconomicItemInput(array $body): array
{
    $allowed = ['description','settlement_channel','direction','amount','currency','apply_on','taxable','economic_party_id','request_id'];
    foreach ($body as $field => $value) {
        if (!in_array($field, $allowed, true) || (!is_scalar($value) && $value !== null)) throw new InvalidArgumentException('Invalid one-time item field: ' . $field, 422);
    }
    $key = strtolower(trim((string)($body['request_id'] ?? '')));
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $key)) throw new InvalidArgumentException('Reload the item form before saving.', 422);
    $description = trim((string)($body['description'] ?? ''));
    if ($description === '' || mb_strlen($description) > 255) throw new InvalidArgumentException('Enter an item description of at most 255 characters.', 422);
    $channel = strtolower(trim((string)($body['settlement_channel'] ?? '')));
    $direction = strtolower(trim((string)($body['direction'] ?? 'charge')));
    if (!in_array($channel, ['ar','ap','payroll','none'], true)) throw new InvalidArgumentException('Invalid destination.', 422);
    if (!in_array($direction, ['charge','credit'], true)) throw new InvalidArgumentException('Invalid item effect.', 422);
    if ($direction === 'credit' && in_array($channel, ['ap','payroll'], true)) throw new InvalidArgumentException('Use the vendor-credit or payroll-deduction workflow for this item.', 422);
    $rawAmount = trim((string)($body['amount'] ?? ''));
    if (is_bool($body['amount'] ?? null) || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $rawAmount) || (float)$rawAmount <= 0) throw new InvalidArgumentException('Enter a positive amount with at most two decimal places.', 422);
    $currency = strtoupper(trim((string)($body['currency'] ?? 'USD')));
    if (!preg_match('/^[A-Z]{3}$/D', $currency)) throw new InvalidArgumentException('Currency must be a three-letter code.', 422);
    $applyOn = (string)($body['apply_on'] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $applyOn);
    if (!$date || $date->format('Y-m-d') !== $applyOn) throw new InvalidArgumentException('Apply date must be a valid date.', 422);
    $taxable = $body['taxable'] ?? false;
    if (!in_array($taxable, [true,false,0,1,'0','1'], true) || ($taxable && $channel !== 'ar')) throw new InvalidArgumentException('Taxable applies only to a client invoice item.', 422);
    $party = $body['economic_party_id'] ?? null;
    if ($party === '') $party = null;
    if ($party !== null && !preg_match('/^[1-9][0-9]*$/D', (string)$party)) throw new InvalidArgumentException('Choose a valid item recipient.', 422);
    if ($channel !== 'none' && $party === null) throw new InvalidArgumentException('Choose the recipient for this item.', 422);
    if ($channel === 'none' && $party !== null) throw new InvalidArgumentException('Margin-only items do not have a settlement recipient.', 422);
    return ['description'=>$description,'settlement_channel'=>$channel,'direction'=>$direction,
        'amount'=>number_format((float)$rawAmount,2,'.',''),'currency'=>$currency,'apply_on'=>$applyOn,
        'taxable'=>(int)(bool)$taxable,'economic_party_id'=>$party === null ? null : (int)$party,
        'source_system'=>'coreflux','source_external_id'=>'native-item:' . $key,'source_managed'=>0];
}

function placementEconomicItemRead(PDO $pdo, int $tenant, int $placement, int $id): array
{
    $read = $pdo->prepare('SELECT * FROM placement_economic_items WHERE tenant_id=? AND placement_id=? AND id=? FOR UPDATE');
    $read->execute([$tenant,$placement,$id]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('One-time item not found.', 404);
    return $row;
}

function placementEconomicsCreateNativeItem(int $tenant, int $placement, array $body, array $user = []): array
{
    $item = placementEconomicItemInput($body);
    return placementWithLockedRecord($tenant,$placement,static function(array $parent, PDO $pdo) use ($tenant,$placement,$item,$user): array {
        $prior = $pdo->prepare('SELECT * FROM placement_economic_items WHERE tenant_id=? AND placement_id=? AND source_system=? AND source_external_id=? FOR UPDATE');
        $prior->execute([$tenant,$placement,$item['source_system'],$item['source_external_id']]);
        $existing = $prior->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            foreach ($item as $field=>$value) {
                if (($existing[$field] === null) !== ($value === null) || ($value !== null && (string)$existing[$field] !== (string)$value)) throw new RuntimeException('This save request already belongs to a different item. Review the saved item before creating another.',409);
            }
            return ['id'=>(int)$existing['id'],'changed'=>false,'item'=>$existing];
        }
        $rate = $pdo->prepare('SELECT currency FROM placement_rates WHERE tenant_id=? AND placement_id=? AND effective_from<=? ORDER BY (approved_at IS NOT NULL) DESC,effective_from DESC,id DESC LIMIT 1 FOR UPDATE');
        $rate->execute([$tenant,$placement,$item['apply_on']]);
        $currency = $rate->fetchColumn();
        if ($currency === false) {
            $any = $pdo->prepare('SELECT id FROM placement_rates WHERE tenant_id=? AND placement_id=? LIMIT 1 FOR UPDATE');
            $any->execute([$tenant,$placement]);
            if ($any->fetchColumn()) throw new InvalidArgumentException('No contract rate covers this item date. Review its apply date.',422);
            $currency = 'USD';
        }
        if (strtoupper((string)$currency) !== $item['currency']) throw new InvalidArgumentException('One-time items must use the placement contract currency.',422);
        if ($item['economic_party_id'] !== null) {
            $party = $pdo->prepare('SELECT settlement_channel FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND id=? AND active=1 FOR UPDATE');
            $party->execute([$tenant,$placement,$item['economic_party_id']]);
            if ($party->fetchColumn() !== $item['settlement_channel']) throw new InvalidArgumentException('The selected recipient does not match this item destination.',422);
        }
        $id = placementEconomicsCreateItem($tenant,$placement,$item+['created_by_user_id'=>$user['id'] ?? null]);
        $after = placementEconomicItemRead($pdo,$tenant,$placement,$id);
        placementRequiredAudit('placement.economic_item.added',['placement_id'=>$placement,'economic_item_id'=>$id,'after'=>$after],$placement);
        return ['id'=>$id,'changed'=>true,'item'=>$after];
    });
}

function placementEconomicsRemoveNativeItem(int $tenant, int $id): array
{
    $pdo = getDB();
    $find = $pdo->prepare('SELECT placement_id FROM placement_economic_items WHERE tenant_id=? AND id=?');
    $find->execute([$tenant,$id]); $placement = (int)$find->fetchColumn();
    if (!$placement) throw new RuntimeException('One-time item not found.',404);
    return placementWithLockedRecord($tenant,$placement,static function(array $parent, PDO $pdo) use ($tenant,$placement,$id): array {
        $before = placementEconomicItemRead($pdo,$tenant,$placement,$id);
        if (!empty($before['source_managed'])) throw new RuntimeException('This item is managed by its source integration.',409);
        if ($before['status'] === 'void') return ['id'=>$id,'changed'=>false,'item'=>$before];
        $used = $pdo->prepare('SELECT id FROM placement_economic_obligations WHERE tenant_id=? AND source_type="economic_item" AND source_ref_id=? AND status<>"void" FOR UPDATE');
        $used->execute([$tenant,$id]);
        if ($used->fetchColumn()) throw new RuntimeException('This item has already been applied and cannot be removed.',409);
        $pdo->prepare('UPDATE placement_economic_items SET status="void" WHERE tenant_id=? AND placement_id=? AND id=?')->execute([$tenant,$placement,$id]);
        $after = placementEconomicItemRead($pdo,$tenant,$placement,$id);
        placementRequiredAudit('placement.economic_item.removed',['placement_id'=>$placement,'economic_item_id'=>$id,'before'=>$before,'after'=>$after],$placement);
        return ['id'=>$id,'changed'=>true,'item'=>$after];
    });
}

/** All item consumers take the same lock before claiming an item or replaying a claim. */
function placementEconomicsClaimItem(int $tenant, int $placement, int $partyId, int $id, array $values): int
{
    return placementWithLockedRecord($tenant,$placement,static function(array $parent, PDO $pdo) use ($tenant,$placement,$partyId,$id,$values): int {
        $item = placementEconomicItemRead($pdo,$tenant,$placement,$id);
        if ($item['status'] !== 'active' || (int)$item['economic_party_id'] !== $partyId || $partyId <= 0) throw new RuntimeException('The one-time item or its settlement recipient changed. Refresh the settlement preview.',409);
        $targetField = ['ar'=>'ar_invoice_id','ap'=>'ap_bill_id','payroll'=>'payroll_ref_id'][$item['settlement_channel']] ?? null;
        if (!$targetField) throw new RuntimeException('Margin-only items cannot be settled.',422);
        if (!preg_match('/^[1-9][0-9]*$/D',(string)($values[$targetField] ?? '')) || !in_array($values['status'] ?? '',['projected','billed','payroll','paid'],true)) throw new RuntimeException('Choose a valid settlement record and state for this item.',422);
        foreach (['ar_invoice_id','ap_bill_id','payroll_ref_id'] as $field) {
            if ($field !== $targetField && !empty($values[$field])) throw new RuntimeException('The one-time item destination differs from the settlement.',409);
        }
        $amount = (float)$item['amount'] * ($item['direction'] === 'credit' ? -1 : 1);
        if (!isset($values['amount']) || !is_numeric($values['amount']) || !is_finite((float)$values['amount']) || abs((float)$values['amount']-$amount) > 0.000001
            || strtoupper((string)($values['currency'] ?? '')) !== $item['currency']) throw new RuntimeException('The one-time item amount or currency differs from the settlement.',409);
        $read = $pdo->prepare('SELECT * FROM placement_economic_obligations WHERE tenant_id=? AND source_type="economic_item" AND source_ref_id=? ORDER BY id FOR UPDATE');
        $read->execute([$tenant,$id]);
        foreach ($read->fetchAll(PDO::FETCH_ASSOC) as $prior) {
            if ($prior['status'] === 'void') continue;
            if ((int)$prior['economic_party_id'] !== $partyId || (int)$prior['placement_id'] !== $placement
                || (int)($prior[$targetField] ?? 0) !== (int)($values[$targetField] ?? 0)
                || abs((float)$prior['amount']-$amount) > 0.000001 || $prior['currency'] !== $item['currency']) throw new RuntimeException('This one-time item is already assigned to another settlement.',409);
            // A native retry must not move a claim to another document or regress its status.
            return (int)$prior['id'];
        }
        $party = $pdo->prepare('SELECT settlement_channel FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND id=? AND active=1 FOR UPDATE');
        $party->execute([$tenant,$placement,$partyId]);
        if ($party->fetchColumn() !== $item['settlement_channel']) throw new RuntimeException('The one-time item recipient is no longer available.',409);
        return placementEconomicsWriteObligation($tenant,$placement,$partyId,'economic_item',$id,$values);
    });
}
