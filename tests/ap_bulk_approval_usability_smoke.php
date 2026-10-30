<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$ui = (string) file_get_contents($root . '/modules/ap/ui/Approvals.jsx');
$billDetail = (string) file_get_contents($root . '/modules/ap/ui/BillDetail.jsx');
$billList = (string) file_get_contents($root . '/modules/ap/ui/BillsList.jsx');
$paymentList = (string) file_get_contents($root . '/modules/ap/ui/PaymentsList.jsx');
$module = (string) file_get_contents($root . '/modules/ap/ui/APModule.jsx');
$billsApi = (string) file_get_contents($root . '/modules/ap/api/bills.php');
$correction = (string) file_get_contents($root . '/modules/ap/lib/bill_correction.php');
$apLibrary = (string) file_get_contents($root . '/modules/ap/lib/ap.php');
$correctActionAt = strpos($billsApi, "\$action === 'correct_posted'");
$correctAction = $correctActionAt === false ? '' : substr($billsApi, $correctActionAt, 300);

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
$check('payment release explains maker-checker and excludes creator from bulk release',
    str_contains($module, '<PaymentsList session={session}')
    && str_contains($paymentList, 'Number(payment.created_by_user_id) === currentUserId')
    && str_contains($paymentList, 'data-testid={`ap-payment-needs-reviewer-${p.id}`}')
    && str_contains($paymentList, "Number(p.unallocated_amount) <= 0.005 && !createdByCurrentUser(p)"));
$check('pending bills offer an audited detail edit',
    str_contains($billDetail, 'data-testid="ap-bill-edit-form"')
    && str_contains($billDetail, 'api.patch(`/modules/ap/api/bills.php?id=${id}`, editForm)'));
$check('intercompany bill split uses the bill owner rather than entity one',
    str_contains($billDetail, 'sourceEntityId={Number(bill.entity_id)}'));
$check('posted manual bill correction requires AP and GL reversal permissions',
    str_contains($correctAction, "rbac_legacy_require(\$user, 'ap.bill.void')")
    && str_contains($correctAction, "rbac_legacy_require(\$user, 'accounting.je.reverse')"));
$check('posted bill correction reverses journal and voids bill together',
    str_contains($correction, 'accountingReverseJe(')
    && str_contains($correction, 'cf_tx_begin($pdo)')
    && str_contains($correction, 'SET status = "void"')
    && str_contains($correction, 'cf_tx_commit($pdo, $ownsTransaction)'));
$check('bill detail links its original and reversal journals',
    str_contains($billDetail, 'data-testid="ap-bill-correct-posted"')
    && str_contains($billDetail, 'bill.reversal_journal_entry_id'));
$check('posted bill correction presents an explicit reversal and reason form',
    str_contains($billDetail, 'data-testid="ap-bill-correction-form"')
    && str_contains($billDetail, 'Void bill and reverse journal')
    && str_contains($billDetail, 'Correction reason'));
$check('detail offers correction only after server-side eligibility check',
    str_contains($billDetail, 'Boolean(bill.correction_available && bill.correction_permitted)')
    && str_contains($billsApi, "rbac_legacy_can(\$user, 'accounting.je.reverse')")
    && str_contains($billsApi, 'apAssertPostedManualBillCorrectable($pdo, $tid, $bill)'));
$check('ordinary and intercompany posts attach journals conditionally',
    str_contains($apLibrary, 'function apAttachPostedBillJournal(')
    && str_contains($apLibrary, 'journal_entry_id IS NULL')
    && substr_count($billsApi, 'apAttachPostedBillJournal(') >= 3);

echo PHP_EOL . "{$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
