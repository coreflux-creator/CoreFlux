<?php
/** Validation shared by first-tenant setup and the legal-entity API. */
declare(strict_types=1);

function accountingNewEntityProfile(array $input): array
{
    $allowed = ['code', 'legal_name', 'country', 'base_currency', 'entity_type',
        'accounting_basis', 'fiscal_year_start_month', 'parent_entity_id'];
    $unknown = array_diff(array_keys($input), $allowed);
    if ($unknown) {
        throw new InvalidArgumentException('Unsupported entity field: ' . reset($unknown));
    }
    foreach (['code', 'legal_name', 'country', 'base_currency', 'entity_type',
        'accounting_basis', 'fiscal_year_start_month'] as $field) {
        if (!array_key_exists($field, $input)) {
            throw new InvalidArgumentException("{$field} is required");
        }
        if ($field === 'fiscal_year_start_month'
            ? (!is_string($input[$field]) && !is_int($input[$field]))
            : !is_string($input[$field])) {
            throw new InvalidArgumentException("{$field} must be text");
        }
    }

    $code = strtoupper(trim((string) $input['code']));
    $name = trim((string) $input['legal_name']);
    $country = strtoupper(trim((string) $input['country']));
    $currency = strtoupper(trim((string) $input['base_currency']));
    $type = trim((string) $input['entity_type']);
    $basis = trim((string) $input['accounting_basis']);
    $month = (string) $input['fiscal_year_start_month'];
    if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,39}$/D', $code)) {
        throw new InvalidArgumentException('Entity code must be 1-40 letters, digits, hyphens or underscores');
    }
    if ($name === '' || strlen($name) > 255) {
        throw new InvalidArgumentException('Legal name must be 1-255 bytes');
    }
    if (!preg_match('/^[A-Z]{2}$/D', $country)) {
        throw new InvalidArgumentException('Country must be a two-letter code');
    }
    if (!preg_match('/^[A-Z]{3}$/D', $currency)) {
        throw new InvalidArgumentException('Base currency must be a three-letter code');
    }
    if ($country !== 'US' || $currency !== 'USD') {
        throw new InvalidArgumentException('Only US/USD entity setup is supported by this accounting workflow');
    }
    if (!in_array($type, ['llc', 'corporation', 'partnership', 'sole_prop', 'nonprofit', 'other'], true)) {
        throw new InvalidArgumentException('Choose a valid legal entity type');
    }
    if ($basis !== 'accrual') {
        throw new InvalidArgumentException('Only accrual-basis setup is supported by this accounting workflow');
    }
    if ($month !== '1') {
        throw new InvalidArgumentException('Only a January fiscal-year start is supported by this accounting workflow');
    }

    $parent = $input['parent_entity_id'] ?? null;
    if ($parent !== null && $parent !== '') {
        if (!is_string($parent) && !is_int($parent)) {
            throw new InvalidArgumentException('parent_entity_id must be a positive integer');
        }
        if (!ctype_digit((string) $parent) || (int) $parent <= 0) {
            throw new InvalidArgumentException('parent_entity_id must be a positive integer');
        }
        $parent = (int) $parent;
    } else {
        $parent = null;
    }
    return [
        'code' => $code,
        'legal_name' => $name,
        'country' => $country,
        'base_currency' => $currency,
        'entity_type' => $type,
        'accounting_basis' => $basis,
        'fiscal_year_start_month' => 1,
        'parent_entity_id' => $parent,
    ];
}

function accountingReviewNewEntityProfile(PDO $pdo, int $tenantId, array $input): array
{
    $fields = accountingNewEntityProfile($input);
    $duplicate = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :t AND code = :code LIMIT 1');
    $duplicate->execute(['t' => $tenantId, 'code' => $fields['code']]);
    if ($duplicate->fetchColumn()) {
        throw new InvalidArgumentException('Entity code is already used in this workspace');
    }
    if ($fields['parent_entity_id'] !== null) {
        $parent = $pdo->prepare(
            'SELECT id FROM accounting_entities WHERE tenant_id = :t AND id = :id AND active = 1 LIMIT 1'
        );
        $parent->execute(['t' => $tenantId, 'id' => $fields['parent_entity_id']]);
        if (!$parent->fetchColumn()) {
            throw new InvalidArgumentException('Parent entity must be active in this workspace');
        }
    }
    return $fields;
}

function accountingReviewEntityProfileUpdate(array $current, array $input): array
{
    if (!$input) throw new InvalidArgumentException('Choose a field to update');
    $unknown = array_diff(array_keys($input), ['legal_name', 'entity_type']);
    if ($unknown) {
        throw new InvalidArgumentException('Entity financial setup is fixed after creation: ' . reset($unknown));
    }
    $changes = [];
    if (array_key_exists('legal_name', $input)) {
        if (!is_string($input['legal_name'])) {
            throw new InvalidArgumentException('Legal name must be text');
        }
        $name = trim((string) $input['legal_name']);
        if ($name === '' || strlen($name) > 255) {
            throw new InvalidArgumentException('Legal name must be 1-255 bytes');
        }
        if ($name !== (string) $current['legal_name']) $changes['legal_name'] = $name;
    }
    if (array_key_exists('entity_type', $input)) {
        if (!is_string($input['entity_type'])) {
            throw new InvalidArgumentException('Entity type must be text');
        }
        $type = trim((string) $input['entity_type']);
        if (!in_array($type, ['llc', 'corporation', 'partnership', 'sole_prop', 'nonprofit', 'other'], true)) {
            throw new InvalidArgumentException('Choose a valid legal entity type');
        }
        if ($type !== (string) $current['entity_type']) $changes['entity_type'] = $type;
    }
    return $changes;
}
