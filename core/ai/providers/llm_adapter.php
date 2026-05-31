<?php
/**
 * core/ai/providers/llm_adapter.php — provider-neutral LLM adapter
 * contract.
 *
 * The Gateway only ever talks to this interface. OpenAI, Claude,
 * Gemini, or a future provider each implement `chatWithTools()` and
 * return the same envelope shape.
 *
 * Why an interface instead of a single function? The Jaz accounting
 * adapter taught us the lesson: provider semantics drift (OpenAI
 * `tool_calls`, Anthropic `tool_use` blocks, Gemini `functionCall`
 * parts). Centralizing the contract is what lets the gateway loop
 * stay provider-agnostic. Adding a new provider is a one-file change.
 *
 * Spec ref: AI-Native Extension v1.2 §2, §15.
 */
declare(strict_types=1);

/**
 * Raised when the upstream provider explicitly refuses
 * (auth, quota, model-unavailable). Caller routes to "failed" run
 * status with provider_error code.
 */
class AiLlmProviderException extends \RuntimeException {}

/**
 * Raised when a provider is configured but the local config layer
 * doesn't have the necessary key. Distinct so the audit can flag
 * "provider key missing" rather than "model rejected the request".
 */
class AiLlmConfigException extends \RuntimeException {}

/**
 * The provider-neutral chat envelope every adapter returns from
 * chatWithTools():
 *
 *   [
 *     'assistant_text' => string|null,    // final user-visible text (null if all tools)
 *     'tool_calls'     => [               // [] when the model didn't call any
 *       ['id' => '…', 'name' => '…', 'arguments' => [array]],
 *       …
 *     ],
 *     'finish_reason'  => 'stop' | 'tool_calls' | 'length' | 'content_filter' | 'other',
 *     'usage'          => ['prompt_tokens' => int, 'completion_tokens' => int, 'total_tokens' => int],
 *     'model'          => string,         // model name actually used by provider
 *     'latency_ms'     => int,
 *     'raw'            => array|null,     // best-effort raw response for debug
 *   ]
 *
 * Adapters MUST normalise to this shape. Tool-call arguments MUST be
 * decoded into a PHP array (callers should never have to re-parse
 * JSON strings).
 */
abstract class AiLlmAdapter
{
    public string $providerKey;

    public function __construct(string $providerKey) { $this->providerKey = $providerKey; }

    /**
     * Single round-trip to the model.
     *
     * @param array $messages  OpenAI-shape: [{role: 'system'|'developer'|'user'|'assistant'|'tool', content: '…', tool_call_id?: …, name?: …}, …]
     * @param array $tools     Normalised tool-call schema (see
     *                         aiLlmFormatToolsForProvider()). [] disables tool-calling.
     * @param array $opts      ['model' => string, 'temperature' => float,
     *                          'max_tokens' => int, 'timeout_seconds' => int].
     *
     * @return array provider-neutral envelope (see class doc above)
     *
     * @throws AiLlmConfigException   provider not configured locally
     * @throws AiLlmProviderException upstream provider refused / errored
     */
    abstract public function chatWithTools(array $messages, array $tools, array $opts = []): array;

    /** Provider identifier the factory routes on. */
    public function getProviderKey(): string { return $this->providerKey; }
}

/**
 * Convert the registry's tool-call schema (aiToolRegistry) into the
 * OpenAI function-calling shape that most providers now accept.
 * Other adapters can transform from this baseline rather than from
 * the raw registry, keeping per-provider drift in one place.
 *
 * Input `args` shape (from tool_gateway.php):
 *   [argName => ['type' => 'string|int|bool|date', 'required' => bool, 'desc' => '…']]
 *
 * Output: OpenAI tool spec:
 *   [
 *     {type: 'function', function: {name, description, parameters: {
 *       type: 'object', properties: {argName: {type, description}}, required: [...]
 *     }}}, …
 *   ]
 */
function aiLlmFormatToolsForProvider(array $registry): array
{
    $out = [];
    foreach ($registry as $name => $tool) {
        $properties = [];
        $required = [];
        foreach (($tool['args'] ?? []) as $argName => $spec) {
            $jsonType = match ($spec['type'] ?? 'string') {
                'int', 'integer'  => 'integer',
                'bool', 'boolean' => 'boolean',
                'date'            => 'string',  // ISO-8601 date string
                default           => 'string',
            };
            $properties[$argName] = [
                'type'        => $jsonType,
                'description' => (string) ($spec['desc'] ?? ''),
            ];
            if (!empty($spec['required'])) $required[] = $argName;
        }
        $out[] = [
            'type'     => 'function',
            'function' => [
                'name'        => $name,
                'description' => (string) ($tool['description'] ?? ''),
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => $properties,
                    'required'   => $required,
                ],
            ],
        ];
    }
    return $out;
}
