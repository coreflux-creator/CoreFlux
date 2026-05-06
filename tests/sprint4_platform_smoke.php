<?php
/**
 * Sprint 4 — Platform polish (A1 WorkflowEngine + A2 Approval tokens +
 *            A3 Audit-log API + B1 Active-entity + AI risk explainer +
 *            E1 foreman sweep) — static smoke.
 *
 *   php -d zend.assertions=1 /app/tests/sprint4_platform_smoke.php
 *
 * Verifies:
 *   1. WorkflowEngine migration tables + columns.
 *   2. workflow_engine.php exports all public functions + parses.
 *   3. Approval-tokens migration + lib + sha256-hashing.
 *   4. AI risk explainer exists, is best-effort (returns "" without API).
 *   5. Active-entity helpers + API.
 *   6. Audit-log API exposes search + CSV export + tenant-scoped.
 *   7. Foreman sweep — no source files contain "foreman" / "crew sheet".
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/approval_tokens.php';

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};

echo "A1 — WorkflowEngine migration\n";
$wfSql = (string) file_get_contents(__DIR__ . '/../core/migrations/019_workflow_engine.sql');
$assert('migration exists', strlen($wfSql) > 0);
foreach (['workflow_definitions','workflow_instances','workflow_step_actions'] as $t) {
    $assert("CREATE TABLE {$t}", stripos($wfSql, "CREATE TABLE IF NOT EXISTS {$t}") !== false);
}
foreach (['def_key','steps_json','subject_type','subject_id','current_step','sla_due_at','payload_json'] as $c) {
    $assert("workflow_instances.{$c}", stripos($wfSql, $c) !== false);
}
$assert("status enum complete", stripos($wfSql, "ENUM('pending','approved','rejected','cancelled','escalated','expired')") !== false);
$assert("action enum complete", stripos($wfSql, "ENUM('approve','reject','skip','delegate','comment','escalate')") !== false);
$assert("via enum",             stripos($wfSql, "ENUM('app','email','api','system')") !== false);

echo "\nA1 — workflow_engine.php\n";
$src = (string) file_get_contents(__DIR__ . '/../core/workflow_engine.php');
foreach (['workflowDefine','workflowStart','workflowAct','workflowGetPendingForUser','workflowGetInstance',
          '_workflowComplete','_workflowPushApprovers','_workflowAuditEvent'] as $fn) {
    $assert("exports {$fn}", stripos($src, "function {$fn}(") !== false);
}
$assert('parses', $lint(__DIR__ . '/../core/workflow_engine.php'));
$assert('audits to audit_log', stripos($src, 'INSERT INTO audit_log') !== false);
$assert('fires push to approvers', stripos($src, 'pushSendToUser(') !== false);
$assert('idempotent start (returns prior pending)', stripos($src, 'workflow_instances') !== false && stripos($src, 'WHERE i.tenant_id') !== false);
$assert('versioned definitions',  stripos($src, "SELECT id, version FROM workflow_definitions") !== false);

echo "\nA2 — Approval-tokens primitive\n";
$atSql = (string) file_get_contents(__DIR__ . '/../core/migrations/020_approval_tokens.sql');
$assert('migration exists',         strlen($atSql) > 0);
$assert('CREATE approval_tokens',   stripos($atSql, "CREATE TABLE IF NOT EXISTS approval_tokens") !== false);
foreach (['token_hash','subject_type','subject_id','workflow_instance_id','actor_user_id','actor_email','actions_json','expires_at','consumed_at'] as $c) {
    $assert("approval_tokens.{$c}", stripos($atSql, $c) !== false);
}
$atSrc = (string) file_get_contents(__DIR__ . '/../core/approval_tokens.php');
foreach (['approvalTokenIssue','approvalTokenLookup','approvalTokenConsume'] as $fn) {
    $assert("exports {$fn}", stripos($atSrc, "function {$fn}(") !== false);
}
$assert('parses',                   $lint(__DIR__ . '/../core/approval_tokens.php'));
$assert('sha256-hashes raw token',  stripos($atSrc, "hash('sha256', ") !== false);
$assert('uses random_bytes for entropy', stripos($atSrc, 'random_bytes(') !== false);

echo "\nAI risk explainer\n";
$reSrc = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/risk_explainer.php');
$assert('exists',  strlen($reSrc) > 0);
$assert('exports apExplainRisk', stripos($reSrc, 'function apExplainRisk(') !== false);
$assert('parses',                $lint(__DIR__ . '/../modules/ap/lib/risk_explainer.php'));
$assert('best-effort try/catch wrap', stripos($reSrc, "catch (\\Throwable") !== false);
$assert('returns "" on no factors',   stripos($reSrc, "return '';") !== false);
$assert('factor map covers all 6 rules',
    stripos($reSrc, 'new_vendor') !== false
    && stripos($reSrc, 'bank_account_change') !== false
    && stripos($reSrc, 'missing_w9') !== false
    && stripos($reSrc, 'missing_coi') !== false
    && stripos($reSrc, 'high_volume') !== false
    && stripos($reSrc, 'sanctions_match') !== false);

$routerSrc = (string) file_get_contents(__DIR__ . '/../modules/ap/lib/approval_router.php');
$assert('approval_router calls apExplainRisk', stripos($routerSrc, 'apExplainRisk(') !== false);
$assert('approval_router appends explanation to push body', stripos($routerSrc, '$body .= "\n\n" . $aiExplain;') !== false);

echo "\nB1 — Active-entity\n";
$aeSrc = (string) file_get_contents(__DIR__ . '/../core/active_entity.php');
foreach (['activeEntityGet','activeEntitySet','activeEntityAvailable'] as $fn) {
    $assert("exports {$fn}", stripos($aeSrc, "function {$fn}(") !== false);
}
$assert('parses', $lint(__DIR__ . '/../core/active_entity.php'));
$assert('reads accounting_entities', stripos($aeSrc, 'FROM accounting_entities') !== false);

$aeApi = (string) file_get_contents(__DIR__ . '/../api/active_entity.php');
$assert('API parses', $lint(__DIR__ . '/../api/active_entity.php'));
$assert('API GET returns entities + active', stripos($aeApi, 'activeEntityAvailable(') !== false);
$assert('API POST sets active',              stripos($aeApi, 'activeEntitySet(') !== false);

echo "\nA3 — Audit-log API\n";
$alApi = (string) file_get_contents(__DIR__ . '/../api/audit_log.php');
$assert('API parses',           $lint(__DIR__ . '/../api/audit_log.php'));
$assert('tenant-scoped where',  stripos($alApi, 'tenant_id = :t') !== false);
$assert('event filter',         stripos($alApi, "event LIKE :e") !== false);
$assert('user filter',          stripos($alApi, "user_id = :u") !== false);
$assert('date range filters',   stripos($alApi, 'created_at >= :f') !== false && stripos($alApi, 'created_at < :to') !== false);
$assert('CSV format support',   stripos($alApi, "format === 'csv'") !== false);
$assert('CSV header includes meta', stripos($alApi, "'meta'") !== false);
$assert('admin-only role guard', stripos($alApi, "['master_admin', 'tenant_admin', 'admin']") !== false);

echo "\nE1 — Foreman sweep\n";
$grepCmd = "cd " . escapeshellarg(__DIR__ . '/..') . " && grep -rln --include='*.php' --include='*.jsx' --include='*.js' --include='*.tsx' "
         . "-e 'foreman' -e 'crew sheet' -e 'crew_sheet' -e 'crew sign' "
         . "modules dashboard/src api spa-assets 2>/dev/null | grep -v -E '(memory|tests)' || true";
$o = trim((string) shell_exec($grepCmd));
$assert('no remaining "foreman" / "crew sheet" in source files', $o === '', "found:\n{$o}");

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
