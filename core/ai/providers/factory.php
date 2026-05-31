<?php
/**
 * core/ai/providers/factory.php — LLM provider factory.
 *
 * Single point of resolution from a provider key string to a
 * concrete AiLlmAdapter. Mirrors the shape of
 * core/accounting/provider_adapter.php so a future provider lands as
 *
 *     case 'claude': $cls = AiLlmClaudeAdapter::class; break;
 *
 * with no surgery elsewhere.
 *
 * Slice 2 ships 'openai' only. The factory still validates the input
 * to keep the gateway forward-compatible.
 */
declare(strict_types=1);

require_once __DIR__ . '/llm_adapter.php';
require_once __DIR__ . '/openai_adapter.php';

function aiLlmProviderFor(string $providerKey): AiLlmAdapter
{
    switch (strtolower($providerKey)) {
        case 'openai':
            return new AiLlmOpenAiAdapter();
        // case 'claude':  return new AiLlmClaudeAdapter();   // Slice 3+
        // case 'gemini':  return new AiLlmGeminiAdapter();   // Slice 3+
        default:
            throw new \InvalidArgumentException("unknown LLM provider '{$providerKey}'");
    }
}

/**
 * Default provider for this CoreFlux instance. Slice 2 keeps it
 * simple — first provider with a configured key wins.
 */
function aiLlmDefaultProvider(): string
{
    if (defined('OPENAI_API_KEY') && OPENAI_API_KEY) return 'openai';
    throw new AiLlmConfigException('no LLM provider configured (OPENAI_API_KEY missing)');
}
