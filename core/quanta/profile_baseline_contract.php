<?php
/** Receipt-backed Core contact baselines; publication never changes a login. */
declare(strict_types=1);
require_once __DIR__ . '/profile_change_contract.php';

function quantaProfileDigest(array $value): string
{
    return hash('sha256', json_encode(integrationDeliveryCanonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function quantaProfileBaselineBodyValidate(array $body): array
{
    quantaProfileExactFields($body, ['person', 'worker_id', 'expected_version', 'core_snapshot_hash', 'profile']);
    if (!is_array($body['person']) || !is_array($body['profile'])
        || !is_string($body['worker_id']) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $body['worker_id'])
        || !is_int($body['expected_version']) || $body['expected_version'] < 0 || $body['expected_version'] >= PHP_INT_MAX
        || !is_string($body['core_snapshot_hash']) || !preg_match('/^[a-f0-9]{64}$/D', $body['core_snapshot_hash'])) {
        throw new InvalidArgumentException('A complete current Core contact baseline is required.');
    }
    $body['person'] = quantaCoreReferenceValidate($body['person'], 'person');
    $body['profile'] = quantaProfileValuesValidate($body['profile']);
    return $body;
}

function quantaProfileChangeRequire(string $key, string $workspace, bool $decisionDelivery = false): void
{
    $context = quantaGet($key, QUANTA_CORE_CONTRACT_PATH . '/profile-change-context');
    if (($context['workspace_id'] ?? null) !== $workspace
        || ($context['contract_version'] ?? null) !== QUANTA_PROFILE_CHANGE_VERSION
        || ($context['baseline_publication'] ?? null) !== true || ($context['worker_proposals'] ?? null) !== true
        || ($context['login_identity_changes'] ?? null) !== false
        || ($decisionDelivery && ($context['decision_delivery'] ?? null) !== true)) {
        throw new RuntimeException('Quanta has not enabled the reviewed contact request contract for this workspace.');
    }
}

function quantaProfileBaselineRead(string $key, string $workspace, array $person, string $worker): ?array
{
    $id = 'cfp_' . substr(quantaCoreRemoteId($workspace, $person), 4);
    try { $record = quantaGet($key, QUANTA_CORE_CONTRACT_PATH . '/profile-baselines/' . $id); }
    catch (QuantaApiException $e) { if ($e->httpStatus === 404) return null; throw $e; }
    return quantaProfileBaselineValidate($record, $workspace, $person, $worker);
}

function quantaProfileBaselineConfirm(array $record, string $workspace, array $body, int $status): array
{
    $body = quantaProfileBaselineBodyValidate($body);
    quantaProfileExactFields($record, ['baseline', 'delivery_id']);
    if ($status !== ($body['expected_version'] === 0 ? 201 : 200) || !is_array($record['baseline'])
        || !is_string($record['delivery_id']) || !preg_match('/^cfd_[a-f0-9]{64}$/D', $record['delivery_id'])) {
        throw new RuntimeException('Unconfirmed contact baseline publication.');
    }
    $baseline = quantaProfileBaselineValidate($record['baseline'], $workspace, $body['person'], $body['worker_id']);
    if (quantaProfileDigest($baseline['profile']) !== quantaProfileDigest($body['profile'])
        || $baseline['core_snapshot_hash'] !== $body['core_snapshot_hash']
        || !in_array($baseline['version'], [max(1, $body['expected_version']), $body['expected_version'] + 1], true)) {
        throw new RuntimeException('The contact baseline does not match the reviewed Core snapshot.');
    }
    return $record;
}

function quantaProfileBaselineReceipt(string $key, string $workspace, array $body, string $deliveryKey): ?array
{
    $body = quantaProfileBaselineBodyValidate($body);
    if (!preg_match('/^[a-f0-9]{64}$/D', $deliveryKey)) throw new InvalidArgumentException('Use the original delivery identity.');
    try { $receipt = quantaGet($key, QUANTA_CORE_CONTRACT_PATH . '/profile-baseline-deliveries/' . $deliveryKey); }
    catch (QuantaApiException $e) { if ($e->httpStatus === 404) return null; throw $e; }
    if (!is_string($receipt['id'] ?? null) || !preg_match('/^cfd_[a-f0-9]{64}$/D', $receipt['id'])
        || ($receipt['request_hash'] ?? null) !== quantaProfileDigest($body)
        || !in_array($receipt['state'] ?? null, ['in_progress', 'uncertain', 'complete'], true)) {
        throw new RuntimeException('The contact baseline receipt does not match this request.');
    }
    if ($receipt['state'] === 'complete' && in_array($receipt['status_code'] ?? null, [200, 201], true)) {
        if (($receipt['response']['delivery_id'] ?? null) !== $receipt['id']) throw new RuntimeException('The receipt identity is inconsistent.');
        quantaProfileBaselineConfirm($receipt['response'], $workspace, $body, $receipt['status_code']);
    }
    return $receipt;
}

/** Call only after the tenant binding, source, review and durable claim are verified. */
function quantaWriteProfileBaseline(string $key, string $workspace, array $body, string $idempotencyKey): array
{
    $body = quantaProfileBaselineBodyValidate($body);
    if ($key === '' || preg_match('/[\r\n]/', $key) || !preg_match('/^[a-f0-9]{64}$/D', $idempotencyKey)) {
        throw new InvalidArgumentException('A scoped key and durable delivery identity are required.');
    }
    $url = quantaApiBase() . QUANTA_CORE_CONTRACT_PATH . '/profile-baselines';
    $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json', 'Idempotency-Key: ' . $idempotencyKey];
    try {
        if (isset($GLOBALS['__quanta_core_write_transport']) && is_callable($GLOBALS['__quanta_core_write_transport'])) {
            $response = ($GLOBALS['__quanta_core_write_transport'])('POST', $url, $headers, $json);
            $status = (int)($response['status'] ?? 0); $raw = (string)($response['body'] ?? '');
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($raw === false) throw new RuntimeException('Transport interrupted');
        }
    } catch (Throwable $e) { throw new QuantaContractWriteException('profile_baseline_network_error', true); }
    if ($status !== ($body['expected_version'] === 0 ? 201 : 200)) {
        throw new QuantaContractWriteException('profile_baseline_rejected_or_unconfirmed', !in_array($status, [400, 401, 403, 404, 422], true));
    }
    try {
        $record = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($record)) throw new RuntimeException('Missing record');
        return quantaProfileBaselineConfirm($record, $workspace, $body, $status);
    } catch (Throwable $e) { throw new QuantaContractWriteException('invalid_profile_baseline_response', true); }
}
