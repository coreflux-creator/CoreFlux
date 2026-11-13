<?php
/** Exact source verification, with the provider lookup outside the write transaction. */
declare(strict_types=1);
require_once __DIR__.'/stored_review.php';
require_once __DIR__.'/placement_write_context.php';

/** Internal evidence only; never return this snapshot from a public API. */
function jobdivaAssignmentRepairSnapshot(int $workspace, int $mappingId): array
{
    if (!JobdivaStoredReviewTransaction::active()) throw new LogicException('Assignment repair requires its protected review transaction.');
    $pdo = getDB();
    $context = jobdivaPlacementWriteContext($pdo, $workspace);
    $q = $pdo->prepare("SELECT * FROM external_entity_mappings WHERE tenant_id=? AND id=? AND source_system='jobdiva' AND internal_entity_type='placement' FOR UPDATE");
    $q->execute([$workspace, $mappingId]);
    $mapping = $q->fetch(PDO::FETCH_ASSOC);
    if (!$mapping) throw new RuntimeException('The original workspace assignment link is unavailable.', 409);
    $external = jobdivaAssignmentIdentityNormaliseId($mapping['external_id']);
    if (!preg_match('/^[0-9]+$/D', $external)) throw new RuntimeException('Review the exact JobDiva Start ID.', 409);
    $identity = jobdivaPlacementWriteIdentity($pdo, $context, $external, (int)$mapping['internal_entity_id']);
    if ((int)($identity['mapping']['id'] ?? 0) !== $mappingId || !$identity['placement']) {
        throw new RuntimeException('The assignment source identity changed.', 409);
    }
    $q = $pdo->prepare("SELECT * FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='jobdiva_assignment' AND external_id IN(?,?) ORDER BY id FOR UPDATE");
    $q->execute([$workspace, $external, 'jd:'.$external]);
    $mirrors = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($mirrors) > 1) throw new RuntimeException('Review the competing assignment mirrors before repair.', 409);
    $q = $pdo->prepare('SELECT id,status,last_sync_at,sync_config,field_ownership FROM jobdiva_connections WHERE tenant_id=? FOR UPDATE');
    $q->execute([$workspace]);
    $connection = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $q = $pdo->prepare('SELECT id,tenant_id,status,deleted_at,external_id FROM people WHERE tenant_id=? AND id=? FOR UPDATE');
    $q->execute([$context['people_owner'], $identity['placement']['person_id']]);
    $person = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $q = $pdo->prepare('SELECT tenant_id,module_key,scope_mode FROM tenant_module_scope WHERE tenant_id IN(?,?) ORDER BY tenant_id,module_key FOR UPDATE');
    $q->execute([$workspace, $context['placement_owner']]);
    return [
        'context'=>$context, 'policies'=>$q->fetchAll(PDO::FETCH_ASSOC),
        'external'=>$external, 'mapping'=>$mapping, 'mirrors'=>$mirrors,
        'connection'=>$connection, 'last_sync_at'=>$connection['last_sync_at'] ?? null,
        'placement'=>$identity['placement'], 'owned_fields'=>$identity['owned_fields'], 'person'=>$person,
    ];
}

function jobdivaAssignmentRepairFingerprint(array $evidence): string
{
    return hash('sha256', json_encode(mappingCanonicalise($evidence), JSON_THROW_ON_ERROR));
}

/** Every dependent record blocks automatic archival, regardless of its module owner. */
function jobdivaAssignmentRepairHasActivity(PDO $pdo, int $owner, int $placementId): bool
{
    // The accessible placement scopes these existence checks. No foreign
    // document contents, identifiers or counts leave this helper.
    foreach (['time_entries','time_daily_finance','time_approval_tokens','time_downstream_feed',
        'ap_bills','placement_documents','people_pipeline_stages','placement_economic_items',
        'placement_economic_obligations','billing_invoice_lines','ap_bill_lines'] as $table) {
        // Some optional modules have no placement reference in older installs.
        // A query failure is not absence: it must abort the repair.
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name="placement_id"');
        $q->execute([$table]);
        if (!(int)$q->fetchColumn()) continue;
        $q = $pdo->prepare('SELECT child.placement_id FROM '.$table.' child JOIN placements p ON p.id=child.placement_id WHERE p.tenant_id=? AND p.id=? LIMIT 1 FOR UPDATE');
        $q->execute([$owner, $placementId]);
        if ($q->fetchColumn() !== false) return true;
    }
    return false;
}

/** Apply only to the same evidence reviewed before the remote read. */
function jobdivaAssignmentRepairApply(array $expected, array $exact, ?int $actor = null): array
{
    return JobdivaStoredReviewTransaction::run(static function () use ($expected, $exact, $actor): array {
        $workspace = (int)$expected['context']['workspace'];
        $mappingId = (int)$expected['mapping']['id'];
        $current = jobdivaAssignmentRepairSnapshot($workspace, $mappingId);
        if (!hash_equals(jobdivaAssignmentRepairFingerprint($expected), jobdivaAssignmentRepairFingerprint($current))) {
            throw new RuntimeException('Assignment evidence changed during source verification. Refresh and review again.', 409);
        }
        $pdo = getDB(); $mapping = $current['mapping']; $placement = $current['placement'];
        $external = $current['external']; $owner = $current['context']['placement_owner'];
        $placementId = (int)$placement['id'];
        $stored = json_decode((string)$mapping['payload_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($stored) || array_is_list($stored)) throw new RuntimeException('Review the saved assignment evidence.', 409);
        $stored = jobdivaAssignmentSanitisePayload($stored, $external);
        $validation = jobdivaAssignmentValidate($stored, $external);
        $fresh = $mapping['sync_status'] === 'ok'
            && (!$current['mirrors'] || $current['mirrors'][0]['sync_status'] === 'ok')
            && jobdivaAssignmentObservedInLatestSync($validation, $mapping['last_seen_at'], $current['last_sync_at']);
        if (($exact['status'] ?? '') === 'verified') {
            $remote = $exact['row'] ?? null;
            if (!is_array($remote) || empty(jobdivaAssignmentValidate($remote, $external)['valid'])
                || empty(jobdivaAssignmentContextEvidence($remote, $stored)['matches'])) {
                throw new RuntimeException('The returned Start does not match the reviewed assignment context.', 409);
            }
        }
        $decision = jobdivaAssignmentSourceDecision($exact, $validation, $external, $fresh);
        $action = $decision['action'];
        $result = ['action'=>$action, 'placement_id'=>$placementId, 'mapping_changed'=>false,
            'placements_restored'=>0, 'placements_archived'=>0, 'archived_retained'=>0,
            'quarantined'=>0, 'review_required'=>0, 'reason'=>$decision['reason']];
        $wasArchived = !empty($placement['deleted_at']) && $placement['deleted_at'] !== '0000-00-00 00:00:00';
        $held = (bool)array_intersect(['status','start_date','end_date','actual_end_date','deleted_at'], $current['owned_fields']);
        $sourceStatus = 'ok'; $error = null; $payload = null;
        if (in_array($action, ['remote_verified','trusted_stored'], true)) {
            // Replace the root with the exact returned Start. Retain separate,
            // exact financial detail; never turn an enriched job/person into a Start.
            $payload = $action === 'remote_verified' ? $exact['row'] : jobdivaAssignmentCompactSnapshotPayload($stored, $external);
            foreach (['_jd_contract','_jd_assignment_detail'] as $key) {
                $facet = $stored[$key] ?? null;
                if (is_array($facet) && jobdivaAssignmentRowId($facet) === $external
                    && !empty(jobdivaAssignmentContextEvidence($facet, $payload)['matches'])) $payload[$key] = $facet;
            }
            $channel = $action === 'remote_verified'
                ? (string)($exact['identity']['channel'] ?? 'repair:exact_assignment')
                : (string)($validation['channel'] ?? 'repair:stored_assignment_evidence');
            $payload = jobdivaAssignmentCompactSnapshotPayload(jobdivaAssignmentMarkVerified($payload, $external, $channel), $external);
            if ($wasArchived) {
                $rawEnd = jobdivaAssignmentIdentityPluck($payload, ['end date','endDate','end_date']);
                $end = $rawEnd === '' ? ($placement['end_date'] ?? null) : jobdivaNormaliseDate($rawEnd);
                if ($rawEnd !== '' && $end === null) throw new RuntimeException('Review the source assignment end date.', 422);
                $status = jobdivaAssignmentPlacementStatusForWrite(
                    jobdivaAssignmentIdentityPluck($payload, ['startStatus','start_status','status','assignmentStatus']),
                    $placement['start_date'], $end, $placement['status']
                )['status'];
                $person = $current['person'];
                if (!$held && in_array($status, ['active','pending_start','on_hold'], true)
                    && $person && empty($person['deleted_at']) && in_array($person['status'], ['active','bench'], true)
                    && empty($placement['actual_end_date'])) {
                    // Do not replay commercial/profile fields in a source repair.
                    // If status/dates differ, the existing reviewed stored apply
                    // must handle that complete transition before restoration.
                    if ($status === $placement['status'] && $end === $placement['end_date']) {
                        $pdo->prepare('UPDATE placements SET deleted_at=NULL,updated_at=NOW() WHERE tenant_id=? AND id=?')->execute([$owner,$placementId]);
                        $result['placements_restored'] = 1;
                    } else $result['reason'] = 'review_lifecycle_transition';
                } else $result['reason'] = $held ? 'core_owned_lifecycle' : 'review_lifecycle_transition';
                if (!$result['placements_restored']) { $result['archived_retained']=1; $result['review_required']=1; }
            }
        } else {
            $error = substr((string)($exact['error'] ?? 'JobDiva assignment verification needs review'), 0, 500);
            $sourceStatus = $action === 'terminal' ? 'deleted_in_source' : 'error';
            if ($action === 'terminal' && !$wasArchived && !$held && !jobdivaAssignmentRepairHasActivity($pdo, $owner, $placementId)) {
                $pdo->prepare('UPDATE placements SET deleted_at=NOW(),updated_at=NOW() WHERE tenant_id=? AND id=?')->execute([$owner,$placementId]);
                $result['placements_archived'] = 1;
            } else {
                $result['archived_retained'] = (int)$wasArchived;
                $result['quarantined'] = (int)!$wasArchived;
                $result['review_required'] = 1;
                if ($held) $result['reason'] = 'core_owned_lifecycle';
            }
        }
        $payloadChanged = $payload !== null && mappingHash($payload) !== $mapping['content_hash'];
        $result['mapping_changed'] = $payloadChanged || $mapping['sync_status'] !== $sourceStatus || $mapping['last_error'] !== $error;
        if ($result['mapping_changed']) {
            if ($payload !== null) {
                mappingBindStable($workspace, 'jobdiva', 'placement', (string)$mapping['external_id'], $placementId, $payload, 'pull', $actor);
                // Trusting a prior observation is not a new provider observation.
                if ($action === 'trusted_stored') $pdo->prepare('UPDATE external_entity_mappings SET last_seen_at=? WHERE tenant_id=? AND id=?')->execute([$mapping['last_seen_at'],$workspace,$mappingId]);
            } else {
                $pdo->prepare('UPDATE external_entity_mappings SET sync_status=?,last_error=? WHERE tenant_id=? AND id=?')->execute([$sourceStatus,$error,$workspace,$mappingId]);
            }
        }
        if ($result['mapping_changed'] || $result['placements_restored'] || $result['placements_archived']) {
            $after = jobdivaAssignmentRepairSnapshot($workspace, $mappingId);
            companySourceAudit($pdo, $workspace, $owner, 'placements', $placementId, 'placement.assignment_source_repaired', ['source','deleted_at'], $actor, [
                'source_system'=>'jobdiva', 'source_mapping_id'=>$mappingId, 'source_external_id'=>$mapping['external_id'],
                'decision'=>$result, 'review_fingerprint'=>jobdivaAssignmentRepairFingerprint($expected),
                'before'=>['mapping'=>$mapping,'placement'=>$placement],
                'after'=>['mapping'=>$after['mapping'],'placement'=>$after['placement']],
            ]);
        }
        return $result;
    });
}

function jobdivaMappingRepairAssignmentSources(int $tenantId, ?int $userId = null, int $limit = 5000): array
{
    $summary = ['checked'=>0,'verified'=>0,'stored_assignments_trusted'=>0,'non_assignments'=>0,'not_found'=>0,
        'placements_restored'=>0,'placements_archived'=>0,'archived_retained'=>0,'quarantined'=>0,
        'review_required'=>0,'api_errors'=>0,'failed'=>0,'mapping_writes'=>0,'errors'=>[]];
    $pdo = getDB();
    // Holding database locks across a remote call is prohibited, even if the
    // caller happens to own a compatible nested transaction.
    if ($pdo->inTransaction() || JobdivaStoredReviewTransaction::inScope()) throw new RuntimeException('Start source verification outside an existing transaction.', 409);
    $limit = max(1, min(5000, $limit));
    $q = $pdo->prepare("SELECT id FROM external_entity_mappings WHERE tenant_id=? AND source_system='jobdiva' AND internal_entity_type='placement' ORDER BY id LIMIT {$limit}");
    $q->execute([$tenantId]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mappingId) {
        $summary['checked']++;
        try {
            $expected = JobdivaStoredReviewTransaction::run(static fn()=>jobdivaAssignmentRepairSnapshot($tenantId,(int)$mappingId), true);
            $hints = json_decode((string)$expected['mapping']['payload_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($hints) || array_is_list($hints)) throw new RuntimeException('Review the stored assignment lookup hints.', 409);
            $exact = jobdivaFetchExactAssignmentById($tenantId, $expected['external'], jobdivaAssignmentSanitisePayload($hints,$expected['external']));
            $result = jobdivaAssignmentRepairApply($expected, $exact, $userId);
            foreach (['placements_restored','placements_archived','archived_retained','quarantined','review_required'] as $key) $summary[$key]+=$result[$key];
            $summary['mapping_writes']+=(int)$result['mapping_changed'];
            if ($result['action']==='remote_verified') $summary['verified']++;
            if ($result['action']==='trusted_stored') $summary['stored_assignments_trusted']++;
            if (($exact['status']??'')==='not_assignment') $summary['non_assignments']++;
            if (($exact['status']??'')==='not_found') $summary['not_found']++;
            if (($exact['status']??'')==='error') { $summary['api_errors']++; if($result['action']==='review')$summary['failed']++; }
        } catch (Throwable $e) {
            $summary['failed']++;
            if (count($summary['errors'])<20) $summary['errors'][]='source link '.$mappingId.': '.$e->getMessage();
        }
    }
    return $summary;
}
