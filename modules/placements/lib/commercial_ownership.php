<?php
/** Reviewed release of linked JobDiva commercial ownership, without applying source values. */
declare(strict_types=1);
require_once __DIR__ . '/ownership.php';

function placementCommercialOwnershipDefinitions(): array
{
    return [
        'client_terms'=>['label'=>'Client payment terms','side'=>'billing','fields'=>['client_payment_terms_override'],'flags'=>['payment_terms_overridden'],'values'=>['payment_terms']],
        'client_cycle'=>['label'=>'Client billing schedule','side'=>'billing','fields'=>['client_bill_cycle','client_bill_cycle_anchor','billing_operating_cycle_id'],'flags'=>['cycle_overridden'],'values'=>['operating_cycle_id']],
        'vendor_terms'=>['label'=>'Contractor terms and paid when paid','side'=>'labor','fields'=>['vendor_payment_terms_override','vendor_pwp_enabled'],'flags'=>['payment_terms_overridden','pwp_overridden'],'values'=>['payment_terms','pwp_enabled']],
        'vendor_cycle'=>['label'=>'Worker payment schedule','side'=>'labor','fields'=>['vendor_pay_cycle','vendor_pay_cycle_anchor','ap_operating_cycle_id','payroll_operating_cycle_id'],'flags'=>['cycle_overridden'],'values'=>['operating_cycle_id']],
    ];
}

function placementCommercialOwnershipFields(): array
{
    return array_values(array_unique(array_merge(...array_column(placementCommercialOwnershipDefinitions(), 'fields'))));
}

/** Caller holds the parent lock. Child locks keep the review coherent with native edits. */
function placementCommercialOwnershipPlan(PDO $pdo, array $placement): array
{
    $tenant = (int) $placement['tenant_id']; $id = (int) $placement['id'];
    $owned = placementOwnershipFields($placement['coreflux_overridden_fields'] ?? null);
    $read = $pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND active=1 ORDER BY id FOR UPDATE');
    $read->execute([$tenant, $id]); $parties = $read->fetchAll(PDO::FETCH_ASSOC);
    $clientRead = $pdo->prepare('SELECT id,company_id FROM staffing_clients WHERE tenant_id=? AND id=? FOR UPDATE');
    $clientRead->execute([$tenant,(int)($placement['client_id'] ?? 0)]); $client = $clientRead->fetch(PDO::FETCH_ASSOC) ?: null;
    $corpRead = $pdo->prepare('SELECT * FROM placement_corp_details WHERE tenant_id=? AND placement_id=? FOR UPDATE');
    $corpRead->execute([$tenant,$id]); $corp = $corpRead->fetch(PDO::FETCH_ASSOC) ?: null;
    $cycles = [];
    $cycleIds = array_values(array_unique(array_filter(array_map(static fn($p) => (int)($p['operating_cycle_id'] ?? 0), $parties)))); sort($cycleIds);
    $cycleRead = $pdo->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? AND id=? FOR UPDATE');
    foreach ($cycleIds as $cycleId) { $cycleRead->execute([$tenant,$cycleId]); $cycles[$cycleId] = $cycleRead->fetch(PDO::FETCH_ASSOC) ?: null; }
    $jd = preg_match('/^jd:[0-9]+$/D', (string) ($placement['external_id'] ?? '')) === 1;
    $billing = []; $labor = [];
    foreach ($parties as $party) {
        $channel = $party['settlement_channel'];
        // Include every participant that can protect these fields in the source
        // projector. An unresolved legacy or manual participant blocks release.
        $isBilling = $party['source_ref'] === 'placement:bill_to'
            || ($party['source_type'] === 'placement' && in_array($party['role'], ['bill_to_client','end_client'], true));
        $isLabor = in_array($party['role'], ['worker','c2c_vendor','individual_vendor'], true)
            || in_array($party['source_type'], ['worker','corp'], true);
        if ($isBilling && $channel === 'ar') $billing[] = $party;
        if ($isLabor && in_array($channel, ['ap','payroll'], true)) $labor[] = $party;
    }
    $groups = [];
    foreach (placementCommercialOwnershipDefinitions() as $key => $definition) {
        $linked = $definition['side'] === 'billing' ? $billing : $labor;
        if ($key === 'vendor_terms') $linked = array_values(array_filter($linked, static fn($p) => $p['settlement_channel'] === 'ap'));
        $releasedFields = array_values(array_intersect($owned, $definition['fields']));
        $ownedParty = false;
        foreach ($linked as $party) foreach ($definition['flags'] as $flag) if (!empty($party[$flag])) $ownedParty = true;
        if (!$releasedFields && !$ownedParty) continue;
        $reason = !$jd ? 'This placement has no verified JobDiva Start ID.' : '';
        if (count($linked) !== 1) $reason = 'Reconcile the commercial participants before returning these fields to JobDiva.';
        foreach ($linked as $party) {
            $canonicalBilling = $party['source_type'] === 'placement' && $party['source_ref'] === 'placement:bill_to' && $party['role'] === 'bill_to_client'
                && $client && (int)$party['company_id'] === (int)$client['company_id'];
            $canonicalWorker = $party['source_type'] === 'worker' && $party['source_ref'] === 'worker:' . (int) $placement['person_id']
                && (int) $party['person_id'] === (int) $placement['person_id']
                && (($placement['engagement_type'] === '1099' && $party['settlement_channel'] === 'ap')
                    || (in_array($placement['engagement_type'], ['w2','temp_to_perm','internal'], true) && $party['settlement_channel'] === 'payroll'));
            $canonicalCorp = $party['source_type'] === 'corp' && $party['source_ref'] === 'corp:' . $id
                && $placement['engagement_type'] === 'c2c' && $party['settlement_channel'] === 'ap'
                && $corp && (int)$corp['company_id'] === (int)$party['company_id'];
            if (empty($party['source_managed']) || $party['source_system'] !== 'jobdiva') {
                $reason = 'A participant is manually managed. Review its source link before releasing ownership.';
            } elseif ((string)$party['source_external_id'] !== substr((string)$placement['external_id'], 3)) {
                $reason = 'The participant source assignment differs from this placement. Review its source link first.';
            } elseif (!($definition['side'] === 'billing' ? $canonicalBilling : ($canonicalWorker || $canonicalCorp))) {
                $reason = 'A participant identity or employment model needs review before ownership can be released.';
            }
        }
        $groups[] = [
            'key'=>$key, 'label'=>$definition['label'], 'selectable'=>$reason === '', 'blocked_reason'=>$reason,
            'placement_values'=>array_intersect_key($placement, array_flip($definition['fields'])),
            'owned_placement_fields'=>$releasedFields,
            'corporation_values'=>$key === 'vendor_terms' && $corp ? array_intersect_key($corp,array_flip(['payment_terms_override','pwp_enabled'])) : null,
            'participants'=>array_map(static fn($p) => [
                'id'=>(int)$p['id'], 'name'=>$p['display_name'], 'source_ref'=>$p['source_ref'],
                'values'=>array_intersect_key($p, array_flip($definition['values'])),
                'cycle'=>$key === 'client_cycle' || $key === 'vendor_cycle' ? array_intersect_key($cycles[(int)($p['operating_cycle_id'] ?? 0)] ?? [],array_flip(['name','cadence','anchor_date'])) : null,
                'owned_flags'=>array_values(array_filter($definition['flags'], static fn($flag) => !empty($p[$flag]))),
            ], $linked),
        ];
    }
    // Current values and identities are part of the seal. This is permission
    // for a later source refresh, not a preview of a particular source value.
    $token = hash('sha256', json_encode(['version'=>1,'placement'=>$placement,'parties'=>$parties,'client'=>$client,'corp'=>$corp,'cycles'=>$cycles,'groups'=>$groups], JSON_THROW_ON_ERROR));
    return ['placement_id'=>$id,'source_external_id'=>$placement['external_id'],'review_token'=>$token,'groups'=>$groups];
}

function placementCommercialOwnershipReview(int $tenantId, int $placementId): array
{
    return placementWithLockedRecord($tenantId, $placementId, static fn(array $row, PDO $pdo): array => placementCommercialOwnershipPlan($pdo, $row));
}

function placementReleaseCommercialOwnership(int $tenantId, int $placementId, array $selected, string $token): array
{
    $definitions = placementCommercialOwnershipDefinitions();
    if (!$selected || !array_is_list($selected) || array_filter($selected, static fn($key) => !is_string($key) || !isset($definitions[$key]))
        || count($selected) !== count(array_unique($selected)) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        throw new InvalidArgumentException('Choose reviewed contract fields to return to JobDiva.', 422);
    }
    return placementWithLockedRecord($tenantId, $placementId, static function (array $row, PDO $pdo) use ($tenantId,$placementId,$selected,$token,$definitions): array {
        $plan = placementCommercialOwnershipPlan($pdo, $row);
        if (!hash_equals($plan['review_token'], $token)) throw new RuntimeException('The reviewed contract changed. Refresh the ownership review and choose the fields again.', 409);
        $byKey = array_column($plan['groups'], null, 'key'); $fields = []; $partyFlags = [];
        foreach ($selected as $key) {
            if (!isset($byKey[$key]) || !$byKey[$key]['selectable']) throw new RuntimeException('A selected ownership group is unavailable. Refresh and review its participant links.', 409);
            $fields = array_merge($fields, $byKey[$key]['owned_placement_fields']);
            foreach ($byKey[$key]['participants'] as $party) $partyFlags[$party['id']] = array_values(array_unique(array_merge($partyFlags[$party['id']] ?? [], $party['owned_flags'])));
        }
        $owned = placementOwnershipFields($row['coreflux_overridden_fields'] ?? null);
        $remaining = array_values(array_diff($owned, $fields));
        if ($remaining !== $owned) $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE tenant_id=? AND id=?')->execute([$remaining ? json_encode($remaining, JSON_THROW_ON_ERROR) : null,$tenantId,$placementId]);
        foreach ($partyFlags as $partyId => $flags) {
            if (!$flags) continue;
            $sets = array_map(static fn($flag) => $flag . '=0', $flags);
            $pdo->prepare('UPDATE placement_economic_parties SET ' . implode(',', $sets) . ' WHERE tenant_id=? AND placement_id=? AND id=?')->execute([$tenantId,$placementId,$partyId]);
        }
        // Keep all current values, identities and corporation source records.
        // Only a later source reconciliation may replace released values.
        placementRequiredAudit('placement.commercial_ownership.released', [
            'placement_id'=>$placementId,'groups'=>$selected,'placement_fields'=>$fields,'participant_flags'=>$partyFlags,
            'review_token'=>$token,'review'=>$plan,'owned_fields_before'=>$owned,'owned_fields_after'=>$remaining,
            'values_applied'=>false,
        ], $placementId);
        return ['changed'=>true,'released_groups'=>$selected,'released_placement_fields'=>$fields,'released_participant_flags'=>$partyFlags,'values_applied'=>false];
    });
}
