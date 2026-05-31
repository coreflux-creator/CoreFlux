<?php
/**
 * core/ai/providers/openai_adapter.php — OpenAI implementation of
 * AiLlmAdapter.
 *
 * Uses the chat completions API (`/v1/chat/completions`) with
 * `tools` (function-calling) format. Reuses the existing
 * OPENAI_API_KEY constant defined in core/config.local.php; this
 * file does NOT introduce a new credential store.
 *
 * Slice 2 deliberately does NOT wire the Responses API or the
 * Agents SDK — chat completions + tools is the lowest-common-
 * denominator path that every other provider can mirror.
 */
declare(strict_types=1);

require_once __DIR__ . '/llm_adapter.php';

class AiLlmOpenAiAdapter extends AiLlmAdapter
{
    private string $apiUrl;

    public function __construct()
    {
        parent::__construct('openai');
        // OPENAI_API_URL is already defined upstream by ai_service.php.
        // If a Slice-2-only consumer pulls us in before that file is
        // loaded we still want a sensible default.
        $this->apiUrl = defined('OPENAI_API_URL')
            ? (string) constant('OPENAI_API_URL')
            : 'https://api.openai.com/v1/chat/completions';
    }

    /** {@inheritdoc} */
    public function chatWithTools(array $messages, array $tools, array $opts = []): array
    {
        if (!defined('OPENAI_API_KEY') || !OPENAI_API_KEY) {
            throw new AiLlmConfigException('OPENAI_API_KEY not configured');
        }

        $model       = (string) ($opts['model'] ?? (defined('AI_MODEL_DRAFT') ? AI_MODEL_DRAFT : 'gpt-5.4'));
        $temperature = $opts['temperature'] ?? 0.2;
        $maxTokens   = (int)   ($opts['max_tokens']      ?? 1200);
        $timeout     = (int)   ($opts['timeout_seconds'] ?? 60);

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $maxTokens,
        ];
        if (!empty($tools)) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = $opts['tool_choice'] ?? 'auto';
        }

        $start = microtime(true);
        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENAI_API_KEY,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $latencyMs = (int) ((microtime(true) - $start) * 1000);

        if ($body === false) {
            throw new AiLlmProviderException("curl failure: {$err}");
        }
        if ($http < 200 || $http >= 300) {
            throw new AiLlmProviderException("HTTP {$http}: " . substr((string) $body, 0, 240));
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new AiLlmProviderException('non-JSON response from OpenAI');
        }
        $choice = $data['choices'][0] ?? null;
        if (!is_array($choice)) {
            throw new AiLlmProviderException('OpenAI response missing choices[0]');
        }
        $message = $choice['message'] ?? [];

        // Normalise tool calls — OpenAI returns them as
        // [{id, type:'function', function:{name, arguments(JSONstr)}}, …].
        $toolCalls = [];
        foreach (($message['tool_calls'] ?? []) as $tc) {
            $fn = $tc['function'] ?? [];
            $argsDecoded = [];
            if (isset($fn['arguments']) && is_string($fn['arguments']) && $fn['arguments'] !== '') {
                $parsed = json_decode($fn['arguments'], true);
                if (is_array($parsed)) $argsDecoded = $parsed;
            }
            $toolCalls[] = [
                'id'        => (string) ($tc['id'] ?? ''),
                'name'      => (string) ($fn['name'] ?? ''),
                'arguments' => $argsDecoded,
            ];
        }

        return [
            'assistant_text' => isset($message['content']) && is_string($message['content']) && $message['content'] !== ''
                ? $message['content']
                : null,
            'tool_calls'    => $toolCalls,
            'finish_reason' => (string) ($choice['finish_reason'] ?? 'other'),
            'usage'         => [
                'prompt_tokens'     => (int) ($data['usage']['prompt_tokens']     ?? 0),
                'completion_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
                'total_tokens'      => (int) ($data['usage']['total_tokens']      ?? 0),
            ],
            'model'      => (string) ($data['model'] ?? $model),
            'latency_ms' => $latencyMs,
            'raw'        => null,                      // suppressed by default for log hygiene
        ];
    }
}
