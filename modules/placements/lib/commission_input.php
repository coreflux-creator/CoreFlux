<?php
declare(strict_types=1);

/**
 * The legacy commission row cannot represent a separate currency or payment
 * schedule. Reject unrepresentable input instead of accepting an amount after
 * dropping its meaning. This validates the native API, not connector imports.
 */
function placementCommissionInput(array $input): array
{
    $allowed = ['role', 'user_id', 'plan_id', 'split_pct', 'basis', 'flat_amount',
        'effective_from', 'effective_to', 'notes'];
    if (array_diff(array_keys($input), $allowed)) {
        throw new InvalidArgumentException(
            'Unsupported commission fields. This form cannot store a separate currency, payment frequency or due date. Keep those terms for review; do not remove them to submit the amount.'
        );
    }
    foreach ($input as $value) {
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException('Commission fields must contain single values. Keep structured source terms for review.');
        }
    }
    if (array_key_exists('basis', $input)
        && !in_array($input['basis'], ['net_margin', 'gross_margin', 'bill_rate', 'flat'], true)) {
        throw new InvalidArgumentException('Unsupported commission basis. Flat does not establish a one-time payment or a payment frequency.');
    }
    return $input;
}
