<?php
/**
 * Sprint 7g follow-ons smoke (Slice 2 + Slice 3 + on-demand digest).
 *
 * Asserts:
 *   - Migration 023_ai_agent_settings.sql shape (idempotent, two tables,
 *     enum mode without auto_apply, dow + idempotency columns).
 *   - core/ai_agents.php exports mode + digest helpers.
 *   - aiAgentRunWithMode handles auto_log via UPDATE on ai_suggestions.
 *   - aiAgentDigestSend uses cf_tenant_mail_sender + sendEmail; honest
 *     fallback recipient = tenant master_admin.
 *   - aiAgentBuildDigestHtml escapes user content (no XSS in email body).
 *   - api/ai_agents.php exposes 4 new actions: mode_set, digest_settings_set,
 *     digest_send_now, list (now returns mode + digest + modes catalog).
 *   - All write actions gated by `ai.config.manage` permission.
 *   - scripts/ai_agents_weekly_digest.php — DOW-gated + 6-day idempotency.
 *   - AIAgents.jsx renders digest panel with Send-now + auto-send checkbox +
 *     DOW dropdown + recipients input + last-sent stamp + per-agent mode
 *     dropdown.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration — 023_ai_agent_settings.sql\n";
$mig = (string) file_get_contents("{$ROOT}/core/migrations/023_ai_agent_settings.sql");
$assert('migration exists',                       strlen($mig) > 0);
$assert('idempotent CREATE for ai_agent_settings',
    strpos($mig, 'CREATE TABLE IF NOT EXISTS ai_agent_settings') !== false);
$assert('mode enum is exactly advisory|auto_log (no auto_apply)',
    strpos($mig, "ENUM('advisory','auto_log')") !== false
    && stripos($mig, "ENUM('advisory','auto_log','auto_apply')") === false
    && stripos($mig, "ENUM('advisory','auto_apply'") === false);
$assert('per-tenant unique on agent_key',
    strpos($mig, 'UNIQUE KEY uk_tenant_agent (tenant_id, agent_key)') !== false);
$assert('idempotent CREATE for ai_agent_digest_settings',
    strpos($mig, 'CREATE TABLE IF NOT EXISTS ai_agent_digest_settings') !== false);
$assert('digest table keyed on tenant_id (1 row per tenant)',
    strpos($mig, 'PRIMARY KEY (tenant_id)') !== false);
$assert('digest send_dow + last_sent_at + last_send_error columns',
    strpos($mig, 'send_dow           TINYINT NOT NULL DEFAULT 1') !== false
    && strpos($mig, 'last_sent_at       TIMESTAMP NULL DEFAULT NULL') !== false
    && strpos($mig, 'last_send_error    VARCHAR(500)') !== false);

echo "\nLibrary — core/ai_agents.php (mode + digest helpers)\n";
$libPath = "{$ROOT}/core/ai_agents.php";
$lib = (string) file_get_contents($libPath);
$assert('parses',                                 $lint($libPath));
$assert('AI_AGENT_MODES constant',                strpos($lib, "AI_AGENT_MODES = ['advisory', 'auto_log']") !== false);
$assert('aiAgentModeRead exists',                 strpos($lib, 'function aiAgentModeRead(') !== false);
$assert('aiAgentModeReadAll exists',              strpos($lib, 'function aiAgentModeReadAll(') !== false);
$assert('aiAgentModeWrite rejects unknown agent', strpos($lib, "throw new \\InvalidArgumentException('Unknown agent: ' . \$agentKey)") !== false);
$assert('aiAgentModeWrite rejects unknown mode',
    strpos($lib, "throw new \\InvalidArgumentException('Invalid mode: ' . \$mode)") !== false);
$assert('aiAgentModeWrite uses ON DUPLICATE KEY UPDATE (race-safe)',
    strpos($lib, 'ON DUPLICATE KEY UPDATE mode = VALUES(mode)') !== false);

$assert('aiAgentRunWithMode exists',              strpos($lib, 'function aiAgentRunWithMode(') !== false);
$assert('aiAgentRunWithMode auto-accepts on auto_log',
    strpos($lib, "review_status = 'accepted'") !== false
    && strpos($lib, "WHERE tenant_id = :t AND interaction_id = :iid") !== false);
$assert('aiAgentRunWithMode auto-log is best-effort (catch Throwable)',
    preg_match('/auto_log.*?catch \(\\\\?Throwable/s', $lib) === 1);

echo "\nDigest builder + sender\n";
$assert('aiAgentRunAll iterates registry order',   strpos($lib, '$keys = array_keys(AI_AGENTS);') !== false);
$assert('aiAgentRunAll captures per-agent error', strpos($lib, "'ok' => false, 'error' => \$e->getMessage()") !== false);
$assert('aiAgentBuildDigestHtml exists',          strpos($lib, 'function aiAgentBuildDigestHtml(') !== false);
$assert('digest HTML escapes labels with htmlspecialchars',
    strpos($lib, "htmlspecialchars(\$agent['label'], ENT_QUOTES, 'UTF-8')") !== false);
$assert('digest HTML escapes body content with htmlspecialchars',
    strpos($lib, "nl2br(htmlspecialchars(\$body, ENT_QUOTES, 'UTF-8'))") !== false);
$assert('digest produces both html + text bodies',
    strpos($lib, "return ['html' => \$html, 'text' => \$textOut]") !== false
    || strpos($lib, "return ['html' => \$html, 'text' => implode(") !== false);
$assert('aiAgentDigestRead returns sane defaults when no row',
    strpos($lib, "'enabled' => false, 'recipients' => null, 'send_dow' => 1") !== false);
$assert('aiAgentDigestWrite validates emails via FILTER_VALIDATE_EMAIL',
    strpos($lib, 'filter_var($email, FILTER_VALIDATE_EMAIL)') !== false);
$assert('aiAgentDigestWrite clamps send_dow to 1..7',
    strpos($lib, "if (\$sendDow < 1 || \$sendDow > 7)") !== false);
$assert('aiAgentDigestRecipients falls back to master_admin',
    strpos($lib, "WHERE ut.tenant_id = :t AND ut.role = 'master_admin'") !== false);
$assert('aiAgentDigestSend uses cf_tenant_mail_sender (tenant Resend pipeline)',
    strpos($lib, "\$sender   = cf_tenant_mail_sender(\$tenantId, 'ai_agents')") !== false
    || strpos($lib, "\$sender = cf_tenant_mail_sender(\$tenantId, 'ai_agents')") !== false);
$assert('aiAgentDigestSend forwards reply_to/from from sender helper',
    strpos($lib, "'reply_to'   => \$sender['reply_to']") !== false);
$assert('aiAgentDigestSend bumps last_sent_at + records send_errors',
    strpos($lib, 'last_sent_at = NOW(), last_send_error = VALUES(last_send_error)') !== false
    || strpos($lib, 'last_sent_at = NOW(), last_send_error = NULL') !== false);
$assert('aiAgentDigestSend throws when no recipients available',
    strpos($lib, 'No digest recipients configured') !== false);

echo "\nAPI — api/ai_agents.php new actions\n";
$apiPath = "{$ROOT}/api/ai_agents.php";
$api = (string) file_get_contents($apiPath);
$assert('parses',                                 $lint($apiPath));
$assert('list returns modes catalog + digest config',
    strpos($api, "'modes'         => AI_AGENT_MODES") !== false
    && strpos($api, "'digest'        => aiAgentDigestRead(\$tid)") !== false
    && strpos($api, "'mode'        => \$modes[\$key]") !== false);
$assert('mode_set action POST + ai.config.manage perm',
    strpos($api, "if (\$action === 'mode_set')") !== false
    && preg_match('/mode_set.*?rbac_legacy_require\\(\\$user, .ai\\.config\\.manage.\\)/s', $api) === 1);
$assert('digest_settings_set action POST + ai.config.manage perm',
    strpos($api, "if (\$action === 'digest_settings_set')") !== false
    && preg_match('/digest_settings_set.*?rbac_legacy_require\\(\\$user, .ai\\.config\\.manage.\\)/s', $api) === 1);
$assert('digest_send_now action POST + ai.config.manage perm',
    strpos($api, "if (\$action === 'digest_send_now')") !== false
    && preg_match('/digest_send_now.*?rbac_legacy_require\\(\\$user, .ai\\.config\\.manage.\\)/s', $api) === 1);
$assert('digest_send_now persists last_send_error on failure',
    strpos($api, 'INSERT INTO ai_agent_digest_settings (tenant_id, last_send_error)') !== false);
$assert('digest_send_now AIDisabled→503',         preg_match('/digest_send_now.*?AIDisabledException.*?api_error\\(\\$e->getMessage\\(\\), 503\\)/s', $api) === 1);
$assert('run action upgraded to aiAgentRunWithMode',
    strpos($api, '$envelope = aiAgentRunWithMode($tid, $user[\'id\'] ?? null, $agentKey)') !== false);

echo "\nWeekly cron — scripts/ai_agents_weekly_digest.php\n";
$cronPath = "{$ROOT}/scripts/ai_agents_weekly_digest.php";
$cron = (string) file_get_contents($cronPath);
$assert('parses',                                 $lint($cronPath));
$assert('reads DOW from date(N)',                 strpos($cron, "(int) date('N')") !== false);
$assert('only fires for enabled tenants',         strpos($cron, 'WHERE enabled = 1') !== false);
$assert('only fires for tenants matching today\'s DOW', strpos($cron, 'AND send_dow = :d') !== false);
$assert('6-day idempotency window (no double-send)',
    strpos($cron, 'last_sent_at < DATE_SUB(NOW(), INTERVAL 6 DAY)') !== false);
$assert('persists per-tenant errors to last_send_error',
    strpos($cron, "ON DUPLICATE KEY UPDATE last_send_error = VALUES(last_send_error)") !== false);
$assert('exit code reflects failures',            strpos($cron, 'exit($failed > 0 ? 1 : 0)') !== false);

echo "\nUI — AIAgents.jsx digest panel + per-agent mode\n";
$pgPath = "{$ROOT}/dashboard/src/pages/AIAgents.jsx";
$pg = (string) file_get_contents($pgPath);
$assert('digest card testid',                     strpos($pg, 'data-testid="ai-agents-digest-card"') !== false);
$assert('Send-now button testid',                 strpos($pg, 'data-testid="ai-agents-digest-send-now"') !== false);
$assert('Auto-send checkbox testid',              strpos($pg, 'data-testid="ai-agents-digest-enabled"') !== false);
$assert('DOW dropdown testid',                    strpos($pg, 'data-testid="ai-agents-digest-dow"') !== false);
$assert('Recipients input testid',                strpos($pg, 'data-testid="ai-agents-digest-recipients"') !== false);
$assert('Last sent stamp testid',                 strpos($pg, 'data-testid="ai-agents-digest-last-sent"') !== false);
$assert('Last error stamp testid',                strpos($pg, 'data-testid="ai-agents-digest-last-error"') !== false);
$assert('per-agent mode dropdown testid template', strpos($pg, 'data-testid={`ai-agents-mode-${agent.key}`}') !== false);
$assert('mode options: advisory + auto_log',
    strpos($pg, '<option value="advisory">') !== false
    && strpos($pg, '<option value="auto_log">') !== false);
$assert('Send-now POSTs digest_send_now',          strpos($pg, "'/api/ai_agents.php?action=digest_send_now'") !== false);
$assert('mode change POSTs mode_set',              strpos($pg, "'/api/ai_agents.php?action=mode_set'") !== false);
$assert('digest toggle POSTs digest_settings_set', strpos($pg, "'/api/ai_agents.php?action=digest_settings_set'") !== false);
$assert('all 7 days in DOW dropdown',
    substr_count($pg, '<option value={1}>') === 1
    && substr_count($pg, '<option value={7}>') === 1);
$assert('reload from useApi hook (matches lib API)', strpos($pg, '{ data, loading, error, reload }') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
