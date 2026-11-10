<?php
/** Read or explicitly reassign a pending Billing invoice approval. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/approval_assignment.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();
try {
    if ($method === 'GET') {
        rbac_legacy_require($user, 'billing.view');
        $invoiceId = (int) api_query('invoice_id', 0);
        if ($invoiceId <= 0) api_error('invoice_id required', 422);
        $assignment = billingInvoiceApprovalAssignmentRead($tenantId, $invoiceId, (int) $user['id']);
        $assignment['viewer_can_request'] = $assignment['viewer_can_request']
            && RBAC::hasPermission($user, 'billing.invoice.draft')
            && RBACResolver::can($user, $tenantId, 'billing', 'write');
        $assignment['viewer_can_approve'] = $assignment['viewer_can_approve']
            && RBAC::hasPermission($user, 'billing.invoice.approve');
        $assignment['viewer_can_reassign'] = $assignment['pending'] && $assignment['managed_assignment']
            && RBAC::hasPermission($user, 'billing.approvals.manage')
            && RBACResolver::can($user, $tenantId, 'billing', 'admin');
        api_ok($assignment);
    }
    if ($method === 'POST') {
        rbac_legacy_require($user, 'billing.approvals.manage');
        if (!RBACResolver::can($user, $tenantId, 'billing', 'admin')) {
            api_error('Billing approval administration access is required.', 403);
        }
        $body = api_json_body();
        if (array_diff(array_keys($body), ['invoice_id', 'reviewer_user_ids'])
            || count($body) !== 2 || !is_array($body['reviewer_user_ids'])) {
            api_error('Send invoice_id and reviewer_user_ids.', 422);
        }
        if (!is_int($body['invoice_id']) || $body['invoice_id'] <= 0) {
            api_error('Choose a valid invoice.', 422);
        }
        api_ok(billingInvoiceApprovalReassign($tenantId, $body['invoice_id'],
            $body['reviewer_user_ids'], (int) $user['id']));
    }
    api_error('Method not allowed', 405);
} catch (OutOfBoundsException $e) {
    api_error($e->getMessage(), 404);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (DomainException $e) {
    api_error($e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('[billing.invoice.approval_assignment] ' . $e->getMessage());
    api_error('Invoice approval assignment is unavailable. Try again or contact an administrator.', 503);
}
