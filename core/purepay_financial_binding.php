<?php
/** Reviewed financial company identity. Never infer it from a wallet or label. */
declare(strict_types=1);

function purepayFinancialContext(array $value): array
{
    $org=$value['organization']??null;
    if (($value['contract_version']??null)!=='2026-10-25.1' || ($value['execution_authority']??null)!==false
        || !in_array($value['key_scope']??null,['read','write'],true) || !is_array($org)
        || !is_string($org['id']??null) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$org['id'])
        || !is_string($org['name']??null) || trim($org['name'])==='' || mb_strlen($org['name'])>200
        || preg_match('/[\x00-\x1f\x7f]/',$org['name'])) {
        throw new PurePayApiException('PurePay did not return a verifiable company identity.');
    }
    return ['contract_version'=>'2026-10-25.1','organization'=>['id'=>$org['id'],'name'=>$org['name']],
        'key_scope'=>$value['key_scope'],'execution_authority'=>false];
}

function purepayFinancialReadContext(string $key): array
{
    try { return purepayFinancialContext(purepayCall($key,'GET','/context')); }
    catch (PurePayApiException $e) {
        throw new PurePayApiException('PurePay company verification failed. Check the key and provider environment; no company was confirmed.');
    }
}

/** Internal rows only; do not serialize credentials or binding internals. */
function purepayFinancialConnection(PDO $pdo,int $tenant,bool $lock=false): ?array
{
    if ($tenant<=0) throw new PurePayApiException('A Core company is required.');
    $q=$pdo->prepare('SELECT * FROM purepay_connections WHERE tenant_id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant]); return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function purepayFinancialBinding(PDO $pdo,int $tenant,bool $lock=false): ?array
{
    $q=$pdo->prepare('SELECT * FROM purepay_financial_bindings WHERE tenant_id=?'.($lock?' FOR UPDATE':''));
    $q->execute([$tenant]); return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

function purepayFinancialKey(array $connection): string
{
    return isset($connection['api_key']) ? (string)$connection['api_key'] : (string)decryptField($connection['api_key_ct']??null);
}

function purepayFinancialHash(array $binding,array $connection,array $context): string
{
    return hash('sha256',json_encode(['tenant'=>(int)$connection['tenant_id'],'connection'=>(int)$connection['id'],
        'revision'=>(int)$binding['revision'],'origin'=>purepayApiBase(),'local_scope'=>$connection['key_scope'],
        'credential'=>purepayConnectionFingerprint((int)$connection['tenant_id'],purepayFinancialKey($connection)),
        'context'=>purepayFinancialContext($context)],JSON_THROW_ON_ERROR));
}

function purepayFinancialAssertPin(PDO $pdo,int $tenant,array $context): void
{
    $q=$pdo->prepare('SELECT * FROM purepay_financial_organizations WHERE tenant_id=?');$q->execute([$tenant]);
    $claim=$q->fetch(PDO::FETCH_ASSOC);
    if ($claim && ($claim['organization_id']!==$context['organization']['id']
        || $claim['origin_hash']!==hash('sha256',purepayApiBase()))) {
        throw new PurePayApiException('This Core company already has a different permanent PurePay company. Review existing financial links before changing it.');
    }
}

/** Caller owns connection lock. Every credential change requires fresh review. */
function purepayFinancialPending(PDO $pdo,array $connection,array $context): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Connection changes require a transaction.');
    $tenant=(int)$connection['tenant_id'];purepayFinancialAssertPin($pdo,$tenant,$context);
    if ($connection['key_scope']==='read_write' && $context['key_scope']!=='write') {
        throw new PurePayApiException('This PurePay key has read-only access. Select read-only in Core.');
    }
    $pdo->prepare('INSERT INTO purepay_financial_bindings
        (tenant_id,connection_id,remote_api_base,remote_organization_id,remote_organization_name,provider_key_scope)
        VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE connection_id=VALUES(connection_id),revision=revision+1,
        remote_api_base=VALUES(remote_api_base),remote_organization_id=VALUES(remote_organization_id),
        remote_organization_name=VALUES(remote_organization_name),provider_key_scope=VALUES(provider_key_scope),
        binding_hash=NULL,verified_by=NULL,verified_at=NULL,updated_at=NOW()')
        ->execute([$tenant,$connection['id'],purepayApiBase(),$context['organization']['id'],$context['organization']['name'],$context['key_scope']]);
}

/** POST review: probes first, then initializes legacy metadata without approving it. */
function purepayFinancialReview(PDO $pdo,int $tenant): array
{
    if ($pdo->inTransaction()) throw new LogicException('Company review requires its own transaction.');
    $connection=purepayFinancialConnection($pdo,$tenant);
    if (!$connection || $connection['status']==='revoked' || purepayFinancialKey($connection)==='') throw new PurePayApiException('Connect PurePay before reviewing its company.');
    $context=purepayFinancialReadContext(purepayFinancialKey($connection));
    $pdo->beginTransaction();
    try {
        $current=purepayFinancialConnection($pdo,$tenant,true);
        if (!$current || $current['status']==='revoked' || $current['key_scope']!==$connection['key_scope']
            || !hash_equals(purepayFinancialKey($connection),purepayFinancialKey($current))) throw new PurePayApiException('The connection changed. Review it again.');
        purepayFinancialAssertPin($pdo,$tenant,$context);
        if ($current['key_scope']==='read_write' && $context['key_scope']!=='write') throw new PurePayApiException('The key no longer has the selected access. Reconnect with read-only access.');
        $binding=purepayFinancialBinding($pdo,$tenant,true);
        if (!$binding) { purepayFinancialPending($pdo,$current,$context);$binding=purepayFinancialBinding($pdo,$tenant,true); }
        $hash=purepayFinancialHash($binding,$current,$context);
        $pdo->commit();
        return ['tenant_id'=>$tenant,'organization'=>$context['organization'],'provider_key_scope'=>$context['key_scope'],
            'local_key_scope'=>$current['key_scope'],'review_hash'=>$hash,
            'verified'=>is_string($binding['binding_hash']) && hash_equals($binding['binding_hash'],$hash)];
    } catch (Throwable $e) { if ($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

function purepayFinancialConfirm(PDO $pdo,int $tenant,int $actor,string $hash,string $organization,?callable $audit=null): array
{
    if ($actor<=0) throw new PurePayApiException('A reviewing administrator is required.');
    $review=purepayFinancialReview($pdo,$tenant);
    if (!hash_equals($review['review_hash'],$hash) || $organization!==$review['organization']['id']) throw new PurePayApiException('The company or access changed. Review it again before confirming.');
    $context=purepayFinancialContext(['contract_version'=>'2026-10-25.1','organization'=>$review['organization'],
        'key_scope'=>$review['provider_key_scope'],'execution_authority'=>false]);
    $pdo->beginTransaction();
    try {
        $connection=purepayFinancialConnection($pdo,$tenant,true);$binding=purepayFinancialBinding($pdo,$tenant,true);
        if (!$connection || $connection['status']==='revoked' || !$binding || !hash_equals($hash,purepayFinancialHash($binding,$connection,$context))) throw new PurePayApiException('The connection changed during review.');
        purepayFinancialAssertPin($pdo,$tenant,$context);
        // Unique origin/org and tenant constraints arbitrate competing confirmations.
        $pdo->prepare('INSERT INTO purepay_financial_organizations(origin_hash,organization_id,tenant_id,connection_id,reviewed_by,reviewed_at)
            VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE origin_hash=origin_hash')
            ->execute([hash('sha256',purepayApiBase()),$organization,$tenant,$connection['id'],$actor]);
        $q=$pdo->prepare('SELECT * FROM purepay_financial_organizations WHERE origin_hash=? AND organization_id=? AND tenant_id=? FOR UPDATE');
        $q->execute([hash('sha256',purepayApiBase()),$organization,$tenant]);$claim=$q->fetch(PDO::FETCH_ASSOC);
        if (!$claim || (int)$claim['tenant_id']!==$tenant || (int)$claim['connection_id']!==(int)$connection['id']) throw new PurePayApiException('This PurePay organization is already assigned to another Core company or connection.');
        $pdo->prepare('UPDATE purepay_financial_bindings SET remote_api_base=?,remote_organization_id=?,remote_organization_name=?,
            provider_key_scope=?,binding_hash=?,verified_by=?,verified_at=NOW() WHERE tenant_id=?')
            ->execute([purepayApiBase(),$organization,$context['organization']['name'],$context['key_scope'],$hash,$actor,$tenant]);
        if ($audit!==null)$audit();
        $pdo->commit();return array_replace($review,['verified'=>true]);
    } catch (Throwable $e) { if ($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

/** Require stored confirmation at every financial state transition. */
function purepayFinancialRequire(PDO $pdo,int $tenant,?array $connection=null,bool $fresh=false): array
{
    $connection=$connection??purepayFinancialConnection($pdo,$tenant,$pdo->inTransaction());
    if (!$connection || ($connection['status']??'')!=='active' || (int)($connection['tenant_id']??0)!==$tenant) throw new PurePayApiException('PurePay company connection is inactive.');
    $binding=purepayFinancialBinding($pdo,$tenant,$pdo->inTransaction());
    if (!$binding || !$binding['binding_hash'] || !$binding['verified_at'] || (int)$binding['connection_id']!==(int)$connection['id']) throw new PurePayApiException('Review and confirm the PurePay company before using financial records.');
    $context=purepayFinancialContext(['contract_version'=>'2026-10-25.1',
        'organization'=>['id'=>$binding['remote_organization_id'],'name'=>$binding['remote_organization_name']],
        'key_scope'=>$binding['provider_key_scope'],'execution_authority'=>false]);
    purepayFinancialAssertPin($pdo,$tenant,$context);
    $q=$pdo->prepare('SELECT connection_id FROM purepay_financial_organizations WHERE tenant_id=? AND origin_hash=? AND organization_id=?');
    $q->execute([$tenant,hash('sha256',purepayApiBase()),$context['organization']['id']]);
    if ((int)$q->fetchColumn()!==(int)$connection['id'] || $binding['remote_api_base']!==purepayApiBase()
        || !hash_equals($binding['binding_hash'],purepayFinancialHash($binding,$connection,$context))) throw new PurePayApiException('The reviewed PurePay connection changed. Review it again.');
    if ($fresh) {
        if ($pdo->inTransaction())throw new LogicException('Provider verification must precede the financial transaction.');
        $live=purepayFinancialReadContext(purepayFinancialKey($connection));
        if (!hash_equals($binding['binding_hash'],purepayFinancialHash($binding,$connection,$live))) throw new PurePayApiException('PurePay company or key access changed. Review it again.');
        // Recheck local state after the network read; rotation cannot reuse this result.
        $current=purepayFinancialRequire($pdo,$tenant);
        if ($current['binding_hash']!==$binding['binding_hash']) throw new PurePayApiException('The connection changed during company verification.');
    }
    return $binding;
}

function purepayFinancialSummary(PDO $pdo,int $tenant): array
{
    try {
        $binding=purepayFinancialBinding($pdo,$tenant);
        try {purepayFinancialRequire($pdo,$tenant);$verified=true;} catch(Throwable $e){$verified=false;}
        return ['verified'=>$verified,'organization'=>$binding?['id'=>$binding['remote_organization_id'],'name'=>$binding['remote_organization_name']]:null,
            'provider_key_scope'=>$binding['provider_key_scope']??null,'reviewed_at'=>$verified?$binding['verified_at']:null];
    } catch (Throwable $e) { return ['verified'=>false,'organization'=>null,'reviewed_at'=>null]; }
}
