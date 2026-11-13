<?php
/** Live contract lookup and canonical writes are separated by a checked snapshot. */
declare(strict_types=1);
require_once __DIR__.'/sync.php';
require_once __DIR__.'/assignment_source_repair.php';
require_once __DIR__.'/joined_index_refresh.php';

/** Internal evidence only. Never return this snapshot through the API. */
function jobdivaLiveContractSnapshot(int $workspace, int $mappingId, string $corporationId = ''): array
{
    $base = jobdivaAssignmentRepairSnapshot($workspace, $mappingId);
    $pdo = getDB(); $context = $base['context'];
    $config = empty($base['connection']['sync_config']) ? [] : json_decode($base['connection']['sync_config'],true,512,JSON_THROW_ON_ERROR);
    $policy = $config['placement'] ?? JOBDIVA_SYNC_DEFAULTS['placement'];
    if (($policy['source'] ?? '') !== 'jobdiva' || !in_array($policy['direction'] ?? '',['pull','two_way'],true)) throw new RuntimeException('JobDiva placement imports are disabled by this workspace\'s sync policy.',409);
    $payload = json_decode((string)$base['mapping']['payload_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_is_list($payload)) throw new RuntimeException('Review the saved assignment lookup evidence.', 409);
    $payload = jobdivaAssignmentSanitisePayload($payload, $base['external']);
    if (jobdivaAssignmentRowId($payload) !== $base['external']) throw new RuntimeException('The saved Start identity does not match its retained source link.', 409);
    $stats = [];
    $payload = jobdivaPlacementPayloadWithMirrors($workspace, $payload, $stats, $base['external'], true);
    $candidate = jobdivaPluckField($payload, ['candidateId','candidate_id','candidate id','employeeId']);
    $personLink = $candidate === '' ? null : jobdivaPlacementSourceLink($pdo, $workspace, 'person', $candidate);
    $q = $pdo->prepare('SELECT id,tenant_id,status,deleted_at,external_id FROM people WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([$context['people_owner'], $personLink['internal_entity_id'] ?? 0]);
    $sourcePerson = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $q = $pdo->prepare('SELECT * FROM placement_rates WHERE tenant_id=? AND placement_id=? ORDER BY id FOR UPDATE');
    $q->execute([$context['placement_owner'], $base['placement']['id']]);
    $rates = $q->fetchAll(PDO::FETCH_ASSOC);
    $q = $pdo->prepare('SELECT * FROM tenant_integration_field_map WHERE tenant_id=? AND integration="jobdiva" ORDER BY id FOR UPDATE');
    $q->execute([$workspace]); $fieldMaps = $q->fetchAll(PDO::FETCH_ASSOC);
    // Saved related identities can change while the remote provider responds.
    // Include missing links as well as existing ones in the protected read.
    $ids = [$candidate, jobdivaPluckField($payload, ['jobId','job_id','job id'])];
    foreach (['_jd_job','_jd_customer','_jd_contact'] as $key) {
        $facet = (array)($payload[$key] ?? []);
        foreach ([['id'],['companyId','company_id','company id'],['contactId','contact_id','contact id']] as $fields) $ids[] = jobdivaPluckField($facet, $fields);
    }
    $ids[] = jobdivaPluckField($payload, ['companyId','company_id','company id']);
    $ids[] = jobdivaPluckField($payload, ['contactId','contact_id','contact id']);
    $ids = array_values(array_unique(array_filter($ids, static fn($id)=>$id!=='')));
    $ids = array_values(array_unique([...$ids, ...array_map(static fn($id)=>'jd:'.$id, $ids)]));
    $links = [];
    if ($ids) {
        $q = $pdo->prepare('SELECT * FROM external_entity_mappings WHERE tenant_id=? AND source_system="jobdiva" AND external_id IN('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE');
        $q->execute([$workspace,...$ids]); $links = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $corporationId = jobdivaAssignmentCompanyId($corporationId);
    $company = $corporationId === '' ? null : jobdivaStoredSubcontractCompanyEvidence($workspace, $corporationId);
    return ['base'=>$base, 'joined_payload'=>$payload, 'person_source'=>$personLink, 'source_person'=>$sourcePerson,
        'graph'=>jobdivaPlacementProjectionAuditSnapshot($context['placement_owner'], (int)$base['placement']['id'], true),
        'rates'=>$rates, 'field_maps'=>$fieldMaps, 'related_links'=>$links,
        'corporation_id'=>$corporationId, 'company_evidence'=>$company];
}

/** Replace the root with the remotely verified Start, keeping exact related facets only. */
function jobdivaLiveContractInput(array $expected, array $exact): array
{
    $base = $expected['base']; $remote = $exact['row'] ?? null;
    if (($exact['status'] ?? '') !== 'verified' || !is_array($remote)
        || empty(jobdivaAssignmentValidate($remote, $base['external'])['valid'])
        || empty(jobdivaAssignmentContextEvidence($remote, $expected['joined_payload'])['matches'])) {
        throw new RuntimeException('The returned Start does not match the retained assignment and worker context.', 409);
    }
    $payload = jobdivaAssignmentStripDerivedFacets($remote, $base['external']);
    foreach (['_jd_job','_jd_candidate','_jd_customer','_jd_contact'] as $key) {
        if (isset($expected['joined_payload'][$key])) $payload[$key] = $expected['joined_payload'][$key];
    }
    // Validate cached facets too: unchanged evidence is not automatically valid.
    jobdivaJoinedIndexValidatePayload(['joined_payload'=>$payload,'external'=>$base['external']], $payload);
    return $payload;
}

/** Validate raw financial evidence and rebuild the contract instead of trusting a supplied result. */
function jobdivaLiveContractValidate(array $expected, array $exact, array $payload): array
{
    $input = jobdivaLiveContractInput($expected, $exact);
    jobdivaJoinedIndexValidatePayload(['joined_payload'=>$input,'external'=>$expected['base']['external']], $payload);
    $rows = $payload['_jd_assignment_detail'] ?? [];
    if (!is_array($rows) || $rows === []) throw new RuntimeException('JobDiva returned no exact assignment financial detail.', 422);
    // Match the enricher's exact Start fallback. Related job/worker mirrors
    // cannot supply missing assignment rates, overheads or lifecycle facts.
    $hint = jobdivaAssignmentStripDerivedFacets($input,$expected['base']['external']);
    $contract = jobdivaAssignmentContractBuild($rows, $hint, $expected['base']['external']);
    if ($contract === [] || mappingHash($contract) !== mappingHash($payload['_jd_contract'] ?? [])) throw new RuntimeException('The contract does not agree with the exact returned financial records.', 409);
    $payload['_jd_contract'] = $contract;
    return $payload;
}

/** This read performs no company, role, vendor or mapping writes. */
function jobdivaLiveContractFetchCompany(int $workspace, string $external): array
{
    if (getDB()->inTransaction() || JobdivaStoredReviewTransaction::inScope()) throw new RuntimeException('Fetch company evidence outside a database transaction.', 409);
    $rows = jobdivaCallBulkIds($workspace, '/apiv2/bi/CompaniesDetail', 'companyIds', [$external], [], 1);
    $matches = [];
    foreach ($rows as $row) {
        if (!is_array($row) || jobdivaAssignmentCompanyId(jobdivaPluckField($row,['id','companyId','company_id','company id'])) !== $external) continue;
        $name = jobdivaPluckField($row,['name','companyName','company_name','company name','legalName','legal_name']);
        if (!jobdivaCompanyNameIsPlaceholder($name,$external,$row)) $matches[] = $row;
    }
    if (count($matches) !== 1) throw new RuntimeException('JobDiva did not return one exact corporate payee. Review its company identity.', 409);
    return $matches[0];
}

/** All native writes, retained mappings, and the required receipt commit together. */
function jobdivaLiveContractApply(array $expected, array $exact, ?array $payload, ?array $companyPayload, ?int $actor): array
{
    return JobdivaStoredReviewTransaction::run(static function() use ($expected,$exact,$payload,$companyPayload,$actor): array {
        $base = $expected['base']; $workspace = (int)$base['context']['workspace'];
        $current = jobdivaLiveContractSnapshot($workspace,(int)$base['mapping']['id'],$expected['corporation_id']);
        if (!hash_equals(jobdivaAssignmentRepairFingerprint($expected),jobdivaAssignmentRepairFingerprint($current))) throw new RuntimeException('Assignment, ownership or source evidence changed during the remote lookup. Retry this contract.',409);
        if (($exact['status'] ?? '') !== 'verified') {
            $repair = jobdivaAssignmentRepairApply($current['base'],$exact,$actor);
            return ['projected'=>0,'restored'=>0,'skipped_not_current'=>0,'review_required'=>1,'source_decision'=>$repair];
        }
        $payload = jobdivaLiveContractValidate($current,$exact,$payload ?? []);
        $contract = $payload['_jd_contract']; $placement = $base['placement'];
        $owner = (int)$base['context']['placement_owner']; $placementId = (int)$placement['id'];
        $archived = !empty($placement['deleted_at']) && $placement['deleted_at'] !== '0000-00-00 00:00:00';
        $held = (bool)array_intersect(['status','start_date','end_date','actual_end_date','deleted_at'],$base['owned_fields']);
        $contractStatus = trim((string)($contract['placement_status'] ?? ''));
        $lifecycle = $contractStatus !== '' ? jobdivaAssignmentCanonicalPlacementStatus($contractStatus,jobdivaNormaliseDate($contract['end_date'] ?? null)) : ['status'=>''];
        $personHeld = in_array('person_id',$base['owned_fields'],true);
        $worker = $personHeld ? $base['person'] : $current['source_person'];
        if (!$personHeld && $current['person_source'] && (!$worker || $current['person_source']['sync_status'] !== 'ok')) throw new RuntimeException('Review the current assignment worker source link.',409);
        $workerCurrent = $worker && empty($worker['deleted_at']) && in_array($worker['status'],['active','bench'],true);
        // Existing inactive workers cannot be reactivated as a side effect of contract refresh.
        if (!$archived && $worker && !$workerCurrent) throw new RuntimeException('Review the inactive assignment worker before refreshing this contract.',409);
        $skip = $archived && ($held || !$workerCurrent || !empty($placement['actual_end_date']) || !in_array($lifecycle['status'],['active','pending_start','on_hold'],true));
        if ($skip) {
            $snapshot = jobdivaAssignmentCompactSnapshotPayload($payload,$base['external']);
            jobdivaPlacementBindRetained($workspace,$base['mapping']['external_id'],$placementId,$snapshot,$actor);
            $result = ['projected'=>0,'restored'=>0,'skipped_not_current'=>1,'review_required'=>(int)($held || !$workerCurrent)];
        } else {
            $corporation = jobdivaAssignmentSubcontractCompanyId($payload);
            if ($corporation !== '' && !in_array('corp_identity',$base['owned_fields'],true)) {
                if ($corporation !== $current['corporation_id']) throw new RuntimeException('The corporate payee was not included in the protected review.',409);
                if (empty($current['company_evidence']['available'])) {
                    $name = is_array($companyPayload) ? jobdivaPluckField($companyPayload,['name','companyName','company_name','company name','legalName','legal_name']) : '';
                    $returnedId = is_array($companyPayload) ? jobdivaAssignmentCompanyId(jobdivaPluckField($companyPayload,['id','companyId','company_id','company id'])) : '';
                    if ($returnedId !== $corporation || jobdivaCompanyNameIsPlaceholder($name,$corporation,$companyPayload ?? [])) throw new RuntimeException('The corporate payee evidence does not match this contract.',409);
                    $attributes = ['created_by_user_id'=>$actor];
                    foreach (['legal_name'=>['legalName','legal_name','legal name'], 'website'=>['website','url','homepage'],
                        'phone'=>['phone','phoneNumber','phone_number'], 'address_line1'=>['address1','address','street1'],
                        'address_line2'=>['address2','street2','suite'], 'city'=>['city','town'], 'state'=>['state','region','province'],
                        'postal_code'=>['zip','postal_code','postalCode'], 'country'=>['country','countryCode','country_code']] as $field=>$aliases) {
                        $value = jobdivaPluckField($companyPayload,$aliases);
                        if ($value !== '') $attributes[$field] = $value;
                    }
                    jobdivaUpsertCompanyMapped($workspace,$corporation,$name,$attributes,$companyPayload,$actor,['vendor']);
                }
            }
            $projection = jobdivaProjectorProjectPlacement($workspace,jobdivaCanonicalPlacementPayload($payload,jobdivaExtractJoinedSubPayloads($payload)),$actor,[
                'payload_is_enriched'=>true,'external_id'=>$base['external'],'existing_placement_id'=>$placementId,
                'person_id'=>0,'force_source_contract'=>true,
            ]);
            if (empty($projection['projected'])) throw new RuntimeException(implode('; ',array_map('strval',$projection['errors'] ?? ['Assignment projection failed.'])),409);
            $after = jobdivaAssignmentRepairSnapshot($workspace,(int)$base['mapping']['id']);
            if ($archived && !empty($after['placement']['deleted_at'])) throw new RuntimeException('The current assignment could not be restored.',409);
            $result = ['projected'=>1,'restored'=>(int)$archived,'skipped_not_current'=>0,'review_required'=>0];
        }
        companySourceAudit(getDB(),$workspace,$owner,'placements',$placementId,'placement.live_contract.refreshed',['source','contract'],$actor,[
            'source_system'=>'jobdiva','source_mapping_id'=>(int)$base['mapping']['id'],'source_external_id'=>$base['mapping']['external_id'],
            'review_fingerprint'=>jobdivaAssignmentRepairFingerprint($expected),'result'=>$result,
        ]);
        return $result;
    });
}

function jobdivaRefreshLiveContract(int $workspace, int $mappingId, ?int $actor): array
{
    if (getDB()->inTransaction() || JobdivaStoredReviewTransaction::inScope()) throw new RuntimeException('Start live contract refresh outside an existing transaction.',409);
    try{$expected = JobdivaStoredReviewTransaction::run(static fn()=>jobdivaLiveContractSnapshot($workspace,$mappingId),true);}
    catch(JobdivaHistoricalPlacementSource){return ['projected'=>0,'restored'=>0,'skipped_not_current'=>1,'review_required'=>0];}
    $exact = jobdivaFetchExactAssignmentById($workspace,$expected['base']['external'],$expected['joined_payload']);
    if (($exact['status'] ?? '') !== 'verified') return jobdivaLiveContractApply($expected,$exact,null,null,$actor);
    $input = jobdivaLiveContractInput($expected,$exact);
    unset($input['_jd_assignment_detail'],$input['_jd_contract']);
    $diagnostics = [];
    $items = jobdivaSyncEnrichRelatedEntities($workspace,[$input],$actor,['kinds'=>['financial'],'enrich_start'=>false,'enrich_financial'=>true],$diagnostics);
    $payload = jobdivaLiveContractValidate($expected,$exact,$items[0] ?? []);
    $corporation = in_array('corp_identity',$expected['base']['owned_fields'],true) ? '' : jobdivaAssignmentSubcontractCompanyId($payload);
    $companyPayload = null;
    if ($corporation !== '') {
        // Its identity is only known after the exact contract arrives. Capture
        // company evidence before that separate network read, retaining the
        // original assignment review rather than accepting a newer placement.
        $expanded = JobdivaStoredReviewTransaction::run(static fn()=>jobdivaLiveContractSnapshot($workspace,$mappingId,$corporation),true);
        $comparison = array_replace($expanded,['corporation_id'=>'','company_evidence'=>null]);
        if (jobdivaAssignmentRepairFingerprint($comparison) !== jobdivaAssignmentRepairFingerprint($expected)) throw new RuntimeException('Assignment evidence changed during the contract lookup. Retry this contract.',409);
        $expected = $expanded;
        if (empty($expanded['company_evidence']['available'])) $companyPayload = jobdivaLiveContractFetchCompany($workspace,$corporation);
    }
    return jobdivaLiveContractApply($expected,$exact,$payload,$companyPayload,$actor);
}
