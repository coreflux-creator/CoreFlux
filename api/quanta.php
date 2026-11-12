<?php
/** Tenant-scoped Quanta connection, explicit placement routes, and reviewed time import. */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/quanta/sync.php';
require_once __DIR__ . '/../core/quanta/publication.php';
require_once __DIR__ . '/../core/quanta/workspace.php';
require_once __DIR__ . '/../core/quanta/metadata_delivery.php';
require_once __DIR__ . '/../core/quanta/worker_intake.php';
require_once __DIR__ . '/../core/quanta/time_review_delivery.php';
require_once __DIR__ . '/../core/quanta/time_revisions.php';
require_once __DIR__ . '/../core/quanta/time_control_delivery.php';
require_once __DIR__ . '/../core/quanta/legacy_time_delivery.php';
require_once __DIR__ . '/../core/quanta/routes.php';
require_once __DIR__ . '/../core/quanta/profile_review.php';
require_once __DIR__ . '/../core/quanta/profile_return_delivery.php';
require_once __DIR__ . '/../modules/people/lib/people.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$actorId = (int) ($user['id'] ?? 0);
$method = api_method();
$action = (string) (api_query('action') ?? 'status');

function quantaApiDate(string $date, string $label): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException("{$label} must be YYYY-MM-DD");
    return $date;
}

function quantaApiWindow(array $body): array
{
    $since = quantaApiDate((string) ($body['changed_since'] ?? date('Y-m-d', strtotime('-30 days'))), 'Changed since');
    $status = (string) ($body['source_status'] ?? 'approved');
    if ($status !== 'approved') {
        throw new InvalidArgumentException('Only approved Quanta time can be previewed or imported');
    }
    return [$since, $status];
}

function quantaApiPlacements(int $tenantId, bool $includeCancelled = false): array
{
    $placementTenant = effectiveTenantIdForModule('placements', $tenantId) ?? $tenantId;
    $peopleTenant = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
    $stmt = getDB()->prepare(
        'SELECT p.id, p.person_id, p.title, p.end_client_name, p.status, p.start_date,
                COALESCE(p.actual_end_date, p.end_date) AS end_date,
                pe.email_primary AS person_email, p.tenant_id AS placement_directory_tenant_id, pe.tenant_id AS person_directory_tenant_id,
                CONCAT_WS(" ", pe.first_name, pe.last_name) AS person_name
           FROM placements p
           JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = :pet AND pe.deleted_at IS NULL
          WHERE p.tenant_id = :pt AND p.deleted_at IS NULL' . ($includeCancelled ? '' : ' AND p.status != "cancelled"') . '
       ORDER BY FIELD(p.status, "active", "draft", "ended"), person_name, p.id'
    );
    $stmt->execute(['pt' => $placementTenant, 'pet' => $peopleTenant]);
    $rows = coreRecordNumbersAttach(getDB(), $placementTenant, 'placement', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    return coreRecordNumbersAttachRelated(getDB(), $peopleTenant, 'person', $rows, 'person_id');
}

function quantaApiPeople(int $tenantId): array
{
    $peopleTenant = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
    $stmt = getDB()->prepare(
        'SELECT p.id, p.first_name, p.last_name, p.email_primary,
                CONCAT_WS(" ", p.first_name, p.last_name) AS name,
                cm.external_id AS connecteam_user_id
           FROM people p
      LEFT JOIN external_entity_mappings cm
             ON cm.tenant_id = :mt AND cm.source_system = "connecteam"
            AND cm.internal_entity_type = "person" AND cm.internal_entity_id = p.id
            AND cm.sync_status <> "deleted_in_source"
          WHERE p.tenant_id = :pt AND p.deleted_at IS NULL
       ORDER BY p.last_name, p.first_name, p.id'
    );
    $stmt->execute(['mt' => $peopleTenant, 'pt' => $peopleTenant]);
    return coreRecordNumbersAttach(getDB(), $peopleTenant, 'person', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

try {
    if ($method === 'GET' && $action === 'contract_context') {
        rbac_legacy_require($user, 'integrations.quanta.view');
        api_ok(quantaWorkspaceReview($tenantId));
    }
    if ($method === 'GET' && $action === 'status') {
        rbac_legacy_require($user, 'integrations.quanta.view');
        $connection = quantaConnection($tenantId);
        $tenant = getDB()->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
        $tenant->execute(['id' => $tenantId]);
        api_ok([
            'configured' => true,
            'connected' => $connection && $connection['status'] === 'active',
            'status' => $connection['status'] ?? null,
            'api_key_last4' => $connection['api_key_last4'] ?? null,
            'connection_revision' => isset($connection['binding_revision']) ? (int)$connection['binding_revision'] : null,
            'last_probe_at' => $connection['last_probe_at'] ?? null,
            'last_probe_error' => $connection['last_probe_error'] ?? null,
            'last_import_at' => $connection['last_import_at'] ?? null,
            'workspace' => ['id' => $tenantId, 'name' => $tenant->fetchColumn() ?: "Workspace {$tenantId}"],
            'route_count' => count(quantaRoutes($tenantId)),
        ]);
    }

    if ($method === 'GET' && $action === 'catalog') {
        rbac_legacy_require($user, 'integrations.quanta.view');
        $catalog = quantaCatalog(quantaApiKey($tenantId));
        $peopleTenant = effectiveTenantIdForModule('people', $tenantId) ?? $tenantId;
        $directory = getDB()->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
        $directory->execute(['id' => $peopleTenant]);
        api_ok($catalog + [
            'people' => quantaApiPeople($tenantId), 'worker_links' => quantaWorkerLinks($tenantId),
            'placements' => quantaApiPlacements($tenantId), 'routes' => quantaRoutes($tenantId),
            'metadata_placements' => quantaApiPlacements($tenantId, true),
            'people_directory' => ['id' => $peopleTenant, 'name' => $directory->fetchColumn() ?: 'People directory'],
        ]);
    }

    if ($method === 'GET' && $action === 'time_access') {
        rbac_legacy_require($user, 'integrations.quanta.view');
        api_ok(['available' => quantaTimeEntryAccess(quantaApiKey($tenantId))]);
    }

    if ($method === 'GET' && $action === 'route_candidates') {
        rbac_legacy_require($user, 'integrations.quanta.view');
        $since = quantaApiDate((string) (api_query('changed_since') ?? date('Y-m-d', strtotime('-30 days'))), 'Changed since');
        $key = quantaApiKey($tenantId);
        $sites = quantaWorksitesById(quantaListAll($key, '/worksites', [], 2000));
        $entries = quantaListAll($key, '/time-entries', ['updated_since' => $since . 'T00:00:00Z']);
        $bound = array_filter($entries, static fn(array $entry):bool=>array_key_exists('core_assignment',$entry));
        $access = $bound ? quantaWorkspaceForDelivery($tenantId) : null;
        api_ok(quantaRouteCandidates(
            $entries, $sites, quantaRoutes($tenantId),
            quantaWorkerPersonMap(quantaWorkerLinks($tenantId)), $access
        ));
    }

    if ($method !== 'POST') api_error('Method not allowed', 405);
    rbac_legacy_require($user, 'integrations.quanta.manage');
    $body = api_json_body();

    if (in_array($action,['time_revision_preview','time_revision_apply','time_revision_history','time_revision_recent'],true)) {
        foreach (['time.view','time.entry.manage','time.review'] as $permission) rbac_legacy_require($user,$permission);
        if ($action==='time_revision_recent') {
            if (isset($body['before'])&&!is_int($body['before'])) throw new InvalidArgumentException('Invalid history page.');
            api_ok(quantaTimeRevisionRecent($tenantId,$actorId,$body['before']??null));
        }
        if (!is_string($body['source_entry_id']??null)) throw new InvalidArgumentException('Choose a Quanta source entry.');
        $sourceId=$body['source_entry_id'];
        if ($action==='time_revision_preview') api_ok(quantaTimeRevisionPreview($tenantId,$actorId,$sourceId));
        if ($action==='time_revision_history') {
            if (isset($body['before'])&&!is_int($body['before'])) throw new InvalidArgumentException('Invalid history page.');
            api_ok(quantaTimeRevisionHistory($tenantId,$actorId,$sourceId,$body['before']??null));
        }
        if (($body['confirm_revision']??null)!==true || !is_string($body['review_hash']??null) || !is_string($body['reason']??null)) {
            throw new InvalidArgumentException('Confirm the reviewed correction and provide its reason.');
        }
        api_ok(quantaTimeRevisionApply($tenantId,$actorId,$sourceId,$body['review_hash'],$body['reason']));
    }

    if (in_array($action, ['profile_return_list', 'profile_return_status', 'profile_return_send', 'profile_return_resume'], true)) {
        rbac_legacy_require($user, 'people.view');
        rbac_legacy_require($user, 'people.manage');
        if ($action === 'profile_return_list') {
            if (isset($body['cursor']) && !is_string($body['cursor'])) throw new InvalidArgumentException('Invalid return page.');
            api_ok(quantaProfileReturnPage($tenantId, $body['cursor'] ?? null));
        }
        if (!is_string($body['change_id'] ?? null)) throw new InvalidArgumentException('Choose a saved contact decision.');
        if ($action === 'profile_return_status') api_ok(quantaProfileReturnStatus($tenantId, $actorId, $body['change_id']));
        if (($body['confirm_return'] ?? null) !== true) throw new InvalidArgumentException('Confirm returning the saved decision to Quanta.');
        api_ok(quantaProfileReturnSend($tenantId, $actorId, $body['change_id'], $action === 'profile_return_resume'));
    }

    if (in_array($action, ['profile_baseline_preview', 'profile_baseline_send', 'profile_baseline_status',
        'profile_review_list', 'profile_review_preview', 'profile_review_apply', 'profile_review_status'], true)) {
        rbac_legacy_require($user, 'people.view');
        // Contact decisions change the shared people directory, not just integration settings.
        if (str_starts_with($action, 'profile_review_')) rbac_legacy_require($user, 'people.manage');
        if ($action === 'profile_review_list') {
            if (isset($body['cursor']) && !is_string($body['cursor'])) throw new InvalidArgumentException('Invalid request page.');
            api_ok(quantaProfileReviewPage($tenantId, $body['cursor'] ?? null));
        }
        if (in_array($action, ['profile_baseline_preview', 'profile_review_preview'], true)) {
            if (!is_int($body['person_id'] ?? null) || $body['person_id'] <= 0) throw new InvalidArgumentException('Choose a Core person.');
            if ($action === 'profile_baseline_preview') api_ok(quantaProfileBaselinePreview($tenantId, $actorId, $body['person_id']));
            if (!is_string($body['change_id'] ?? null)) throw new InvalidArgumentException('Choose a contact request.');
            api_ok(quantaProfileReviewPreview($tenantId, $actorId, $body['person_id'], $body['change_id']));
        }
        if (str_starts_with($action, 'profile_baseline_')) {
            if (!is_int($body['delivery_id'] ?? null) || $body['delivery_id'] <= 0 || !is_string($body['review_hash'] ?? null)) {
                throw new InvalidArgumentException('Choose the reviewed contact publication.');
            }
            if ($action === 'profile_baseline_status') api_ok(quantaProfileBaselineStatus($tenantId, $actorId, $body['delivery_id'], $body['review_hash']));
            if (($body['confirm_profile'] ?? null) !== true) throw new InvalidArgumentException('Confirm the contact details to share with Quanta.');
            api_ok(quantaProfileBaselineSend($tenantId, $actorId, $body['delivery_id'], $body['review_hash']));
        }
        if (!is_string($body['review_token'] ?? null)) throw new InvalidArgumentException('Preview the contact request first.');
        if ($action === 'profile_review_status') api_ok(quantaProfileReviewStatus($tenantId, $actorId, $body['review_token']));
        if (($body['confirm_decision'] ?? null) !== true || !is_string($body['decision'] ?? null) || !is_string($body['reason'] ?? null)) {
            throw new InvalidArgumentException('Confirm your contact decision and its reason.');
        }
        api_ok(quantaProfileReviewApply($tenantId, $actorId, $body['review_token'], $body['decision'], $body['reason']));
    }

    if (in_array($action,['time_control_context','time_control_preview','time_control_pending','time_control_send','time_control_receipt'],true)) {
        foreach(['people.view','placements.view','time.view','time.review'] as $permission)rbac_legacy_require($user,$permission);
        if($action==='time_control_pending') {
            if(isset($body['before'])&&(!is_int($body['before'])||$body['before']<=0))throw new InvalidArgumentException('Invalid delivery page');
            api_ok(quantaTimeControlPending($tenantId,$body['before']??null));
        }
        if(in_array($action,['time_control_context','time_control_preview'],true)) {
            if(!is_int($body['person_id']??null)||$body['person_id']<=0||!is_string($body['worksite_id']??null))throw new InvalidArgumentException('Choose a Core person and Quanta worksite');
            if($action==='time_control_context')api_ok(quantaTimeControlContext($tenantId,$body['person_id'],$body['worksite_id'])['view']);
            if(!is_array($body['placement_ids']??null)||!is_string($body['context_hash']??null))throw new InvalidArgumentException('Review the assignment choices first');
            api_ok(quantaTimeControlPreview($tenantId,$actorId,$body['person_id'],$body['worksite_id'],$body['placement_ids'],$body['context_hash']));
        }
        if(!is_int($body['delivery_id']??null)||$body['delivery_id']<=0||!is_string($body['review_hash']??null))throw new InvalidArgumentException('Choose the original time setup');
        if($action==='time_control_receipt')api_ok(quantaTimeControlReceipt($tenantId,$body['delivery_id'],$body['review_hash']));
        if(($body['confirm_time_control']??null)!==true)throw new InvalidArgumentException('Confirm the reviewed assignment time setup');
        api_ok(quantaTimeControlSend($tenantId,$actorId,$body['delivery_id'],$body['review_hash']));
    }

    if (in_array($action,['legacy_time_inventory','legacy_time_preview','legacy_time_pending','legacy_time_send','legacy_time_receipt'],true)) {
        foreach(['people.view','placements.view','time.view','time.review'] as $permission)rbac_legacy_require($user,$permission);
        if($action==='legacy_time_pending') {
            if(isset($body['before'])&&(!is_int($body['before'])||$body['before']<=0))throw new InvalidArgumentException('Invalid delivery page');
            api_ok(quantaLegacyTimePending($tenantId,$body['before']??null));
        }
        if($action==='legacy_time_inventory') {
            if(!is_int($body['person_id']??null)||$body['person_id']<=0||!is_string($body['worksite_id']??null))throw new InvalidArgumentException('Choose a Core person and Quanta worksite');
            api_ok(quantaLegacyTimeInventory($tenantId,$body['person_id'],$body['worksite_id']));
        }
        if($action==='legacy_time_preview') {
            if(!is_string($body['source_entry_id']??null))throw new InvalidArgumentException('Choose existing imported time');
            api_ok(quantaLegacyTimePreview($tenantId,$actorId,$body['source_entry_id']));
        }
        if(!is_int($body['delivery_id']??null)||$body['delivery_id']<=0||!is_string($body['review_hash']??null))throw new InvalidArgumentException('Choose the original historical time link');
        if($action==='legacy_time_receipt')api_ok(quantaLegacyTimeReceipt($tenantId,$body['delivery_id'],$body['review_hash']));
        if(($body['confirm_existing_time']??null)!==true)throw new InvalidArgumentException('Confirm the reviewed historical time link');
        api_ok(quantaLegacyTimeSend($tenantId,$actorId,$body['delivery_id'],$body['review_hash']));
    }

    if (in_array($action,['time_review_pending','time_review_preview','time_review_send','time_review_receipt'],true)) {
        foreach(['time.view','placements.view','people.view'] as $permission) rbac_legacy_require($user,$permission);
        if($action==='time_review_pending') {
            if(isset($body['before']) && (!is_int($body['before']) || $body['before']<=0)) throw new InvalidArgumentException('Invalid delivery page');
            api_ok(quantaTimeReviewPending($tenantId,$body['before']??null));
        }
        if($action==='time_review_preview') {
            if(!is_string($body['source_entry_id']??null)) throw new InvalidArgumentException('Choose imported Quanta time');
            api_ok(quantaTimeReviewPreview($tenantId,$actorId,$body['source_entry_id']));
        }
        if(!is_int($body['delivery_id']??null) || $body['delivery_id']<=0 || !is_string($body['review_hash']??null)) throw new InvalidArgumentException('Choose the reviewed time status');
        if($action==='time_review_receipt') api_ok(quantaTimeReviewReceipt($tenantId,$body['delivery_id'],$body['review_hash']));
        if(($body['confirm_status']??null)!==true) throw new InvalidArgumentException('Review the status to share with Quanta');
        api_ok(quantaTimeReviewSend($tenantId,$actorId,$body['delivery_id'],$body['review_hash']));
    }

    if ($action === 'metadata_preview') {
        rbac_legacy_require($user, 'people.view');
        $kind=$body['entity_type']??null;
        if (!in_array($kind,['person','placement'],true) || !is_int($body['entity_id']??null) || $body['entity_id']<=0) throw new InvalidArgumentException('Choose a person or placement');
        if ($kind==='placement') rbac_legacy_require($user, 'placements.view');
        $site=$body['worksite_id']??null;
        if ($site!==null && !is_string($site)) throw new InvalidArgumentException('Invalid worksite');
        api_ok(quantaMetadataPreview($tenantId,$actorId,$kind,$body['entity_id'],$site));
    }

    if ($action === 'metadata_pending') {
        foreach(['people.view','placements.view'] as $permission)rbac_legacy_require($user,$permission);
        if(isset($body['before']) && (!is_int($body['before']) || $body['before']<=0))throw new InvalidArgumentException('Invalid delivery page');
        api_ok(quantaMetadataPending($tenantId,$body['before']??null));
    }

    if (in_array($action,['metadata_send','metadata_receipt'],true)) {
        rbac_legacy_require($user, 'people.view');
        if (!is_int($body['delivery_id']??null) || $body['delivery_id']<=0
            || !is_string($body['review_hash']??null)) throw new InvalidArgumentException('Choose the original metadata delivery');
        $delivery=integrationDeliveryGet(getDB(),$tenantId,$body['delivery_id']);
        if ($delivery['entity_type']==='placement') rbac_legacy_require($user, 'placements.view');
        if($action==='metadata_receipt')api_ok(quantaMetadataReceipt($tenantId,$actorId,$body['delivery_id'],$body['review_hash']));
        if (($body['confirm_metadata']??null)!==true)throw new InvalidArgumentException('Confirm the reviewed metadata before sending');
        api_ok(quantaMetadataSend($tenantId,$actorId,$body['delivery_id'],$body['review_hash']));
    }

    if ($action === 'confirm_workspace') {
        if (($body['confirm_same_organization'] ?? null) !== true) throw new InvalidArgumentException('Confirm that these workspaces represent the same organization');
        $instance = integrationInstanceId();
        if ($instance === null) throw new RuntimeException('An administrator must configure the permanent identity for this Core installation before publishing.');
        $connection = quantaConnection($tenantId); $key = quantaApiKey($tenantId);
        $context = quantaContractContext($key);
        if (($body['remote_workspace_id'] ?? null) !== $context['workspace']['id']) throw new RuntimeException('The Quanta workspace changed. Check the connection again.');
        $bound = quantaWorkspaceBind(getDB(), $tenantId, $actorId, $connection, $key, $instance, $context, (string)($body['review_hash'] ?? ''));
        platformAuditLogWrite($tenantId, $actorId, 'quanta.workspace.verified', null,
            ['remote_workspace_id'=>$bound['remote_workspace_id'],'contract_version'=>$bound['contract_version']], ['source'=>'quanta']);
        api_ok(['verified'=>true,'workspace'=>$context['workspace']]);
    }

    if ($action === 'publication_preview') {
        rbac_legacy_require($user, 'people.view');
        $result = quantaPublicationPreview($tenantId, $actorId, $body['person_ids'] ?? []);
        platformAuditLogWrite($tenantId, $actorId, 'quanta.publication.previewed', null,
            ['count' => count($result['rows']), 'delivery_enabled' => $result['delivery_enabled']], ['source' => 'quanta']);
        api_ok($result);
    }

    if (in_array($action,['publication_send','publication_status'],true)) {
        rbac_legacy_require($user,'people.view');
        if(!is_int($body['delivery_id']??null)||$body['delivery_id']<=0||!is_string($body['review_hash']??null))throw new InvalidArgumentException('Choose a reviewed worker delivery');
        if($action==='publication_send'){
            if(($body['confirm_profile']??null)!==true)throw new InvalidArgumentException('Confirm the reviewed contact fields before sending');
            api_ok(quantaWorkerProfilesSend($tenantId,$actorId,$body['delivery_id'],$body['review_hash']));
        }
        api_ok(quantaWorkerProfilesStatus($tenantId,$actorId,$body['delivery_id'],$body['review_hash']));
    }

    if ($action==='publication_history') {
        rbac_legacy_require($user,'people.view');
        if(!is_int($body['person_id']??null)||$body['person_id']<=0||
            (isset($body['before'])&&(!is_int($body['before'])||$body['before']<=0)))throw new InvalidArgumentException('Choose a person and valid history page');
        api_ok(quantaWorkerProfilesHistory($tenantId,$body['person_id'],$body['before']??null));
    }

    if ($action === 'connect') {
        $key = trim((string) ($body['api_key'] ?? ''));
        if (strlen($key) < 12 || strlen($key) > 1500) throw new InvalidArgumentException('Enter a valid Quanta API key');
        if ((int) ($body['confirm_tenant_id'] ?? 0) !== $tenantId) {
            throw new InvalidArgumentException('Confirm the CoreFlux workspace before connecting Quanta');
        }
        $existing = quantaConnection($tenantId);
        if ($existing && empty($body['confirm_same_quanta_workspace'])) {
            throw new InvalidArgumentException('Confirm this key belongs to the same Quanta workspace as the previous connection');
        }
        quantaProbeConnection($key);
        $ciphertext = encryptField($key);
        $params = ['t' => $tenantId, 'k' => $ciphertext, 'l' => substr($key, -4), 'u' => $actorId];
        quantaWorkspaceReconfigure(getDB(), $tenantId, static function() use ($params): void { getDB()->prepare(
            'INSERT INTO quanta_connections
                (tenant_id, api_key_ct, api_key_last4, status, last_probe_at, last_probe_error, connected_by_user_id)
             VALUES (:t,:k,:l,"active",NOW(),NULL,:u)
             ON DUPLICATE KEY UPDATE api_key_ct = VALUES(api_key_ct), api_key_last4 = VALUES(api_key_last4),
                 status = "active", last_probe_at = NOW(), last_probe_error = NULL,
                 connected_by_user_id = VALUES(connected_by_user_id)'
        )->execute($params); });
        platformAuditLogWrite($tenantId, $actorId, 'quanta.connection.connected', null, [], ['source' => 'quanta']);
        api_ok(['connected' => true, 'api_key_last4' => substr($key, -4)]);
    }

    if ($action === 'disconnect') {
        quantaWorkspaceReconfigure(getDB(), $tenantId, static function() use ($tenantId): void {
            getDB()->prepare('UPDATE quanta_connections SET api_key_ct = NULL, status = "revoked" WHERE tenant_id = :t')
                ->execute(['t' => $tenantId]);
        });
        platformAuditLogWrite($tenantId, $actorId, 'quanta.connection.disconnected', null, [], ['source' => 'quanta']);
        api_ok(['connected' => false]);
    }

    if ($action === 'probe') {
        $key = quantaApiKey($tenantId);
        try {
            quantaProbeConnection($key);
            getDB()->prepare('UPDATE quanta_connections SET last_probe_at = NOW(), last_probe_error = NULL WHERE tenant_id = :t')
                ->execute(['t' => $tenantId]);
        } catch (Throwable $e) {
            getDB()->prepare('UPDATE quanta_connections SET last_probe_at = NOW(), last_probe_error = :e WHERE tenant_id = :t')
                ->execute(['e' => substr($e->getMessage(), 0, 500), 't' => $tenantId]);
            throw $e;
        }
        api_ok(['ok' => true]);
    }

    if ($action === 'identity_preview') {
        rbac_legacy_require($user,'people.view');
        if(($body['mode']??null)==='create')rbac_legacy_require($user,'people.manage');
        api_ok(quantaWorkerIntakePreview($tenantId,$actorId,$body));
    }
    if (in_array($action,['save_worker_links','create_worker_person','identity_status'],true)) {
        rbac_legacy_require($user,'people.view');
        if(!is_string($body['review_token']??null))throw new InvalidArgumentException('Preview the worker identity before applying it');
        if($action==='identity_status')api_ok(quantaWorkerIntakeStatus($tenantId,$actorId,$body['review_token']));
        if(($body['confirm_review']??null)!==true)throw new InvalidArgumentException('Confirm the reviewed worker identity');
        $mode=$action==='create_worker_person'?'create':'link';
        if($mode==='create')rbac_legacy_require($user,'people.manage');
        api_ok(quantaWorkerIntakeApply($tenantId,$actorId,$body['review_token'],$mode));
    }
    if ($action === 'delete_worker_link') {
        $workerId = trim((string) ($body['worker_id'] ?? ''));
        if ($workerId === '' || strlen($workerId) > 128) throw new InvalidArgumentException('Quanta worker ID required');
        $pdo = getDB();
        $owns = cf_tx_begin($pdo);
        try {
            $linked = $pdo->prepare('SELECT id FROM quanta_worker_links WHERE tenant_id = :t AND worker_id = :w FOR UPDATE');
            $linked->execute(['t' => $tenantId, 'w' => $workerId]);
            if (!$linked->fetchColumn()) throw new InvalidArgumentException('Quanta worker is not linked in this workspace');
            $routes = $pdo->prepare('SELECT id FROM quanta_time_routes WHERE tenant_id = :t AND worker_id = :w LIMIT 1');
            $routes->execute(['t' => $tenantId, 'w' => $workerId]);
            if ($routes->fetchColumn()) throw new RuntimeException('Remove this worker’s placement routes before unlinking the person');
            $imports = $pdo->prepare('SELECT id FROM quanta_time_imports WHERE tenant_id = :t AND (worker_id = :w OR worker_id IS NULL) LIMIT 1');
            $imports->execute(['t' => $tenantId, 'w' => $workerId]);
            if ($imports->fetchColumn()) throw new RuntimeException('Imported Quanta time depends on this identity; review the imported records before changing it');
            $pdo->prepare('DELETE FROM quanta_worker_links WHERE tenant_id = :t AND worker_id = :w')
                ->execute(['t' => $tenantId, 'w' => $workerId]);
            cf_tx_commit($pdo, $owns);
        } catch (Throwable $e) {
            cf_tx_rollback($pdo, $owns);
            throw $e;
        }
        platformAuditLogWrite($tenantId, $actorId, 'quanta.worker_link.deleted', null, ['worker_id' => $workerId], ['source' => 'quanta']);
        api_ok(['worker_links' => quantaWorkerLinks($tenantId)]);
    }

    if ($action === 'save_routes' || $action === 'delete_route') {
        foreach(['people.view','placements.view','time.view','time.review'] as $permission)rbac_legacy_require($user,$permission);
        if($action==='save_routes') {
            if(!is_array($body['routes']??null))throw new InvalidArgumentException('Provide reviewed placement routes');
            api_ok(['routes'=>quantaSaveRoutes($tenantId,$actorId,$body['routes'])]);
        }
        if(!is_int($body['id']??null))throw new InvalidArgumentException('Route ID required');
        api_ok(['routes'=>quantaDeleteRoute($tenantId,$actorId,$body['id'])]);
    }

    if ($action === 'preview' || $action === 'import') {
        [$since, $sourceStatus] = quantaApiWindow($body);
        $access = quantaWorkspaceForDelivery($tenantId);
        $key = $access['api_key'];
        $sites = quantaWorksitesById(quantaListAll($key, '/worksites', [], 2000));
        $rawEntries = quantaEntries($key, $since, $sourceStatus);
        if ($action === 'preview') {
            $preview = quantaPreviewRows($tenantId, $rawEntries, $sites, $sourceStatus, $access);
            $offset = max(0, (int) ($body['offset'] ?? 0));
            $preview['rows'] = array_slice($preview['rows'], $offset, 200);
            $preview['offset'] = $offset;
            $preview['page_size'] = 200;
            api_ok($preview);
        }
        $ids = $body['entry_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids)) throw new InvalidArgumentException('Select Quanta entries from preview');
        $reviews = $body['source_reviews'] ?? [];
        if (!is_array($reviews) || count($reviews)>200) throw new InvalidArgumentException('Invalid historical source review');
        if ($reviews && ($body['confirm_original_workspace_id'] ?? null) !== $access['context']['workspace']['id']) {
            throw new InvalidArgumentException('Confirm the original company for the selected historical Quanta time');
        }
        foreach ($reviews as $id => $hash) {
            if (!in_array((string)$id, array_map('strval', $ids), true) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new InvalidArgumentException('Historical source review must match the selected preview');
            }
        }
        $result = quantaImportSelected($tenantId, $actorId, $rawEntries, $sites, $sourceStatus, $ids, $access, $reviews);
        platformAuditLogWrite($tenantId, $actorId, 'quanta.time.imported', null, [
            'selected' => count($ids), 'inserted' => $result['inserted'], 'updated' => $result['updated'],
            'source_verified' => $result['source_verified'], 'source_workspace_id' => $result['source_workspace']['id'],
        ], ['source' => 'quanta']);
        api_ok($result);
    }
    api_error('Unknown Quanta action', 404);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (QuantaApiException $e) {
    $code = in_array($e->httpStatus, [401, 403], true) ? $e->httpStatus : 502;
    api_error($e->getMessage(), $code);
} catch (PDOException $e) {
    error_log('Quanta database error: ' . $e->getMessage());
    api_error('Quanta database request failed', 500);
} catch (RuntimeException $e) {
    api_error($e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('Quanta integration: ' . $e->getMessage());
    api_error('Quanta integration could not complete the request', 500);
}
