<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../core/quanta/profile_baseline_contract.php';
$n = 0;
$check = static function (bool $ok, string $label) use (&$n): void { if (!$ok) throw new RuntimeException($label); $n++; };
$fails = static function (callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } };
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/quanta-profile-change.json'), true, 32, JSON_THROW_ON_ERROR);
$baseline = $fixture['proposal']['baseline'];
$body = ['person' => $baseline['person'], 'worker_id' => $baseline['worker_id'], 'expected_version' => $baseline['version'] - 1,
    'core_snapshot_hash' => $baseline['core_snapshot_hash'], 'profile' => $baseline['profile']];
$record = ['baseline' => $baseline, 'delivery_id' => 'cfd_' . str_repeat('a', 64)];
$check(quantaProfileDigest($baseline) === $fixture['request']['expected_baseline_hash'], 'Canonical Unicode baseline hash matches native Python');
$check(quantaProfileBaselineConfirm($record, 'tenant-a', $body, 200) === $record, 'Native baseline confirms without rewriting fields');
foreach (['worker_id' => 'worker-b', 'core_snapshot_hash' => str_repeat('b', 64), 'version' => 9, 'tenant_id' => 'tenant-b'] as $field => $value) {
    $bad = $record; $bad['baseline'][$field] = $value;
    $check($fails(fn() => quantaProfileBaselineConfirm($bad, 'tenant-a', $body, 200)), 'Reject wrong baseline ' . $field);
}
$bad = $record; $bad['baseline']['profile']['phone_primary'] = 'Unreviewed';
$check($fails(fn() => quantaProfileBaselineConfirm($bad, 'tenant-a', $body, 200)), 'Reject unreviewed contact value');
$check($fails(fn() => quantaProfileBaselineBodyValidate($body + ['activate' => true])), 'No activation smuggled into baseline');
$check($fails(fn() => quantaProfileBaselineBodyValidate(array_replace($body, ['expected_version' => true]))), 'Boolean is not a revision');
$check($fails(fn() => quantaProfileBaselineConfirm($record, 'tenant-a', $body, 201)), 'Unexpected creation cannot confirm update');
$calls = 0; $mode = 'ok';
$GLOBALS['__quanta_core_write_transport'] = static function ($method, $url, $headers, $json) use (&$calls, &$mode, $body, $record, $check) {
    $calls++;
    $check($method === 'POST' && str_ends_with($url, '/api/v1/integrations/coreflux/profile-baselines'), 'Only reviewed baseline route is written');
    $check(quantaProfileDigest(json_decode($json, true)) === quantaProfileDigest($body)
        && in_array('Idempotency-Key: ' . str_repeat('d', 64), $headers, true), 'Exact body and stable delivery key sent');
    if ($mode === 'timeout') throw new RuntimeException('Synthetic timeout');
    if ($mode === 'reject') return ['status' => 403, 'body' => '{}'];
    if ($mode === 'conflict') return ['status' => 409, 'body' => '{}'];
    return ['status' => 200, 'body' => $mode === 'invalid' ? '{' : json_encode($record)];
};
$check(quantaWriteProfileBaseline('fixture-key', 'tenant-a', $body, str_repeat('d', 64)) === $record, 'Successful response validated');
foreach (['timeout' => true, 'reject' => false, 'conflict' => true, 'invalid' => true] as $case => $uncertain) {
    $mode = $case;
    try { quantaWriteProfileBaseline('fixture-key', 'tenant-a', $body, str_repeat('d', 64)); throw new LogicException('Expected failure'); }
    catch (QuantaContractWriteException $e) { $check($e->uncertain === $uncertain, 'Outcome classified: ' . $case); }
}
$GLOBALS['__quanta_transport'] = static fn() => ['status' => 200, 'body' => json_encode([
    'id' => $record['delivery_id'], 'state' => 'complete', 'request_hash' => quantaProfileDigest($body), 'status_code' => 200, 'response' => $record])];
$check(quantaProfileBaselineReceipt('fixture-key', 'tenant-a', $body, str_repeat('d', 64))['response'] === $record, 'Receipt lookup confirms exact original request');
$badBody = $body; $badBody['profile']['first_name'] = 'Changed';
$check($fails(fn() => quantaProfileBaselineReceipt('fixture-key', 'tenant-a', $badBody, str_repeat('d', 64))), 'Different request cannot consume receipt');
$check($calls === 5, 'Receipt lookup never resends a baseline');
echo json_encode(['passed' => $n, 'scope' => 'Contact baseline transport and receipt boundaries', 'live_calls' => false]), "\n";
