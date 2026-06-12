<?php
/**
 * AI workflow approval controls smoke test.
 *
 * Locks the AI-native alignment rule that approval decisions are governed
 * state changes. Audit viewers can inspect workflow runs, but they cannot
 * approve, reject, or cancel workflow approvals unless they also hold approval
 * authority.
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}\n";
    }
};

$root = dirname(__DIR__);
$api = (string) file_get_contents($root . '/api/ai/workflows.php');
$engine = (string) file_get_contents($root . '/core/ai/workflows/engine.php');
$rbac = (string) file_get_contents($root . '/core/rbac/legacy_map.php');
$alignment = (string) file_get_contents($root . '/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');

echo "AI workflow approval API gate\n";
$a('decide helper exists', str_contains($api, 'function aiWorkflowCanDecideApproval(array $user): bool'));
$a('decide helper accepts explicit AI approval permission', str_contains($api, "rbac_legacy_can(\$user, 'ai.workflow.approve')"));
$a('decide helper accepts domain approval compatibility', str_contains($api, "rbac_legacy_can(\$user, 'accounting.approve')"));
$a('decide helper accepts platform AI admin', str_contains($api, "rbac_legacy_can(\$user, 'platform.ai.admin')"));
$a('decide endpoint uses helper before body parsing', strpos($api, 'if (!aiWorkflowCanDecideApproval($user))') !== false
    && strpos($api, 'if (!aiWorkflowCanDecideApproval($user))') < strpos($api, '$body = api_json_body();'));
$a('decide endpoint returns required_any permissions', str_contains($api, "'required_any' => ['ai.workflow.approve', 'accounting.approve', 'platform.ai.admin']"));
$a('decide endpoint no longer requires audit read permission', !str_contains($api, "if (\$method === 'POST' && \$action === 'decide_approval') {\n    rbac_legacy_require(\$user, 'ai.audit.view');"));

echo "\nRBAC bridge\n";
$a('ai.gateway.invoke has explicit write map', str_contains($rbac, "'ai.gateway.invoke'                  => ['ai', 'write']"));
$a('ai.workflow.approve has explicit admin map', str_contains($rbac, "'ai.workflow.approve'                => ['ai', 'admin']"));
$a('accounting.approve remains accounting admin compatibility', str_contains($rbac, "'accounting.approve'                  => ['accounting', 'admin']"));

echo "\nWorkflow decision audit\n";
$a('engine declares AI workflow audit helper', str_contains($engine, 'function _aiWorkflowAuditEvent('));
$a('audit helper prefers actor_user_id', str_contains($engine, '(tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)'));
$a('audit helper falls back to user_id', str_contains($engine, '(tenant_id, user_id, event, target_id, meta_json, ip_address, created_at)'));
$a('decision writes ai.workflow.approval_* event', str_contains($engine, '"ai.workflow.approval_{$decision}"'));
$a('decision audit includes workflow_run_id', str_contains($engine, "'workflow_run_id' => (string) \$row['workflow_run_id']"));
$a('decision audit avoids storing full payload copy', str_contains($engine, "'decision_payload_keys' => array_values(array_keys(\$decisionPayload))"));

echo "\nArchitecture alignment\n";
$a('alignment states approval decisions are state-changing', str_contains($alignment, 'AI workflow approval decisions are state-changing approval actions'));
$a('alignment names required permissions', str_contains($alignment, '`ai.workflow.approve`, `accounting.approve`, or `platform.ai.admin`'));
$a('alignment names audit event pattern', str_contains($alignment, '`ai.workflow.approval_*` audit event'));

echo "\nSyntax checks\n";
foreach ([
    'api/ai/workflows.php',
    'core/ai/workflows/engine.php',
    'core/rbac/legacy_map.php',
] as $file) {
    $out = [];
    $rc = 0;
    @exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $out, $rc);
    $a("{$file} parses", $rc === 0);
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
