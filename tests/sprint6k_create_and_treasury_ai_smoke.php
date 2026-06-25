<?php
/**
 * Sprint 6k smoke — two production bugs reported via mobile screenshots:
 *
 *  Bug 1: PlacementCreate POST → "Column 'status' cannot be null" (SQLSTATE 23000).
 *         Root cause: ternary in placements.php read $body['status'] (undefined → null)
 *         when the in_array check passed against the default 'draft'.
 *
 *  Bug 2: Treasury AccountTransactions AI cat → "AI suggestion failed: line_id required".
 *         Root cause: fetchAiCat sent line_id in JSON body, but bank_ai.php reads from
 *         $_GET['line_id'].
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Bug 1 — placements.php status default cannot be null\n";
$api = (string) file_get_contents("{$ROOT}/modules/placements/api/placements.php");
$assert('uses an intermediate $statusInput variable',
    strpos($api, "\$statusInput = \$body['status'] ?? 'draft'") !== false);
$assert('insert status branches off $statusInput, not raw $body[status]',
    preg_match('#in_array\(\$statusInput,\s*ALLOWED_STATUS#', $api) === 1);
$assert('falsy branch still falls back to draft',
    preg_match('#in_array\(\$statusInput.*\?\s*\$statusInput\s*:\s*\'draft\'#s', $api) === 1);
// The legacy buggy pattern must be gone:
$assert('legacy buggy ternary is gone',
    strpos($api, "in_array(\$body['status'] ?? 'draft', ALLOWED_STATUS, true) ? \$body['status']") === false);

echo "\nBug 2 — Treasury fetchAiCat must pass line_id via query string\n";
$ui = (string) file_get_contents("{$ROOT}/modules/treasury/ui/AccountTransactions.jsx");
$assert('fetchAiCat URL includes line_id=${lineId} in the query string',
    strpos($ui, '?line_id=${lineId}') !== false || strpos($ui, '&line_id=${lineId}') !== false);
$assert('fetchAiCat does NOT pass line_id in JSON body',
    strpos($ui, "{ line_id: lineId })") === false);
$assert('still hits suggest_categorize action',
    strpos($ui, 'action=suggest_categorize') !== false || strpos($ui, '/suggest-categorize') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
