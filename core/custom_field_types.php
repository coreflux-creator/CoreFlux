<?php
declare(strict_types=1);

/** Strict source types. No truthy "false", partial number extraction or inferred dates. */
function customFieldTypedValue(array $definition, mixed $value): mixed
{
    if ($value === null) {
        if (!empty($definition['required'])) throw new InvalidArgumentException('This custom field is required.');
        return null;
    }
    $type = $definition['field_type'] ?? '';
    if ($type === 'boolean') {
        if (in_array($value, [true, 1, '1', 'true'], true)) return true;
        if (in_array($value, [false, 0, '0', 'false'], true)) return false;
        throw new InvalidArgumentException('Use an explicit true or false value.');
    }
    if ($type === 'number') {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || !preg_match('/^-?(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/D', (string) $value)) {
            throw new InvalidArgumentException('Use a decimal number with at most fourteen integer and six decimal digits.');
        }
        $number = (string) $value;
        if (str_contains($number, '.')) $number = rtrim(rtrim($number, '0'), '.');
        return $number === '-0' ? '0' : $number;
    }
    if ($type === 'date') {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Use a valid date in YYYY-MM-DD format.');
        return $value;
    }
    if ($type === 'text') {
        if (!is_string($value) || strlen($value) > 16000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)
            || (!empty($definition['required']) && trim($value) === '')) throw new InvalidArgumentException('Use a valid custom text value.');
        return $value;
    }
    if (!in_array($type, ['select', 'multiselect'], true)) throw new InvalidArgumentException('Unsupported custom field type.');
    $options = $definition['options_json'] ?? null;
    if (is_string($options)) $options = json_decode($options, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($options) || !array_is_list($options) || count($options) > 200
        || array_filter($options, static fn($o) => !is_string($o) || $o === '' || strlen($o) > 200)) {
        throw new InvalidArgumentException('The custom field requires a reviewed list of choices.');
    }
    $selected = $type === 'select' ? [$value] : $value;
    if (!is_array($selected) || !array_is_list($selected) || count($selected) > 200
        || (!empty($definition['required']) && !$selected)) throw new InvalidArgumentException('Choose an allowed custom field value.');
    foreach ($selected as $choice) if (!is_string($choice) || !in_array($choice, $options, true)) throw new InvalidArgumentException('The selection is not an allowed choice.');
    if ($type === 'select') return $selected[0];
    $selected = array_values(array_unique($selected)); sort($selected, SORT_STRING); return $selected;
}
