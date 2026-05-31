<?php
/**
 * core/ai/prompt_versions.php — versioned prompt resolver (Slice 2).
 *
 * Slice 2 keeps prompts hardcoded as built-in defaults and falls
 * through to ai_prompt_versions (091) when an admin has activated a
 * tenant-or-platform-level override. The table is the source of
 * truth once populated; built-in defaults are the bootstrap floor so
 * the gateway works on a fresh install.
 *
 * Spec §15 / Appendix B: prompts must instruct agents to use tools,
 * produce structured outputs when appropriate, disclose uncertainty,
 * and never claim execution unless a tool result confirms it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Hardcoded floor prompts. Indexed by agent_name. Slice 2 only
 * ships the orchestrator agent — module-specific agents (accounting,
 * treasury, payroll, …) arrive in Slice 3+ as their workflows land.
 */
function aiPromptVersionBuiltinDefaults(): array
{
    return [
        'orchestrator' => [
            'version'       => '2026-02-default',
            'system_prompt' =>
                "You are the CoreFlux Orchestrator agent. You operate inside a multi-tenant ERP.\n\n" .
                "HARD RULES:\n" .
                "1. You CANNOT query or mutate CoreFlux data directly. The only permitted execution\n" .
                "   path is the registered tools provided to you.\n" .
                "2. Prefer calling a tool over speculating. If you need a fact about the tenant,\n" .
                "   their chart of accounts, their bank transactions, or their permissions, call\n" .
                "   the matching tool first.\n" .
                "3. You inherit the calling user's permissions. If a tool returns 'denied' you must\n" .
                "   NOT retry it; explain the missing permission in plain language.\n" .
                "4. Never claim an action has been taken unless a tool result confirms it. Drafts\n" .
                "   created via tools are clearly drafts — say so.\n" .
                "5. If you are not confident, say so. Never invent numbers.\n",
            'developer_prompt' =>
                "When you have all the information you need, write a short summary for the user.\n" .
                "Otherwise call exactly the tools you need next. Keep tool calls minimal: prefer one\n" .
                "tool per turn unless they are clearly independent.\n",
            'params_json' => ['temperature' => 0.2, 'max_tokens' => 1200],
        ],
    ];
}

/**
 * Resolve the active prompt for an agent. Returns:
 *   ['version' => string, 'system_prompt' => string,
 *    'developer_prompt' => string|null, 'params' => array]
 * Order of lookup: ai_prompt_versions WHERE is_active = 1 → built-in
 * default → throws.
 */
function aiPromptVersionResolve(string $agentName): array
{
    // 1. DB override.
    try {
        $stmt = getDB()->prepare(
            'SELECT version, system_prompt, developer_prompt, params_json
               FROM ai_prompt_versions
              WHERE agent_name = :a AND is_active = 1
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute(['a' => $agentName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $params = [];
            if (!empty($row['params_json']) && is_string($row['params_json'])) {
                $decoded = json_decode($row['params_json'], true);
                if (is_array($decoded)) $params = $decoded;
            }
            return [
                'version'          => (string) $row['version'],
                'system_prompt'    => (string) $row['system_prompt'],
                'developer_prompt' => $row['developer_prompt'] !== null
                                       ? (string) $row['developer_prompt'] : null,
                'params'           => $params,
            ];
        }
    } catch (\Throwable $e) { /* schema-not-ready or DB hiccup; fall through */ }

    // 2. Built-in defaults.
    $defaults = aiPromptVersionBuiltinDefaults();
    if (isset($defaults[$agentName])) {
        $d = $defaults[$agentName];
        return [
            'version'          => $d['version'],
            'system_prompt'    => $d['system_prompt'],
            'developer_prompt' => $d['developer_prompt'] ?? null,
            'params'           => $d['params_json'] ?? [],
        ];
    }

    throw new \InvalidArgumentException("no prompt registered for agent '{$agentName}'");
}

/**
 * Discovery helper for the admin trace UI — lists every agent we
 * have a built-in default for. Slice 2 = ['orchestrator'].
 */
function aiPromptVersionListAgents(): array
{
    return array_keys(aiPromptVersionBuiltinDefaults());
}
