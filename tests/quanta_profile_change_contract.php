<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../core/quanta/profile_change_contract.php';
$passed = 0;
$check = static function (bool $ok, string $message) use (&$passed): void {
    if (!$ok) throw new RuntimeException($message);
    $passed++;
};
$rejects = static function (callable $fn): bool {
    try { $fn(); } catch (InvalidArgumentException $e) { return true; }
    return false;
};
$fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/quanta-profile-change.json'), true, 32, JSON_THROW_ON_ERROR);
$record = $fixture['proposal']; $person = $record['person'];
$validate = static fn(array $row) => quantaProfileProposalValidate($row, 'tenant-a', $person, 'worker-a', $record['id']);
$check($validate($record) === $record, 'Native Python proposal validates without rewriting fields');
$check(quantaMetadataRequestHash($fixture['request']) === $record['request_hash'], 'Request hash matches Python with Unicode and null values');
$check(quantaMetadataRequestHash($record['baseline']) === $fixture['request']['expected_baseline_hash'], 'Baseline hash matches Python');
$base = $record['baseline']['profile'];
$result = quantaProfileChangeCompare($base, $record['proposed'], $base);
$check($result['state'] === 'change' && count($result['changes']) === 3, 'Reviewed name, contact email and phone clear are represented');
$current = array_replace($base, ['last_name' => 'Independent Core edit']);
$check(!array_key_exists('last_name', quantaProfileChangeCompare($base, $record['proposed'], $current)['changes']), 'Unrelated Core edit is preserved');
$conflict = quantaProfileChangeCompare($base, $record['proposed'], array_replace($base, ['phone_primary' => '202-555-0102']));
$check($conflict['state'] === 'conflict' && $conflict['changes'] === [] && isset($conflict['conflicts']['phone_primary']), 'One conflicting field blocks the complete unreviewed update');
$same = quantaProfileChangeCompare($base, $record['proposed'], $record['proposed']);
$check($same['state'] === 'already_current' && $same['changes'] === [], 'Already applied fields are not a second update');
foreach (['tenant_id' => 'tenant-b', 'worker_id' => 'worker-b', 'id' => 'cpc_' . str_repeat('1', 64),
    'person_link_id' => 'cfl_' . str_repeat('2', 64), 'revision' => true, 'origin' => 'coreflux',
    'request_hash' => 'invalid', 'changed_fields' => ['phone_primary'],
    'created_at' => '2026-02-31T00:00:00Z'] as $field => $value) {
    $check($rejects(fn() => $validate(array_replace($record, [$field => $value]))), 'Reject substituted ' . $field);
}
foreach (['kind' => 'admin', 'user_id' => 'worker-b', 'identity_id' => 'invalid identity'] as $field => $value) {
    $changed = $record; $changed['actor'][$field] = $value;
    $check($rejects(fn() => $validate($changed)), 'Reject forged actor ' . $field);
}
$changed = $record; $changed['actor']['roles'] = ['admin'];
$check($rejects(fn() => $validate($changed)), 'Actor cannot smuggle access grants');
foreach (['version' => 0, 'tenant_id' => 'tenant-b', 'worker_id' => 'worker-b', 'profile' => []] as $field => $value) {
    $changed = $record; $changed['baseline'][$field] = $value;
    $check($rejects(fn() => $validate($changed)), 'Reject substituted baseline ' . $field);
}
foreach ([['phone_primary' => ''], ['phone_primary' => 123], ['first_name' => "\u{00a0}name"],
    ['first_name' => str_repeat('é', 51)], ['email_primary' => 'Mixed@example.com'],
    ['first_name' => '', 'last_name' => '']] as $changes) {
    $check($rejects(fn() => quantaProfileValuesValidate(array_replace($base, $changes))), 'Reject incompatible contact value');
}
$single = array_replace($base, ['last_name' => '', 'email_primary' => null, 'phone_primary' => null]);
$check(quantaProfileValuesValidate($single) === $single, 'Single name and explicit contact clears remain supported');
echo json_encode(['passed' => $passed, 'scope' => 'Profile proposal contract and three-way conflict boundary',
    'database_or_provider_writes' => false]), "\n";
