<?php
/** Inspect the accepted Meridian demo at its replacement preview; never repin or write records. */
declare(strict_types=1);

const CONTINUITY_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CONTINUITY_INSTANCE = '9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const CONTINUITY_ORG = 'org_purepay_demo';
const CONTINUITY_OLD_BASE = 'https://beautiful-kalam-6.preview.emergentagent.com/api/v1';
const CONTINUITY_CANDIDATE = 'https://pure-pay-support.preview.emergentagent.com/api/v1';

function purepayPreviewContinuityFixtures(): array
{
    return [
        'person'=>['delivery_id'=>7, 'entity_id'=>15, 'number'=>'P-15',
            'provider_id'=>'emp_core_c8af23472bb476f0cdd78341daffaac0b601bea851434a50a61436b5cbe615a3'],
        'client'=>['delivery_id'=>8, 'entity_id'=>1, 'number'=>'CL-1', 'provider_id'=>'cus_2851af69d9'],
        'placement'=>['delivery_id'=>9, 'entity_id'=>13, 'number'=>'PL-13',
            'provider_id'=>'asg_core_0a33a112da5a16fd7d1ed5105e02368300ff7266efc49214933955327002921e'],
    ];
}

/** Bounded GETs only. The candidate is fixed, with no redirects in the native adapter. */
function purepayPreviewContinuityRead(string $key, array $bodies): array
{
    $report = ['candidate'=>CONTINUITY_CANDIDATE, 'continuity_verified'=>false,
        'configuration_changed'=>false, 'provider_record_writes'=>false, 'checks'=>[]];
    $stage = 'organization';
    try {
        $get = static fn(string $path): array => purepayCall($key, 'GET', PUREPAY_CORE_PATH.$path,
            [], 15, null, CONTINUITY_CANDIDATE);
        $context = purepayCoreContextValidate($get('/context'));
        if ($context['organization']['id'] !== CONTINUITY_ORG
            || $context['link_contract_version'] !== PUREPAY_CORE_LINK_VERSION
            || !in_array('read:payroll', $context['capabilities'], true)) {
            throw new RuntimeException('Organization or read contract changed');
        }
        $report['checks'][$stage] = ['verified'=>true];
        foreach (purepayPreviewContinuityFixtures() as $kind=>$fixture) {
            $stage = $kind.'_record';
            $body = $bodies[$kind] ?? [];
            $reference = integrationRecordReference(CONTINUITY_INSTANCE, 999, $kind, $fixture['number']);
            if (($body['provider_id'] ?? null) !== $fixture['provider_id']
                || integrationDeliveryHash($body['reference'] ?? []) !== integrationDeliveryHash($reference)) {
                throw new RuntimeException('Local fixture identity changed');
            }
            $record = purepayCoreRecordView($kind, $get('/records/'.$kind.'/'.$fixture['provider_id']));
            if ($record['id'] !== $fixture['provider_id'] || !isset($record['record_version'])) {
                throw new RuntimeException('Provider identity changed');
            }
            if ($kind === 'person' && ($record['status'] ?? null) !== 'inactive') {
                throw new RuntimeException('Demo employee is no longer inactive');
            }
            if ($kind === 'placement') {
                $expected = ['status'=>'draft', 'person_number'=>'P-15', 'placement_number'=>'PL-13',
                    'client_number'=>'CL-1', 'employee_id'=>purepayPreviewContinuityFixtures()['person']['provider_id'],
                    'customer_id'=>purepayPreviewContinuityFixtures()['client']['provider_id'],
                    'auto_link'=>false, 'auto_bills'=>false, 'bills_drive_invoicing'=>false];
                foreach ($expected as $field=>$value) {
                    if (!array_key_exists($field, $record) || $record[$field] !== $value) {
                        throw new RuntimeException('Demo assignment state changed');
                    }
                }
            }
            $report['checks'][$stage] = ['verified'=>true, 'number'=>$fixture['number']];
            $stage = $kind.'_link';
            $links = $get('/links/resolve?'.http_build_query($reference+['provider_id'=>$fixture['provider_id']]));
            if (!is_array($links['reference_link'] ?? null) || !is_array($links['provider_link'] ?? null)) {
                throw new RuntimeException('Both durable links are required');
            }
            // Compare the original reviewed version, not the current mutable profile version.
            $left = purepayCoreLinkReceipt($links['reference_link'], CONTINUITY_ORG, $body, true);
            $right = purepayCoreLinkReceipt($links['provider_link'], CONTINUITY_ORG, $body, true);
            if ($left !== $right) throw new RuntimeException('Conflicting links');
            $report['checks'][$stage] = ['verified'=>true, 'number'=>$fixture['number']];
        }
        $report['continuity_verified'] = true;
    } catch (PurePayApiException $e) {
        $report['checks'][$stage] = ['verified'=>false, 'http_status'=>$e->httpStatus];
    } catch (Throwable $e) {
        // Provider text, personal record fields, encrypted payloads and keys never enter output.
        $report['checks'][$stage] = ['verified'=>false, 'reason'=>'identity_contract_or_fixture_mismatch'];
    }
    return $report;
}

// Tests import only the bounded reader; the deployed executable accepts no target overrides.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--inspect-reviewed-preview'
    || getenv('COREFLUX_ENV') !== 'staging' || realpath(getcwd()) !== CONTINUITY_ROOT) {
    fwrite(STDERR, "Exact isolated staging CLI and explicit inspection required.\n");
    exit(2);
}
try {
    require_once CONTINUITY_ROOT.'/core/db.php';
    $pdo = getDB();
    if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx'
        || !defined('COREFLUX_STAGING') || COREFLUX_STAGING !== true
        || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn() !== 1) {
        throw new RuntimeException('Simulation identity unavailable');
    }
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    require_once CONTINUITY_ROOT.'/core/integrations/purepay_links.php';
    require_once CONTINUITY_ROOT.'/core/sub_tenants.php';
    if (integrationInstanceId() !== CONTINUITY_INSTANCE
        || effectiveTenantIdForModule('people',999) !== 999
        || effectiveTenantIdForModule('placements',999) !== 999) {
        throw new RuntimeException('Directory identity changed');
    }
    $connection = purepayCoreConnection($pdo,999);
    if (!$connection || $connection['status'] !== 'active' || (int)$connection['revision'] !== 2
        || $connection['remote_organization_id'] !== CONTINUITY_ORG
        || $connection['core_instance_id'] !== CONTINUITY_INSTANCE
        || $connection['remote_api_base'] !== CONTINUITY_OLD_BASE || !is_string($connection['binding_hash'])) {
        throw new RuntimeException('Reviewed saved binding changed');
    }
    $bodies = []; $before = [];
    foreach (purepayPreviewContinuityFixtures() as $kind=>$fixture) {
        $row = integrationDeliveryGet($pdo,999,$fixture['delivery_id']);
        $payload = json_decode((string)decryptField($row['payload_ct']),true,64,JSON_THROW_ON_ERROR);
        $reference = integrationRecordReference(CONTINUITY_INSTANCE,999,$kind,$fixture['number']);
        if ($row['provider'] !== 'purepay' || $row['operation'] !== 'record.link' || $row['state'] !== 'delivered'
            || (int)$row['entity_tenant_id'] !== 999 || (int)$row['entity_id'] !== $fixture['entity_id']
            || $row['entity_type'] !== $kind || (string)$row['connection_ref'] !== (string)$connection['id']
            || $row['remote_id'] !== $fixture['provider_id']
            || $row['remote_receipt_id'] !== 'cfl_'.integrationDeliveryHash([CONTINUITY_ORG,$reference])
            || ($payload['organization_id'] ?? null) !== CONTINUITY_ORG) {
            throw new RuntimeException('Accepted local delivery changed');
        }
        $source = purepayCoreLinkSource($pdo,CONTINUITY_INSTANCE,999,$kind,$fixture['number']);
        if ($source['entity_id'] !== $fixture['entity_id']) throw new RuntimeException('Local identity changed');
        $bodies[$kind] = $payload['body']; $before[$kind] = $row;
    }
    $key = (string)decryptField($connection['api_key_ct']);
    $report = purepayPreviewContinuityRead($key,$bodies);
    $key = ''; unset($payload,$bodies);
    if (purepayCoreConnection($pdo,999) !== $connection) throw new RuntimeException('Connection changed during reads');
    foreach (purepayPreviewContinuityFixtures() as $kind=>$fixture) {
        if (integrationDeliveryGet($pdo,999,$fixture['delivery_id']) !== $before[$kind]) {
            throw new RuntimeException('Delivery changed during reads');
        }
    }
    $report += ['checked_at'=>gmdate('c'), 'tenant_id'=>999, 'local_writes'=>false,
        'saved_binding_unchanged'=>true, 'saved_deliveries_unchanged'=>true,
        'origin_migration_authorized'=>false];
    echo 'PUREPAY_PREVIEW_CONTINUITY='.json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit($report['continuity_verified'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "PurePay continuity inspection stopped: local staging identity or saved evidence changed. No binding was updated.\n");
    exit(2);
}
