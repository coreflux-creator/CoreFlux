<?php
/** Revalidate source time at a consuming operation; never revise financial history. */
declare(strict_types=1);
require_once __DIR__.'/sync.php';

const QUANTA_APPROVED_STATE_PATH = '/time-entries/approved-state';

/** Legacy aggregate bundles cannot preserve the per-entry Quanta consumption proof. */
function quantaTimeAssertLegacyBundles(PDO $pdo, int $tenantId, array $bundleIds): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Bundle verification requires the consuming transaction.');
    $ids = array_values(array_unique(array_map('intval', $bundleIds)));
    if (!$ids || min($ids) <= 0 || count($ids) > 5000) throw new InvalidArgumentException('A scoped bundle batch is required.');
    $s = $pdo->prepare('SELECT id,entries_json FROM time_downstream_feed WHERE tenant_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).') FOR UPDATE');
    $s->execute([$tenantId,...$ids]);
    $bundles = $s->fetchAll(PDO::FETCH_ASSOC);
    if (count($bundles) !== count($ids)) throw new RuntimeException('Some time bundles no longer belong to this company.');
    $entryIds = [];
    foreach ($bundles as $bundle) {
        $entries = json_decode((string)$bundle['entries_json'], true, 32, JSON_THROW_ON_ERROR)['entry_ids']??null;
        if (!is_array($entries) || !$entries) throw new RuntimeException('This time bundle has no source entries. Rebuild and review it.');
        $entryIds = array_merge($entryIds, $entries);
    }
    if (quantaTimeAssertCurrent($pdo,$tenantId,$entryIds)) {
        throw new RuntimeException('Use Time Settlement for Quanta entries so each approved source entry stays linked to its bill, invoice, or payroll draft.');
    }
}

/** Every requested ID must be explicitly answered by the same workspace and fresh request. */
function quantaTimeStateFetch(string $key, string $workspaceId, array $ids): array
{
    $ids = array_values(array_unique($ids));
    if (!$ids || count($ids) > 1000) throw new InvalidArgumentException('Recheck 1-1000 Quanta entries at a time.');
    foreach ($ids as $id) {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id)) throw new InvalidArgumentException('Invalid Quanta source entry ID.');
    }
    $rows = []; $observations = [];
    foreach (array_chunk($ids, 25) as $chunk) {
        $nonce = bin2hex(random_bytes(16));
        try { $result = quantaGet($key, QUANTA_APPROVED_STATE_PATH, ['entry_ids'=>implode(',', $chunk), 'request_id'=>$nonce]); }
        catch (QuantaApiException $e) {
            throw new QuantaApiException('Quanta could not confirm the current approved hours. Check the connection and retry. No approval or extraction was allowed.', $e->httpStatus);
        }
        if (($result['version']??null) !== 1 || ($result['workspace_id']??null) !== $workspaceId
            || ($result['request_id']??null) !== $nonce || !is_array($result['data']??null)
            || !array_is_list($result['data']) || count($result['data']) !== count($chunk)) {
            throw new RuntimeException('Quanta source-state response is incomplete or belongs to another request/workspace.');
        }
        $checkedAt = quantaApprovalTimestamp($result['checked_at']??null, 'source checked_at');
        if ($checkedAt === null) throw new RuntimeException('Quanta source-state response has no observation time.');
        $seen = [];
        foreach ($result['data'] as $row) {
            $id = $row['id']??null;
            if (!is_array($row) || !is_string($id) || !in_array($id, $chunk, true) || isset($seen[$id])) {
                throw new RuntimeException('Quanta source-state response has duplicate or unexpected entries.');
            }
            $seen[$id] = true;
            if (($row['state']??null) === 'approved') {
                $entry = $row['entry']??null;
                if (!is_array($entry) || ($entry['id']??null) !== $id || ($entry['timesheet_status']??null) !== 'approved'
                    || ($entry['payroll_excluded']??null) !== false) throw new RuntimeException('Quanta returned invalid approved source time.');
            } elseif (($row['state']??null) !== 'unavailable' || isset($row['entry'])) {
                throw new RuntimeException('Quanta returned an unsupported source state.');
            }
            $rows[$id] = $row;
        }
        $observations[] = ['request_id'=>$nonce, 'checked_at'=>$checkedAt, 'entries'=>count($chunk)];
    }
    return ['rows'=>$rows, 'observations'=>$observations];
}

/** Caller holds the consuming transaction so approval/extraction cannot race a Core edit. */
function quantaTimeAssertCurrent(PDO $pdo, int $tenantId, array $entryIds): array
{
    $ids = array_values(array_unique(array_map('intval', $entryIds)));
    if (!$ids) return [];
    if ($tenantId <= 0 || min($ids) <= 0 || count($ids) > 5000) throw new InvalidArgumentException('A scoped time-entry batch is required.');
    $entries = [];
    foreach (array_chunk($ids, 200) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $s = $pdo->prepare('SELECT * FROM time_entries WHERE tenant_id=? AND id IN ('.$marks.') ORDER BY id'.($pdo->inTransaction()?' FOR UPDATE':''));
        $s->execute([$tenantId, ...$chunk]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) $entries[(int)$row['id']] = $row;
    }
    if (count($entries) !== count($ids)) throw new RuntimeException('Some time entries no longer belong to this company.');
    $quantaIds = array_keys(array_filter($entries, static fn(array $e):bool=>($e['source']??null)==='quanta' || ($e['source_system']??null)==='quanta'));
    $hasImports = (bool)$pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='quanta_time_imports'")->fetchColumn();
    if (!$hasImports) {
        if ($quantaIds) throw new RuntimeException('Quanta source provenance is unavailable. Apply the integration schema before continuing.');
        return [];
    }
    $sourceIds = [];
    foreach (array_chunk($ids, 200) as $chunk) {
        $s = $pdo->prepare('SELECT time_entry_id,quanta_entry_id FROM quanta_time_imports WHERE tenant_id=? AND time_entry_id IN ('.implode(',',array_fill(0,count($chunk),'?')).')'.($pdo->inTransaction()?' FOR UPDATE':''));
        $s->execute([$tenantId, ...$chunk]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['time_entry_id'];
            if (isset($sourceIds[$id])) throw new RuntimeException('Quanta time has ambiguous source provenance.');
            $sourceIds[$id] = $row['quanta_entry_id'];
        }
    }
    // A changed source label must not erase the durable original import.
    $quantaIds = array_values(array_unique([...$quantaIds, ...array_keys($sourceIds)]));
    if (!$quantaIds) return []; // Native time never requires a Quanta connection.
    if (!$pdo->inTransaction() || $pdo !== getDB()) throw new LogicException('Quanta time verification requires the consuming transaction.');
    if (count($sourceIds) !== count($quantaIds)) throw new RuntimeException('Quanta time has missing source provenance. Refresh its original import before continuing.');
    $access = quantaWorkspaceForDelivery($tenantId);
    $workspace = $access['context']['workspace']['id'];
    $state = quantaTimeStateFetch($access['api_key'], $workspace, array_values($sourceIds));
    $imports = quantaImportLookup($tenantId, array_values($sourceIds), true);
    $routes = quantaRoutes($tenantId);
    $workers = quantaWorkerPersonMap(quantaWorkerLinks($tenantId));
    $verified = [];
    foreach ($state['rows'] as $sourceId=>$row) {
        if ($row['state'] !== 'approved') throw new RuntimeException("Quanta entry {$sourceId} is no longer available as approved time. Review its original import before continuing.");
        $entry = quantaNormalizeEntry($row['entry'], [], 'approved');
        $route = quantaRouteFor($routes, $entry, $workers);
        $prior = $imports[$sourceId]??[];
        if (!$entry['source_approval'] || count($prior) !== count($entry['components'])) throw new RuntimeException('Quanta source components or approval evidence changed. Refresh and review the import.');
        foreach ($entry['components'] as $component=>$hours) {
            $stored = $prior[$component]??[];
            if (($stored['source_workspace_id']??null) !== $workspace
                || !quantaTimeSourceMatches($entry,$route,$component,$hours,$stored)
                || !quantaTimeApprovalMatches($entry,$stored)) {
                throw new RuntimeException("Quanta entry {$sourceId} or its Core import changed. Refresh and review the source before approving or using these hours.");
            }
            $verified[] = ['time_entry_id'=>(int)$stored['time_entry_id'],'source_entry_id'=>$sourceId,
                'source_hash'=>$stored['source_hash'],'source_approval_hash'=>$stored['source_approval_hash']];
        }
    }
    quantaWorkspaceAssertCurrent($pdo,$tenantId,$access,true);
    return ['workspace_id'=>$workspace,'source_entries'=>count($state['rows']),
        'observations'=>$state['observations'],'verified_source_hash'=>integrationDeliveryHash($verified)];
}
