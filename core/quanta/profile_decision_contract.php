<?php
/** Immutable Core contact decisions and verifiable Quanta application receipts. */
declare(strict_types=1);
require_once __DIR__ . '/profile_baseline_contract.php';

function quantaProfileDecisionTimestamp(mixed $value): void
{
    if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|\+00:00)$/D', $value)) {
        throw new InvalidArgumentException('A UTC contact decision timestamp is required.');
    }
    try { new DateTimeImmutable($value); $errors = DateTimeImmutable::getLastErrors(); }
    catch (Throwable $e) { throw new InvalidArgumentException('Invalid contact decision timestamp.'); }
    if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException('Invalid contact decision timestamp.');
}

function quantaProfileDecisionValidate(array $body, array $proposal): array
{
    quantaProfileExactFields($body, ['contract_version', 'change_id', 'proposal_hash', 'person', 'worker_id', 'core_tenant_id',
        'actor_id', 'review_id', 'decision', 'reason', 'before', 'after', 'changed_fields', 'decided_at']);
    quantaProfileProposalValidate($proposal, $proposal['tenant_id'], $proposal['person'], $proposal['worker_id'], $proposal['id']);
    if ($body['contract_version'] !== QUANTA_PROFILE_CHANGE_VERSION || $body['change_id'] !== $proposal['id']
        || $body['worker_id'] !== $proposal['worker_id'] || $body['proposal_hash'] !== quantaProfileDigest($proposal)
        || !is_array($body['person']) || quantaCoreReferenceValidate($body['person'], 'person') !== quantaCoreReferenceValidate($proposal['person'], 'person')
        || !is_int($body['core_tenant_id']) || $body['core_tenant_id'] <= 0 || !is_int($body['actor_id']) || $body['actor_id'] <= 0
        || !is_string($body['review_id']) || !preg_match('/^[a-f0-9]{64}$/D', $body['review_id'])
        || !in_array($body['decision'], ['accepted', 'rejected'], true) || !is_string($body['reason'])
        || $body['reason'] !== trim($body['reason']) || strlen($body['reason']) > 1000
        || preg_match('/[\x00-\x1f\x7f]/', $body['reason']) || preg_match_all('/./us', $body['reason']) === false
        || ($body['decision'] === 'rejected' && $body['reason'] === '')
        || !is_array($body['before']) || !is_array($body['after']) || !is_array($body['changed_fields']) || !array_is_list($body['changed_fields'])) {
        throw new InvalidArgumentException('The Core decision does not match its original contact request.');
    }
    quantaProfileDecisionTimestamp($body['decided_at']);
    quantaProfileValuesValidate($body['before']); quantaProfileValuesValidate($body['after']);
    $comparison = quantaProfileChangeCompare($proposal['baseline']['profile'], $proposal['proposed'], $body['before']);
    if ($body['decision'] === 'accepted' && $comparison['state'] === 'conflict') throw new InvalidArgumentException('A conflicting Core decision cannot be returned as accepted.');
    $changes = $body['decision'] === 'accepted' ? $comparison['changes'] : [];
    if ($body['changed_fields'] !== array_keys($changes)
        || quantaProfileDigest($body['after']) !== quantaProfileDigest(array_replace($body['before'], $changes))) {
        throw new InvalidArgumentException('The accepted values differ from Core’s reviewed change.');
    }
    return $body;
}

function quantaProfileDecisionBody(int $tenant, array $decision, array $proposal): array
{
    $body = array_intersect_key($decision, array_flip(['contract_version', 'change_id', 'proposal_hash', 'person', 'worker_id',
        'actor_id', 'review_id', 'decision', 'reason', 'before', 'after', 'changed_fields', 'decided_at']));
    $body['core_tenant_id'] = $tenant;
    return quantaProfileDecisionValidate($body, $proposal);
}

function quantaProfileDecisionConfirm(array $record, string $workspace, array $body, array $proposal): array
{
    quantaProfileDecisionValidate($body, $proposal);
    quantaProfileExactFields($record, ['id', 'tenant_id', 'change_id', 'request_hash', 'decision', 'state', 'outcome']);
    $id = 'cpd_' . quantaProfileDigest([$workspace, $body['change_id']]);
    if ($workspace !== $proposal['tenant_id'] || $record['tenant_id'] !== $workspace || $record['id'] !== $id
        || $record['change_id'] !== $body['change_id'] || $record['request_hash'] !== quantaProfileDigest($body)
        || !is_array($record['decision']) || quantaProfileDigest($record['decision']) !== quantaProfileDigest($body)
        || !in_array($record['state'], ['received', 'complete'], true)) throw new RuntimeException('The Quanta receipt does not match this Core decision.');
    if ($record['state'] === 'received') {
        if ($record['outcome'] !== null) throw new RuntimeException('An incomplete decision has an unexpected outcome.');
        return $record;
    }
    if (!is_array($record['outcome'])) throw new RuntimeException('Quanta has not confirmed the contact outcome.');
    $outcome = $record['outcome'];
    quantaProfileExactFields($outcome, ['id', 'decision_hash', 'decision', 'reason', 'decided_at', 'received_at',
        'completed_at', 'contact_state', 'native_changed_fields', 'conflict_fields']);
    $allowed = [];
    if (array_intersect(['first_name', 'last_name'], $proposal['changed_fields'])) $allowed[] = 'full_name';
    if (in_array('phone_primary', $proposal['changed_fields'], true)) $allowed[] = 'phone';
    if (in_array('email_primary', $proposal['changed_fields'], true)) $allowed[] = 'contact_email';
    if ($outcome['id'] !== $id || $outcome['decision_hash'] !== $record['request_hash']
        || $outcome['decision'] !== $body['decision'] || $outcome['reason'] !== $body['reason'] || $outcome['decided_at'] !== $body['decided_at']
        || !in_array($outcome['contact_state'], ['applied', 'conflict', 'rejected'], true)
        || !is_array($outcome['native_changed_fields']) || !array_is_list($outcome['native_changed_fields'])
        || !is_array($outcome['conflict_fields']) || !array_is_list($outcome['conflict_fields'])
        || count(array_filter(array_merge($outcome['native_changed_fields'], $outcome['conflict_fields']), 'is_string'))
            !== count($outcome['native_changed_fields']) + count($outcome['conflict_fields'])
        || array_diff(array_merge($outcome['native_changed_fields'], $outcome['conflict_fields']), $allowed)
        || count(array_unique($outcome['native_changed_fields'])) !== count($outcome['native_changed_fields'])
        || count(array_unique($outcome['conflict_fields'])) !== count($outcome['conflict_fields'])
        || (($body['decision'] === 'rejected') !== ($outcome['contact_state'] === 'rejected'))
        || ($outcome['contact_state'] !== 'applied' && $outcome['native_changed_fields'])
        || (($outcome['contact_state'] === 'conflict') !== (bool)$outcome['conflict_fields'])) {
        throw new RuntimeException('The native contact result is inconsistent with the reviewed Core decision.');
    }
    quantaProfileDecisionTimestamp($outcome['received_at']); quantaProfileDecisionTimestamp($outcome['completed_at']);
    return $record;
}

function quantaProfileDecisionReceipt(string $key, string $workspace, array $body, array $proposal): ?array
{
    quantaProfileDecisionValidate($body, $proposal);
    try { $record = quantaGet($key, QUANTA_CORE_CONTRACT_PATH . '/profile-changes/' . $body['change_id'] . '/decision'); }
    catch (QuantaApiException $e) { if ($e->httpStatus === 404) return null; throw $e; }
    return quantaProfileDecisionConfirm($record, $workspace, $body, $proposal);
}

/** Call only from a reviewed durable delivery; recovery must use this exact original body. */
function quantaWriteProfileDecision(string $key, string $workspace, array $body, array $proposal): array
{
    quantaProfileDecisionValidate($body, $proposal);
    if ($key === '' || preg_match('/[\r\n]/', $key)) throw new InvalidArgumentException('A scoped Quanta key is required.');
    $url = quantaApiBase() . QUANTA_CORE_CONTRACT_PATH . '/profile-changes/' . $body['change_id'] . '/decision';
    $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json'];
    try {
        if (isset($GLOBALS['__quanta_core_write_transport']) && is_callable($GLOBALS['__quanta_core_write_transport'])) {
            $response = ($GLOBALS['__quanta_core_write_transport'])('PUT', $url, $headers, $json);
            $status = (int)($response['status'] ?? 0); $raw = (string)($response['body'] ?? '');
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($raw === false) throw new RuntimeException('Transport interrupted');
        }
    } catch (Throwable $e) { throw new QuantaContractWriteException('profile_decision_network_error', true); }
    if ($status !== 200) throw new QuantaContractWriteException('profile_decision_rejected_or_unconfirmed', !in_array($status, [400, 401, 403, 404, 422], true));
    try {
        $record = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($record)) throw new RuntimeException('Missing receipt');
        return quantaProfileDecisionConfirm($record, $workspace, $body, $proposal);
    } catch (Throwable $e) { throw new QuantaContractWriteException('invalid_profile_decision_receipt', true); }
}
