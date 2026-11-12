<?php
/** Shared validation for the focused setup screen and native placement edits. */
declare(strict_types=1);

function placementApprovalContactFields(): array
{
    return ['client_approver_name', 'client_approver_email',
        'tokenized_email_approval_enabled', 'bulk_uploads_can_be_pre_approved'];
}

function placementApprovalContactInput(array $patch, bool $focused = false): array
{
    if ($focused && array_diff(array_keys($patch), placementApprovalContactFields())) {
        throw new InvalidArgumentException('Only timesheet approval setup can be changed here.', 422);
    }
    foreach (['client_approver_name' => 255, 'client_approver_email' => 255] as $field => $limit) {
        if (!array_key_exists($field, $patch)) continue;
        $value = $patch[$field];
        if ($value !== null && !is_string($value)) throw new InvalidArgumentException('Enter a valid approval contact.', 422);
        if ($value !== null && (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value))) {
            throw new InvalidArgumentException('Approval contact must be printable text.', 422);
        }
        $value = $value === null ? null : trim($value);
        if ($value === '') $value = null;
        if ($value !== null && (mb_strlen($value) > $limit
            || ($field === 'client_approver_email' && !filter_var($value, FILTER_VALIDATE_EMAIL)))) {
            throw new InvalidArgumentException('Enter a valid approval contact of up to 255 characters.', 422);
        }
        $patch[$field] = $value;
    }
    foreach (['tokenized_email_approval_enabled', 'bulk_uploads_can_be_pre_approved'] as $field) {
        if (!array_key_exists($field, $patch)) continue;
        if (!in_array($patch[$field], [true, false, 0, 1, '0', '1'], true)) {
            throw new InvalidArgumentException('Choose yes or no for each approval option.', 422);
        }
        $patch[$field] = (int) $patch[$field];
    }
    return $patch;
}
