<?php
/**
 * ai_gateway_slice2_smoke.php
 *
 * AI Tool Gateway — Slice 2 (LLM Provider Wiring). Plumbing turns
 * into actual model calls. Two design pillars:
 *
 *   1. Provider-neutral abstraction. core/ai/providers/llm_adapter.php
 *      defines the AiLlmAdapter interface + AiLlmProviderException +
 *      AiLlmConfigException, plus aiLlmFormatToolsForProvider() that
 *      translates the existing tool_gateway registry into the OpenAI
 *      function-calling schema (which other providers can adapt from).
 *      core/ai/providers/openai_adapter.php implements it.
 *      core/ai/providers/factory.php routes `'openai'` → adapter.
 *
 *   2. LLM-driven run loop. core/ai/gateway.php :: aiGatewayRunWithLlm
 *      feeds user intent + tool registry to the LLM, loops on
 *      tool_calls, executes each through aiGatewayInvokeTool (so
 *      RBAC + audit + ai_tool_invocations back-link all still
 *      apply), and completes the run with usage + assistant text.
 *      Caps: AI_GATEWAY_MAX_LLM_TURNS (5) and
 *      AI_GATEWAY_MAX_TOOLS_PER_TURN (8) protect against runaway loops.
 *
 *   3. Versioned prompts (deferred from Slice 1). Migration 091 +
 *      core/ai/prompt_versions.php (built-in defaults + DB override
 *      lookup). Slice 2 ships one agent — orchestrator/2026-02-default.
 *
 *   4. api/ai/runs.php — POST now supports two modes:
 *      • `{intent}` → LLM mode (default when intent set + no tools).
 *      • `{tools: [...]}` → deterministic Slice-1 path (preserved).
 *
 *   5. dashboard/src/pages/AskAiPanel.jsx — LLM-mode default + tab
 *      switcher to deterministic mode. Renders assistant_text +
 *      tool-call details + usage.
 *
 * No live OpenAI call is made by this smoke (no network in CI). The
 * functional probe targets the adapter's pre-flight config check
 * (AiLlmConfigException when key missing) + aiLlmFormatToolsForProvider
 * shape conversion.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => is_file($p) ? (string) file_get_contents($p) : '';
$ROOT = dirname(__DIR__);

echo "AI Tool Gateway — Slice 2 smoke (LLM provider wiring)\n";
echo "=====================================================\n\n";

// ── migration 091 ──────────────────────────────────────────────────
echo "core/migrations/091_ai_prompt_versions.sql\n";
$m = $read("{$ROOT}/core/migrations/091_ai_prompt_versions.sql");
$a('file exists',                                  $m !== '');
$a('CREATE TABLE ai_prompt_versions',              str_contains($m, 'CREATE TABLE IF NOT EXISTS ai_prompt_versions'));
$a('  unique (agent_name, version)',               str_contains($m, 'UNIQUE KEY uq_apv_agent_version (agent_name, version)'));
$a('  is_active index for fast active lookup',     str_contains($m, 'KEY ix_apv_active (agent_name, is_active)'));
$a('  system_prompt MEDIUMTEXT NOT NULL',          str_contains($m, 'system_prompt       MEDIUMTEXT NOT NULL'));
$a('  params_json JSON',                           str_contains($m, 'params_json         JSON'));

// ── llm_adapter.php — interface + helpers ──────────────────────────
echo "\ncore/ai/providers/llm_adapter.php\n";
$la = $read("{$ROOT}/core/ai/providers/llm_adapter.php");
$a('file exists',                                  $la !== '');
$a('declares AiLlmProviderException',              str_contains($la, 'class AiLlmProviderException extends \\RuntimeException'));
$a('declares AiLlmConfigException',                str_contains($la, 'class AiLlmConfigException extends \\RuntimeException'));
$a('declares abstract AiLlmAdapter',               str_contains($la, 'abstract class AiLlmAdapter'));
$a('chatWithTools is abstract',                    str_contains($la, 'abstract public function chatWithTools(array $messages, array $tools, array $opts = []): array;'));
$a('aiLlmFormatToolsForProvider declared',         str_contains($la, 'function aiLlmFormatToolsForProvider(array $registry): array'));
$a('  emits function-calling shape',               str_contains($la, "'type'     => 'function',")
                                                && str_contains($la, "'parameters'  => [")
                                                && str_contains($la, "'type'       => 'object',"));
$a('  maps int → JSON integer',                    str_contains($la, "'int', 'integer'  => 'integer'"));
$a('  maps bool → JSON boolean',                   str_contains($la, "'bool', 'boolean' => 'boolean'"));
$a('  maps date → JSON string (ISO-8601)',         str_contains($la, "'date'            => 'string'"));

// ── openai_adapter.php ─────────────────────────────────────────────
echo "\ncore/ai/providers/openai_adapter.php\n";
$oa = $read("{$ROOT}/core/ai/providers/openai_adapter.php");
$a('file exists',                                  $oa !== '');
$a('extends AiLlmAdapter',                         str_contains($oa, 'class AiLlmOpenAiAdapter extends AiLlmAdapter'));
$a('throws AiLlmConfigException if key missing',   str_contains($oa, "throw new AiLlmConfigException('OPENAI_API_KEY not configured')"));
$a('uses OPENAI_API_URL constant w/ fallback',     str_contains($oa, "defined('OPENAI_API_URL')")
                                                && str_contains($oa, "'https://api.openai.com/v1/chat/completions'"));
$a('sends Authorization: Bearer header',           str_contains($oa, "'Authorization: Bearer ' . OPENAI_API_KEY"));
$a('payload includes tools + tool_choice when set',str_contains($oa, "\$payload['tools']       = \$tools;")
                                                && str_contains($oa, "\$payload['tool_choice'] = \$opts['tool_choice'] ?? 'auto';"));
$a('non-2xx → AiLlmProviderException',             str_contains($oa, "throw new AiLlmProviderException(\"HTTP {\$http}: \""));
$a('parses choices[0].message',                    str_contains($oa, "\$choice = \$data['choices'][0] ?? null;"));
$a('decodes tool_calls[].function.arguments JSON', str_contains($oa, "isset(\$fn['arguments']) && is_string(\$fn['arguments'])"));
$a('returns provider-neutral envelope shape',      str_contains($oa, "'assistant_text' => isset(\$message['content'])")
                                                && str_contains($oa, "'tool_calls'    => \$toolCalls,")
                                                && str_contains($oa, "'finish_reason' =>")
                                                && str_contains($oa, "'usage'         =>")
                                                && str_contains($oa, "'latency_ms'"));

// ── factory.php ────────────────────────────────────────────────────
echo "\ncore/ai/providers/factory.php\n";
$fa = $read("{$ROOT}/core/ai/providers/factory.php");
$a('file exists',                                  $fa !== '');
$a('aiLlmProviderFor declared',                    str_contains($fa, 'function aiLlmProviderFor(string $providerKey): AiLlmAdapter'));
$a('  case openai → AiLlmOpenAiAdapter',           str_contains($fa, "case 'openai':")
                                                && str_contains($fa, 'return new AiLlmOpenAiAdapter();'));
$a('  unknown provider → InvalidArgumentException',str_contains($fa, "throw new \\InvalidArgumentException(\"unknown LLM provider"));
$a('aiLlmDefaultProvider declared',                str_contains($fa, 'function aiLlmDefaultProvider(): string'));
$a('  picks openai when OPENAI_API_KEY defined',   str_contains($fa, "defined('OPENAI_API_KEY') && OPENAI_API_KEY"));
$a('  throws AiLlmConfigException when no key',    str_contains($fa, "throw new AiLlmConfigException('no LLM provider configured"));

// ── prompt_versions.php ────────────────────────────────────────────
echo "\ncore/ai/prompt_versions.php\n";
$pv = $read("{$ROOT}/core/ai/prompt_versions.php");
$a('file exists',                                  $pv !== '');
$a('built-in defaults include orchestrator',       str_contains($pv, "'orchestrator' => ["));
$a('  version key 2026-02-default',                str_contains($pv, "'version'       => '2026-02-default',"));
$a('  system prompt enforces tool-only execution', str_contains($pv, 'only permitted execution'));
$a('  system prompt forbids hallucinating numbers',str_contains($pv, 'Never invent numbers'));
$a('  developer prompt minimises tool fan-out',    str_contains($pv, 'Keep tool calls minimal'));
$a('aiPromptVersionResolve declared',              str_contains($pv, 'function aiPromptVersionResolve(string $agentName): array'));
$a('  reads from ai_prompt_versions WHERE active', str_contains($pv, "WHERE agent_name = :a AND is_active = 1"));
$a('  falls through to built-in defaults',         str_contains($pv, '$defaults = aiPromptVersionBuiltinDefaults();'));
$a('  throws on unknown agent',                    str_contains($pv, "throw new \\InvalidArgumentException(\"no prompt registered for agent"));
$a('aiPromptVersionListAgents declared',           str_contains($pv, 'function aiPromptVersionListAgents(): array'));

// ── gateway.php — LLM mode ─────────────────────────────────────────
echo "\ncore/ai/gateway.php — Slice 2 LLM loop\n";
$g = $read("{$ROOT}/core/ai/gateway.php");
$a('requires providers/factory.php',               str_contains($g, "require_once __DIR__ . '/providers/factory.php';"));
$a('requires prompt_versions.php',                 str_contains($g, "require_once __DIR__ . '/prompt_versions.php';"));
$a('constant AI_GATEWAY_MAX_LLM_TURNS = 5',        str_contains($g, "const AI_GATEWAY_MAX_LLM_TURNS = 5;"));
$a('constant AI_GATEWAY_MAX_TOOLS_PER_TURN = 8',   str_contains($g, "const AI_GATEWAY_MAX_TOOLS_PER_TURN = 8;"));

$a('aiGatewayRunWithLlm declared',                 str_contains($g, 'function aiGatewayRunWithLlm('));
$a('  resolves prompt via aiPromptVersionResolve', str_contains($g, '$prompt   = aiPromptVersionResolve($agent);'));
$a('  builds OpenAI-shape messages (system+user)', str_contains($g, "'role' => 'system'")
                                                && str_contains($g, "'role' => 'user', 'content' => \$intent"));
$a('  hands tool registry to provider',            str_contains($g, '$toolSpecs = aiLlmFormatToolsForProvider(aiToolRegistry());'));
$a('  loops up to MAX_LLM_TURNS',                  str_contains($g, "for (; \$turns < AI_GATEWAY_MAX_LLM_TURNS; \$turns++)"));
$a('  exits loop when no tool_calls returned',     str_contains($g, "if (empty(\$toolCalls)) {")
                                                && str_contains($g, "// Model finished"));
$a('  caps per-turn tool fan-out',                 str_contains($g, "if (++\$perTurn > AI_GATEWAY_MAX_TOOLS_PER_TURN) break;"));
$a('  every tool call routes via aiGatewayInvokeTool',
    str_contains($g, "\$toolEnv = aiGatewayInvokeTool(\$runId, \$name, \$args, \$callerCtx);"));
$a('  feeds tool result back to LLM as role=tool', str_contains($g, "'role'         => 'tool',")
                                                && str_contains($g, "'tool_call_id' => \$tc['id'],"));
$a('  AiLlmProviderException → mark run failed',   str_contains($g, "catch (AiLlmProviderException \$e) {")
                                                && str_contains($g, "\$runFailed = true;"));
$a('  budget exhausted → mark run failed',         str_contains($g, "hit max %d LLM turns without final answer"));
$a('  completes run with usage summary',           str_contains($g, "sprintf('turns=%d tool_calls=%d tokens=%d'"));

// ── api/ai/runs.php — Slice 2 mode dispatch ────────────────────────
echo "\napi/ai/runs.php — Slice 2 mode dispatch\n";
$ar = $read("{$ROOT}/api/ai/runs.php");
$a('intent + mode body fields read',               str_contains($ar, "\$intent       = trim((string) (\$body['intent'] ?? ''));")
                                                && str_contains($ar, "\$mode         = \$body['mode'] ?? null;"));
$a('LLM mode auto-selected when intent + no tools',str_contains($ar, "\$useLlm       = \$mode === 'llm' || (\$mode === null && \$intent !== '' && !\$hasTools);"));
$a('LLM mode → aiGatewayRunWithLlm',               str_contains($ar, '$res = aiGatewayRunWithLlm($tid, $callerCtx[\'user_id\'], $intent, $callerCtx, $opts);'));
$a('LLM config missing → 503',                     str_contains($ar, "} catch (AiLlmConfigException \$e) {")
                                                && str_contains($ar, "api_error('LLM provider not configured"));
$a('deterministic mode preserved (Slice 1 contract)',
    str_contains($ar, '// Deterministic mode (Slice 1 contract preserved).')
 && str_contains($ar, '$runId = aiGatewayCreateRun('));

// ── AskAiPanel — LLM-mode UX ───────────────────────────────────────
echo "\ndashboard/src/pages/AskAiPanel.jsx — Slice 2 UX\n";
$ap = $read("{$ROOT}/dashboard/src/pages/AskAiPanel.jsx");
$a('mode toggle tabs rendered',                    str_contains($ap, 'data-testid="ask-ai-mode-tabs"'));
$a('  LLM tab testid',                             str_contains($ap, 'data-testid="ask-ai-mode-llm"'));
$a('  deterministic tool tab testid',              str_contains($ap, 'data-testid="ask-ai-mode-tool"'));
$a('LLM mode default state',                       str_contains($ap, "useState('llm')"));
$a('POST body in LLM mode has intent + mode',      str_contains($ap, "body = { agent, intent, mode: 'llm' };"));
$a('POST body in tool mode preserves Slice 1 shape',
    str_contains($ap, "body = { agent, input_summary: intent, tools: toolName ? [{ name: toolName, args }] : [] };"));
$a('renders assistant_text block',                 str_contains($ap, 'data-testid="ask-ai-assistant-text"'));
$a('shows turns + tokens + model in header',       str_contains($ap, '{run.turns} LLM turns')
                                                && str_contains($ap, '{run.usage.total_tokens} tokens'));
$a('Slice 2 badge present',                        str_contains($ap, 'Slice 2 · LLM planner live'));

// ── Functional probes ──────────────────────────────────────────────
echo "\nFunctional probes\n";
require_once "{$ROOT}/core/ai/providers/factory.php";
require_once "{$ROOT}/core/ai/providers/openai_adapter.php";

// Factory returns the right adapter shape.
$adapter = aiLlmProviderFor('openai');
$a('factory returns AiLlmOpenAiAdapter for openai',$adapter instanceof AiLlmOpenAiAdapter);
$a('adapter exposes providerKey=openai',           $adapter->getProviderKey() === 'openai');

// Factory rejects unknown providers.
$threw = false;
try { aiLlmProviderFor('claude-but-not-yet'); }
catch (\InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), "unknown LLM provider"); }
$a('factory throws on unknown provider',           $threw);

// Tool format conversion shape.
$registry = [
    'sample.tool' => [
        'description' => 'Sample',
        'permission'  => 'ai.use',
        'args'        => [
            'foo' => ['type' => 'int',  'required' => true,  'desc' => 'an integer'],
            'bar' => ['type' => 'bool', 'required' => false, 'desc' => 'a flag'],
            'on'  => ['type' => 'date', 'required' => false, 'desc' => 'an ISO date'],
        ],
    ],
];
$converted = aiLlmFormatToolsForProvider($registry);
$a('format emits 1 tool spec',                     count($converted) === 1);
$a('  type=function',                              ($converted[0]['type'] ?? null) === 'function');
$a('  function.name = sample.tool',                ($converted[0]['function']['name'] ?? null) === 'sample.tool');
$a('  parameters.type = object',                   ($converted[0]['function']['parameters']['type'] ?? null) === 'object');
$a('  foo → integer',                              ($converted[0]['function']['parameters']['properties']['foo']['type'] ?? null) === 'integer');
$a('  bar → boolean',                              ($converted[0]['function']['parameters']['properties']['bar']['type'] ?? null) === 'boolean');
$a('  on  → string  (date as ISO-8601 string)',    ($converted[0]['function']['parameters']['properties']['on']['type']  ?? null) === 'string');
$a('  required list includes foo only',            ($converted[0]['function']['parameters']['required'] ?? []) === ['foo']);

// Prompt resolver returns the orchestrator default in absence of DB.
require_once "{$ROOT}/core/ai/prompt_versions.php";
$resolved = aiPromptVersionResolve('orchestrator');
$a('prompt resolver returns orchestrator version', ($resolved['version'] ?? null) === '2026-02-default');
$a('  system prompt is non-empty',                 strlen((string) ($resolved['system_prompt'] ?? '')) > 100);

$threw = false;
try { aiPromptVersionResolve('not-a-real-agent'); }
catch (\InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'no prompt registered'); }
$a('unknown agent throws InvalidArgumentException',$threw);

// Adapter pre-flight: when OPENAI_API_KEY is undefined we must throw
// AiLlmConfigException before any network call. The CLI smoke env
// doesn't load config.local.php, so this is the right condition.
if (!defined('OPENAI_API_KEY')) {
    $threw = false;
    try { $adapter->chatWithTools([['role' => 'user', 'content' => 'ping']], []); }
    catch (AiLlmConfigException $e) { $threw = true; }
    $a('adapter throws AiLlmConfigException w/o key', $threw);
} else {
    echo "  · OPENAI_API_KEY is defined in this env; skipping AiLlmConfigException probe\n";
}

// ── PHP syntax checks ──────────────────────────────────────────────
echo "\nPHP syntax checks\n";
foreach ([
    'core/ai/providers/llm_adapter.php',
    'core/ai/providers/openai_adapter.php',
    'core/ai/providers/factory.php',
    'core/ai/prompt_versions.php',
    'core/ai/gateway.php',
    'api/ai/runs.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "AI Gateway Slice 2: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
