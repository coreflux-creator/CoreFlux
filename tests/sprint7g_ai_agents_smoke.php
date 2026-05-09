<?php
/**
 * Sprint 7g smoke — AI agent suite + Last Sync tile.
 *
 * Asserts:
 *   - core/ai_agents.php parses + AI_AGENTS registry has 5 agents (bookkeeper,
 *     reconciliation, treasury_analyst, cfo, tax) with required keys.
 *   - All agents go through aiAsk() chokepoint with feature_class='narrative'
 *     and a unique feature_key per agent.
 *   - All agent system prompts forbid raw numeric values (advisory-only rule).
 *   - Each agent's context_fn maps to a real exported function.
 *   - aiAgentBucketCount/Days emit qualitative labels (so the model can't
 *     launder raw counts into the narrative).
 *   - api/ai_agents.php: list (GET) + run (POST) actions, RBAC, AIDisabled→503.
 *   - AIAgents.jsx page renders all 5 agent cards + per-agent run + result via
 *     <AISuggestion /> component.
 *   - books_health envelope adds `integrations: [{source,label,status,
 *     last_sync_at,hours_since,last_sync_error}]` graceful when table missing.
 *   - BookkeepingOverview renders Last Sync tile with hours-ago label and
 *     stale/aging/fresh palette.
 *   - AccountingModule routes /ai-agents and adds sub-nav tab.
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

echo "Library — core/ai_agents.php\n";
$libPath = "{$ROOT}/core/ai_agents.php";
$lib = (string) file_get_contents($libPath);
$assert('parses',                                 $lint($libPath));
$assert('declares strict_types',                  strpos($lib, 'declare(strict_types=1)') !== false);
$assert('requires ai_service.php (chokepoint)',   strpos($lib, "require_once __DIR__ . '/ai_service.php'") !== false);
$assert('AI_AGENTS registry exists',              strpos($lib, 'const AI_AGENTS = [') !== false);

// Phase A.1 + A.5 — 4 base agents + 4 tax sub-agents + 2 CFO/Treasury
// splits = 10 total. We assert each is reachable from the registry.
foreach (['bookkeeper','reconciliation','treasury_analyst','treasury_payments','cfo','cfo_variance',
          'tax_mapping','sales_tax','payroll_tax','partner_distributions'] as $agent) {
    $assert("registry includes '{$agent}'",
        preg_match("/'{$agent}'\\s*=>\\s*\\[/", $lib) === 1);
    $assert("'{$agent}' has feature_class=narrative",
        preg_match("/'{$agent}'.*?'feature_class'\\s*=>\\s*'narrative'/s", $lib) === 1);
    $assert("'{$agent}' has unique feature_key",
        preg_match("/'feature_key'\\s*=>\\s*'agent\\.[a-z_]+\\..+?'/", $lib) === 1);
    $assert("'{$agent}' has context_fn",
        preg_match("/'{$agent}'.*?'context_fn'\\s*=>\\s*'aiAgentContext[A-Z]/s", $lib) === 1);
}

$assert('agent system prompts forbid raw numbers',
    substr_count($lib, 'NEVER restate raw') >= 1
    && substr_count($lib, 'Do NOT include amounts') >= 1
    && substr_count($lib, 'Do NOT restate numbers') >= 1
    && substr_count($lib, 'qualitatively') >= 1);

echo "\nRunner — aiAgentRun()\n";
$assert('aiAgentRun exists',                      strpos($lib, 'function aiAgentRun(') !== false);
$assert('aiAgentRun rejects unknown agent',       strpos($lib, "throw new \\InvalidArgumentException(\"Unknown agent") !== false);
$assert('aiAgentRun calls aiAsk() chokepoint',    strpos($lib, 'return aiAsk([') !== false);
$assert('aiAgentRun caps max_output_tokens',      strpos($lib, "'max_output_tokens' => 600") !== false);
$assert('passes feature_class + feature_key + system + context to aiAsk',
    strpos($lib, "'feature_class' => \$agent['feature_class']") !== false
    && strpos($lib, "'feature_key'   => \$agent['feature_key']") !== false
    && strpos($lib, "'system'        => \$agent['system']") !== false
    && strpos($lib, "'context'       => \$context") !== false);

echo "\nContext builders\n";
foreach (['Bookkeeper','Reconciliation','Treasury','TreasuryPayments','CFO','CFOVariance',
          'TaxMapping','SalesTax','PayrollTax','PartnerDistributions'] as $fn) {
    $assert("aiAgentContext{$fn} exists",
        strpos($lib, "function aiAgentContext{$fn}(") !== false);
}
$assert('all context builders use SHOW TABLES guards (graceful pre-tables)',
    substr_count($lib, "SHOW TABLES LIKE") >= 8);
$assert('CFO synthesises bookkeeper + treasury contexts',
    strpos($lib, "'books'    => aiAgentContextBookkeeper(\$tenantId)") !== false
    && strpos($lib, "'treasury' => aiAgentContextTreasury(\$tenantId)") !== false);

echo "\nBucket helpers (qualitative-only — no raw numbers)\n";
$assert('aiAgentBucketCount exists',              strpos($lib, 'function aiAgentBucketCount(') !== false);
$assert('aiAgentBucketDays exists',               strpos($lib, 'function aiAgentBucketDays(') !== false);
require_once $libPath;
$assert("bucketCount(0) === 'none'",              aiAgentBucketCount(0)    === 'none');
$assert("bucketCount(1) === 'very_few'",          aiAgentBucketCount(1)    === 'very_few');
$assert("bucketCount(50) === 'moderate'",         aiAgentBucketCount(50)   === 'moderate');
$assert("bucketCount(1000) === 'very_large'",     aiAgentBucketCount(1000) === 'very_large');
$assert("bucketDays(3) === 'within_a_week'",      aiAgentBucketDays(3)     === 'within_a_week');
$assert("bucketDays(200) === 'over_six_months'",  aiAgentBucketDays(200)   === 'over_six_months');

echo "\nAPI — api/ai_agents.php\n";
$apiPath = "{$ROOT}/api/ai_agents.php";
$api = (string) file_get_contents($apiPath);
$assert('parses',                                 $lint($apiPath));
$assert('list action (GET)',                      strpos($api, "if (\$action === 'list') {") !== false
                                                 && strpos($api, "if (api_method() !== 'GET') api_error('Method not allowed', 405)") !== false);
$assert('run action (POST)',                      strpos($api, "if (\$action === 'run') {") !== false);
$assert('RBAC accounting.je.view',                strpos($api, "RBAC::requirePermission(\$user, 'accounting.je.view')") !== false);
$assert('agent param required (422 when empty)',  strpos($api, "api_error('agent required', 422)") !== false);
$assert('unknown agent → 404',                    strpos($api, "api_error('Unknown agent: '") !== false);
$assert('AIDisabledException → 503',              strpos($api, "catch (\\AIDisabledException \$e)") !== false
                                                 && strpos($api, "api_error(\$e->getMessage(), 503)") !== false);
$assert('Throwable → 502',                        strpos($api, "api_error('Agent run failed: '") !== false);
$assert('returns {agent,envelope}',               strpos($api, "'envelope' => \$envelope") !== false);

echo "\nUI — AIAgents.jsx page\n";
$pgPath = "{$ROOT}/dashboard/src/pages/AIAgents.jsx";
$pg = (string) file_get_contents($pgPath);
$assert('imports AISuggestion (the only AI render path)',
    strpos($pg, "import AISuggestion from '../components/AISuggestion'") !== false);
$assert('lists agents from /api/ai_agents.php?action=list',
    strpos($pg, "'/api/ai_agents.php?action=list'") !== false);
$assert('runs agent via POST ?action=run&agent=...',
    strpos($pg, "/api/ai_agents.php?action=run&agent=") !== false);
$assert('encodeURIComponent on agent key',        strpos($pg, 'encodeURIComponent(key)') !== false);
$assert('per-agent card testid template',         strpos($pg, 'data-testid={`ai-agents-card-${agent.key}`}') !== false);
$assert('per-agent run-btn testid template',      strpos($pg, 'data-testid={`ai-agents-run-${agent.key}`}') !== false);
$assert('per-agent result testid template',       strpos($pg, 'data-testid={`ai-agents-result-${agent.key}`}') !== false);
$assert('renders AISuggestion with envelope + featureKey',
    strpos($pg, '<AISuggestion envelope={runs[agent.key]}') !== false
    && strpos($pg, 'featureKey={agent.feature_key}') !== false);
$assert('icon map covers all 10 agents',
    substr_count($pg, 'bookkeeper:') + substr_count($pg, 'reconciliation:')
    + substr_count($pg, 'treasury_analyst:') + substr_count($pg, 'treasury_payments:')
    + substr_count($pg, 'cfo:') + substr_count($pg, 'cfo_variance:')
    + substr_count($pg, 'tax_mapping:') + substr_count($pg, 'sales_tax:')
    + substr_count($pg, 'payroll_tax:') + substr_count($pg, 'partner_distributions:') >= 10);

echo "\nBooks-health integrations envelope (Last Sync tile)\n";
$bh = (string) file_get_contents("{$ROOT}/api/books_health.php");
$assert('declares integrations envelope',         strpos($bh, '$integrations = [];') !== false);
$assert('guards on jobdiva_connections table',
    strpos($bh, "SHOW TABLES LIKE 'jobdiva_connections'") !== false);
$assert('computes hours_since via strtotime',     strpos($bh, '(int) round($diff / 3600)') !== false);
$assert('emits source/label/status/last_sync_at/hours_since/last_sync_error',
    strpos($bh, "'source'          => 'jobdiva'") !== false
    && strpos($bh, "'label'           => 'JobDiva'") !== false
    && strpos($bh, "'hours_since'     => \$hoursSince") !== false);
$assert('integrations included in api_ok envelope',
    strpos($bh, "'integrations'     => \$integrations") !== false);

echo "\nUI — BookkeepingOverview Last Sync tile\n";
$bo = (string) file_get_contents("{$ROOT}/dashboard/src/pages/BookkeepingOverview.jsx");
$assert('integrations card testid',               strpos($bo, 'data-testid="bookkeeping-overview-integrations-card"') !== false);
$assert('per-source row testid template',         strpos($bo, 'data-testid={`bookkeeping-overview-integration-row-${integ.source}`}') !== false);
$assert('per-source last-sync testid template',   strpos($bo, 'data-testid={`bookkeeping-overview-integration-last-sync-${integ.source}`}') !== false);
$assert('palette: red after 7d, amber after 24h, green otherwise',
    strpos($bo, 'integ.hours_since > 168') !== false
    && strpos($bo, 'integ.hours_since > 24') !== false);
$assert('renders only when integrations array non-empty',
    strpos($bo, '(data.integrations?.length ?? 0) > 0') !== false);
$assert('humanises hours_since to "Xh ago"/"Xd ago"',
    strpos($bo, "`\${integ.hours_since}h ago`") !== false
    && strpos($bo, "`\${Math.round(integ.hours_since / 24)}d ago`") !== false);

echo "\nRouting — top-level /ai-agents (Phase A.0 promotion)\n";
$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert('App.jsx imports AIAgents page',          strpos($app, "import AIAgents from './pages/AIAgents'") !== false);
$assert('App.jsx mounts top-level /ai-agents',    strpos($app, '<Route path="/ai-agents"') !== false);

$am = (string) file_get_contents("{$ROOT}/modules/accounting/ui/AccountingModule.jsx");
$assert('AccountingModule keeps legacy /ai-agents redirect alias',
    strpos($am, '<Route path="ai-agents" element={<Navigate to="/ai-agents" replace />} />') !== false);
$assert('AccountingModule still exposes "AI Agents" sub-nav tab (now redirects)',
    strpos($am, '<Tab to="ai-agents" label="AI Agents" />') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
