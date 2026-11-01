<?php
/** Publish only the approved P-15 / PL-13 demo checklist through installed native services. */
declare(strict_types=1);

const PILOT_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PILOT_TENANT = 999;
const PILOT_INSTANCE = '9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const PILOT_WORKSPACE = '0598d4ef-3075-4289-9714-c93f904aa85d';
const PILOT_WORKER = 'cfw_2c5e38cf6059e5d00f16b534feb7e2006c697e0e82b5b009cabcfa86eef0892c';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--publish-demo-checklist'
    || getenv('COREFLUX_ENV') !== 'staging' || realpath(getcwd()) !== PILOT_ROOT) {
    fwrite(STDERR, "The named isolated pilot and explicit demo publication are required.\n");
    exit(2);
}
require_once PILOT_ROOT.'/core/db.php';
$pdo = getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx'
    || !defined('COREFLUX_STAGING') || COREFLUX_STAGING !== true
    || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999 AND is_active=1')->fetchColumn() !== 1) {
    fwrite(STDERR, "The isolated simulation database could not be verified.\n");
    exit(2);
}
require_once PILOT_ROOT.'/core/sub_tenants.php';
require_once PILOT_ROOT.'/core/quanta/onboarding_delivery.php';
require_once PILOT_ROOT.'/core/RBAC.php';
require_once PILOT_ROOT.'/core/rbac/permissions.php';
require_once PILOT_ROOT.'/core/rbac/legacy_map.php';

$report = ['checked_at'=>gmdate('c'), 'tenant_id'=>PILOT_TENANT,
    'fixture'=>'pilot:core-quanta-purepay:20261023', 'mode'=>'synthetic_onboarding_publication',
    'application_deployed'=>false, 'persistent_configuration_changed'=>false,
    'permissions_changed'=>false, 'login_or_invitation_created'=>false,
    'worker_submissions_created'=>false, 'evidence_approved'=>false,
    'time_approved'=>false, 'payroll_or_financial_writes'=>false];
$previousFlag = getenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY');
$phase = 'guard';
$exit = 0;
try {
    if (effectiveTenantIdForModule('people', PILOT_TENANT) !== PILOT_TENANT
        || effectiveTenantIdForModule('placements', PILOT_TENANT) !== PILOT_TENANT
        || integrationInstanceId() !== PILOT_INSTANCE
        || quantaApiBase() !== 'https://clockin-hub-16.preview.emergentagent.com/api/v1') {
        throw new RuntimeException('Reviewed directory, instance or destination changed');
    }
    if (quantaOnboardingDocumentsEnabled(PILOT_TENANT)) {
        throw new RuntimeException('Document intake must remain disabled for this publication-only check');
    }
    if (!$pdo->query("SELECT id FROM people WHERE tenant_id=999 AND id=15 AND status='active'
        AND email_primary='core-integration-20261023@example.com' AND deleted_at IS NULL")->fetchColumn()
        || !$pdo->query("SELECT id FROM placements WHERE tenant_id=999 AND id=13 AND person_id=15
        AND status='active' AND title='Synthetic Core Integration 20261023' AND deleted_at IS NULL")->fetchColumn()) {
        throw new RuntimeException('Accepted synthetic records changed');
    }

    // Both real permission models must grant the existing operator. No session, user or grant is created.
    $operator = $pdo->query("SELECT id,role FROM users WHERE id=4 AND email='stage-admin@coreflux.test'")->fetch(PDO::FETCH_ASSOC);
    $membership = RBACResolver::activeMembership(4, PILOT_TENANT);
    if (!$operator || ($membership['status'] ?? null) !== 'active') throw new RuntimeException('Existing operator unavailable');
    $operator['global_role'] = $operator['role'];
    $operator['role'] = RBACResolver::personaTypeOf(4, PILOT_TENANT);
    foreach (['people.onboarding.view', 'people.onboarding.manage', 'people.onboarding.review',
        'placements.view', 'people.docs.view', 'integrations.quanta.manage'] as $permission) {
        [$module, $action] = RbacLegacyMap::resolve($permission);
        if (!RBAC::hasPermission($operator, $permission)
            || !RBACResolver::can($operator, PILOT_TENANT, $module, $action)) {
            throw new RuntimeException('Existing operator lacks a required permission');
        }
    }
    $scope = ['tenant_id'=>PILOT_TENANT, 'people_tenant_id'=>PILOT_TENANT,
        'placements_tenant_id'=>PILOT_TENANT, 'actor_user_id'=>4,
        'permissions'=>['view'=>true, 'manage'=>true, 'review'=>true]];
    $storage = null;
    $head = static function(string $key) use (&$storage): ?array {
        $storage ??= new \Core\StorageService();
        return $storage->head($key);
    };

    $phase = 'provider_preflight';
    $access = quantaWorkspaceForDelivery(PILOT_TENANT);
    if ($access['context']['workspace']['id'] !== PILOT_WORKSPACE) throw new RuntimeException('Unexpected workspace');
    quantaOnboardingContext($access['api_key'], PILOT_WORKSPACE);
    $assignment = quantaContractRecord($access['api_key'], PILOT_WORKSPACE,
        integrationRecordReference(PILOT_INSTANCE, PILOT_TENANT, 'placement', 'PL-13'));
    if (!$assignment || ($assignment['worker_id'] ?? null) !== PILOT_WORKER
        || ($assignment['status'] ?? null) !== 'active') throw new RuntimeException('Verified assignment unavailable');
    if (defined('COREFLUX_QUANTA_ONBOARDING_DELIVERY') && COREFLUX_QUANTA_ONBOARDING_DELIVERY !== true) {
        throw new RuntimeException('Persistent configuration explicitly prohibits publication');
    }
    // Limit the feature switch to this exact guarded process; restore it on every exit.
    putenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY=1');
    if (!quantaOnboardingDeliveryEnabled()) throw new RuntimeException('Publication remains disabled');
    $access = null;

    $requestId = static function(string $label): string {
        $h = hash('sha256', 'core-pilot-onboarding-20261024:'.$label);
        return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-8'.substr($h,17,3).'-'.substr($h,20,12);
    };
    $commands = [];
    $command = static function(string $label, array $body) use ($pdo, $scope, $head, $requestId, &$commands): array {
        $body['request_id'] = $requestId($label);
        $receipt = onboardingCommand($pdo, $scope, $body, $head);
        $replay = onboardingCommand($pdo, $scope, $body, $head);
        if (!$replay['replayed'] || $receipt['case_id'] !== $replay['case_id']
            || $receipt['case_version'] !== $replay['case_version'] || $receipt['task_id'] !== $replay['task_id']) {
            throw new RuntimeException('Native command replay changed its receipt');
        }
        $commands[] = $receipt + ['replay_verified'=>true];
        return $receipt;
    };
    $phase = 'create_requirements';
    $created = $command('create', ['action'=>'create', 'person_id'=>15, 'placement_id'=>13]);
    $caseId = $created['case_id'];
    $report['case_id'] = $caseId;
    $tasks = [
        ['title'=>'TEST: Sample document', 'instructions'=>'Upload only the synthetic sample PDF. Do not upload personal documents.', 'assignee'=>'worker', 'evidence_kind'=>'document'],
        ['title'=>'TEST: Review assignment summary', 'instructions'=>'Confirm that you have reviewed this synthetic assignment summary.', 'assignee'=>'worker', 'evidence_kind'=>'attestation'],
        ['title'=>'TEST: Employer review', 'instructions'=>'Synthetic staff task. Employer evidence review stays in Core.', 'assignee'=>'staff', 'evidence_kind'=>'attestation'],
    ];
    foreach ($tasks as $i=>$task) {
        $command('task-'.$i, ['action'=>'add_task', 'case_id'=>$caseId, 'expected_version'=>$i+1, 'required'=>true] + $task);
    }
    $command('confirm-plan', ['action'=>'confirm_plan', 'case_id'=>$caseId, 'expected_version'=>4]);
    $report['commands'] = $commands;
    $case = onboardingView($pdo, $scope, $caseId, $head);
    if ($case['version'] !== 5 || $case['required_count'] !== 3 || $case['satisfied_count'] !== 0
        || $case['readiness'] !== 'incomplete' || $case['source_changed']) throw new RuntimeException('Unexpected checklist state');

    $phase = 'publish';
    $preview = quantaOnboardingPreview($scope, $caseId, 'published', $head);
    if ($preview['status'] !== 'unchanged') {
        $delivery = $preview['delivery'];
        if (in_array($delivery['state'], ['sending', 'uncertain'], true)) {
            $checked = quantaOnboardingCheckReceipt($scope, $caseId, (int)$delivery['id'], $delivery['review_hash'], $head);
            $delivery = $checked['delivery'];
        }
        if (!in_array($delivery['state'], ['preview', 'approved', 'delivered'], true)) {
            throw new RuntimeException('Prior publication requires reconciliation');
        }
        $sent = quantaOnboardingSend($scope, $caseId, (int)$delivery['id'], $delivery['review_hash'], $head);
        $replay = quantaOnboardingSend($scope, $caseId, (int)$delivery['id'], $delivery['review_hash'], $head);
        if ($sent['state'] !== 'delivered' || $sent !== $replay) throw new RuntimeException('Delivery replay changed its receipt');
        $report['delivery'] = array_intersect_key($sent, array_flip(['id','state','remote_id','remote_receipt_id','attempt_count']));
        $report['delivery_replay_verified'] = true;
    } else {
        $report['publication_already_unchanged'] = true;
    }
    $phase = 'verify';
    $comparison = quantaOnboardingPreview($scope, $caseId, 'published', $head);
    if ($comparison['status'] !== 'unchanged') throw new RuntimeException('Publication did not reconcile');
    $snapshot = quantaOnboardingSnapshot($scope, $caseId, $head);
    $remote = $snapshot['remote'];
    if (count($remote['submissions']) !== 0) throw new RuntimeException('Unexpected worker submissions require review');
    $report['remote'] = array_intersect_key($remote, array_flip(['id','version','worker_id','assignment_id','projection_hash']));
    $report['required_count'] = 3;
    $report['satisfied_count'] = 0;
    $report['readiness'] = 'incomplete';
    $report['fresh_comparison'] = $comparison['status'];
    $report['passed'] = true;
} catch (Throwable $e) {
    // Logs contain only bounded diagnostics; never credentials, access arrays or provider bodies.
    $report['passed'] = false;
    $report['failed_phase'] = $phase;
    $report['failure_class'] = get_class($e);
    if ($e instanceof QuantaApiException) $report['provider_http_status'] = $e->httpStatus;
    $exit = 1;
} finally {
    $access = null;
    putenv($previousFlag === false ? 'COREFLUX_QUANTA_ONBOARDING_DELIVERY' : 'COREFLUX_QUANTA_ONBOARDING_DELIVERY='.$previousFlag);
    $report['publication_flag_restored'] = getenv('COREFLUX_QUANTA_ONBOARDING_DELIVERY') === $previousFlag;
    $report['document_intake_enabled_after'] = quantaOnboardingDocumentsEnabled(PILOT_TENANT);
}
echo 'CORE_PILOT_ONBOARDING='.json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
exit($exit);
