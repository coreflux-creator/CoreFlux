<?php
/**
 * One-step person + placement creation regression coverage.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = static function (string $message, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        echo "  PASS {$message}\n";
        $pass++;
        return;
    }
    echo "  FAIL {$message}\n";
    $fail++;
};

$root = realpath(__DIR__ . '/..');
$ui = (string) file_get_contents($root . '/modules/placements/ui/PlacementCreate.jsx');
$api = (string) file_get_contents($root . '/modules/placements/api/placements.php');
$people = (string) file_get_contents($root . '/modules/people/lib/people.php');

echo "PlacementCreate one-step workflow\n";
$assert('offers new and existing person modes',
    str_contains($ui, 'placement-create-person-new')
    && str_contains($ui, 'placement-create-person-existing'));
$assert('collects the minimal new-person identity',
    str_contains($ui, 'placement-create-person-first-name')
    && str_contains($ui, 'placement-create-person-last-name')
    && str_contains($ui, 'placement-create-person-email')
    && str_contains($ui, 'placement-create-person-phone'));
$assert('submits new person with the placement request',
    str_contains($ui, 'payload.new_person = {')
    && str_contains($ui, "delete payload.person_id"));
$assert('primary action clearly creates both records',
    str_contains($ui, 'Create person & placement'));
$assert('duplicate email can switch to the existing person without losing placement fields',
    str_contains($ui, 'placement-create-person-conflict')
    && str_contains($ui, 'placement-create-use-existing-conflict')
    && str_contains($ui, 'useConflictingPerson'));
$assert('classification preview follows engagement type',
    str_contains($ui, 'classificationForEngagement')
    && str_contains($ui, 'placement-create-person-classification'));

echo "\nPlacements API atomic create\n";
$assert('accepts either person_id or new_person',
    str_contains($api, '$newPersonBody')
    && str_contains($api, 'Choose either an existing person or a new person'));
$assert('new-person writes require people.manage',
    preg_match('/if \(\$newPersonBody !== null\).*?rbac_legacy_require\(\$user, \'people\.manage\'\)/s', $api) === 1);
$assert('validates tenant-wide email uniqueness and returns an actionable conflict',
    str_contains($api, 'peopleFindByEmail($email)')
    && str_contains($people, 'LOWER(email_primary) = LOWER(:email)')
    && str_contains($api, "'conflict_id'")
    && str_contains($api, "'conflict' =>"));
$assert('person and placement share one transaction',
    str_contains($api, '$pdo = cf_begin_transaction();')
    && str_contains($api, 'peopleCreateForPlacement(')
    && str_contains($people, "scopedInsert('people', [")
    && str_contains($api, "scopedInsert('placements', \$insert)")
    && str_contains($api, '$pdo->commit()')
    && str_contains($api, '$pdo->rollBack()'));
$assert('server derives classification instead of trusting the browser',
    str_contains($api, 'function placementPersonClassification')
    && str_contains($api, "'temp_to_perm' => 'temp'")
    && str_contains($api, "'direct_hire' => 'perm'")
    && str_contains($api, "default => 'w2'"));
$assert('active W-2 people retain the payroll bridge',
    str_contains($api, 'peopleEnsureEmployeesFromW2()'));
$assert('response identifies the created person',
    str_contains($api, "'person_created' => \$createdPersonId !== null")
    && str_contains($api, "\$response['person'] = peopleGet(\$createdPersonId)"));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
