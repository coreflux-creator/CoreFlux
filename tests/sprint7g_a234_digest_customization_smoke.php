<?php
/**
 * Sprint 7g Phase A.2 + A.3 + A.4 smoke — digest customization.
 *
 * Phase A.2 — per-tenant included_agents picker:
 *   - JSON column added to ai_agent_digest_settings (idempotent migration).
 *   - aiAgentDigestRead returns array of agent keys (or null = all).
 *   - aiAgentDigestWrite accepts array of keys, validates against registry,
 *     deduplicates, persists JSON, treats empty as null.
 *   - aiAgentRunAll honors $onlyKeys filter when included_agents is set.
 *   - UI chip picker with "All agents" pseudo-toggle + per-agent chips.
 *
 * Phase A.3 — subject + intro overrides:
 *   - subject_override + intro_override columns added.
 *   - Reader returns them. Writer trims, length-caps (200 / 1000), rejects
 *     header injection (\r\n) in subject.
 *   - aiAgentBuildDigestHtml uses intro override when non-empty.
 *   - aiAgentDigestSend uses subject override.
 *   - UI inputs wired with maxLength + onBlur autosave.
 *
 * Phase A.4 — week-over-week bucket diff:
 *   - ai_agent_context_snapshots table created.
 *   - aiAgentContextSnapshotWrite + Prior helpers exist (best-effort).
 *   - aiAgentBucketDiff renders "key: prior → current" lines, recurses
 *     one level deep (CFO context wraps books + treasury), skips equal
 *     values, handles missing keys.
 *   - aiAgentDigestSend snapshots after each run + threads diffs into
 *     aiAgentBuildDigestHtml.
 *   - HTML template renders the diff list per-section with safe escaping.
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

echo "Migration — 025_ai_digest_customization.sql\n";
$migPath = "{$ROOT}/core/migrations/025_ai_digest_customization.sql";
$assert('migration file exists',                  is_readable($migPath));
$mig = (string) file_get_contents($migPath);
foreach (['included_agents','subject_override','intro_override'] as $col) {
    $assert("adds {$col} column to ai_agent_digest_settings",
        preg_match("/column_name\\s*=\\s*'{$col}'/", $mig) === 1
        && preg_match("/ADD COLUMN {$col}\\s/", $mig) === 1);
}
$assert('included_agents is JSON column',         strpos($mig, 'included_agents JSON NULL') !== false);
$assert('subject_override capped at 200 chars',   strpos($mig, 'subject_override VARCHAR(200) NULL') !== false);
$assert('intro_override capped at 1000 chars',    strpos($mig, 'intro_override VARCHAR(1000) NULL') !== false);
$assert('every ALTER information_schema-guarded',
    substr_count($mig, '@col_exists = 0') >= 3);
$assert('creates ai_agent_context_snapshots (Phase A.4)',
    strpos($mig, 'CREATE TABLE IF NOT EXISTS ai_agent_context_snapshots') !== false);
$assert('snapshot table has lookup index by tenant+agent+date',
    strpos($mig, 'INDEX idx_tac_lookup (tenant_id, agent_key, snapshot_at)') !== false);
$assert('snapshot context_json is JSON NOT NULL',
    strpos($mig, 'context_json  JSON NOT NULL') !== false);

echo "\nLibrary — Phase A.2 included_agents\n";
$lib = (string) file_get_contents("{$ROOT}/core/ai_agents.php");
$assert('aiAgentDigestRead reads included_agents column',
    strpos($lib, "'SELECT enabled, recipients, send_dow, last_sent_at, last_send_error,") !== false
    && strpos($lib, 'included_agents, subject_override, intro_override') !== false);
$assert('aiAgentDigestRead json_decodes included_agents',
    strpos($lib, "json_decode((string) \$row['included_agents'], true)") !== false);
$assert('aiAgentDigestRead filters stale/unknown agent keys',
    strpos($lib, "array_filter(\$decoded, fn (\$k) => isset(AI_AGENTS[(string) \$k]))") !== false);
$assert('aiAgentDigestWrite validates included_agents is array',
    strpos($lib, "throw new \\InvalidArgumentException('included_agents must be an array')") !== false);
$assert('aiAgentDigestWrite rejects unknown agent keys',
    strpos($lib, "throw new \\InvalidArgumentException('Unknown agent in included_agents: ' . \$k)") !== false);
$assert('aiAgentDigestWrite dedupes via array key map',
    strpos($lib, '$clean[$k] = true; // dedupe') !== false);
$assert('empty included_agents → null (= all)',
    strpos($lib, "if (\$raw === null || \$raw === '' || \$raw === [])") !== false);
$assert('aiAgentRunAll accepts $onlyKeys filter',
    strpos($lib, 'function aiAgentRunAll(int $tenantId, ?int $userId, ?array $onlyKeys = null)') !== false
    && strpos($lib, '$onlyKeys !== null') !== false);

echo "\nLibrary — Phase A.3 subject + intro overrides\n";
$assert('write rejects subject > 200 chars',
    strpos($lib, "throw new \\InvalidArgumentException('subject_override max 200 chars')") !== false);
$assert('write rejects \\r\\n header injection in subject',
    strpos($lib, "preg_match(\"/[\\r\\n]/\", \$subjectOverride)") !== false);
$assert('write rejects intro > 1000 chars',
    strpos($lib, "throw new \\InvalidArgumentException('intro_override max 1000 chars')") !== false);
$assert('aiAgentBuildDigestHtml accepts intro parameter',
    strpos($lib, 'function aiAgentBuildDigestHtml(array $runAllResults, ?string $intro = null') !== false);
$assert('aiAgentBuildDigestHtml htmlspecialchars escapes intro',
    strpos($lib, 'htmlspecialchars($intro !== null && $intro !== \'\' ? $intro : $defaultIntro') !== false);
$assert('aiAgentDigestSend uses subject_override',
    strpos($lib, "\$cfg['subject_override'] ?: 'Your weekly AI Agent digest'") !== false
    && strpos($lib, "'subject'    => \$cfg['subject_override'] ?: 'Your weekly AI Agent digest'") !== false);
$assert('aiAgentDigestSend response surfaces effective subject',
    strpos($lib, "'subject'     => \$subject") !== false);

echo "\nLibrary — Phase A.4 bucket diff + snapshots\n";
$assert('aiAgentContextSnapshotWrite exists',     strpos($lib, 'function aiAgentContextSnapshotWrite(') !== false);
$assert('snapshot writer is best-effort (catches Throwable)',
    preg_match('/function aiAgentContextSnapshotWrite\(.*?catch \(\\\\Throwable \$e\)/s', $lib) === 1);
$assert('aiAgentContextSnapshotPrior reads <cutoff snapshot',
    strpos($lib, 'function aiAgentContextSnapshotPrior(') !== false
    && strpos($lib, "snapshot_at < :c") !== false);
$assert('default cutoff = 6 days ago (matches weekly cadence)',
    strpos($lib, "strtotime('-6 days')") !== false);
$assert('aiAgentBucketDiff exists',               strpos($lib, 'function aiAgentBucketDiff(') !== false);
$assert('aiAgentBucketDiff recurses on nested arrays',
    strpos($lib, 'aiAgentBucketDiff(is_array($pv) ? $pv : [], is_array($cv) ? $cv : [], $label)') !== false);
$assert('aiAgentBuildDigestHtml accepts diffsByAgent',
    strpos($lib, 'function aiAgentBuildDigestHtml(array $runAllResults, ?string $intro = null, array $diffsByAgent = []') !== false);
$assert('digest section renders "Changed since last week"',
    strpos($lib, 'Changed since last week') !== false);
$assert('digest section escapes diff lines via htmlspecialchars',
    preg_match('/htmlspecialchars\(\(string\) \$line, ENT_QUOTES, \'UTF-8\'\)/', $lib) === 1);
$assert('aiAgentDigestSend reads prior contexts BEFORE running',
    strpos($lib, '$priorContexts[$k] = aiAgentContextSnapshotPrior($tenantId, $k)') !== false);
$assert('aiAgentDigestSend writes new snapshot AFTER run',
    strpos($lib, 'aiAgentContextSnapshotWrite($tenantId, $k, $current)') !== false);
$assert('aiAgentDigestSend builds diffs and threads them into the template',
    strpos($lib, '$diffs[$k] = aiAgentBucketDiff($priorContexts[$k], $current)') !== false
    && strpos($lib, 'aiAgentBuildDigestHtml(') !== false
    && strpos($lib, "\$cfg['intro_override']") !== false
    && strpos($lib, '$diffs,') !== false);
$assert('aiAgentDigestSend honors included_agents (Phase A.2)',
    strpos($lib, "\$onlyKeys = \$cfg['included_agents']; // null → run all") !== false
    && strpos($lib, 'aiAgentRunAll($tenantId, $userId, $onlyKeys)') !== false);

echo "\nUI — AIAgents.jsx digest customization\n";
$pg = (string) file_get_contents("{$ROOT}/dashboard/src/pages/AIAgents.jsx");
$assert('digest state includes included_agents/subject/intro defaults',
    strpos($pg, 'included_agents: null, subject_override: null, intro_override: null') !== false);
$assert('included picker root testid',            strpos($pg, 'data-testid="ai-agents-digest-included-picker"') !== false);
$assert('"All agents" toggle testid',             strpos($pg, 'data-testid="ai-agents-digest-include-all"') !== false);
$assert('per-agent include chip testid template',
    strpos($pg, 'data-testid={`ai-agents-digest-include-${a.key}`}') !== false);
$assert('toggling "All" sends included_agents:null',
    strpos($pg, 'onChange={() => setDigest({ included_agents: null })}') !== false);
$assert('per-chip toggle adds/removes from list',
    strpos($pg, "next.length === allAgentKeys.length ? null : next") !== false);
$assert('subject override input testid',          strpos($pg, 'data-testid="ai-agents-digest-subject-override"') !== false);
$assert('subject override maxLength=200',         strpos($pg, 'maxLength={200}') !== false);
$assert('intro override textarea testid',         strpos($pg, 'data-testid="ai-agents-digest-intro-override"') !== false);
$assert('intro override maxLength=1000',          strpos($pg, 'maxLength={1000}') !== false);
$assert('subject autosave on blur',
    strpos($pg, "if (v !== (digest.subject_override || '')) setDigest({ subject_override: v })") !== false);
$assert('intro autosave on blur',
    strpos($pg, "if (v !== (digest.intro_override || '')) setDigest({ intro_override: v })") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
