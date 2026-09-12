<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../api/ai_accuracy.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read AI accuracy API.\n");
    exit(1);
}

$checks = [
    'history query avoids the reserved rows alias' => !preg_match('/COUNT\(\*\)\s+AS\s+rows\b/i', $source),
    'history query uses a portable internal alias' => str_contains($source, 'COUNT(*) AS history_rows'),
    'API preserves the rows response field' => str_contains($source, '$row[\'rows\'] = (int) $row[\'history_rows\']'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
