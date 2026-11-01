<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/credit_applications.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'accounting.ledger.import');
$action = (string) ($_GET['action'] ?? '');
try {
    if (api_method() === 'GET') {
        api_ok(accountingCreditList(getDB(), $tenantId, (int) ($_GET['entity_id'] ?? 0), (string) ($_GET['side'] ?? '')));
    }
    if (api_method() !== 'POST') api_error('Use GET or POST.', 405);
    $body = api_json_body();
    if ($action === 'preview') api_ok(accountingCreditReview(getDB(), $tenantId, $body));
    rbac_legacy_require($user, 'accounting.je.post');
    $side = (string) ($body['side'] ?? '');
    accountingCreditSpec($side);
    rbac_legacy_require($user, $side === 'ar' ? 'billing.payments.record' : 'ap.payment.allocate');
    if ($action === 'commit') {
        $result = accountingCreditCommit(getDB(), $tenantId, $body, (int) $user['id']);
    } elseif ($action === 'reverse' && ($body['confirmed'] ?? false) === true) {
        // Scope the requested application before choosing its source permission.
        $q = getDB()->prepare('SELECT side FROM accounting_credit_applications WHERE tenant_id = :t AND id = :id');
        $q->execute(['t' => $tenantId, 'id' => (int) ($body['id'] ?? 0)]);
        if ($q->fetchColumn() !== $side) api_error('Credit application not found.', 404);
        $result = accountingCreditReverse(getDB(), $tenantId, (int) ($body['id'] ?? 0),
            (string) ($body['reversal_date'] ?? ''), (string) ($body['reason'] ?? ''), (int) $user['id']);
    } else {
        api_error('Review an application or confirm a correction.', 422);
    }
    if (!$result['idempotent_replay']) accountingAudit('accounting.credit.' . $action,
        ['application_id' => $result['id'], 'side' => $side]);
    api_ok($result);
} catch (InvalidArgumentException $error) {
    api_error($error->getMessage(), 422);
}
