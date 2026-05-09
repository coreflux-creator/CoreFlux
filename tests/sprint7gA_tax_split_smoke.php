<?php
/**
 * Phase A.0 + A.1 smoke — AI agent registry refactor.
 *
 * Asserts:
 *   - The legacy 'tax' agent has been split into 4 honest sub-agents
 *     (tax_mapping, sales_tax, payroll_tax, partner_distributions).
 *   - Each new agent has its own context builder with at least one
 *     SHOW TABLES guard so it degrades gracefully on tenants whose
 *     accounting tables haven't been provisioned yet.
 *   - The Phase A.0 metadata (domain + modules tags) is present in the
 *     registry AND surfaced through /api/ai_agents.php?action=list.
 *   - The page mounts at the new top-level /ai-agents route (Phase A.0
 *     promoted AI from an accounting-only sub-route to a platform feature).
 *   - The legacy /modules/accounting/ai-agents path still resolves via a
 *     <Navigate> redirect so existing bookmarks survive the move.
 *   - AIAgents.jsx renders the per-agent domain chip list.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Registry — Phase A.1 tax split\n";
$lib = (string) file_get_contents("{$ROOT}/core/ai_agents.php");

$assert("legacy 'tax' agent removed from registry",
    preg_match("/'tax'\\s*=>\\s*\\[/", $lib) === 0);

foreach (['tax_mapping','sales_tax','payroll_tax','partner_distributions'] as $key) {
    $assert("registry includes '{$key}'",
        preg_match("/'{$key}'\\s*=>\\s*\\[/", $lib) === 1);
    $assert("'{$key}' has unique feature_key 'agent.{$key}.review'",
        strpos($lib, "'agent.{$key}.review'") !== false);
}

echo "\nRegistry — Phase A.0 metadata (domain + modules)\n";
$assert('every agent declares a domain tag',
    substr_count($lib, "'domain'") >= 8);
$assert('every agent declares a modules tag',
    substr_count($lib, "'modules'") >= 8);
$assert("'tax_mapping' belongs to domain=['tax']",
    preg_match("/'tax_mapping'.*?'domain'\\s*=>\\s*\\['tax'\\]/s", $lib) === 1);
$assert("'sales_tax' modules include billing",
    preg_match("/'sales_tax'.*?'modules'\\s*=>\\s*\\['accounting',\\s*'billing'\\]/s", $lib) === 1);
$assert("'payroll_tax' domain spans tax + payroll",
    preg_match("/'payroll_tax'.*?'domain'\\s*=>\\s*\\['tax',\\s*'payroll'\\]/s", $lib) === 1);
$assert("'partner_distributions' domain spans tax + equity",
    preg_match("/'partner_distributions'.*?'domain'\\s*=>\\s*\\['tax',\\s*'equity'\\]/s", $lib) === 1);

echo "\nContext builders — 4 new tax sub-agents\n";
foreach (['TaxMapping','SalesTax','PayrollTax','PartnerDistributions'] as $fn) {
    $assert("aiAgentContext{$fn} declared",
        strpos($lib, "function aiAgentContext{$fn}(int \$tenantId)") !== false);
    $assert("aiAgentContext{$fn} guards on SHOW TABLES",
        preg_match("/function aiAgentContext{$fn}\\(.*?SHOW TABLES LIKE/s", $lib) === 1);
    $assert("aiAgentContext{$fn} returns qualitative buckets only",
        preg_match("/function aiAgentContext{$fn}\\(.*?aiAgentBucketCount/s", $lib) === 1);
}

$assert("aiAgentContextSalesTax reads accounting_accounts (sales-tax surface)",
    preg_match("/aiAgentContextSalesTax.*?accounting_accounts/s", $lib) === 1);
$assert("aiAgentContextSalesTax checks billing_invoices for recent activity",
    preg_match("/aiAgentContextSalesTax.*?billing_invoices/s", $lib) === 1);
$assert("aiAgentContextPayrollTax inspects journal_lines for posting cadence",
    preg_match("/aiAgentContextPayrollTax.*?accounting_journal_lines/s", $lib) === 1);
$assert("aiAgentContextPartnerDistributions filters on equity / distribution / draw",
    strpos($lib, "account_type = 'equity'") !== false
    && strpos($lib, "LIKE '%distribution%'") !== false
    && strpos($lib, "LIKE '%draw%'") !== false);

echo "\nAPI — list response surfaces domain + modules metadata\n";
$api = (string) file_get_contents("{$ROOT}/api/ai_agents.php");
$assert("'list' response includes domain key",   strpos($api, "'domain'") !== false);
$assert("'list' response includes modules key",  strpos($api, "'modules'") !== false);
$assert('domain falls back to empty array',      strpos($api, "\$a['domain']  ?? []") !== false);
$assert('modules falls back to empty array',     strpos($api, "\$a['modules'] ?? []") !== false);

echo "\nUI — AIAgents.jsx wiring\n";
$pg = (string) file_get_contents("{$ROOT}/dashboard/src/pages/AIAgents.jsx");
$assert('icon map covers tax_mapping',           strpos($pg, 'tax_mapping:') !== false);
$assert('icon map covers sales_tax',             strpos($pg, 'sales_tax:') !== false);
$assert('icon map covers payroll_tax',           strpos($pg, 'payroll_tax:') !== false);
$assert('icon map covers partner_distributions', strpos($pg, 'partner_distributions:') !== false);
$assert('legacy generic Bot icon for "tax" removed',
    strpos($pg, 'tax:              Bot,') === false);
$assert('declares DOMAIN_LABELS map',            strpos($pg, 'const DOMAIN_LABELS = {') !== false);
$assert('per-agent domain chip testid',          strpos($pg, 'data-testid={`ai-agents-domains-${agent.key}`}') !== false);
$assert('iterates agent.domain to render chips', strpos($pg, 'agent.domain.map(d =>') !== false);
$assert('digest copy says "all ten agents"',     strpos($pg, 'all ten agents') !== false);

echo "\nRouting — top-level /ai-agents promotion\n";
$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$assert('App.jsx imports AIAgents page',          strpos($app, "import AIAgents from './pages/AIAgents'") !== false);
$assert('App.jsx mounts top-level /ai-agents',    strpos($app, '<Route path="/ai-agents"') !== false);

$am = (string) file_get_contents("{$ROOT}/modules/accounting/ui/AccountingModule.jsx");
$assert('AccountingModule no longer imports AIAgents directly',
    strpos($am, "import AIAgents from") === false);
$assert('AccountingModule keeps legacy /ai-agents redirect alias',
    strpos($am, '<Route path="ai-agents" element={<Navigate to="/ai-agents" replace />} />') !== false);
$assert('AccountingModule still exposes "AI Agents" sub-nav tab (now redirects)',
    strpos($am, '<Tab to="ai-agents" label="AI Agents" />') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
