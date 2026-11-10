<?php
/** Reviewed contact proposals. Validation never changes people, provider identities or access. */
declare(strict_types=1);
require_once __DIR__ . '/core_contract.php';

const QUANTA_PROFILE_CHANGE_VERSION = '2026-11-10.1';
const QUANTA_PROFILE_FIELDS = ['first_name', 'last_name', 'email_primary', 'phone_primary'];

function quantaProfileExactFields(array $value, array $fields): void
{
    if (array_diff($fields, array_keys($value)) || array_diff(array_keys($value), $fields)) {
        throw new InvalidArgumentException('Complete reviewed profile fields required.');
    }
}

function quantaProfileValuesValidate(array $profile): array
{
    quantaProfileExactFields($profile, QUANTA_PROFILE_FIELDS);
    foreach (['first_name' => 100, 'last_name' => 100, 'phone_primary' => 40] as $field => $limit) {
        $value = $profile[$field];
        if ($field === 'phone_primary' && $value === null) continue;
        if (!is_string($value) || ($field === 'phone_primary' && $value === '')
            || $value !== trim($value) || strlen($value) > $limit
            || preg_match_all('/./us', $value) === false || preg_match('/[\x00-\x1f\x7f]/', $value)
            || preg_match('/(*UCP)(?:^\s|\s$)/u', $value)) {
            throw new InvalidArgumentException('Profile contact value does not fit the reviewed Core field.');
        }
    }
    $name = trim($profile['first_name'] . ' ' . $profile['last_name']);
    if ($name === '' || preg_match_all('/./us', $name) > 120) {
        throw new InvalidArgumentException('The full name must fit the Quanta worker directory.');
    }
    $email = $profile['email_primary'];
    if ($email !== null && (!is_string($email) || strlen($email) > 254
        || $email !== strtolower(trim($email)) || preg_match('/[^\x20-\x7e]/', $email)
        || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('Use a normalized contact email. Sign-in email is managed separately.');
    }
    return $profile;
}

function quantaProfileBaselineValidate(array $baseline, string $workspace, array $person, string $worker): array
{
    quantaProfileExactFields($baseline, ['contract_version', 'id', 'tenant_id', 'person', 'worker_id',
        'version', 'core_snapshot_hash', 'profile']);
    $person = quantaCoreReferenceValidate($person, 'person');
    $linkId = quantaCoreRemoteId($workspace, $person);
    if ($baseline['contract_version'] !== QUANTA_PROFILE_CHANGE_VERSION
        || $baseline['id'] !== 'cfp_' . substr($linkId, 4) || $baseline['tenant_id'] !== $workspace
        || $baseline['worker_id'] !== $worker || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $worker)
        || !is_array($baseline['person'])
        || quantaCoreReferenceValidate($baseline['person'], 'person') !== $person
        || !is_int($baseline['version']) || $baseline['version'] < 1
        || !is_string($baseline['core_snapshot_hash'])
        || !preg_match('/^[a-f0-9]{64}$/D', $baseline['core_snapshot_hash']) || !is_array($baseline['profile'])) {
        throw new InvalidArgumentException('The profile baseline belongs to another identity or revision.');
    }
    quantaProfileValuesValidate($baseline['profile']);
    return $baseline;
}

function quantaProfileChangedFields(array $baseline, array $proposed): array
{
    $baseline = quantaProfileValuesValidate($baseline); $proposed = quantaProfileValuesValidate($proposed);
    return array_values(array_filter(QUANTA_PROFILE_FIELDS, static fn($field) => $baseline[$field] !== $proposed[$field]));
}

/** Callers must also prove that the baseline matches Core's own saved publication receipt. */
function quantaProfileProposalValidate(array $record, string $workspace, array $person, string $worker, string $id): array
{
    quantaProfileExactFields($record, ['contract_version', 'id', 'tenant_id', 'worker_id', 'person_link_id',
        'person', 'revision', 'origin', 'actor', 'baseline', 'proposed', 'changed_fields', 'request_hash', 'created_at']);
    $person = quantaCoreReferenceValidate($person, 'person');
    if (!preg_match('/^cpc_[a-f0-9]{64}$/D', $id) || $record['id'] !== $id
        || $record['contract_version'] !== QUANTA_PROFILE_CHANGE_VERSION || $record['tenant_id'] !== $workspace
        || $record['worker_id'] !== $worker || $record['person_link_id'] !== quantaCoreRemoteId($workspace, $person)
        || !is_array($record['person']) || quantaCoreReferenceValidate($record['person'], 'person') !== $person
        || $record['revision'] !== 1 || $record['origin'] !== 'quanta_portal' || !is_array($record['actor'])
        || !is_array($record['baseline']) || !is_array($record['proposed']) || !is_array($record['changed_fields'])
        || !is_string($record['request_hash']) || !preg_match('/^[a-f0-9]{64}$/D', $record['request_hash'])) {
        throw new InvalidArgumentException('The profile proposal does not match its source and canonical person.');
    }
    quantaProfileExactFields($record['actor'], ['kind', 'user_id', 'identity_id']);
    if ($record['actor']['kind'] !== 'worker' || $record['actor']['user_id'] !== $worker
        || !is_string($record['actor']['identity_id'])
        || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $record['actor']['identity_id'])) {
        throw new InvalidArgumentException('The originating worker identity is required.');
    }
    $baseline = quantaProfileBaselineValidate($record['baseline'], $workspace, $person, $worker);
    $changed = quantaProfileChangedFields($baseline['profile'], $record['proposed']);
    if (!$changed || $record['changed_fields'] !== $changed) {
        throw new InvalidArgumentException('The exact changed profile fields are required.');
    }
    $timestamp = $record['created_at'];
    if (!is_string($timestamp)
        || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:\+00:00|Z)$/D', $timestamp)) {
        throw new InvalidArgumentException('A source UTC timestamp is required.');
    }
    try { new DateTimeImmutable($timestamp); $errors = DateTimeImmutable::getLastErrors(); }
    catch (Exception $e) { throw new InvalidArgumentException('Invalid source timestamp.', 0, $e); }
    if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
        throw new InvalidArgumentException('Invalid source timestamp.');
    }
    return $record;
}

/** A conflict blocks the entire unreviewed update; unrelated Core edits are preserved. */
function quantaProfileChangeCompare(array $baseline, array $proposed, array $current): array
{
    $baseline = quantaProfileValuesValidate($baseline); $proposed = quantaProfileValuesValidate($proposed);
    $current = quantaProfileValuesValidate($current); $changes = []; $conflicts = []; $already = [];
    foreach (QUANTA_PROFILE_FIELDS as $field) {
        if ($proposed[$field] === $baseline[$field]) continue;
        if ($current[$field] === $proposed[$field]) $already[] = $field;
        elseif ($current[$field] === $baseline[$field]) $changes[$field] = $proposed[$field];
        else $conflicts[$field] = ['baseline' => $baseline[$field], 'proposed' => $proposed[$field], 'current' => $current[$field]];
    }
    return ['state' => $conflicts ? 'conflict' : ($changes ? 'change' : 'already_current'),
        'changes' => $conflicts ? [] : $changes, 'conflicts' => $conflicts, 'already_current_fields' => $already];
}
