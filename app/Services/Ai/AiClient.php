<?php

namespace App\Services\Ai;

use App\Exceptions\SsrfException;
use App\Models\AiProvider;
use App\Services\Security\SsrfGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal AI Client. Calls active default AiProvider.
 *
 * Default routing: text-based tasks → DeepSeek V4 Flash (or whatever is set as default in /admin/ai-settings).
 * For audio/vision/embedding tasks, future implementation should add ProviderRouter.
 *
 * Security: every constructed endpoint is screened by {@see SsrfGuard} before
 * the HTTP call goes out. This protects us from a misconfigured admin who
 * pastes a base_url that resolves to an internal IP (e.g. http://localhost:8080
 * or http://169.254.169.254). The guard throws {@see SsrfException}, which we
 * surface as a normal RuntimeException to keep call-site error handling stable.
 */
class AiClient
{
    public function __construct(
        protected UsageTracker $tracker,
        protected SsrfGuard $ssrfGuard,
    ) {
    }

    /**
     * Send chat completion request.
     *
     * @param  array       $messages  e.g. [['role'=>'system','content'=>'...'], ['role'=>'user','content'=>'...']]
     * @param  array       $options   max_tokens, temperature, tools (for function calling)
     * @param  string      $taskType  Free-form label persisted with the usage log (e.g. "chat.recommend").
     * @param  Model|null  $subject   Optional related Eloquent model (Movie, User, ...) for the usage log.
     * @return array  ['content', 'tool_calls', 'usage', 'provider', 'model', 'finish_reason']
     * @throws \RuntimeException if no provider configured or API call fails
     */
    public function chat(array $messages, array $options = [], string $taskType = 'chat.generic', ?Model $subject = null): array
    {
        $provider = $this->pickProvider();

        $payload = [
            'model' => $provider->model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 500,
            'temperature' => $options['temperature'] ?? 0.7,
            'stream' => false,
        ];

        // OpenAI-compatible function calling (DeepSeek V4, Groq, OpenAI, OpenRouter)
        if (!empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        $base = rtrim($provider->base_url ?: $this->defaultBaseUrl($provider->provider), '/');

        // OpenAI-compatible endpoint (covers DeepSeek, Groq, Mistral, OpenRouter, OpenAI, custom)
        $endpoint = match ($provider->provider) {
            'anthropic' => $base . '/messages',
            'google'    => $base . '/models/' . $provider->model . ':generateContent?key=' . $provider->api_key,
            default     => $base . '/chat/completions',
        };

        $headers = $this->buildHeaders($provider);
        $payload = $this->normalizePayloadForProvider($provider->provider, $payload, $messages);

        // SSRF guard: refuse to talk to a base_url that resolves to a private
        // network or cloud-metadata host. Misconfigured providers fail fast
        // here rather than silently exfiltrating metadata to a malicious admin.
        try {
            $this->ssrfGuard->assertUrlAllowed($endpoint);
        } catch (SsrfException $e) {
            Log::error('AiClient: provider base_url rejected by SSRF guard', [
                'provider' => $provider->provider,
                'reason'   => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                'AI provider base_url failed safety check. Update it at /admin/ai-settings.'
            );
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout(30)
                ->connectTimeout(5)
                ->withHeaders($headers)
                ->withOptions([
                    'allow_redirects' => [
                        'max'       => 3,
                        'protocols' => ['http', 'https'],
                        'strict'    => true,
                        'referer'   => false,
                    ],
                ])
                ->post($endpoint, $payload);

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            if (!$response->successful()) {
                Log::warning('AiClient API error', [
                    'provider' => $provider->provider,
                    'model' => $provider->model,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('AI provider returned ' . $response->status() . ': ' . substr($response->body(), 0, 200));
            }

            $data = $response->json();
            $result = $this->parseResponse($provider->provider, $data);

            // Track usage (persists AiUsageLog row + rolls up provider totals).
            $this->tracker->track(
                provider: $provider,
                taskType: $taskType,
                inTokens: $result['usage']['input_tokens']
                    ?? $result['usage']['prompt_tokens']
                    ?? 0,
                outTokens: $result['usage']['output_tokens']
                    ?? $result['usage']['completion_tokens']
                    ?? 0,
                latencyMs: $latencyMs,
                success: true,
                subject: $subject,
            );

            return [
                'content' => $result['content'],
                'tool_calls' => $result['tool_calls'] ?? [],
                'finish_reason' => $result['finish_reason'] ?? null,
                'usage' => $result['usage'] ?? [],
                'provider' => $provider->provider,
                'model' => $provider->model,
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            Log::error('AiClient exception', [
                'provider' => $provider->provider,
                'model' => $provider->model,
                'error' => $e->getMessage(),
            ]);

            // Best-effort failure tracking — never re-throw from the tracker.
            try {
                $this->tracker->track(
                    provider: $provider,
                    taskType: $taskType,
                    inTokens: 0,
                    outTokens: 0,
                    latencyMs: $latencyMs,
                    success: false,
                    error: $e->getMessage(),
                    subject: $subject,
                );
            } catch (\Throwable $trackerError) {
                Log::warning('AiClient failed to record failure usage', [
                    'error' => $trackerError->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Pick the default active provider.
     * Falls back to first active provider if no default set.
     */
    protected function pickProvider(): AiProvider
    {
        $provider = AiProvider::default();

        if (!$provider) {
            throw new \RuntimeException(
                'No active AI provider configured. Add one at /admin/ai-settings.'
            );
        }

        return $provider;
    }

    protected function defaultBaseUrl(string $providerName): string
    {
        return match ($providerName) {
            'openai'     => 'https://api.openai.com/v1',
            'anthropic'  => 'https://api.anthropic.com/v1',
            'deepseek'   => 'https://api.deepseek.com/v1',
            'google'     => 'https://generativelanguage.googleapis.com/v1beta',
            'groq'       => 'https://api.groq.com/openai/v1',
            'mistral'    => 'https://api.mistral.ai/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            default      => '',
        };
    }

    protected function buildHeaders(AiProvider $provider): array
    {
        $headers = ['Content-Type' => 'application/json'];

        switch ($provider->provider) {
            case 'anthropic':
                $headers['x-api-key'] = $provider->api_key;
                $headers['anthropic-version'] = '2023-06-01';
                break;
            case 'google':
                // API key in URL, no auth header needed
                break;
            default: // openai-compatible (deepseek, groq, mistral, openrouter, openai, custom)
                $headers['Authorization'] = 'Bearer ' . $provider->api_key;
        }

        return $headers;
    }

    protected function normalizePayloadForProvider(string $providerName, array $payload, array $messages): array
    {
        if ($providerName === 'anthropic') {
            // Anthropic uses different format: system separate, max_tokens required, no `stream` in this version
            $system = '';
            $userMessages = [];
            foreach ($messages as $msg) {
                if ($msg['role'] === 'system') {
                    $system .= $msg['content'] . "\n";
                } else {
                    $userMessages[] = $msg;
                }
            }
            return [
                'model' => $payload['model'],
                'system' => trim($system) ?: 'You are a helpful assistant.',
                'messages' => $userMessages,
                'max_tokens' => $payload['max_tokens'],
                'temperature' => $payload['temperature'],
            ];
        }

        if ($providerName === 'google') {
            // Gemini format: contents with parts
            $contents = [];
            $systemInstruction = null;
            foreach ($messages as $msg) {
                if ($msg['role'] === 'system') {
                    $systemInstruction = ['parts' => [['text' => $msg['content']]]];
                } else {
                    $contents[] = [
                        'role' => $msg['role'] === 'assistant' ? 'model' : 'user',
                        'parts' => [['text' => $msg['content']]],
                    ];
                }
            }
            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $payload['temperature'],
                    'maxOutputTokens' => $payload['max_tokens'],
                ],
            ];
            if ($systemInstruction) {
                $payload['systemInstruction'] = $systemInstruction;
            }
            return $payload;
        }

        return $payload;
    }

    protected function parseResponse(string $providerName, array $data): array
    {
        if ($providerName === 'anthropic') {
            return [
                'content' => $data['content'][0]['text'] ?? '',
                'usage' => [
                    'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                    'output_tokens' => $data['usage']['output_tokens'] ?? 0,
                    'total_tokens' => ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
                ],
            ];
        }

        if ($providerName === 'google') {
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return [
                'content' => $text,
                'usage' => [
                    'total_tokens' => ($data['usageMetadata']['totalTokenCount'] ?? 0),
                ],
            ];
        }

        // OpenAI-compatible (DeepSeek, Groq, OpenAI, OpenRouter, Mistral)
        $message = $data['choices'][0]['message'] ?? [];
        return [
            'content' => $message['content'] ?? '',
            'tool_calls' => $message['tool_calls'] ?? [],
            'finish_reason' => $data['choices'][0]['finish_reason'] ?? null,
            'usage' => $data['usage'] ?? [],
        ];
    }
}
