<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../core/quanta/profile_decision_contract.php';
require_once __DIR__ . '/fixtures/staffing_dictionary_checks.php';
$f = json_decode(file_get_contents(__DIR__ . '/fixtures/quanta-profile-decision.json'), true, 64, JSON_THROW_ON_ERROR);
$body = $f['body']; $proposal = $f['proposal']; $record = $f['receipt']; $workspace = $record['tenant_id'];
$n = 0; $calls = 0; $mode = 'ok';
$check = static function (bool $ok, string $label) use (&$n): void { if (!$ok) throw new RuntimeException($label); $n++; };
$fails = static function (callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } };
$check(quantaProfileDigest($body) === $f['body_hash'], 'Unicode Core decision matches native Python hash');
$check(quantaProfileDecisionValidate($body, $proposal) === $body, 'Exact original Core decision validates');
$check(quantaProfileDecisionConfirm($record, $workspace, $body, $proposal) === $record, 'Native completion receipt matches the reviewed values');
staffingDictionaryAssertPayload('quanta_contact_baseline', 'quanta', $proposal['baseline']);
staffingDictionaryAssertPayload('quanta_contact_request', 'quanta', $proposal);
staffingDictionaryAssertPayload('quanta_contact_decision', 'quanta', $body);
staffingDictionaryAssertPayload('quanta_contact_return_receipt', 'quanta', $record);
$check(true, 'Field dictionary describes the actual validated round-trip payloads without granting sync rights');
$check($fails(fn() => quantaProfileDecisionConfirm($record, 'other-workspace', $body, $proposal)), 'Foreign workspace cannot confirm return');
$check($fails(fn() => quantaProfileDecisionValidate($body + ['email' => 'login@example.com'], $proposal)), 'No login field may enter decision');
foreach (['actor_id' => true, 'core_tenant_id' => 0, 'change_id' => 'cpc_' . str_repeat('f', 64),
    'proposal_hash' => str_repeat('f', 64), 'worker_id' => 'worker-b', 'changed_fields' => [], 'decided_at' => '2026-02-30T00:00:00Z'] as $field => $value) {
    $bad = array_replace($body, [$field => $value]);
    $check($fails(fn() => quantaProfileDecisionValidate($bad, $proposal)), 'Reject changed Core decision ' . $field);
}
$bad = $body; $bad['after']['last_name'] = 'Unrequested';
$check($fails(fn() => quantaProfileDecisionValidate($bad, $proposal)), 'Cannot insert unrequested contact change');
$bad = $body; $bad['before']['first_name'] = 'Concurrent Core value';
$check($fails(fn() => quantaProfileDecisionValidate($bad, $proposal)), 'Cannot accept same-field Core conflict');
$held = $record; $held['state'] = 'received'; $held['outcome'] = null;
$check(quantaProfileDecisionConfirm($held, $workspace, $body, $proposal)['state'] === 'received', 'Receipt can prove receipt without claiming contact application');
$conflict = $record; $conflict['outcome']['contact_state'] = 'conflict'; $conflict['outcome']['native_changed_fields'] = []; $conflict['outcome']['conflict_fields'] = ['phone'];
$check(quantaProfileDecisionConfirm($conflict, $workspace, $body, $proposal)['outcome']['contact_state'] === 'conflict', 'Core acceptance with native conflict remains explicit');
foreach (['decision_hash' => str_repeat('f', 64), 'reason' => 'Changed note', 'contact_state' => 'rejected', 'native_changed_fields' => ['email'],
    'conflict_fields' => ['phone'], 'completed_at' => 'yesterday'] as $field => $value) {
    $bad = $record; $bad['outcome'][$field] = $value;
    $check($fails(fn() => quantaProfileDecisionConfirm($bad, $workspace, $body, $proposal)), 'Reject wrong provider outcome ' . $field);
}
$GLOBALS['__quanta_core_write_transport'] = static function ($method, $url, $headers, $json) use (&$calls, &$mode, $record, $body, $held, $check) {
    $calls++;
    $check($method === 'PUT' && str_ends_with($url, '/profile-changes/' . $body['change_id'] . '/decision'), 'Write only the immutable original request resource');
    $check(quantaProfileDigest(json_decode($json, true)) === quantaProfileDigest($body), 'Return only the originally reviewed body');
    if ($mode === 'lost') throw new RuntimeException('Synthetic lost response');
    if ($mode === 'invalid') return ['status' => 200, 'body' => '{}'];
    if (in_array($mode, ['403', '409', '503'], true)) return ['status' => (int)$mode, 'body' => '{}'];
    return ['status' => 200, 'body' => json_encode($mode === 'received' ? $held : $record)];
};
$check(quantaWriteProfileDecision('synthetic-key', $workspace, $body, $proposal) === $record, 'Successful return verifies native receipt');
$mode = 'received'; $check(quantaWriteProfileDecision('synthetic-key', $workspace, $body, $proposal)['state'] === 'received', 'Incomplete return is not labeled complete');
foreach (['lost' => true, 'invalid' => true, '403' => false, '409' => true, '503' => true] as $mode => $uncertain) {
    // Numeric-looking PHP array keys need their string form for this fixture.
    $mode = (string)$mode;
    try { quantaWriteProfileDecision('synthetic-key', $workspace, $body, $proposal); throw new LogicException('Expected held return'); }
    catch (QuantaContractWriteException $e) { $check($e->uncertain === $uncertain, 'Classify original return outcome ' . $mode); }
}
$GLOBALS['__quanta_transport'] = static fn() => ['status' => 200, 'body' => json_encode($record)];
$before = $calls;
$check(quantaProfileDecisionReceipt('synthetic-key', $workspace, $body, $proposal) === $record && $calls === $before, 'Receipt inspection never retries a native write');
echo json_encode(['passed' => $n, 'scope' => 'Core decision and native Quanta receipt contract', 'live_calls' => false]), "\n";
