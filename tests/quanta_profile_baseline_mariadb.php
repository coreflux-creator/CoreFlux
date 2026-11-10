<?php
/** Real disposable MariaDB, synthetic provider responses, no application credentials. */
declare(strict_types=1);
$inCi = getenv('GITHUB_ACTIONS') === 'true' && getenv('CI') === 'true';
$local = getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE') === '1' && in_array('--confirm-disposable-fixture', $argv, true)
    && (string)getenv('COREFLUX_DELIVERY_TEST_PASSWORD') !== '';
if (!$inCi && !$local) throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1'); putenv('COREFLUX_DATA_KEY=' . base64_encode(random_bytes(32)));
require_once __DIR__ . '/../core/quanta/profile_baseline_delivery.php';
require_once __DIR__ . '/../core/migrate.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $inCi ? 'root' : getenv('COREFLUX_DELIVERY_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$database = (defined('QUANTA_PROFILE_REVIEW_FIXTURE') ? 'coreflux_profile_reviews_' : 'coreflux_profile_baselines_')
    . ($inCi ? 'ci' : gmdate('YmdHis') . '_' . getmypid());
$pdo->exec('CREATE DATABASE ' . $database); $pdo->exec('USE ' . $database);
$pdo->exec('CREATE TABLE quanta_connections(id BIGINT PRIMARY KEY,tenant_id BIGINT NOT NULL UNIQUE,status VARCHAR(20),api_key_ct VARBINARY(2048)) ENGINE=InnoDB');
foreach (['158_integration_deliveries.sql', '162_integration_delivery_receipt.sql', '159_quanta_workspace_binding.sql', '148_quanta_worker_identity.sql', '185_quanta_profile_baselines.sql'] as $file)
    foreach (coreflux_split_sql_statements(file_get_contents(__DIR__ . '/../core/migrations/' . $file)) as $sql) $pdo->exec($sql);
$pdo->exec('CREATE TABLE tenants(id BIGINT PRIMARY KEY,parent_id BIGINT NULL,tenant_type VARCHAR(20),name VARCHAR(80),is_active INT) ENGINE=InnoDB');
$pdo->exec("INSERT INTO tenants VALUES(10,NULL,'master','Synthetic directory',1),(11,10,'sub','Synthetic pilot',1),(12,10,'sub','Other pilot',1)");
$pdo->exec('CREATE TABLE tenant_module_scope(tenant_id BIGINT,module_key VARCHAR(40),scope_mode VARCHAR(20)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE people(id BIGINT PRIMARY KEY,tenant_id BIGINT,first_name VARCHAR(100),last_name VARCHAR(100),email_primary VARCHAR(255),phone_primary VARCHAR(40),status VARCHAR(20),deleted_at DATETIME NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE core_record_numbers(record_id BIGINT,tenant_id BIGINT,entity_type VARCHAR(20),record_number VARCHAR(64)) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE audit_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT,actor_user_id BIGINT,event VARCHAR(100),meta_json TEXT) ENGINE=InnoDB');
$instance = '381e452a-5d11-4e2e-9daa-e9427501898a'; putenv('COREFLUX_INTEGRATION_INSTANCE_ID=' . $instance);
$key = 'synthetic-baseline-key'; $workspace = 'workspace-a'; $links = [];
foreach (range(101, 108) as $id) {
    $pdo->prepare('INSERT INTO people VALUES(?,10,"Synthetic","Worker",?,NULL,"active",NULL)')->execute([$id, 'worker' . $id . '@example.com']);
    $pdo->prepare('INSERT INTO core_record_numbers VALUES(?,10,"person",?)')->execute([$id, 'P-' . ($id + 9000)]);
    $pdo->prepare('INSERT INTO quanta_worker_links(tenant_id,worker_id,person_id) VALUES(11,?,?)')->execute(['worker-' . $id, $id]);
    $ref = integrationRecordReference($instance, 10, 'person', 'P-' . ($id + 9000)); $rid = quantaCoreRemoteId($workspace, $ref);
    $links[$rid] = ['id' => $rid, 'tenant_id' => $workspace, 'person' => $ref, 'worker_id' => 'worker-' . $id];
}
$context = ['workspace' => ['id' => $workspace, 'name' => 'Synthetic Quanta'], 'contract_version' => QUANTA_CORE_CONTRACT_VERSION,
    'execution_side_effects' => false, 'capabilities' => ['person_links', 'assignment_metadata', 'conditional_assignment_updates', 'durable_delivery_receipts']];
$pdo->prepare('INSERT INTO quanta_connections(id,tenant_id,status,api_key_ct) VALUES(11,11,"active",?)')->execute([encryptField($key)]);
quantaWorkspaceBind($pdo, 11, 7, ['id' => 11, 'binding_revision' => 1], $key, $instance, $context, quantaWorkspaceBindingHash(11, 11, $key, $instance, $context));
$n = 0; $writes = 0; $mode = 'ok'; $baselines = []; $receipts = [];
$check = static function (bool $ok, string $label) use (&$n): void { if (!$ok) throw new RuntimeException($label); $n++; echo "PASS $label\n"; };
$fails = static function (callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } };
$GLOBALS['__quanta_transport'] = static function ($method, $url) use (&$context, &$links, &$baselines, &$receipts, $workspace) {
    $path = parse_url($url, PHP_URL_PATH); $id = basename($path);
    if (str_ends_with($path, '/profile-change-context')) $out = ['workspace_id' => $workspace, 'contract_version' => QUANTA_PROFILE_CHANGE_VERSION,
        'baseline_publication' => true, 'worker_proposals' => true, 'decision_delivery' => false, 'login_identity_changes' => false];
    elseif (str_ends_with($path, '/context')) $out = $context;
    elseif (str_contains($path, '/person-links/')) $out = $links[$id] ?? null;
    elseif (str_contains($path, '/profile-baseline-deliveries/')) $out = $receipts[$id] ?? null;
    else $out = $baselines[$id] ?? null;
    return ['status' => $out === null ? 404 : 200, 'body' => json_encode($out ?? [])];
};
$GLOBALS['__quanta_core_write_transport'] = static function ($method, $url, $headers, $json) use (&$writes, &$mode, &$baselines, &$receipts, $pdo, $workspace, $check) {
    $writes++; $check(!$pdo->inTransaction(), 'Provider IO follows committed claim');
    $idem = substr(array_values(array_filter($headers, static fn($h) => str_starts_with($h, 'Idempotency-Key: ')))[0], 17);
    $s = $pdo->prepare('SELECT state FROM integration_deliveries WHERE idempotency_key=?'); $s->execute([$idem]);
    $check($s->fetchColumn() === 'sending', 'Provider sees exactly one claimed attempt');
    $body = json_decode($json, true); $id = 'cfp_' . substr(quantaCoreRemoteId($workspace, $body['person']), 4);
    $receiptId = 'cfd_' . hash('sha256', $idem);
    if ($mode === 'no_receipt') throw new RuntimeException('Synthetic lost result');
    if ($mode === 'reject') {
        $receipts[$idem] = ['id' => $receiptId, 'request_hash' => quantaProfileDigest($body), 'state' => 'complete', 'status_code' => 409,
            'response' => ['detail' => ['error' => 'profile_baseline_changed'], 'delivery_id' => $receiptId]];
        return ['status' => 409, 'body' => json_encode($receipts[$idem]['response'])];
    }
    $prior = $baselines[$id] ?? null;
    $check(($prior['version'] ?? 0) === $body['expected_version'], 'Provider conditions publication on original version');
    $same = $prior && $prior['core_snapshot_hash'] === $body['core_snapshot_hash'] && $prior['profile'] === $body['profile'];
    $baseline = ['contract_version' => QUANTA_PROFILE_CHANGE_VERSION, 'id' => $id, 'tenant_id' => $workspace, 'person' => $body['person'],
        'worker_id' => $body['worker_id'], 'version' => $same ? $prior['version'] : $body['expected_version'] + 1,
        'core_snapshot_hash' => $body['core_snapshot_hash'], 'profile' => $body['profile']];
    $baselines[$id] = $baseline; $response = ['baseline' => $baseline, 'delivery_id' => $receiptId];
    $status = $body['expected_version'] === 0 ? 201 : 200;
    $receipts[$idem] = ['id' => $receiptId, 'request_hash' => quantaProfileDigest($body), 'state' => 'complete', 'status_code' => $status, 'response' => $response];
    if ($mode === 'link_drift') $pdo->prepare('UPDATE quanta_worker_links SET worker_id="changed-worker" WHERE tenant_id=11 AND worker_id=?')->execute([$body['worker_id']]);
    if ($mode === 'scope_drift') $pdo->exec('INSERT INTO tenant_module_scope VALUES(11,"people","isolated")');
    if ($mode === 'lost_ack') throw new RuntimeException('Synthetic lost acknowledgement after publication');
    return ['status' => $status, 'body' => json_encode($response)];
};
$preview = static fn($id) => quantaProfileBaselinePreview(11, 7, $id);
$send = static fn($p) => quantaProfileBaselineSend(11, 7, (int)$p['delivery']['id'], $p['delivery']['review_hash']);
$status = static fn($p) => quantaProfileBaselineStatus(11, 7, (int)$p['delivery']['id'], $p['delivery']['review_hash']);
putenv('COREFLUX_QUANTA_PROFILE_RETURN'); $p = $preview(101);
$check($fails(fn() => $send($p)) && $writes === 0, 'Profile return defaults off');
putenv('COREFLUX_QUANTA_PROFILE_RETURN=1');
$pdo->exec('UPDATE people SET first_name="Edited before sending" WHERE id=101');
$check($fails(fn() => $send($p)) && $writes === 0, 'Core change after review prevents IO');
$p = $preview(101); $before = $pdo->query('SELECT * FROM people')->fetchAll(PDO::FETCH_ASSOC); $sent = $send($p);
$check($sent['state'] === 'delivered' && $writes === 1, 'Baseline and delivery commit successfully');
$check($pdo->query('SELECT * FROM people')->fetchAll(PDO::FETCH_ASSOC) === $before, 'Publication changes no Core person');
$saved = $pdo->query('SELECT * FROM quanta_profile_baselines WHERE person_id=101')->fetch(PDO::FETCH_ASSOC);
$check(!str_contains($saved['baseline_ct'], 'example.com') && quantaProfileBaselineSaved($pdo, 11,
    quantaWorkspaceBindingGet($pdo, 11)['binding_hash'], 10, 101, $baselines[$saved['baseline_id']]), 'Encrypted baseline has independently checked Core receipt');
$check($send($p)['state'] === 'delivered' && $writes === 1, 'Repeated delivery never calls provider');
$check($preview(101)['status'] === 'unchanged', 'Matching saved baseline avoids another publication');
$bad = $baselines[$saved['baseline_id']]; $bad['profile']['first_name'] = 'Unreviewed source';
$check($fails(fn() => quantaProfileBaselineSaved($pdo, 11, $saved['binding_hash'], 10, 101, $bad)), 'Changed provider baseline cannot impersonate Core evidence');
$old = $baselines[$saved['baseline_id']]; $pdo->exec('UPDATE people SET last_name="Updated" WHERE id=101');
$p2 = $preview(101); $send($p2);
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_profile_baselines WHERE person_id=101')->fetchColumn() === 2,
    'New publication preserves historical baseline for outstanding requests');
$check(quantaProfileBaselineSaved($pdo, 11, $saved['binding_hash'], 10, 101, $old), 'Original baseline remains provable after later Core edit');

$mode = 'lost_ack'; $p = $preview(102); $before = $writes;
$check($fails(fn() => $send($p)), 'Lost acknowledgement remains uncertain');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_profile_baselines WHERE person_id=102')->fetchColumn() === 0, 'No local baseline until receipt is confirmed');
$check($status($p)['delivery']['state'] === 'delivered' && $writes === $before + 1, 'Receipt-only recovery saves baseline without resending');
$mode = 'no_receipt'; $p = $preview(103); $check($fails(fn() => $send($p)), 'Unconfirmed publication held'); $before = $writes;
$check($status($p)['receipt_state'] === 'not_found' && $writes === $before, 'Missing receipt never authorizes retry');
$check($fails(fn() => $send($p)) && $writes === $before, 'Unknown attempt cannot be resent');
$mode = 'link_drift'; $p = $preview(104); $before = $writes;
$check($fails(fn() => $send($p)) && $writes === $before + 1, 'Identity change during IO prevents local confirmation');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_profile_baselines WHERE person_id=104')->fetchColumn() === 0, 'Failed completion leaves no partial baseline');
$pdo->exec('UPDATE quanta_worker_links SET worker_id="worker-104" WHERE tenant_id=11 AND person_id=104');
$check($status($p)['delivery']['state'] === 'delivered' && $writes === $before + 1, 'Restored original identity permits receipt-only recovery');
$mode = 'reject'; $p = $preview(105); $check($fails(fn() => $send($p)), 'HTTP conflict waits for durable rejection'); $before = $writes;
$check($status($p)['delivery']['state'] === 'failed' && $writes === $before, 'Durable rejection releases held attempt without retry');
$mode = 'scope_drift'; $p = $preview(106); $check($fails(fn() => $send($p)), 'Directory changes during IO block completion'); $before = $writes;
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_profile_baselines WHERE person_id=106')->fetchColumn() === 0, 'Scope drift leaves no partial baseline');
$pdo->exec('DELETE FROM tenant_module_scope WHERE tenant_id=11');
$check($status($p)['delivery']['state'] === 'delivered' && $writes === $before, 'Restored directory recovers original baseline receipt');
$mode = 'ok'; $p = $preview(107); $before = $writes;
$pdo->exec('UPDATE quanta_connections SET binding_revision=binding_revision+1 WHERE tenant_id=11');
$check($fails(fn() => $send($p)) && $writes === $before, 'Connection rotation invalidates old review');
$pdo->exec('UPDATE quanta_connections SET binding_revision=binding_revision-1 WHERE tenant_id=11');
$check($fails(fn() => quantaProfileBaselineStatus(12, 7, (int)$p['delivery']['id'], $p['delivery']['review_hash'])), 'Other company cannot inspect this baseline delivery');
$check((int)$pdo->query('SELECT COUNT(*) FROM quanta_worker_links')->fetchColumn() === 8, 'Existing worker identities are preserved');
echo "Quanta profile baseline MariaDB: $n passed\n";
