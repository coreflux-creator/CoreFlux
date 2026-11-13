<?php
/**
 * QuickBooks Online — Slice 3 inbound sync drivers.
 *
 * Pulls QBO Customer + Vendor master data into CoreFlux, idempotent
 * via `external_entity_mappings` (source='quickbooks_online',
 * entity_type='customer' | 'vendor'). Tenants must have the relevant
 * entity's direction set to `pull` or `two_way`.
 *
 * - QBO Customer  → CoreFlux `staffing_clients`
 *   Exact source identity; namesakes require review, native fields remain protected.
 * - QBO Vendor    → CoreFlux `ap_vendors_index`
 *   Match-by-name UNIQUE KEY uq_apv_tenant_name (tenant_id, vendor_name).
 *
 * QBO Query API pagination:
 *   /v3/company/{realm}/query?query=SELECT * FROM Customer STARTPOSITION 1 MAXRESULTS 1000
 *
 * Each page is upserted, then we advance STARTPOSITION until QBO returns
 * fewer rows than requested (or the optional `limit` opt is reached).
 *
 * Public surface:
 *   qboSyncCustomers(int $tid, ?int $userId, array $opts=[]): array
 *   qboSyncVendors(int $tid, ?int $userId, array $opts=[]): array
 *   qboUpsertCustomer(int $tid, array $qboCustomer): array  // {internal_id, action: 'created'|'updated'|'unchanged'}
 *   qboUpsertVendor(int $tid, array $qboVendor): array
 *
 * Opts shared by both syncers:
 *   - limit:   int (default 1000) — max records to ingest this run.
 *   - max_pages: int (default 10) — safety net against runaway loops.
 *   - modified_since: optional ISO datetime for incremental scheduled pulls.
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';
require_once __DIR__ . '/conflict_rules.php';
require_once __DIR__ . '/../../modules/staffing/lib/clients.php';
require_once __DIR__ . '/../integrations/source_values.php';

const QBO_PAGE_SIZE = 100;

// =====================================================================
// Customer pull → staffing_clients
// =====================================================================

function qboSyncCustomers(int $tenantId, ?int $userId, array $opts = []): array
{
    return _qboSyncMasterEntity($tenantId, $userId, $opts, [
        'entity'       => 'customer',
        'qbo_resource' => 'Customer',
        'upsert'       => 'qboUpsertCustomer',
    ]);
}

function qboSyncVendors(int $tenantId, ?int $userId, array $opts = []): array
{
    return _qboSyncMasterEntity($tenantId, $userId, $opts, [
        'entity'       => 'vendor',
        'qbo_resource' => 'Vendor',
        'upsert'       => 'qboUpsertVendor',
    ]);
}

function _qboSyncMasterEntity(int $tenantId, ?int $userId, array $opts, array $cfg): array
{
    $start    = microtime(true);
    $limit    = max(1, min(5000, (int) ($opts['limit'] ?? 1000)));
    $maxPages = max(1, min(50,    (int) ($opts['max_pages'] ?? 10)));
    $since    = trim((string) ($opts['modified_since'] ?? ''));

    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        throw new \RuntimeException('QuickBooks is not connected for this tenant');
    }
    $entity     = $cfg['entity'];                 // 'customer' | 'vendor'
    $resource   = $cfg['qbo_resource'];           // 'Customer' | 'Vendor'
    $upsertFn   = $cfg['upsert'];
    $cfgKey     = $entity . 's';                  // 'customers' | 'vendors'
    $config     = qboSyncConfigRead($tenantId);
    if (!in_array($config[$cfgKey] ?? 'off', ['pull', 'two_way'], true)) {
        throw new \RuntimeException(ucfirst($entity) . 's direction is not pull/two_way for this tenant');
    }
    $realm = (string) $conn['realm_id'];

    $created = 0; $updated = 0; $unchanged = 0; $failed = 0;
    $startPos = 1;
    $pulled   = 0;
    $pages    = 0;
    $results  = [];

    while ($pulled < $limit && $pages < $maxPages) {
        $pages++;
        $pageSize = min(QBO_PAGE_SIZE, $limit - $pulled);
        $where = $since !== ''
            ? " WHERE MetaData.LastUpdatedTime >= '" . addslashes($since) . "'"
            : '';
        $query = sprintf('SELECT * FROM %s%s STARTPOSITION %d MAXRESULTS %d', $resource, $where, $startPos, $pageSize);
        try {
            $resp = qboCall($tenantId, 'GET', '/v3/company/' . $realm . '/query', null, [
                'query'        => $query,
                'minorversion' => 65,
            ]);
        } catch (\Throwable $e) {
            qboAudit($tenantId, 'sync_' . $entity . '_error', [
                'ok' => false, 'actor_user_id' => $userId,
                'direction' => 'pull', 'entity_type' => $entity,
                'detail' => ['error' => substr($e->getMessage(), 0, 500), 'page' => $pages, 'startPosition' => $startPos],
            ]);
            throw $e;
        }
        $rows = $resp['QueryResponse'][$resource] ?? [];
        if (!is_array($rows) || count($rows) === 0) break;

        foreach ($rows as $row) {
            try {
                $r = $upsertFn($tenantId, $row);
                $action = $r['action'] ?? 'unchanged';
                if      ($action === 'created')   $created++;
                elseif  ($action === 'updated')   $updated++;
                else                              $unchanged++;
                $results[] = [
                    'qbo_id'      => (string) ($row['Id'] ?? ''),
                    'name'        => (string) ($row['DisplayName'] ?? $row['CompanyName'] ?? ''),
                    'internal_id' => $r['internal_id'] ?? null,
                    'action'      => $action,
                    'held_fields' => $r['held_fields'] ?? [],
                ];
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'qbo_id' => (string) ($row['Id'] ?? ''),
                    'name'   => (string) ($row['DisplayName'] ?? $row['CompanyName'] ?? ''),
                    'action' => 'failed', 'error' => substr($e->getMessage(), 0, 300),
                ];
            }
        }
        $pulled += count($rows);
        if (count($rows) < $pageSize) break;   // last page
        $startPos += count($rows);
    }

    $latency = (int) round((microtime(true) - $start) * 1000);
    qboAudit($tenantId, 'sync_' . $entity, [
        'entity_type' => $entity, 'direction' => 'pull',
        'ok' => $failed === 0,
        'actor_user_id'   => $userId,
        'items_processed' => $created + $updated,
        'items_skipped'   => $unchanged,
        'items_failed'    => $failed,
        'detail' => [
            'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged,
            'failed'  => $failed,  'pulled'  => $pulled,
            'pages'   => $pages,   'latency_ms' => $latency,
            'modified_since' => $since,
        ],
    ]);
    return [
        'entity'    => $entity,
        'created'   => $created,
        'updated'   => $updated,
        'unchanged' => $unchanged,
        'failed'    => $failed,
        'pulled'    => $pulled,
        'pages'     => $pages,
        'latency_ms'=> $latency,
        'modified_since' => $since,
        'results'   => $results,
    ];
}

// =====================================================================
// Upserters — translate QBO payload → CoreFlux row
// =====================================================================

/**
 * QBO Customer → staffing_clients.
 *
 * Match strategy (in order):
 *   1. existing mapping (source='quickbooks_online', entity_type='customer', external_id=QBO Id)
 *   2. independently owned current company/client graph; namesakes require review.
 * Native field ownership takes precedence over source timestamps.
 */
function qboUpsertCustomer(int $tenantId, array $qbo): array
{
    $qboId=$qbo['Id']??null;
    if((!is_string($qboId)&&!is_int($qboId))||trim((string)$qboId)==='')throw new InvalidArgumentException('QBO Customer missing Id');
    foreach(['DisplayName','CompanyName']as$field)if(isset($qbo[$field])&&!is_string($qbo[$field]))throw new InvalidArgumentException('Invalid QBO customer name.');
    foreach(['Active','Job']as$field)if(isset($qbo[$field])&&!is_bool($qbo[$field]))throw new InvalidArgumentException('Invalid QBO customer status.');
    foreach(['PrimaryEmailAddr','PrimaryPhone','BillAddr','ParentRef']as$field)if(isset($qbo[$field])&&!is_array($qbo[$field]))throw new InvalidArgumentException('Invalid QBO customer profile.');
    foreach(['PrimaryEmailAddr'=>['Address'],'PrimaryPhone'=>['FreeFormNumber'],'BillAddr'=>['Line1','Line2','City','CountrySubDivisionCode','PostalCode','Country']]as$field=>$keys){
        foreach($keys as$key)if(isset($qbo[$field][$key])&&!is_string($qbo[$field][$key]))throw new InvalidArgumentException('Invalid QBO customer field.');
    }
    $name=trim((string)($qbo['DisplayName']??$qbo['CompanyName']??''));
    if($name==='')throw new InvalidArgumentException('QBO Customer missing DisplayName/CompanyName');
    $payload=['name'=>$name];
    foreach(['CompanyName'=>'legal_name']as$source=>$target)if(array_key_exists($source,$qbo)&&$qbo[$source]!==null)$payload[$target]=(string)$qbo[$source];
    foreach(['PrimaryEmailAddr'=>['Address','primary_contact_email'],'PrimaryPhone'=>['FreeFormNumber','primary_contact_phone']]as$key=>[$field,$target]){
        if(is_array($qbo[$key]??null)&&array_key_exists($field,$qbo[$key])&&$qbo[$key][$field]!==null)$payload[$target]=(string)$qbo[$key][$field];
    }
    if(is_array($qbo['BillAddr']??null)){
        $addr=$qbo['BillAddr'];
        foreach(['Line1'=>'billing_address_line1','Line2'=>'billing_address_line2','City'=>'billing_city','CountrySubDivisionCode'=>'billing_state','PostalCode'=>'billing_postal_code']as$key=>$target){
            if(array_key_exists($key,$addr)&&$addr[$key]!==null)$payload[$target]=(string)$addr[$key];
        }
        if(!empty($addr['Country'])){
            $country=trim((string)$addr['Country']);
            $payload['billing_country']=integrationSourceCountry($country);
            if($payload['billing_country']===null)throw new InvalidArgumentException('Review the QuickBooks billing country before importing.');
        }
    }
    if(!empty($qbo['Job'])||!empty($qbo['ParentRef']['value']))$payload['status']='inactive';
    elseif(array_key_exists('Active',$qbo))$payload['status']=$qbo['Active']?'active':'inactive';
    $result=staffingClientEnsureForCompany($tenantId,null,$name,$payload+[
        'source_system'=>'qbo','source_external_id'=>trim((string)$qboId),'source_payload'=>$qbo,
    ]);
    return ['internal_id'=>$result['client_id'],'action'=>$result['created']?'created':($result['changed']?'updated':'unchanged'),'held_fields'=>$result['held_fields']];
}

/**
 * QBO Vendor → ap_vendors_index.
 *
 * Mirrors qboUpsertCustomer's match-strategy and content-hash flow,
 * adapted to the vendor schema's columns.
 */
function qboUpsertVendor(int $tenantId, array $qbo): array
{
    $qboId = (string) ($qbo['Id'] ?? '');
    if ($qboId === '') throw new \InvalidArgumentException('QBO Vendor missing Id');

    $displayName = trim((string) ($qbo['DisplayName'] ?? $qbo['CompanyName'] ?? ''));
    if ($displayName === '') throw new \InvalidArgumentException('QBO Vendor missing DisplayName/CompanyName');
    // QBO Vendor.Vendor1099 flag → 1099 readiness signal.
    $is1099 = !empty($qbo['Vendor1099']) ? 1 : 0;
    $type = $is1099 ? '1099_individual' : 'other';

    $pdo = getDB();
    $mapping = mappingFindInternal($tenantId, QBO_SOURCE, 'vendor', $qboId);
    $internalId = $mapping ? (int) $mapping['internal_entity_id'] : 0;
    if (!$internalId) {
        $stmt = $pdo->prepare('SELECT id FROM ap_vendors_index WHERE tenant_id = :t AND vendor_name = :n LIMIT 1');
        $stmt->execute(['t' => $tenantId, 'n' => $displayName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $internalId = $row ? (int) $row['id'] : 0;
    }
    $action = 'unchanged';
    if (!$internalId) {
        $pdo->prepare(
            'INSERT INTO ap_vendors_index
                (tenant_id, vendor_name, vendor_type, requires_1099)
             VALUES (:t, :n, :ty, :r)'
        )->execute([
            't'  => $tenantId,
            'n'  => $displayName,
            'ty' => $type,
            'r'  => $is1099,
        ]);
        $internalId = (int) $pdo->lastInsertId();
        $action = 'created';
    } else {
        // Cheap dirty-check on vendor_type + requires_1099.
        $stmt = $pdo->prepare('SELECT vendor_type, requires_1099 FROM ap_vendors_index WHERE id = :id AND tenant_id = :t LIMIT 1');
        $stmt->execute(['id' => $internalId, 't' => $tenantId]);
        $cur = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        $changed = ((string) ($cur['vendor_type']   ?? '') !== $type)
                || ((int)    ($cur['requires_1099'] ?? -1) !== $is1099);
        if ($changed) {
            $pdo->prepare(
                'UPDATE ap_vendors_index
                    SET vendor_type = :ty, requires_1099 = :r
                  WHERE id = :id AND tenant_id = :t'
            )->execute(['ty' => $type, 'r' => $is1099, 'id' => $internalId, 't' => $tenantId]);
            $action = 'updated';
        }
    }
    $row = mappingUpsert($tenantId, QBO_SOURCE, 'vendor', $qboId, $internalId, $qbo, 'pull');
    if (!$row['changed'] && $action === 'unchanged') $action = 'unchanged';
    return ['internal_id' => $internalId, 'action' => $action];
}
