<?php
/** Reviewer setup for the Billing invoice approval policy. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/approval_settings.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'billing.approvals.manage');

$method = api_method();
try {
    if ($method === 'GET') {
        api_ok(billingInvoiceApprovalSettingsRead($tenantId));
    }
    if ($method === 'PUT') {
        $body = api_json_body();
        if (array_keys($body) !== ['reviewer_user_ids'] || !is_array($body['reviewer_user_ids'])) {
            api_error('Send reviewer_user_ids as an array.', 422);
        }
        api_ok(billingInvoiceApprovalSettingsSave(
            $tenantId, $body['reviewer_user_ids'], (int) $user['id']
        ));
    }
    api_error('Method not allowed', 405);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (DomainException $e) {
    api_error($e->getMessage(), 409);
} catch (Throwable $e) {
    error_log('[billing.approval_settings] ' . $e->getMessage());
    api_error('Invoice reviewer settings are unavailable. Try again or contact an administrator.', 503);
}
