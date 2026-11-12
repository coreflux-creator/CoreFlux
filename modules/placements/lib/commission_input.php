<?php
declare(strict_types=1);

/** Commission terms retain source precision; null means unreviewed, never USD. */
function placementCommissionInput(array $input): array
{
    $allowed = ['role', 'user_id', 'plan_id', 'split_pct', 'basis', 'flat_amount',
        'effective_from', 'effective_to', 'notes', 'currency', 'payment_frequency', 'due_month'];
    if (array_diff(array_keys($input), $allowed)) {
        throw new InvalidArgumentException(
            'Unsupported commission fields. Use currency, payment frequency and due month for the complete terms; do not put payment terms into effective dates.'
        );
    }
    foreach ($input as $value) {
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException('Commission fields must contain single values. Keep structured source terms for review.');
        }
    }
    if (array_key_exists('basis', $input)
        && !in_array($input['basis'], ['net_margin', 'gross_margin', 'bill_rate', 'flat'], true)) {
        throw new InvalidArgumentException('Unsupported commission basis. Flat does not establish a one-time payment or a payment frequency.');
    }
    return $input;
}

/** Shared by native entry, CSV and source mappings, including partial updates. */
function placementCommissionTermsInput(array $input): array
{
    foreach (['currency', 'payment_frequency', 'due_month'] as $field) {
        if (!array_key_exists($field, $input)) continue;
        $value = $input[$field];
        if ($value === '' || $value === null) { $input[$field] = null; continue; }
        if (!is_string($value)) throw new InvalidArgumentException('Invalid commission '.$field.'.', 422);
        $value = trim($value);
        if ($field === 'currency') {
            $value = strtoupper($value);
            // Supported two-decimal commission denominations; no FX inference.
            if (!in_array($value, ['USD','CAD','GBP','EUR','INR','AUD'], true)) throw new InvalidArgumentException('Choose a supported commission currency.', 422);
        } elseif ($field === 'payment_frequency') {
            if (!in_array($value, ['one_time','per_pay_period'], true)) throw new InvalidArgumentException('Choose one time or per pay period.', 422);
        } elseif (!preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D', $value)) {
            throw new InvalidArgumentException('Enter the commission due month as YYYY-MM.', 422);
        }
        $input[$field] = $value;
    }
    return $input;
}

function placementCommissionTermsIssue(array $row): ?string
{
    $flat = ($row['basis'] ?? $row['fee_basis'] ?? '') === 'flat';
    $currency = $row['currency'] ?? null;
    $frequency = $row['payment_frequency'] ?? null;
    $month = $row['due_month'] ?? null;
    if (!$flat) {
        return $currency || $month || ($frequency && $frequency !== 'per_pay_period')
            ? 'Percentage commissions follow the calculation currency and earned pay period; separate fixed terms require a flat amount.' : null;
    }
    try { placementCommissionTermsInput(compact('currency') + ['payment_frequency'=>$frequency,'due_month'=>$month]); }
    catch (InvalidArgumentException $e) { return $e->getMessage(); }
    if (!$currency || !$frequency) return 'Review the flat commission currency and payment frequency before settlement.';
    if ($frequency === 'one_time' && !$month) return 'Choose the due month for the one-time commission.';
    if ($frequency === 'per_pay_period' && $month) return 'A recurring commission uses its effective dates; clear the one-time due month.';
    return null;
}

function placementCommissionCurrencyTotals(array $lines, string $contractCurrency): array
{
    $totals=[];
    foreach($lines as $line) {
        $currency=array_key_exists('currency',$line)?($line['currency']?:'UNKNOWN'):$contractCurrency;
        $totals[$currency]=round(($totals[$currency]??0)+(float)$line['amount'],2);
    }
    ksort($totals);
    return $totals;
}

/** Serialized with rule edits; return zero when that scheduled amount is already claimed. */
function placementCommissionClaim(int $tenant,int $placement,int $partyId,string $sourceType,int $sourceId,array $values): int
{
    require_once __DIR__.'/ownership.php';
    return placementWithLockedRecord($tenant,$placement,static function(array $parent,PDO $pdo)use($tenant,$placement,$partyId,$sourceType,$sourceId,$values):int{
        $terms=$values['commission_terms'];
        if($issue=placementCommissionTermsIssue($terms))throw new RuntimeException($issue,409);
        if(($terms['source_type']??'')!=='commission'||($terms['fee_basis']??'')!=='flat'
            ||($values['status']??'')!=='payroll'||empty($values['payroll_ref_id'])
            ||($values['currency']??null)!=='USD'
            ||($values['currency']??null)!==$terms['currency']
            ||round((float)($values['amount']??0),2)!==round((float)$terms['fee_flat'],2))throw new RuntimeException('Review the commission settlement terms.',409);
        $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND id=? FOR UPDATE');
        $q->execute([$tenant,$placement,$partyId]);$current=$q->fetch(PDO::FETCH_ASSOC);
        if(!$current||$current['source_type']!=='commission'||empty($current['active']))throw new RuntimeException('The commission participant needs review.',409);
        foreach(['user_id','person_id','ap_vendor_id','settlement_channel'] as $field) {
            if((string)($current[$field]??'')!==(string)($terms[$field]??''))throw new RuntimeException('The commission recipient changed since this contract was approved. Review the recipient before settlement.',409);
        }
        $frequency=$terms['payment_frequency'];
        if($frequency==='one_time' && $terms['due_month']>substr((string)($values['period_end']??''),0,7))throw new RuntimeException('The commission due month has not arrived.',409);
        $sql='SELECT * FROM placement_economic_obligations WHERE tenant_id=? AND placement_id=? AND economic_party_id=? AND status<>"void"';
        $params=[$tenant,$placement,$partyId];
        if($frequency==='per_pay_period'){$sql.=' AND payroll_ref_id=?';$params[]=$values['payroll_ref_id'];}
        $q=$pdo->prepare($sql.' FOR UPDATE');$q->execute($params);$prior=$q->fetchAll(PDO::FETCH_ASSOC);
        if($prior) {
            if(count($prior)!==1||$prior[0]['currency']!==$values['currency']||round((float)$prior[0]['amount'],2)!==round((float)$values['amount'],2))throw new RuntimeException('A different commission amount is already reserved for this payment. Review it before continuing.',409);
            placementPayrollReceiptEvidence($prior[0],placementPayrollReceipt($pdo,$tenant,(int)$prior[0]['id']));
            return 0;
        }
        return placementEconomicsWriteObligation($tenant,$placement,$partyId,$sourceType,$sourceId,$values);
    });
}

/** Consumed rules retain their monetary and recipient contract. Future terms need a new rule. */
function placementCommissionProtectConsumed(PDO $pdo,int $tenant,int $placement,int $ruleId,array $changes): void
{
    $fields=['user_id','basis','split_pct','flat_amount','currency','payment_frequency','due_month'];
    $changes=array_intersect_key($changes,array_flip($fields));
    if(!$changes||$ruleId<=0)return;
    $q=$pdo->prepare('SELECT c.* FROM placement_commissions c WHERE c.tenant_id=? AND c.placement_id=? AND c.id=?');
    $q->execute([$tenant,$placement,$ruleId]);$before=$q->fetch(PDO::FETCH_ASSOC);
    if(!$before)return;
    $changed=false;
    foreach($changes as $field=>$value) {
        $prior=$before[$field]??null;
        if(in_array($field,['flat_amount','split_pct'],true)&&$prior!==null&&$value!==null) {if((float)$prior!==(float)$value)$changed=true;}
        elseif((string)$prior!==(string)$value)$changed=true;
    }
    if(!$changed)return;
    if(placementCommissionHasObligations($pdo,$tenant,$placement,$ruleId))throw new RuntimeException('This commission has entered a financial workflow. Keep its recipient and terms; end this rule and add a new rule for future changes.',409);
}

function placementCommissionHasObligations(PDO $pdo,int $tenant,int $placement,int $ruleId): bool
{
    $q=$pdo->prepare('SELECT o.id FROM placement_economic_obligations o JOIN placement_economic_parties p ON p.id=o.economic_party_id AND p.tenant_id=o.tenant_id AND p.placement_id=o.placement_id WHERE p.tenant_id=? AND p.placement_id=? AND p.source_ref=? AND o.status<>"void" LIMIT 1');
    $q->execute([$tenant,$placement,'commission:'.$ruleId]);
    return (bool)$q->fetchColumn();
}
