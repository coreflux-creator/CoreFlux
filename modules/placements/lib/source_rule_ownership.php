<?php
/** Ownership of legacy rule sets whose source does not provide stable child IDs. */
declare(strict_types=1);
require_once __DIR__ . '/ownership.php';

function placementSourceRuleDefinitions(): array
{
    return [
        'chain' => ['table'=>'placement_client_chain', 'field'=>'chain_rules', 'label'=>'Vendor chain'],
        'referral' => ['table'=>'placement_referrals', 'field'=>'referral_rules', 'label'=>'Referral fees'],
        'commission' => ['table'=>'placement_commissions', 'field'=>'commission_rules', 'label'=>'Commission splits'],
    ];
}

/** Source callers must hold the same parent lock throughout their whole write. */
function placementWithSourceRuleWrite(int $tenant, int $placement, string $kind, callable $write, mixed $held): mixed
{
    $definition = placementSourceRuleDefinitions()[$kind] ?? null;
    if (!$definition) throw new InvalidArgumentException('Unknown placement rule set.',422);
    return placementWithLockedRecord($tenant,$placement,static function(array $parent) use ($definition,$write,$held): mixed {
        return in_array($definition['field'],placementOwnershipFields($parent['coreflux_overridden_fields'] ?? null),true)
            ? $held : $write();
    });
}
