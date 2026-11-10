<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/purepay_people_inputs.php';
$expected=['people'=>10,'placements'=>20];
if (purepayPeopleInputOwners($expected)!==$expected || purepayPeopleInputOwners(['ap'=>30]+$expected)!==$expected) {
    throw new RuntimeException('Person ownership normalization failed');
}
foreach ([[],['people'=>10],['people'=>'10','placements'=>20],['people'=>10,'placements'=>0],
    $expected+['ap'=>'30'],$expected+['ap'=>null],$expected+['ap'=>-1],$expected+['treasury'=>30]] as $invalid) {
    $rejected=false;
    try {purepayPeopleInputOwners($invalid);} catch (InvalidArgumentException $e) {$rejected=true;}
    if (!$rejected) throw new RuntimeException('Invalid or unknown directory scope accepted');
}
echo "PASS person directory ownership and legacy metadata validation\n";
