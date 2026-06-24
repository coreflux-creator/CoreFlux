<?php
/**
 * Gusto audit evidence controls smoke.
 *
 * Keeps Gusto payroll integration evidence on the shared platform audit writer
 * with tenant-scoped, token-scrubbed snapshots for connection, token refresh,
 * webhook, and Track B sync paths.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};
$containsAll = function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, (string) $needle)) return false;
    }
    return true;
};

$svc = (string) file_get_contents("{$ROOT}/core/gusto_service.php");
$start = (string) file_get_contents("{$ROOT}/api/gusto_oauth_start.php");
$callback = (string) file_get_contents("{$ROOT}/api/gusto_oauth_callback.php");
$webhook = (string) file_get_contents("{$ROOT}/api/gusto_webhook.php");
$connect = (string) file_get_contents("{$ROOT}/modules/payroll/api/gusto_connect.php");
$submit = (string) file_get_contents("{$ROOT}/modules/payroll/api/gusto_submit.php");
$trackB = (string) file_get_contents("{$ROOT}/core/gusto_track_b.php");
$manifest = (string) file_get_contents("{$ROOT}/modules/payroll/manifest.php");
$auditDoc = (string) file_get_contents("{$ROOT}/docs/AUDIT_GOVERNANCE.md");
$alignment = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Files parse\n";
foreach ([
    'core/gusto_service.php',
    'api/gusto_oauth_start.php',
    'api/gusto_oauth_callback.php',
    'api/gusto_webhook.php',
    'modules/payroll/api/gusto_connect.php',
    'modules/payroll/api/gusto_submit.php',
    'core/gusto_track_b.php',
    'modules/payroll/manifest.php',
    'tests/gusto_audit_evidence_controls_smoke.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nGusto audit writer\n";
$a('gustoAudit delegates to shared platform audit writer',
    $containsAll($svc, [
        "require_once __DIR__ . '/audit.php'",
        'function gustoAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = [])',
        'platformAuditLogWrite(',
        "'object_type' => 'payroll_gusto'",
        "'source' => 'payroll'",
    ]));
$a('gustoAudit avoids direct audit_log insert and currentTenantContext',
    !preg_match('/function gustoAudit[\s\S]*INSERT INTO audit_log/', $svc)
    && !str_contains($svc, 'currentTenantContext'));
$a('connection snapshots scrub encrypted token ciphertext',
    $containsAll($svc, [
        'function gustoConnectionAuditRow(',
        'function gustoConnectionAuditRowById(',
        'function gustoScrubConnectionAuditRow(',
        "unset(\$row['access_token_ct'], \$row['refresh_token_ct'])",
    ]));
$a('token refresh captures before/after connection evidence',
    $containsAll($svc, [
        "gustoAudit('payroll.gusto.token_refreshed'",
        '$before = $tenantId > 0 && $connectionId > 0',
        '$after = $tenantId > 0 && $connectionId > 0',
        "'tenant_id' => \$tenantId > 0 ? \$tenantId : null",
    ]));
$a('401 refresh captures before/after connection evidence',
    $containsAll($svc, [
        "gustoAudit('payroll.gusto.token_refreshed_on_401'",
        'gustoScrubConnectionAuditRow($connection)',
        "'before' => \$before",
        "'after' => \$after",
    ]));

echo "\nCall-site context\n";
$a('OAuth start passes explicit tenant and actor',
    $containsAll($start, [
        "gustoAudit('payroll.gusto.connect_initiated'",
        "'tenant_id' => (int) \$ctx['tenant_id']",
        "'actor_user_id' => (int) (\$ctx['user']['id'] ?? 0)",
    ]));
$a('OAuth callback preserves saved tenant/user audit context',
    $containsAll($callback, [
        '$pendingAuditOpts',
        '$auditOpts',
        "gustoAudit('payroll.gusto.connect_denied'",
        "gustoAudit('payroll.gusto.connect_state_invalid'",
        "gustoAudit('payroll.gusto.connect_exchange_failed'",
        "gustoAudit('payroll.gusto.connect_persist_failed'",
        "'after' => gustoConnectionAuditRow(\$tenantId, \$connectionId)",
    ]));
$a('manual connect and disconnect snapshot connection rows',
    $containsAll($connect, [
        "gustoAudit('payroll.gusto.connected_manual'",
        "'after' => gustoConnectionAuditRow((int) \$ctx['tenant_id'], \$id)",
        '$before = gustoConnectionAuditRow((int) $ctx[\'tenant_id\'], (int) $conn[\'id\'])',
        '$after = gustoConnectionAuditRow((int) $ctx[\'tenant_id\'], (int) $conn[\'id\'])',
        "gustoAudit('payroll.gusto.disconnected'",
    ]));
$a('Gusto API submit emits tenant-scoped run evidence',
    $containsAll($submit, [
        "gustoAudit('payroll.gusto.run_submitted'",
        "'tenant_id' => (int) \$ctx['tenant_id']",
        "'actor_user_id' => (int) (\$ctx['user']['id'] ?? 0)",
        "'before' => \$run",
        'payrollGustoRunAuditRow((int) $ctx[\'tenant_id\'], $runId)',
    ]));

echo "\nWebhook and Track B\n";
$a('webhook resolves payroll run before/after snapshots',
    $containsAll($webhook, [
        'function _gustoWebhookRunAuditRow(',
        '$beforeRun = $resourceUuid !== \'\' ? _gustoWebhookRunAuditRow($resourceUuid) : null',
        '$afterRun = _gustoWebhookRunAuditRow($resourceUuid)',
        "gustoAudit('payroll.gusto.webhook_received'",
        "'actor_type' => 'system'",
        "'before' => \$beforeRun",
        "'after' => \$afterRun",
    ]));
$a('Track B uses canonical Gusto auth key',
    str_contains($trackB, "['connection' => \$conn]")
    && !str_contains($trackB, "['conn' => \$conn]"));
$a('Track B sync audits are tenant scoped',
    $containsAll($trackB, [
        "gustoAudit('payroll.gusto.employees_synced'",
        "gustoAudit('payroll.gusto.pay_schedules_synced'",
        "gustoAudit('payroll.gusto.compensations_synced'",
        "gustoAudit('payroll.gusto.webhook_subscribed'",
        "'tenant_id' => \$tenantId",
        "'tenant_id' => (int) (\$conn['tenant_id'] ?? 0)",
    ]));

echo "\nContracts and docs\n";
foreach ([
    'payroll.gusto.connected_manual',
    'payroll.gusto.employees_synced',
    'payroll.gusto.pay_schedules_synced',
    'payroll.gusto.compensations_synced',
    'payroll.gusto.webhook_subscribed',
] as $event) {
    $a("manifest declares {$event}", str_contains($manifest, "'{$event}'"));
}
$a('audit governance names Gusto integration evidence',
    str_contains($auditDoc, 'Gusto payroll integration lifecycle events'));
$a('architecture alignment records Gusto audit evidence',
    str_contains($alignment, 'Gusto connection, token refresh, webhook, and Track B sync events'));

echo "\nGusto audit evidence controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
