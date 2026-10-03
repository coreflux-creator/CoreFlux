<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ui = (string) file_get_contents($root . '/modules/ap/ui/Approvals.jsx');
$billDetail = (string) file_get_contents($root . '/modules/ap/ui/BillDetail.jsx');
$billList = (string) file_get_contents($root . '/modules/ap/ui/BillsList.jsx');
$module = (string) file_get_contents($root . '/modules/ap/ui/APModule.jsx');

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
$check('bill detail does not invite its creator to self-approve',
    str_contains($module, '<BillDetail session={session}')
    && str_contains($billDetail, '!createdByCurrentUser')
    && str_contains($billDetail, 'Another authorized user must approve this bill.'));
$check('bulk approval excludes bills created by the acting user',
    str_contains($module, '<BillsList session={session}')
    && str_contains($billList, 'Number(row.created_by_user_id) !== currentUserId'));
$check('pending bills offer an audited detail edit',
    str_contains($billDetail, 'data-testid="ap-bill-edit-form"')
    && str_contains($billDetail, 'api.patch(`/modules/ap/api/bills.php?id=${id}`, editForm)'));
$check('intercompany bill split uses the bill owner rather than entity one',
    str_contains($billDetail, 'sourceEntityId={Number(bill.entity_id)}'));

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
