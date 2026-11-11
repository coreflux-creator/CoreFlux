<?php
/** Native participant edits. The API authorizes placements.manage first. */
declare(strict_types=1);
require_once __DIR__ . '/ownership.php';
require_once __DIR__ . '/economics.php';

function placementEconomicsNativeDiffers(array $row, array $changes): bool
{
    foreach ($changes as $field => $value) {
        $prior = $row[$field] ?? null;
        if (($prior === null) !== ($value === null) || ($prior !== null && (string) $prior !== (string) $value)) return true;
    }
    return false;
}

function placementEconomicsApplyNativePartyEdit(int $tenantId, int $partyId, array $changes): array
{
    if (!$changes) throw new InvalidArgumentException('No fields to update.', 422);
    $allowed = ['fee_basis','fee_pct','fee_flat','payment_terms','pwp_enabled','operating_cycle_id','effective_from','effective_to','active','cadence'];
    foreach ($changes as $field => $value) {
        if (!in_array($field, $allowed, true) || (!is_scalar($value) && $value !== null)) {
            throw new InvalidArgumentException('This participant field cannot be edited here: ' . $field, 422);
        }
    }
    $pdo = getDB();
    $find = $pdo->prepare('SELECT placement_id FROM placement_economic_parties WHERE tenant_id=? AND id=?');
    $find->execute([$tenantId, $partyId]);
    $placementId = (int) $find->fetchColumn();
    if (!$placementId) throw new RuntimeException('Economic party not found.', 404);
    // Always lock parent before child, matching native placement and source writes.
    return placementWithLockedRecord($tenantId, $placementId, static function (array $placement, PDO $pdo) use ($tenantId, $partyId, $placementId, $changes): array {
        $owned = placementOwnershipFields($placement['coreflux_overridden_fields'] ?? null);
        $read = $pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND id=? FOR UPDATE');
        $read->execute([$tenantId, $placementId, $partyId]);
        $before = $read->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new RuntimeException('Economic party not found.', 404);
        if (!(int) $before['active']) throw new RuntimeException('Refresh the contract before editing an inactive participant.', 409);
        if (!empty($before['source_managed']) && array_diff(array_keys($changes), ['cadence','operating_cycle_id','payment_terms','pwp_enabled'])) {
            throw new RuntimeException('Edit this participant calculation or lifecycle in its source record.', 409);
        }
        $channel = (string) $before['settlement_channel'];
        $purpose = $channel === 'ar' ? 'billing' : $channel;
        foreach (['active','pwp_enabled'] as $field) {
            if (!array_key_exists($field, $changes)) continue;
            if (!in_array($changes[$field], [true,false,0,1,'0','1'], true)) throw new InvalidArgumentException('Choose yes or no for ' . $field, 422);
            $changes[$field] = (int) (bool) $changes[$field];
        }
        if (array_key_exists('payment_terms', $changes)) {
            if (!in_array($channel, ['ar','ap'], true)) throw new InvalidArgumentException('This participant does not use invoice payment terms.', 422);
            $terms = placementEconomicsNormaliseTerms($changes['payment_terms'] === null ? null : (string) $changes['payment_terms'], '');
            if ($terms === '') throw new InvalidArgumentException('Choose valid payment terms; returning ownership requires a separate review.', 422);
            $pwp = placementEconomicsTermsArePwp($terms);
            if ($channel === 'ar' && $pwp) throw new InvalidArgumentException('Paid when paid applies to AP, not client invoices.', 422);
            if (array_key_exists('pwp_enabled', $changes) && (bool) $changes['pwp_enabled'] !== $pwp) throw new InvalidArgumentException('Payment terms and paid-when-paid must agree.', 422);
            $changes['payment_terms'] = $terms;
            if ($channel === 'ap') $changes['pwp_enabled'] = (int) $pwp;
        }
        if (array_key_exists('pwp_enabled', $changes)) {
            if ($channel !== 'ap') {
                if ($changes['pwp_enabled']) throw new InvalidArgumentException('Paid when paid applies only to AP.', 422);
                unset($changes['pwp_enabled']); // AR term controls also submit false.
            } elseif (!$changes['pwp_enabled'] && placementEconomicsTermsArePwp($changes['payment_terms'] ?? $before['payment_terms'])) {
                throw new InvalidArgumentException('Choose non-PWP payment terms to disable paid when paid.', 422);
            }
        }
        $cycle = null;
        if (array_key_exists('cadence', $changes) || array_key_exists('operating_cycle_id', $changes)) {
            if (!in_array($purpose, ['billing','ap','payroll'], true)) throw new InvalidArgumentException('This participant does not use a payment frequency.', 422);
            if (array_key_exists('cadence', $changes)) {
                $cadence = strtolower(trim((string) $changes['cadence']));
                if (!in_array($cadence, ['weekly','biweekly','semimonthly','monthly','adhoc'], true)) throw new InvalidArgumentException('Choose a valid payment frequency.', 422);
                $resolved = placementEconomicsEnsureStandardCycle($tenantId, $purpose, $cadence);
                if (!$resolved) throw new RuntimeException('Could not resolve the requested payment frequency.', 422);
                if (array_key_exists('operating_cycle_id', $changes) && (string) $changes['operating_cycle_id'] !== (string) $resolved) throw new InvalidArgumentException('Frequency and cycle must identify the same schedule.', 422);
                $changes['operating_cycle_id'] = $resolved;
                unset($changes['cadence']);
            }
            if (!preg_match('/^[1-9][0-9]*$/D', (string) $changes['operating_cycle_id'])) throw new InvalidArgumentException('Choose an active cycle; returning ownership requires a separate review.', 422);
            $lookup = $pdo->prepare('SELECT * FROM staffing_operating_cycles WHERE tenant_id=? AND id=? AND active=1 FOR UPDATE');
            $lookup->execute([$tenantId, (int) $changes['operating_cycle_id']]);
            $cycle = $lookup->fetch(PDO::FETCH_ASSOC);
            if (!$cycle || $cycle['purpose'] !== $purpose) throw new InvalidArgumentException('Cycle must be an active ' . $purpose . ' cycle in this workspace.', 422);
            $changes['operating_cycle_id'] = (int) $cycle['id'];
        }
        if (array_key_exists('fee_basis', $changes) && !in_array($changes['fee_basis'], ['none','pay_rate','portal_fee_pct','portal_fee_flat','per_hour','per_invoice','one_time','pct_bill','pct_margin','net_margin','gross_margin','bill_rate','flat'], true)) throw new InvalidArgumentException('Invalid calculation basis.', 422);
        foreach (['fee_pct'=>6,'fee_flat'=>4] as $field => $precision) {
            if (!array_key_exists($field, $changes)) continue;
            if (!is_numeric($changes[$field]) || !is_finite((float) $changes[$field]) || (float) $changes[$field] < 0 || (float) $changes[$field] > ($field === 'fee_pct' ? 99.999999 : 99999999.9999)) throw new InvalidArgumentException('Invalid amount for ' . $field, 422);
            $changes[$field] = number_format((float) $changes[$field], $precision, '.', '');
        }
        foreach (['effective_from','effective_to'] as $field) {
            if (!array_key_exists($field, $changes)) continue;
            if ($changes[$field] === '' || $changes[$field] === null) { $changes[$field] = null; continue; }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $changes[$field]);
            if (!$date || $date->format('Y-m-d') !== $changes[$field]) throw new InvalidArgumentException('Choose a valid effective date.', 422);
        }
        $next = array_replace($before, $changes);
        if ($next['effective_from'] && $next['effective_to'] && $next['effective_to'] < $next['effective_from']) throw new InvalidArgumentException('The effective end cannot precede the start.', 422);
        if (!$changes) throw new InvalidArgumentException('No fields to update.', 422);
        foreach (['payment_terms'=>'payment_terms_overridden','pwp_enabled'=>'pwp_overridden','operating_cycle_id'=>'cycle_overridden'] as $field => $flag) {
            if (array_key_exists($field, $changes)) $next[$flag] = 1;
        }
        $partyChanged = placementEconomicsNativeDiffers($before, $next);
        $summary = [];
        $billTo = $before['source_type'] === 'placement' && $before['source_ref'] === 'placement:bill_to' && $before['role'] === 'bill_to_client' && $channel === 'ar';
        $worker = $before['source_type'] === 'worker' && $before['source_ref'] === 'worker:' . (int) $placement['person_id'] && (int) $before['person_id'] === (int) $placement['person_id'];
        $corp = $before['source_type'] === 'corp' && $before['source_ref'] === 'corp:' . $placementId && $channel === 'ap';
        $payrollModel = in_array($placement['engagement_type'], ['w2','temp_to_perm','internal'], true);
        $labor = ($corp && $placement['engagement_type'] === 'c2c') || ($worker && (($payrollModel && $channel === 'payroll') || ($placement['engagement_type'] === '1099' && $channel === 'ap')));
        if ($billTo || $labor) {
            if ($cycle) {
                $prefix = $billTo ? 'client_bill_cycle' : 'vendor_pay_cycle';
                $summary[$prefix] = $cycle['cadence'];
                $summary[$prefix . '_anchor'] = $cycle['anchor_date'];
                $summary[$purpose . '_operating_cycle_id'] = (int) $cycle['id'];
            }
            if (array_key_exists('payment_terms', $changes)) $summary[$billTo ? 'client_payment_terms_override' : 'vendor_payment_terms_override'] = $changes['payment_terms'];
            if ($labor && array_key_exists('pwp_enabled', $changes)) $summary['vendor_pwp_enabled'] = $changes['pwp_enabled'];
        }
        $corpBefore = null;
        $corpChanges = [];
        if ($corp && $labor) {
            $corpRead = $pdo->prepare('SELECT * FROM placement_corp_details WHERE tenant_id=? AND placement_id=? FOR UPDATE');
            $corpRead->execute([$tenantId, $placementId]);
            $corpBefore = $corpRead->fetch(PDO::FETCH_ASSOC);
            if (!$corpBefore || (int) $corpBefore['company_id'] !== (int) $before['company_id']) throw new RuntimeException('Review the corporate participant identity before editing its terms.', 409);
            if (array_key_exists('payment_terms', $changes)) $corpChanges['payment_terms_override'] = $changes['payment_terms'];
            if (array_key_exists('pwp_enabled', $changes)) $corpChanges['pwp_enabled'] = $changes['pwp_enabled'];
        }
        if ($summary && str_starts_with((string) $placement['external_id'], 'jd:')) {
            $summary['coreflux_overridden_fields'] = json_encode(array_values(array_unique(array_merge($owned, array_keys($summary)))), JSON_THROW_ON_ERROR);
        }
        $summaryChanged = placementEconomicsNativeDiffers($placement, $summary);
        $corpChanged = $corpBefore && placementEconomicsNativeDiffers($corpBefore, $corpChanges);
        if (!$partyChanged && !$summaryChanged && !$corpChanged) return ['changed'=>false, 'party'=>$before];
        if ($partyChanged) placementEconomicsUpdateParty($tenantId, $partyId, $changes);
        if ($summaryChanged) {
            $sets = array_map(static fn($field) => $field . ' = :' . $field, array_keys($summary));
            $pdo->prepare('UPDATE placements SET ' . implode(', ', $sets) . ' WHERE tenant_id=:t AND id=:p')->execute($summary + ['t'=>$tenantId,'p'=>$placementId]);
        }
        if ($corpChanged) {
            $sets = array_map(static fn($field) => $field . ' = :' . $field, array_keys($corpChanges));
            $pdo->prepare('UPDATE placement_corp_details SET ' . implode(', ', $sets) . ' WHERE tenant_id=:t AND placement_id=:p')->execute($corpChanges + ['t'=>$tenantId,'p'=>$placementId]);
        }
        $graph = placementEconomicsReconcile($tenantId, $placementId);
        if (!$graph['available'] || $graph['errors']) throw new RuntimeException('The contract graph could not be updated. Review its company links and try again.', 422);
        $read->execute([$tenantId, $placementId, $partyId]);
        $after = $read->fetch(PDO::FETCH_ASSOC);
        if (!$after || placementEconomicsNativeDiffers($after, array_intersect_key($next, $changes + ['active'=>1,'payment_terms_overridden'=>1,'pwp_overridden'=>1,'cycle_overridden'=>1]))) throw new RuntimeException('The contract did not retain the reviewed participant values.', 409);
        placementRequiredAudit('placement.economic_party.updated', [
            'placement_id'=>$placementId, 'economic_party_id'=>$partyId, 'fields'=>array_keys($changes),
            'before'=>$before, 'after'=>$after, 'placement_before'=>$placement,
            'placement_after'=>placementAuditRow($placementId), 'corp_before'=>$corpBefore,
            'corp_changes'=>$corpChanges,
        ], $placementId);
        return ['changed'=>true, 'party'=>$after];
    });
}
