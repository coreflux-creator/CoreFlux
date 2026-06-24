<?php
/**
 * Workflow audit governance smoke.
 *
 * Verifies WorkflowEngine emits canonical platform audit evidence for
 * decisions, state transitions, and People Graph routing through the
 * shared platform audit writer, without requiring a database.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
require_once "{$ROOT}/core/workflow_engine.php";

$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};
$containsAll = function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, (string) $needle)) return false;
    }
    return true;
};

$src = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$auditDoc = (string) file_get_contents("{$ROOT}/docs/AUDIT_GOVERNANCE.md");
$alignment = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Workflow audit writer\n";
$out = [];
$rc = 0;
exec('php -l ' . escapeshellarg("{$ROOT}/core/workflow_engine.php") . ' 2>&1', $out, $rc);
$a('workflow_engine.php parses', $rc === 0);
$a('workflow actions emit platform audit events',
    str_contains($src, '"workflow.action.{$action}"')
    && str_contains($src, 'comment_present')
    && str_contains($src, 'delegated_to_user_id'));
$a('audit writer delegates to shared platform helper',
    $containsAll($src, ['function _workflowAuditEvent', 'platformAuditLogWrite(']));
$a('canonical actor/object/request fields are written when available',
    $containsAll($src, [
        "actor_user_id",
        "actor_type",
        "actor_email",
        "object_type",
        "before_json",
        "after_json",
        "request_id",
        "source",
        "user_agent",
    ]));
$a('legacy audit schema compatibility is centralized',
    str_contains($src, "require_once __DIR__ . '/audit.php';"));
$a('state transitions include before/after snapshots',
    $containsAll($src, ["'workflow.started'", "'workflow.advanced'", "'workflow.completed'", "'before_json'", "'after_json'"]));
$a('People Graph resolution audit includes object and source',
    $containsAll($src, ["'workflow.people_graph_resolved'", "'object' => \$object", "'source' => 'workflow'"]));

echo "\nRequest id helper\n";
$_SERVER['HTTP_X_REQUEST_ID'] = 'req_from_header';
$a('payload request id wins', _workflowRequestId(['request_id' => 'req_from_payload']) === 'req_from_payload');
$a('context request id is supported', _workflowRequestId(['context' => ['request_id' => 'req_from_context']]) === 'req_from_context');
$a('header request id fallback works', _workflowRequestId([]) === 'req_from_header');
$a('non-array context is quiet', _workflowRequestId(['context' => 'not-an-array']) === 'req_from_header');

echo "\nDocs\n";
$a('audit doc describes workflow evidence',
    $containsAll($auditDoc, ['Workflow Evidence', 'workflow.action.{action}', 'workflow.people_graph_resolved']));
$a('alignment doc describes canonical workflow audit fields',
    $containsAll($alignment, ['WorkflowEngine action decisions', 'canonical audit fields', 'before/after state']));

echo "\nWorkflow audit governance smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
