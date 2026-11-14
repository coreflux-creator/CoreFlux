<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/clients.php';
require_once __DIR__ . '/job_source.php';

function staffingJobPluck(array $payload, array $keys): string
{
    $norm = [];
    foreach ($payload as $key => $value) {
        if (!is_string($key)) continue;
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        if ($nk === '' || array_key_exists($nk, $norm)) continue;
        $norm[$nk] = $value;
    }
    foreach ($keys as $key) {
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        if (!array_key_exists($nk, $norm)) continue;
        $value = $norm[$nk];
        if (!is_scalar($value) || $value === null) continue;
        $out = trim((string) $value);
        if ($out !== '') return $out;
    }
    return '';
}

function staffingJobNormalizeDate(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '0' || strtolower($raw) === 'null') return null;
    if (preg_match('/^\d{10}(?:\d{3})?$/D', $raw)) {
        $n = (int) $raw;
        if ($n >= 1_000_000_000_000) $n = (int) ($n / 1000);
        if ($n >= 100_000_000) return gmdate('Y-m-d', $n);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $raw, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $m[1].'-'.$m[2].'-'.$m[3];
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/D', $raw, $m)
        && checkdate((int)$m[1], (int)$m[2], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
    throw new InvalidArgumentException('JobDiva returned an unrecognized job date. Review its source value.');
}

function staffingJobNormalizeStatus(string $raw): string
{
    $key = strtolower(trim($raw));
    $key = str_replace([' ', '-'], '_', $key);
    return match ($key) {
        'active' => 'active',
        'hold', 'on_hold', 'paused' => 'on_hold',
        'filled', 'placed', 'fulfilled' => 'filled',
        'closed', 'inactive', 'expired' => 'closed',
        'cancelled', 'canceled' => 'cancelled',
        default => 'open',
    };
}

function staffingJobNormalizeRemotePolicy(string $raw): ?string
{
    $key = strtolower(trim($raw));
    $key = str_replace([' ', '-'], '_', $key);
    return match ($key) {
        'onsite', 'on_site' => 'onsite',
        'hybrid' => 'hybrid',
        'remote', 'wfh', 'work_from_home' => 'remote',
        default => null,
    };
}

function staffingJobFindBySource(int $tenantId, string $sourceSystem, string $externalId): ?array
{
    if (trim($externalId) === '') return null;
    try {
        $st = getDB()->prepare(
            'SELECT * FROM staffing_jobs
              WHERE tenant_id = :t AND source_system = :s AND external_id = :e
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 's' => $sourceSystem, 'e' => $externalId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $_) {
        return null;
    }
}

function staffingJobEnsureFromJobDivaPayload(int $tenantId, string $externalId, array $payload, ?int $actorUserId = null): array
{
    require_once __DIR__.'/../../../core/jobdiva/party_sync.php';
    return jobdivaPartyAtomic(static fn(PDO $pdo):array=>staffingJobEnsureFromJobDivaPayloadLocked($tenantId,$externalId,$payload,$actorUserId));
}

/** Keep the company identity locked until the job reference is saved. */
function staffingJobEnsureFromJobDivaPayloadLocked(int $tenantId, string $externalId, array $payload, ?int $actorUserId): array
{
    if(!getDB()->inTransaction())throw new LogicException('A job company write requires an active transaction.');
    // Acquire policy locks before the first consistent read. A merge that wins
    // the lock must be visible to this job's company and client lookups.
    require_once __DIR__.'/../../people/lib/company_relationships.php';
    $scope=companyRelationshipScope(getDB(),$tenantId,'staffing');
    $owner=$scope['owner'];
    if(jobdivaPartyDirectoryScope(getDB(),$owner,true)['owner']!==$scope['company_owner'])throw new RuntimeException('This shared job cannot use a private company directory.',409);
    $externalId = trim($externalId);
    if ($externalId === '') throw new \InvalidArgumentException('job external id required');

    $title = staffingJobPluck($payload, [
        'title', 'jobTitle', 'job_title', 'job title', 'positionTitle', 'position_title',
        'role', 'roleName', 'reqTitle', 'requisitionTitle',
    ]);

    $companyExtId = staffingJobPluck($payload, [
        'companyId', 'company_id', 'company id', 'customerId', 'customer_id', 'clientId', 'client_id',
    ]);
    $companyId = null;
    if ($companyExtId !== '' && function_exists('mappingFindInternal')) {
        $mapped = mappingFindInternal($tenantId, 'jobdiva', 'company', $companyExtId);
        if ($mapped) {
            require_once __DIR__.'/../../../core/jobdiva/party_sync.php';
            $companyId = jobdivaCompanyCanonicalId($tenantId,(int)$mapped['internal_entity_id'],true);
        }
    }

    // A requisition's company is not necessarily the assignment's bill-to
    // company. Jobs may reference an existing client, but only an exact
    // assignment BILLING.COMPANY contract may create a client consumer row.
    $clientScope=companyRelationshipScope(getDB(),$tenantId,'placements');
    $client=null;
    if($companyId!==null){
        $q=getDB()->prepare('SELECT * FROM staffing_clients WHERE tenant_id=? AND company_id=? AND status="active" ORDER BY id FOR UPDATE');
        $q->execute([$clientScope['owner'],$companyId]);$clients=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($clients)>1)throw new RuntimeException('Multiple active clients reference this job company. Review the client identities.',409);
        $client=$clients[0]??null;
    }
    if($client && $clientScope['owner']!==companyRelationshipScope(getDB(),$owner,'placements')['owner'])throw new RuntimeException('This shared job cannot use a private client directory.',409);
    $clientId = $client ? (int) $client['id'] : null;

    $countryRaw = staffingJobPluck($payload, ['country', 'jobCountry', 'job_country', 'worksiteCountry']);
    $country = integrationSourceCountry($countryRaw);
    if ($countryRaw !== '' && $country === null) throw new InvalidArgumentException('JobDiva returned an unrecognized job country. Review its source value.');
    $statusRaw = staffingJobPluck($payload, ['status', 'jobStatus', 'job_status']);
    $statusKey = str_replace([' ', '-'], '_', strtolower(trim($statusRaw)));
    if ($statusRaw !== '' && !in_array($statusKey, ['open','active','hold','on_hold','paused','filled','placed','fulfilled','closed','inactive','expired','cancelled','canceled'], true)) throw new InvalidArgumentException('JobDiva returned an unrecognized job status. Review its source value.');
    $remoteRaw = staffingJobPluck($payload, ['remotePolicy', 'remote_policy', 'workLocation', 'locationType']);
    $remote = staffingJobNormalizeRemotePolicy($remoteRaw);
    if ($remoteRaw !== '' && $remote === null) throw new InvalidArgumentException('JobDiva returned an unrecognized remote policy. Review its source value.');

    $row = [
        'tenant_id'          => $owner,
        'client_id'          => $clientId,
        'company_id'         => $companyId,
        'title'              => $title,
        'status'             => $statusRaw === '' ? null : staffingJobNormalizeStatus($statusRaw),
        'external_id'        => $externalId,
        'source_system'      => 'jobdiva',
        'description'        => staffingJobPluck($payload, ['description', 'jobDescription', 'job_description']),
        'department'         => staffingJobPluck($payload, ['department', 'dept', 'division']),
        'location_city'      => staffingJobPluck($payload, ['city', 'jobCity', 'job_city', 'worksiteCity']),
        'location_state'     => staffingJobPluck($payload, ['state', 'jobState', 'job_state', 'worksiteState']),
        'location_country'   => $country,
        'remote_policy'      => $remote,
        'opened_at'          => staffingJobNormalizeDate(staffingJobPluck($payload, ['openDate', 'openedAt', 'dateOpened', 'startDate'])),
        'closed_at'          => staffingJobNormalizeDate(staffingJobPluck($payload, ['closedDate', 'closedAt', 'dateClosed', 'endDate'])),
        'created_by_user_id' => $actorUserId,
    ];

    $pdo = getDB();
    $q=$pdo->prepare("SELECT * FROM staffing_jobs WHERE tenant_id=? AND source_system='jobdiva' AND external_id=? FOR UPDATE");
    $q->execute([$owner,$externalId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)>1)throw new RuntimeException('Multiple jobs claim this JobDiva identity. Review their links.',409);
    $existing=$rows[0]??null;
    if($existing){
        $q=$pdo->prepare("SELECT m.id FROM external_entity_mappings m JOIN staffing_jobs j ON j.id=m.internal_entity_id AND j.tenant_id=? WHERE m.source_system='jobdiva' AND m.internal_entity_type='staffing_job' AND j.id=? AND m.tenant_id<>? FOR UPDATE");
        $q->execute([$owner,(int)$existing['id'],$tenantId]);
        if($q->fetchColumn())throw new RuntimeException('This job already has a JobDiva identity from another workspace. Review its source account.',409);
    }
    $q=$pdo->prepare("SELECT internal_entity_id FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='staffing_job' AND external_id=? FOR UPDATE");
    $q->execute([$tenantId,$externalId]);$mapped=$q->fetchColumn();
    if($mapped!==false&&(!$existing||(int)$mapped!==(int)$existing['id']))throw new RuntimeException('The retained JobDiva job link does not match the current directory. Review its identity.',409);
    $values = array_filter(array_intersect_key($row, array_flip(staffingJobNativeFields())), static fn($v) => $v !== null && $v !== '');
    return staffingJobSourceProject($pdo, $tenantId, $owner, $externalId, $existing, $values, $actorUserId);
}

function staffingJobLinkPlacementsByJobDivaId(int $tenantId, string $jobdivaJobId, int $staffingJobId, ?int $actorUserId = null): int
{
    if (trim($jobdivaJobId) === '' || $staffingJobId <= 0) return 0;
    require_once __DIR__.'/../../placements/lib/source_identity.php';
    require_once __DIR__.'/../../placements/lib/ownership.php';
    $pdo=getDB();
    return companyRelationshipAtomic($pdo,static function()use($pdo,$tenantId,$jobdivaJobId,$staffingJobId,$actorUserId):int{
        $placements=companyRelationshipScope($pdo,$tenantId,'placements');
        $jobs=companyRelationshipScope($pdo,$tenantId,'staffing');
        if(companyRelationshipScope($pdo,$placements['owner'],'staffing')['owner']!==$jobs['owner'])throw new RuntimeException('This shared placement cannot use a private job directory.',409);
        $q=$pdo->prepare('SELECT * FROM staffing_jobs WHERE tenant_id=? AND id=? FOR UPDATE');$q->execute([$jobs['owner'],$staffingJobId]);$job=$q->fetch(PDO::FETCH_ASSOC);
        if(!$job||$job['source_system']!=='jobdiva'||$job['external_id']!==$jobdivaJobId)throw new RuntimeException('The job source identity changed before placement linking.',409);
        $state=staffingJobSourceState($job);
        if($state['source']!==['system'=>'jobdiva','workspace_id'=>$tenantId,'external_id'=>$jobdivaJobId])throw new RuntimeException('An observed job from this source workspace is required.',409);
        // tenant-leak-allow: globally unique job ID in the authorized directory constrains the conflict lookup; no foreign source is returned.
        $q=$pdo->prepare('SELECT tenant_id,external_id FROM external_entity_mappings WHERE source_system="jobdiva" AND internal_entity_type="staffing_job" AND internal_entity_id=? FOR UPDATE');$q->execute([$staffingJobId]);$links=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($links)!==1||(int)$links[0]['tenant_id']!==$tenantId||$links[0]['external_id']!==$jobdivaJobId)throw new RuntimeException('Review the exact job source link before linking placements.',409);
        $q=$pdo->prepare('SELECT * FROM placements WHERE tenant_id=? AND jobdiva_job_id=? AND deleted_at IS NULL AND (staffing_job_id IS NULL OR staffing_job_id<>?) ORDER BY id FOR UPDATE');
        $q->execute([$placements['owner'],$jobdivaJobId,$staffingJobId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$written=0;
        foreach($rows as$row){
            if($row['jobdiva_job_id']!==$jobdivaJobId)continue;
            $owned=placementOwnershipFields($row['coreflux_overridden_fields']??null);
            if(in_array('staffing_job_id',$owned,true))continue;
            $family=placementJobdivaSourceFamily($pdo,$placements['owner'],(int)$row['id']);
            if((int)$family['placement']['id']!==(int)$row['id'])continue;
            $source=placementJobdivaSourceIdentity($pdo,$row);
            // A bare job number or legacy display label does not grant another
            // workspace's connector authority over a placement or merged family.
            if(!$source||$source['source_workspace_id']!==$tenantId||!$source['mapping_id'])continue;
            $update=$pdo->prepare('UPDATE placements SET staffing_job_id=? WHERE tenant_id=? AND id=?');
            $update->execute([$staffingJobId,$placements['owner'],$row['id']]);
            companySourceAudit($pdo,$tenantId,$placements['owner'],'placements',(int)$row['id'],'placement.job.source_linked',['staffing_job_id'],$actorUserId,
                ['source'=>$source,'job_source'=>$state['source'],'before'=>['staffing_job_id'=>$row['staffing_job_id']===null?null:(int)$row['staffing_job_id']],
                    'after'=>['staffing_job_id'=>$staffingJobId]]);
            $written++;
        }
        return$written;
    });
}
