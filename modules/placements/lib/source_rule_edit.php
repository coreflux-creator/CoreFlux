<?php
/** Atomic native legacy rule edits; API callers enforce the rule-specific permission. */
declare(strict_types=1);
require_once __DIR__ . '/source_rule_ownership.php';
require_once __DIR__ . '/economics.php';
require_once __DIR__ . '/source_rule_input.php';
require_once __DIR__ . '/../../../core/memberships.php';

function placementSourceRuleMember(PDO $pdo, int $workspace, int $owner, mixed $id): void
{
    if (!$id) throw new InvalidArgumentException('Choose an active recipient.',422);
    // A private workspace member cannot become a payee in a shared parent graph.
    foreach(array_unique([$workspace,$owner]) as $tenant) {
        $q=$pdo->prepare('SELECT u.id FROM users u JOIN '.membershipReadSourceSql().' m ON m.user_id=u.id WHERE m.tenant_id=? AND u.id=? AND u.is_active=1 LIMIT 1');
        $q->execute([$tenant,$id]);
        if (!$q->fetchColumn()) throw new InvalidArgumentException('Recipient is not active in the placement workspace.',422);
    }
}

/** Resolve selected identity; never keep hidden IDs for another recipient type. */
function placementSourceRuleResolve(PDO $pdo,int $tenant,int $placement,int $id,string $kind,array $values,array $input,array $user): array
{
    if ($kind==='commission') {
        placementSourceRuleMember($pdo,(int)currentTenantId(),$tenant,$values['user_id']??null);
        if (!empty($input['plan_id'])) throw new InvalidArgumentException('Commission plans are not configured. Keep the plan reference for review.',422);
        return $values;
    }
    $type=$kind==='chain'?'vendor':$values['referrer_type'];
    $companyField=$kind==='chain'?'company_id':'referrer_company_id';
    $nameField=$kind==='chain'?'party_name':'referrer_vendor_name';
    if ($kind==='referral') {
        $identityFields=['vendor'=>['referrer_company_id','referrer_vendor_name'],'person'=>['referrer_person_id'],'user'=>['referrer_user_id']];
        foreach(array_diff(array_merge(...array_values($identityFields)),$identityFields[$type]) as $field) {
            if (!empty($input[$field])) throw new InvalidArgumentException('Choose only the identity matching the referrer type.',422);
            $values[$field]=null;
        }
        if ($type==='user') placementSourceRuleMember($pdo,(int)currentTenantId(),$tenant,$values['referrer_user_id']??null);
        if ($type==='person') {
            $owner=(int)effectiveTenantIdForModule('people',$tenant);
            if ($owner!==(int)effectiveTenantIdForModule('people',(int)currentTenantId())) throw new InvalidArgumentException('This placement cannot use a private people directory.',422);
            $q=$pdo->prepare('SELECT id FROM people WHERE tenant_id=? AND id=? AND deleted_at IS NULL');
            $q->execute([$owner,$values['referrer_person_id']??0]);
            if (!$q->fetchColumn()) throw new InvalidArgumentException('Choose a person in the placement directory.',422);
        }
    }
    if ($kind==='chain') {
        $q=$pdo->prepare('SELECT id FROM placement_client_chain WHERE tenant_id=? AND placement_id=? AND position=? AND id<>?');
        $q->execute([$tenant,$placement,$values['position'],$id]);
        if($q->fetchColumn()) throw new RuntimeException('That chain position is already occupied.',409);
        if(!empty($values['vendor_portal_id'])) {
            $q=$pdo->prepare('SELECT id FROM tenant_vendor_portals WHERE tenant_id=? AND id=? AND active=1');$q->execute([$tenant,$values['vendor_portal_id']]);
            if(!$q->fetchColumn()) throw new InvalidArgumentException('Choose an active portal in this placement directory.',422);
        }
        if(!empty($input['contract_storage_object_id'])) {
            $q=$pdo->prepare('SELECT id FROM storage_objects WHERE tenant_id=? AND id=? AND module="placements" AND entity_type="chain_contract" AND entity_id=? AND soft_deleted_at IS NULL');
            $q->execute([$tenant,$input['contract_storage_object_id'],$id]);
            if($id<=0 || !$q->fetchColumn()) throw new InvalidArgumentException('Choose a contract file belonging to this chain row.',422);
        }
    }
    if ($type==='vendor') {
        $owner=placementsCompanyDirectoryTenantId();
        $roles=$kind==='chain'?[in_array($values['party_role'],['end_client','direct'],true)?'client':$values['party_role']]:['referrer','vendor'];
        if (!empty($values[$companyField])) {
            $company=companiesGet((int)$values[$companyField],$owner);
            if (!$company) throw new InvalidArgumentException('Choose a company in the placement directory.',422);
            if (array_key_exists($nameField,$input) && !array_key_exists($companyField,$input) && $input[$nameField]!==$company['name']) throw new InvalidArgumentException('Select the company to change the party identity.',422);
            $values[$nameField]=$company['name'];
        } else {
            if (empty($values[$nameField])) throw new InvalidArgumentException('Choose or name the company.',422);
            $values[$companyField]=companiesUpsertByName($owner,$values[$nameField],['created_by_user_id'=>$user['id']??null],$roles);
        }
    }
    if (!empty($values['payment_terms_override'])) $values['payment_terms_override']=placementEconomicsNormaliseTerms($values['payment_terms_override']);
    return $values;
}

function placementSourceRuleEdit(int $tenant,int $placement,string $kind,string $operation,int $id,array $body,array $user): array
{
    $definition=placementSourceRuleDefinitions()[$kind]??null;
    if(!$definition || !in_array($operation,['create','update','delete'],true)) throw new InvalidArgumentException('Invalid rule operation.',422);
    $request=$body['request_id']??null;unset($body['request_id']);
    if($operation==='create' && (!is_string($request) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$request))) throw new InvalidArgumentException('Reload the rule form before saving.',422);
    if($operation!=='create' && $request!==null) throw new InvalidArgumentException('Request IDs apply to new rules only.',422);
    $input=placementSourceRuleInput($kind,$body); $table=$definition['table'];
    if($operation!=='delete' && !$input) throw new InvalidArgumentException('Choose rule fields to save.',422);
    return placementWithLockedRecord($tenant,$placement,static function(array $parent,PDO $pdo)use($tenant,$placement,$kind,$operation,$id,$input,$user,$definition,$table,$request):array{
        $owned=placementOwnershipFields($parent['coreflux_overridden_fields']??null);
        if($request!==null) {
            $q=$pdo->prepare('SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event=? AND JSON_UNQUOTE(JSON_EXTRACT(meta_json,"$.request_id"))=? ORDER BY id DESC LIMIT 1');
            $q->execute([currentTenantId(),$placement,'placement.'.$kind.'.added',$request]);$receipt=$q->fetchColumn();
            if($receipt!==false) {
                $receipt=json_decode($receipt,true,512,JSON_THROW_ON_ERROR);$seen=$receipt['input']??[];$expected=$input;ksort($seen);ksort($expected);
                if($seen!==$expected) throw new RuntimeException('This save request belongs to a different rule. Refresh and review the saved rule.',409);
                $q=$pdo->prepare('SELECT id FROM '.$table.' WHERE tenant_id=? AND placement_id=? AND id=?');$q->execute([$tenant,$placement,$receipt['rule_id']]);
                if(!$q->fetchColumn()) throw new RuntimeException('The saved rule has since been removed. Refresh the contract.',409);
                return ['id'=>(int)$receipt['rule_id'],'changed'=>false,'replayed'=>true];
            }
        }
        $before=null;
        if($operation!=='create') {
            $q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=? AND placement_id=? AND id=? FOR UPDATE');$q->execute([$tenant,$placement,$id]);$before=$q->fetch(PDO::FETCH_ASSOC);
            if(!$before) throw new RuntimeException('Rule not found.',404);
        }
        $after=null;$values=[];
        if($operation!=='delete') {
            $defaults=match($kind){'chain'=>['pwp_enabled'=>0,'is_payable'=>0],'referral'=>['pwp_enabled'=>0],default=>['basis'=>'net_margin']};
            $values=array_intersect_key(array_replace($defaults,$before??[],$input),array_flip(placementSourceRuleFields($kind)));
            placementSourceRuleValues($kind,$values);
            if ($kind==='commission' && ($operation==='create' || array_intersect(array_keys($input),['basis','flat_amount','split_pct','currency','payment_frequency','due_month']))) {
                $issue=placementCommissionTermsIssue($values);
                if($issue)throw new InvalidArgumentException($issue,422);
            }
            $values=placementSourceRuleResolve($pdo,$tenant,$placement,$id,$kind,$values,$input,$user);
            if($kind==='commission' && $before)placementCommissionProtectConsumed($pdo,$tenant,$placement,$id,$values);
        }
        // A separately reviewed participant must not silently swallow this edit.
        if($before) {
            $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=? FOR UPDATE');$q->execute([$tenant,$placement,$kind.':'.$id]);$party=$q->fetch(PDO::FETCH_ASSOC);
            if($party && (empty($party['source_managed']) || ($operation!=='delete' &&
                ((!empty($party['payment_terms_overridden']) && array_key_exists('payment_terms_override',$input))
                || (!empty($party['pwp_overridden']) && array_key_exists('pwp_enabled',$input)))))) throw new RuntimeException('This participant has separate reviewed settings. Review those settings on the contract before changing its source rule.',409);
        }
        $next=array_values(array_unique(array_merge($owned,[$definition['field']])));
        $changed=$operation!=='update' || $next!==$owned;
        foreach($values as $field=>$value) if(($before[$field]??null)!==$value && (($before[$field]??null)===null || $value===null || (string)$before[$field]!== (string)$value)) $changed=true;
        if(!$changed) return ['id'=>$id,'changed'=>false,'company_id'=>$values[$kind==='chain'?'company_id':'referrer_company_id']??null];
        $companyId=$values[$kind==='chain'?'company_id':'referrer_company_id']??null;
        if($companyId) {
            $roles=$kind==='chain'?[in_array($values['party_role'],['end_client','direct'],true)?'client':$values['party_role']]:['referrer','vendor'];
            foreach($roles as $role) companiesAddRole((int)$companyId,$role);
        }
        if($operation==='create') {
            $columns=array_keys($values);$sql='INSERT INTO '.$table.' (tenant_id,placement_id,'.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns)+2,'?')).')';
            $pdo->prepare($sql)->execute(array_merge([$tenant,$placement],array_values($values)));$id=(int)$pdo->lastInsertId();
        } elseif($operation==='update') {
            $pdo->prepare('UPDATE '.$table.' SET '.implode(',',array_map(static fn($field)=>$field.'=?',array_keys($values))).' WHERE tenant_id=? AND placement_id=? AND id=?')->execute(array_merge(array_values($values),[$tenant,$placement,$id]));
        } else $pdo->prepare('DELETE FROM '.$table.' WHERE tenant_id=? AND placement_id=? AND id=?')->execute([$tenant,$placement,$id]);
        $pdo->prepare('UPDATE placements SET coreflux_overridden_fields=? WHERE tenant_id=? AND id=?')->execute([json_encode($next,JSON_THROW_ON_ERROR),$tenant,$placement]);
        $graph=placementEconomicsReconcile($tenant,$placement);
        if(empty($graph['available'])) throw new RuntimeException('The rule could not be applied to the contract. Review the placement setup.',503);
        if($operation!=='delete') {$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE tenant_id=? AND placement_id=? AND id=?');$q->execute([$tenant,$placement,$id]);$after=$q->fetch(PDO::FETCH_ASSOC);}
        // Chain credentials are not commercial source evidence or audit data.
        foreach(['portal_credentials_ct','kms_key_version'] as $private){unset($before[$private],$after[$private]);}
        placementRequiredAudit('placement.'.$kind.'.'.match($operation){'create'=>'added','update'=>'updated',default=>'removed'},[
            'placement_id'=>$placement,'rule_id'=>$id,'before'=>$before,'after'=>$after,'owned_fields_before'=>$owned,'owned_fields_after'=>$next,
            'ownership_scope'=>'complete_rule_set','source_refresh_held'=>true,
            'request_id'=>$request,'input'=>$input,
        ],$placement);
        return ['id'=>$id,'changed'=>true,'company_id'=>$values[$kind==='chain'?'company_id':'referrer_company_id']??null,'owned_rule_set'=>$definition['field']];
    });
}
