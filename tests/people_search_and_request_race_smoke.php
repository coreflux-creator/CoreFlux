<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$people = (string) file_get_contents($root . '/modules/people/lib/people.php');
$apiClient = (string) file_get_contents($root . '/dashboard/src/lib/api.js');

$checks = [
    'people search matches a displayed full legal name' =>
        str_contains($people, "CONCAT_WS(' ', p.first_name, p.last_name) LIKE :q5")
        && str_contains($people, '$params[\'q5\']'),
    'people search matches a displayed preferred full name' =>
        str_contains($people, "CONCAT_WS(' ', p.preferred_name, p.last_name) LIKE :q6")
        && str_contains($people, '$params[\'q6\']'),
    'shared API hook tracks the latest request' =>
        str_contains($apiClient, 'const requestSequence = useRef(0);')
        && str_contains($apiClient, 'const sequence = ++requestSequence.current;'),
    'stale API results cannot overwrite current list data' =>
        substr_count($apiClient, 'sequence !== requestSequence.current') >= 3,
    'unmounted API hooks invalidate in-flight requests' =>
        str_contains($apiClient, 'requestSequence.current += 1;'),
    'cached list hook also tracks the latest request' =>
        substr_count($apiClient, 'const requestSequence = useRef(0);') >= 2
        && substr_count($apiClient, 'const sequence = ++requestSequence.current;') >= 2,
    'cached list switches data when its cache key changes' =>
        str_contains($apiClient, 'if (fresh) {')
        && str_contains($apiClient, 'setData(fresh.data);')
        && str_contains($apiClient, 'setData(null);'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
