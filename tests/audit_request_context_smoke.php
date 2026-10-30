<?php
/** Audit context must follow the validated request tenant, not a different tab's session. */
declare(strict_types=1);

require_once __DIR__ . '/../core/tenant_scope.php';

$passed = 0;
$check = static function (bool $condition, string $label) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $passed++;
    echo "PASS {$label}\n";
};

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$beforeSession = $_SESSION;
$beforePin = $GLOBALS['__cf_request_tenant_id'] ?? null;
try {
    $_SESSION['tenant_id'] = 41;
    $_SESSION['user'] = ['id' => 73, 'role' => 'tenant_admin'];
    setRequestTenantId(null);
    $context = currentTenantContext();
    $check($context['tenant_id'] === 41 && $context['user']['id'] === 73,
        'session tenant and signed-in actor are available to audits');

    setRequestTenantId(999);
    $context = currentTenantContext();
    $check($context['tenant_id'] === 999 && $context['user']['id'] === 73,
        'validated request tenant overrides the session tenant for audits');

    setRequestTenantId(null);
    $check(currentTenantContext()['tenant_id'] === 41,
        'clearing the request pin restores the session tenant');

    unset($_SESSION['user'], $_SESSION['tenant_id']);
    $context = currentTenantContext();
    $check($context['tenant_id'] === null && $context['user'] === null,
        'missing tenant and actor remain null instead of being invented');
} finally {
    $_SESSION = $beforeSession;
    setRequestTenantId($beforePin !== null ? (int) $beforePin : null);
}

echo "{$passed} audit context checks passed\n";
