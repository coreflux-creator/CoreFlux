<?php
declare(strict_types=1);

/** Bind or refresh an exact pair. A competing identity is never an upsert. */
function mappingBindStable(int $tenant, string $source, string $type, string $external, int $internal, ?array $payload, string $direction, ?int $actor): array
{
    $pdo = getDB();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $s = $pdo->prepare('SELECT * FROM external_entity_mappings WHERE tenant_id=:t AND source_system=:s AND internal_entity_type=:k AND (external_id=:e OR internal_entity_id=:i) FOR UPDATE');
        $s->execute(['t'=>$tenant,'s'=>$source,'k'=>$type,'e'=>$external,'i'=>$internal]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($row['external_id'] !== $external || (int)$row['internal_entity_id'] !== $internal) {
                throw new RuntimeException('This JobDiva candidate or Core person is already linked elsewhere. Review the existing identity; automatic replacement is disabled.');
            }
        }
        $old = $rows[0] ?? null;
        $hash = $payload === null ? null : mappingHash($payload);
        $snapshot = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $changed = !$old || ($hash !== null && $hash !== $old['content_hash']);
        if ($old) {
            $s = $pdo->prepare('UPDATE external_entity_mappings SET payload_snapshot=COALESCE(:p,payload_snapshot), content_hash=COALESCE(:h,content_hash), direction=:d, sync_status="ok", last_error=NULL, last_seen_at=NOW(), last_synced_at=IF(:c=1,NOW(),last_synced_at) WHERE tenant_id=:t AND id=:id');
            $s->execute(['p'=>$snapshot,'h'=>$hash,'d'=>$direction,'c'=>(int)$changed,'t'=>$tenant,'id'=>$old['id']]);
        } else {
            $s = $pdo->prepare('INSERT INTO external_entity_mappings (tenant_id,source_system,internal_entity_type,external_id,internal_entity_id,payload_snapshot,content_hash,direction,sync_status,last_seen_at,last_synced_at) VALUES(:t,:s,:k,:e,:i,:p,:h,:d,"ok",NOW(),NOW())');
            $s->execute(['t'=>$tenant,'s'=>$source,'k'=>$type,'e'=>$external,'i'=>$internal,'p'=>$snapshot,'h'=>$hash,'d'=>$direction]);
        }
        if ($changed && $snapshot !== null) {
            entitySyncHistoryRecord($tenant,$source,$type,$internal,$external,$direction,$old['payload_snapshot']??null,$snapshot,$old['content_hash']??null,$hash,$actor);
        }
        if ($payload !== null) {
            try {
                require_once __DIR__.'/payload_field_index.php';
                integrationPayloadFieldIndexRecord($tenant,$source,$type,$payload);
            } catch (Throwable $e) { error_log('[mappingBindStable] source field indexing unavailable: '.$e->getMessage()); }
        }
        $result = mappingFindInternal($tenant,$source,$type,$external);
        if ($own) $pdo->commit();
        return $result + ['changed'=>$changed];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode()==='23000') throw new RuntimeException('A competing person link was recorded. Refresh and review the existing identity.',0,$e);
        throw $e;
    }
}
