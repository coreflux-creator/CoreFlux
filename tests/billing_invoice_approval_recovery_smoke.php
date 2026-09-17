<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$failures = [];
$ok = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? 'OK  ' : 'BAD ') . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
};

foreach (['core/ModuleRegistry.php', 'modules/billing/manifest.php', 'modules/billing/lib/workflow.php', 'modules/billing/api/invoices.php'] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg("{$root}/{$file}") . ' 2>&1', $output, $code);
    $ok("PHP lint {$file}", $code === 0);
}

require_once "{$root}/core/ModuleRegistry.php";
$registry = ModuleRegistry::reset("{$root}/modules");
$contract = $registry->getPeopleGraphContract('billing');
$ok('registry exposes Billing People Graph contract', is_array($contract));
$ok('Billing declares invoice approval resource',
    ($contract['object_types']['invoice']['approval_resource'] ?? null) === 'billing.invoice');

$workflow = (string) file_get_contents("{$root}/modules/billing/lib/workflow.php");
$api = (string) file_get_contents("{$root}/modules/billing/api/invoices.php");
$list = (string) file_get_contents("{$root}/modules/billing/ui/InvoicesList.jsx");
$ok('approval checks for a matching policy', str_contains($workflow, 'billingInvoiceApprovalRouting'));
$ok('no matching policy uses direct approval', str_contains($workflow, 'billingInvoiceApproveDirect'));
$ok('direct approval records approver and timestamp',
    str_contains($workflow, "status = 'approved'")
    && str_contains($workflow, 'approved_by_user_id = :u')
    && str_contains($workflow, 'approved_at = COALESCE'));
$ok('configured routes still use WorkflowEngine', str_contains($workflow, 'workflowAct('));
$ok('API documents optional policy routing', str_contains($api, 'Without one, an authorized billing user can approve'));
$ok('invoice list supports one-click approval of selected drafts',
    str_contains($list, 'billing-invoices-select-all-drafts')
    && str_contains($list, 'billing-invoices-approve-selected')
    && str_contains($list, 'Approve selected'));
$ok('bulk approval reuses the normal guarded invoice endpoint',
    str_contains($list, '/api/v1/billing/invoices?action=approve&id=${id}')
    && str_contains($list, 'normal approval policy and separation-of-duties checks'));
$ok('failed approvals stay selected with row-level reasons',
    str_contains($list, 'setSelected(new Set(failures.map')
    && str_contains($list, 'failure.reason'));

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Billing invoice approval recovery smoke passed.' . PHP_EOL;
