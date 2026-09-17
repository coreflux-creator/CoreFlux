<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ui = (string) file_get_contents($root . '/modules/ap/ui/Approvals.jsx');

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

$check('approval inbox supports select all',
    str_contains($ui, 'data-testid="ap-approvals-select-all"'));
$check('approval inbox supports row selection',
    str_contains($ui, 'data-testid={`ap-approvals-select-${r.bill_id}`}'));
$check('selected bills can be approved in one action',
    str_contains($ui, 'data-testid="ap-approvals-bulk-approve"')
    && str_contains($ui, 'const approveSelected = async () =>'));
$check('bulk approvals reuse the guarded bill approval endpoint',
    str_contains($ui, "api.post('/modules/ap/api/bill_approvals.php?action=approve'"));
$check('bulk processing reports progress and keeps failures selected',
    str_contains($ui, 'Approving ${bulkProgress.done} of ${bulkProgress.total}')
    && str_contains($ui, 'setSelected(new Set(failed.map'));
$check('single and bulk decisions prevent duplicate clicks',
    str_contains($ui, 'disabled={bulkBusy || decidingId === r.bill_id}')
    && str_contains($ui, 'disabled={decidingId === r.bill_id}'));
$check('pending count refreshes after decisions',
    substr_count($ui, 'reloadCount();') >= 2);
$check('approval errors are translated into useful guidance',
    str_contains($ui, 'function friendlyApprovalError(error)'));

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
