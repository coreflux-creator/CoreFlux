<?php
/** Explicit remote organization binding; never infer identity from a display name. */
declare(strict_types=1);
require_once __DIR__.'/client.php';
require_once __DIR__.'/../integrations/delivery.php';
require_once __DIR__.'/../integrations/identity.php';

const QUANTA_CORE_CONTRACT_VERSION = '2026-10-20.1';
const QUANTA_CORE_CONTRACT_PATH = '/integrations/coreflux';

function quantaContractContextValidate(array $context): array
{
    $workspace = $context['workspace'] ?? null;
    $caps = ['person_links','assignment_metadata','conditional_assignment_updates','durable_delivery_receipts'];
    if (!is_array($workspace) || !is_string($workspace['id'] ?? null)
        || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $workspace['id'])
        || !is_string($workspace['name'] ?? null) || trim($workspace['name']) === ''
        || preg_match_all('/./us', $workspace['name']) === false || preg_match_all('/./us', $workspace['name']) > 200
        || preg_match('/[\x00-\x1f\x7f]/', $workspace['name'])
        || ($context['contract_version'] ?? null) !== QUANTA_CORE_CONTRACT_VERSION
        || ($context['execution_side_effects'] ?? null) !== false
        || !is_array($context['capabilities'] ?? null) || !array_is_list($context['capabilities'])
        || array_filter($context['capabilities'], static fn($v)=>!is_string($v))
        || array_diff($caps, $context['capabilities'])) {
        throw new RuntimeException('Quanta does not expose the required workspace and assignment contract');
    }
    // Only reviewed capabilities form the binding; unrelated additions are not authority.
    return ['workspace'=>['id'=>$workspace['id'],'name'=>$workspace['name']],
        'contract_version'=>QUANTA_CORE_CONTRACT_VERSION,'capabilities'=>$caps,'execution_side_effects'=>false];
}

function quantaContractContext(string $apiKey): array
{
    try { return quantaContractContextValidate(quantaGet($apiKey,QUANTA_CORE_CONTRACT_PATH.'/context')); }
    catch (QuantaApiException $e) {
        $message = match($e->httpStatus) {
            401 => 'Quanta did not accept this connection. Restore its access before verifying the workspace.',
            403 => 'This Quanta key needs the Core integration read permission.',
            404 => 'The connected Quanta environment has not published the shared Core contract yet.',
            default => 'Could not verify the Quanta workspace. No records were sent.',
        };
        throw new QuantaApiException($message,$e->httpStatus);
    }
}

function quantaWorkspaceBindingHash(int $tenantId, int $connectionId, string $apiKey, string $instanceId, array $context, int $revision=1): string
{
    if ($tenantId<=0 || $connectionId<=0 || $revision<=0 || $apiKey==='' || preg_match('/[\r\n]/',$apiKey)) throw new InvalidArgumentException('A scoped active connection is required');
    integrationRecordReference($instanceId,$tenantId,'person','P-1'); // Validate the namespace, never allocate a record.
    return integrationDeliveryHash(['tenant_id'=>$tenantId,'connection_id'=>$connectionId,'connection_revision'=>$revision,'provider_url'=>QUANTA_API_BASE,
        'credential_digest'=>hash('sha256',$apiKey),'instance_id'=>$instanceId,'context'=>quantaContractContextValidate($context)]);
}

function quantaWorkspaceBindingGet(PDO $pdo,int $tenantId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM quanta_workspace_bindings WHERE tenant_id=:t');
    $stmt->execute(['t'=>$tenantId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function quantaWorkspaceBindingMatches(?array $binding,string $hash): bool
{
    return $binding && $binding['state']==='verified' && hash_equals((string)$binding['binding_hash'],$hash);
}

/** Provider context was read before entry. Re-check connection under lock before saving it. */
function quantaWorkspaceBind(PDO $pdo,int $tenantId,int $actorId,array $connection,string $apiKey,string $instanceId,array $context,string $reviewHash): array
{
    if ($pdo->inTransaction()) throw new LogicException('Workspace verification requires its own durable transaction');
    if ($actorId<=0) throw new InvalidArgumentException('A reviewing administrator is required');
    $context=quantaContractContextValidate($context);
    $revision=(int)($connection['binding_revision']??1);
    $hash=quantaWorkspaceBindingHash($tenantId,(int)$connection['id'],$apiKey,$instanceId,$context,$revision);
    if (!hash_equals($hash,$reviewHash)) throw new RuntimeException('The workspace or connection changed. Check it again before confirming.');
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT id,status,api_key_ct,binding_revision FROM quanta_connections WHERE tenant_id=:t FOR UPDATE');
        $stmt->execute(['t'=>$tenantId]); $live=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$live || $live['status']!=='active' || (int)$live['id']!==(int)$connection['id'] || (int)$live['binding_revision']!==$revision
            || !hash_equals($apiKey,(string)decryptField($live['api_key_ct']))) {
            throw new RuntimeException('The Quanta connection changed during review');
        }
        $prior=quantaWorkspaceBindingGet($pdo,$tenantId);
        if ($prior && ($prior['remote_workspace_id']!==$context['workspace']['id'] || $prior['core_instance_id']!==$instanceId)) {
            throw new RuntimeException('This connection has a different permanent identity. Existing record links require migration review.');
        }
        $pdo->prepare('INSERT INTO quanta_workspace_bindings
            (tenant_id,connection_id,core_instance_id,remote_workspace_id,remote_workspace_name,contract_version,context_hash,binding_hash,state,verified_by)
            VALUES (:t,:c,:i,:r,:n,:v,:h,:b,"verified",:u)
            ON DUPLICATE KEY UPDATE connection_id=VALUES(connection_id),remote_workspace_name=VALUES(remote_workspace_name),
                contract_version=VALUES(contract_version),context_hash=VALUES(context_hash),binding_hash=VALUES(binding_hash),
                state="verified",verified_by=VALUES(verified_by),verified_at=NOW(),revoked_at=NULL')
            ->execute(['t'=>$tenantId,'c'=>(int)$connection['id'],'i'=>$instanceId,'r'=>$context['workspace']['id'],
                'n'=>$context['workspace']['name'],'v'=>$context['contract_version'],'h'=>integrationDeliveryHash($context),'b'=>$hash,'u'=>$actorId]);
        $row=quantaWorkspaceBindingGet($pdo,$tenantId);
        $pdo->commit(); return $row;
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function quantaWorkspaceBindingRevoke(PDO $pdo,int $tenantId): void
{
    $pdo->prepare('UPDATE quanta_workspace_bindings SET state="revoked",revoked_at=NOW() WHERE tenant_id=:t')->execute(['t'=>$tenantId]);
}

function quantaWorkspaceReview(int $tenantId): array
{
    $instance=integrationInstanceId();
    $connection=quantaConnection($tenantId); $key=quantaApiKey($tenantId);
    $context=quantaContractContext($key);
    $hash=$instance===null ? null : quantaWorkspaceBindingHash($tenantId,(int)$connection['id'],$key,$instance,$context,(int)$connection['binding_revision']);
    return ['workspace'=>$context['workspace'],'contract_version'=>$context['contract_version'],
        'identity_ready'=>$instance!==null,'review_hash'=>$hash,
        'verified'=>$hash!==null && quantaWorkspaceBindingMatches(quantaWorkspaceBindingGet(getDB(),$tenantId),$hash)];
}

/** Keep reconfiguration and binding invalidation atomic, using the connection lock first. */
function quantaWorkspaceReconfigure(PDO $pdo,int $tenantId,callable $change): void
{
    if($pdo->inTransaction()) throw new LogicException('Connection changes require their own transaction');
    $pdo->beginTransaction();
    try {
        $change();
        $pdo->prepare('UPDATE quanta_connections SET binding_revision=binding_revision+1 WHERE tenant_id=:t')->execute(['t'=>$tenantId]);
        quantaWorkspaceBindingRevoke($pdo,$tenantId); $pdo->commit();
    }
    catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

/** Internal only: credentials never belong in an API response or audit payload. */
function quantaWorkspaceForDelivery(int $tenantId): array
{
    $instance=integrationInstanceId();
    if ($instance===null) throw new RuntimeException('An administrator must configure the permanent identity for this Core installation before publishing.');
    $connection=quantaConnection($tenantId); $key=quantaApiKey($tenantId);
    $context=quantaContractContext($key);
    $hash=quantaWorkspaceBindingHash($tenantId,(int)$connection['id'],$key,$instance,$context,(int)$connection['binding_revision']);
    if (!quantaWorkspaceBindingMatches(quantaWorkspaceBindingGet(getDB(),$tenantId),$hash)) {
        throw new RuntimeException('Verify the connected organization before preparing or sending Core records.');
    }
    return ['connection'=>$connection,'api_key'=>$key,'instance_id'=>$instance,'context'=>$context,'binding_hash'=>$hash];
}
