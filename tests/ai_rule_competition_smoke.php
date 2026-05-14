<?php
/**
 * tests/ai_rule_competition_smoke.php — Phase 2 AI v1.
 *
 * Static contract checks for the rule-competing AI:
 *   - Migration 046 shape
 *   - core/ai_rule_competition.php library (registry + replay + compete + scoring)
 *   - core/ai_rule_proposer.php library (rpCurrentRule + rpRecentActivity + aiProposeRule)
 *   - api/admin/rule_proposals.php endpoint (GET/POST + tenant scoping)
 *   - <RuleProposals /> React UI (proposer trigger + diff table + accept/reject)
 *   - App.jsx route /ai/rule-proposals
 *
 * Lane: harness (AI scoring + replay logic sits alongside the sim harness
 * + invariants — same conceptual area).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}\n"; }
};

$ROOT = realpath(__DIR__ . '/..');

// ── Migration 046 ────────────────────────────────────────────────────
echo "Migration 046 — rule_proposals schema\n";
$mig = (string) file_get_contents("{$ROOT}/core/migrations/046_rule_proposals.sql");
$a('046_rule_proposals.sql exists',                  is_file("{$ROOT}/core/migrations/046_rule_proposals.sql"));
$a('CREATE TABLE IF NOT EXISTS rule_proposals',      str_contains($mig, 'CREATE TABLE IF NOT EXISTS rule_proposals'));
$a('rule_type column',                               str_contains($mig, 'rule_type           VARCHAR(80)'));
$a('current_rule_json + proposed_rule_json (JSON)',
    str_contains($mig, 'current_rule_json   JSON') && str_contains($mig, 'proposed_rule_json  JSON'));
$a('comparison_json (JSON)',                         str_contains($mig, 'comparison_json     JSON'));
$a('score DECIMAL(6,4)',                             str_contains($mig, 'score               DECIMAL(6,4)'));
$a('events_compared + events_changed counters',
    str_contains($mig, 'events_compared') && str_contains($mig, 'events_changed'));
$a('dollars_changed DECIMAL(18,2)',                  str_contains($mig, 'dollars_changed     DECIMAL(18,2)'));
$a('status ENUM with 6 states',
    str_contains($mig, "ENUM('proposed','competed','accepted','rejected','applied','error')"));
$a('tenant + status index',                          str_contains($mig, 'ix_rp_tenant_status'));
$a('tenant + type index',                            str_contains($mig, 'ix_rp_tenant_type'));
$a('utf8mb4_unicode_ci',                             str_contains($mig, 'utf8mb4_unicode_ci'));

// ── ai_rule_competition.php ──────────────────────────────────────────
echo "\ncore/ai_rule_competition.php\n";
$comp = (string) file_get_contents("{$ROOT}/core/ai_rule_competition.php");
$a('library exists',                                 is_file("{$ROOT}/core/ai_rule_competition.php"));
$a('parses',
   (int) shell_exec('php -l ' . escapeshellarg("{$ROOT}/core/ai_rule_competition.php") . ' >/dev/null 2>&1; echo $?') === 0);
$a('declare(strict_types=1)',                        str_contains($comp, 'declare(strict_types=1)'));
$a('exports rcRegisterReplay()',                     str_contains($comp, 'function rcRegisterReplay(string $ruleType, callable $fn): void'));
$a('exports rcReplayRule()',                         str_contains($comp, 'function rcReplayRule(int $tenantId'));
$a('exports aiRuleCompete()',                        str_contains($comp, 'function aiRuleCompete(int $proposalId'));
$a('rcReplayRule throws on unknown rule_type',       str_contains($comp, "no replay handler for rule_type"));
$a('registry stored on $GLOBALS',                    str_contains($comp, "_AI_RULE_REPLAY_REGISTRY"));
$a('v1 registers ap_expense_category_map',           str_contains($comp, "rcRegisterReplay('ap_expense_category_map'"));
$a('replay joins ap_bill_lines + ap_bills',
    str_contains($comp, 'FROM ap_bill_lines l') && str_contains($comp, 'JOIN ap_bills      b ON b.id = l.bill_id'));
$a('replay filters by tenant + posted status',
    str_contains($comp, 'b.tenant_id = :t') && str_contains($comp, 'status IN ("approved","paid","partially_paid","posted")'));
$a('replay falls back to default key',               str_contains($comp, "(string) (\$rule['default'] ?? '6900')"));
$a('replay returns event_key + dollars + outcome_value',
    str_contains($comp, "'event_key'") && str_contains($comp, "'outcome_value'"));
$a('aiRuleCompete refuses missing proposal',         str_contains($comp, 'proposal {$proposalId} not found'));
$a('aiRuleCompete persists comparison_json',         str_contains($comp, 'comparison_json = :cj'));
$a('aiRuleCompete persists score',                   str_contains($comp, 'score           = :sc'));
$a('aiRuleCompete preserves accepted/rejected/applied',
    str_contains($comp, 'IF(status IN ("accepted","rejected","applied")'));
$a('score caps diff at 200 rows',                    str_contains($comp, 'array_slice($diff, 0, 200)'));
$a('score formula penalizes over-large change ratios',
    str_contains($comp, 'abs($changeRatio - 0.15)'));
$a('error path marks status=error + status_reason',
    str_contains($comp, 'SET status="error", status_reason=:r'));

// ── ai_rule_proposer.php ─────────────────────────────────────────────
echo "\ncore/ai_rule_proposer.php\n";
$prop = (string) file_get_contents("{$ROOT}/core/ai_rule_proposer.php");
$a('library exists',                                 is_file("{$ROOT}/core/ai_rule_proposer.php"));
$a('parses',
   (int) shell_exec('php -l ' . escapeshellarg("{$ROOT}/core/ai_rule_proposer.php") . ' >/dev/null 2>&1; echo $?') === 0);
$a('requires ai_service.php',                        str_contains($prop, "require_once __DIR__ . '/ai_service.php'"));
$a('requires ai_rule_competition.php',               str_contains($prop, "require_once __DIR__ . '/ai_rule_competition.php'"));
$a('exports rpCurrentRule()',                        str_contains($prop, 'function rpCurrentRule(int $tenantId, string $ruleType): array'));
$a('exports rpRecentActivity()',                     str_contains($prop, 'function rpRecentActivity(int $tenantId, string $ruleType, int $contextSize): array'));
$a('exports aiProposeRule()',                        str_contains($prop, 'function aiProposeRule(int $tenantId, string $ruleType, ?int $userId'));
$a('rpCurrentRule rejects unknown rule_type',        str_contains($prop, "rpCurrentRule: unsupported rule_type"));
$a('rpCurrentRule reads accounting_account_mapping_rules',
    str_contains($prop, 'FROM accounting_account_mapping_rules') && str_contains($prop, "module = \"ap\""));
$a('rpCurrentRule has fallback baseline',            str_contains($prop, "'default'    => '6900'"));
$a('rpRecentActivity groups by category',            str_contains($prop, 'GROUP BY l.category'));
$a('aiProposeRule calls aiAsk()',                    str_contains($prop, 'aiAsk(['));
$a('aiProposeRule kind=json',                        str_contains($prop, "'kind'          => 'json'"));
$a('aiProposeRule feature_class=rule_proposal',      str_contains($prop, "'feature_class' => 'rule_proposal'"));
$a('aiProposeRule asks for {proposed_rule, rationale}',
    str_contains($prop, 'proposed_rule') && str_contains($prop, 'rationale'));
$a('aiProposeRule degrades cleanly on no-structured-response',
    str_contains($prop, 'recorded as no-change'));
$a('aiProposeRule catches AI errors → status=error',
    str_contains($prop, "'st'  => \$statusReason ? 'error' : 'proposed'"));
$a('aiProposeRule auto-competes on success',
    preg_match('/if\s*\(!\$statusReason\)\s*\{\s*try\s*\{\s*aiRuleCompete\(\$proposalId\);/', $prop) === 1);
$a('aiProposeRule returns the inserted id',          str_contains($prop, 'return $proposalId'));

// ── API endpoint ────────────────────────────────────────────────────
echo "\napi/admin/rule_proposals.php endpoint\n";
$ep = (string) file_get_contents("{$ROOT}/api/admin/rule_proposals.php");
$a('endpoint exists',                                is_file("{$ROOT}/api/admin/rule_proposals.php"));
$a('endpoint parses',
   (int) shell_exec('php -l ' . escapeshellarg("{$ROOT}/api/admin/rule_proposals.php") . ' >/dev/null 2>&1; echo $?') === 0);
$a('requires ai_rule_proposer',                      str_contains($ep, "require_once __DIR__ . '/../../core/ai_rule_proposer.php'"));
$a('requires ai_rule_competition',                   str_contains($ep, "require_once __DIR__ . '/../../core/ai_rule_competition.php'"));
$a('api_require_auth',                               str_contains($ep, 'api_require_auth()'));
$a('GET by id is tenant-scoped',
    str_contains($ep, 'FROM rule_proposals WHERE tenant_id = :t AND id = :id'));
$a('GET list filters status + rule_type',            str_contains($ep, "'s' => (string) \$s") && str_contains($ep, "'rt' => (string) \$rt"));
$a('GET list caps limit at 200',                     str_contains($ep, 'min(200, (int) api_query'));
$a('POST action=propose route',                      str_contains($ep, "action === 'propose'"));
$a('POST action=compete route',                      str_contains($ep, "action === 'compete'"));
$a('POST action=review route',                       str_contains($ep, "action === 'review'"));
$a('compete checks tenant ownership before run',
    preg_match('/action === .compete.[\s\S]+?WHERE tenant_id = :t AND id = :id[\s\S]+?aiRuleCompete\(\$id/', $ep) === 1);
$a('review validates decision in (accept|reject)',   str_contains($ep, "in_array(\$decision, ['accept', 'reject'], true)"));
$a('review writes status accepted/rejected',         str_contains($ep, "\$decision === 'accept' ? 'accepted' : 'rejected'"));
$a('json-decodes rule columns in row_decode',
    str_contains($ep, "'current_rule_json', 'proposed_rule_json', 'comparison_json'"));
$a('unknown action → 422',                           str_contains($ep, 'Unknown action. Use "propose" | "compete" | "review"'));
$a('rejects non-GET/POST methods',                   str_contains($ep, "Method not allowed', 405"));

// ── React UI ─────────────────────────────────────────────────────────
echo "\n<RuleProposals /> React page\n";
$ui = (string) file_get_contents("{$ROOT}/dashboard/src/pages/RuleProposals.jsx");
$a('page file exists',                               is_file("{$ROOT}/dashboard/src/pages/RuleProposals.jsx"));
$a('default-exports RuleProposals',                  str_contains($ui, 'export default function RuleProposals'));
$a('lists rule types (ap_expense_category_map)',     str_contains($ui, "key: 'ap_expense_category_map'"));
$a('useApi reads /api/admin/rule_proposals.php',     str_contains($ui, "useApi('/api/admin/rule_proposals.php?limit=100')"));
$a('propose button POSTs action=propose',
    str_contains($ui, "action: 'propose'") && str_contains($ui, "rule_type: ruleType"));
$a('renders ProposalCard for each row',              str_contains($ui, '<ProposalCard key={row.id}'));
$a('card has data-testid=rule-proposals-card-{id}',  str_contains($ui, 'data-testid={`rule-proposals-card-${row.id}`}'));
$a('card has rationale block',                       str_contains($ui, 'data-testid={`rule-proposals-rationale-${row.id}`}'));
$a('card renders current + proposed JSON boxes',
    str_contains($ui, '<RuleJsonBox label="Current rule"') &&
    str_contains($ui, '<RuleJsonBox label="Proposed rule"'));
$a('card renders diff table',                        str_contains($ui, 'data-testid={`rule-proposals-diff-${row.id}`}'));
$a('accept button POSTs action=review decision=accept',
    str_contains($ui, "decision: 'accept'") || str_contains($ui, "review('accept')"));
$a('reject button POSTs action=review decision=reject',
    str_contains($ui, "decision: 'reject'") || str_contains($ui, "review('reject')"));
$a('recompete button POSTs action=compete',          str_contains($ui, "action:'compete'"));
$a('empty state has testid',                         str_contains($ui, 'data-testid="rule-proposals-empty"'));
$a('list root has testid',                           str_contains($ui, 'data-testid="rule-proposals-list"'));
$a('page root has testid',                           str_contains($ui, 'data-testid="rule-proposals-page"'));
$a('refresh button has testid',                      str_contains($ui, 'data-testid="rule-proposals-refresh"'));

// ── App.jsx route ───────────────────────────────────────────────────
echo "\nApp.jsx route /ai/rule-proposals\n";
$app = (string) file_get_contents("{$ROOT}/dashboard/src/App.jsx");
$a('App.jsx imports RuleProposals',                  str_contains($app, "import RuleProposals from './pages/RuleProposals'"));
$a('App.jsx mounts /ai/rule-proposals',              str_contains($app, '<Route path="/ai/rule-proposals" element={<RuleProposals />} />'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
