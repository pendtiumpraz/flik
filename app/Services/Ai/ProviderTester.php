<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Exceptions\SsrfException;
use App\Models\AiProvider;
use App\Services\Security\SsrfGuard;
use Illuminate\Support\Facades\Http;

/**
 * Connection tester for AiProvider entries.
 *
 * Sends a minimal "Reply with: OK" prompt to the configured provider + model
 * and returns a structured result. Does NOT update provider usage counters
 * (last_used_at / total_tokens_used) — this is a diagnostic, not real usage.
 *
 * Why we don't reuse AiClient::chat():
 *   - AiClient mutates provider rows (last_used_at, total_tokens_used).
 *   - AiClient picks the *default* active provider; here we test the row the
 *     admin selected, even if it's inactive.
 *   - We want all exceptions caught & shaped into a uniform array for the UI.
 */
class ProviderTester
{
    /** Maximum body bytes we surface back to the UI on error. */
    private const ERROR_BODY_PREVIEW = 400;

    /** Test request HTTP timeout (seconds). Shorter than AiClient's 30s — admin is waiting. */
    private const TIMEOUT_SECONDS = 15;

    public function __construct(private readonly SsrfGuard $ssrfGuard = new SsrfGuard())
    {
    }

    /**
     * Run a connection test against a single provider.
     *
     * @return array{
     *     success: bool,
     *     latency_ms: int,
     *     response: string,
     *     error: string|null,
     *     model: string,
     *     usage: array<string, int|float>,
     * }
     */
    public function test(AiProvider $provider): array
    {
        $startedAt = microtime(true);

        $result = [
            'success'    => false,
            'latency_ms' => 0,
            'response'   => '',
            'error'      => null,
            'model'      => (string) $provider->model,
            'usage'      => [],
        ];

        try {
            if (empty($provider->api_key)) {
                throw new \RuntimeException('API key is empty for this provider.');
            }

            $messages = [
                ['role' => 'system', 'content' => 'You are a connectivity probe. Respond with exactly: OK'],
                ['role' => 'user',   'content' => 'Reply with: OK'],
            ];

            $base = rtrim($provider->base_url ?: $this->defaultBaseUrl($provider->provider), '/');
            if ($base === '') {
                throw new \RuntimeException('No base_url configured for provider type "' . $provider->provider . '".');
            }

            $endpoint = match ($provider->provider) {
                'anthropic' => $base . '/messages',
                'google'    => $base . '/models/' . $provider->model . ':generateContent?key=' . $provider->api_key,
                default     => $base . '/chat/completions',
            };

            $payload = $this->buildPayload($provider, $messages);
            $headers = $this->buildHeaders($provider);

            // SSRF guard — refuse to "test" a base_url that resolves to a
            // private IP / cloud-metadata host. The test endpoint is the
            // most attractive SSRF surface in admin (arbitrary URL input).
            try {
                $this->ssrfGuard->assertUrlAllowed($endpoint);
            } catch (SsrfException $e) {
                throw new \RuntimeException('Base URL rejected by SSRF guard: ' . $e->getMessage());
            }

            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(5)
                ->withHeaders($headers)
                ->withOptions([
                    'allow_redirects' => [
                        'max'       => 3,
                        'protocols' => ['http', 'https'],
                        'strict'    => true,
                    ],
                ])
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                $body = substr($response->body(), 0, self::ERROR_BODY_PREVIEW);
                throw new \RuntimeException('HTTP ' . $response->status() . ' — ' . ($body ?: 'no body'));
            }

            $parsed = $this->parseResponse($provider->provider, $response->json() ?? []);

            $result['success']  = true;
            $result['response'] = $parsed['content'];
            $result['usage']    = $parsed['usage'];
        } catch (\Throwable $e) {
            $result['error'] = $this->formatError($e);
        } finally {
            $result['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        }

        return $result;
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    private function buildPayload(AiProvider $provider, array $messages): array
    {
        if ($provider->provider === 'anthropic') {
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
                'model'       => $provider->model,
                'system'      => trim($system) ?: 'You are a connectivity probe.',
                'messages'    => $userMessages,
                'max_tokens'  => 16,
                'temperature' => 0,
            ];
        }

        if ($provider->provider === 'google') {
            $contents = [];
            $systemInstruction = null;
            foreach ($messages as $msg) {
                if ($msg['role'] === 'system') {
                    $systemInstruction = ['parts' => [['text' => $msg['content']]]];
                } else {
                    $contents[] = [
                        'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                        'parts' => [['text' => $msg['content']]],
                    ];
                }
            }

            $payload = [
                'contents'         => $contents,
                'generationConfig' => [
                    'temperature'     => 0,
                    'maxOutputTokens' => 16,
                ],
            ];
            if ($systemInstruction !== null) {
                $payload['systemInstruction'] = $systemInstruction;
            }

            return $payload;
        }

        // OpenAI-compatible (openai, deepseek, groq, mistral, openrouter, custom)
        return [
            'model'       => $provider->model,
            'messages'    => $messages,
            'max_tokens'  => 16,
            'temperature' => 0,
            'stream'      => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(AiProvider $provider): array
    {
        $headers = ['Content-Type' => 'application/json'];

        switch ($provider->provider) {
            case 'anthropic':
                $headers['x-api-key']         = (string) $provider->api_key;
                $headers['anthropic-version'] = '2023-06-01';
                break;
            case 'google':
                // API key is in the URL — no auth header needed.
                break;
            default:
                $headers['Authorization'] = 'Bearer ' . (string) $provider->api_key;
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{content: string, usage: array<string, int|float>}
     */
    private function parseResponse(string $providerName, array $data): array
    {
        if ($providerName === 'anthropic') {
            $input  = (int) ($data['usage']['input_tokens'] ?? 0);
            $output = (int) ($data['usage']['output_tokens'] ?? 0);

            return [
                'content' => (string) ($data['content'][0]['text'] ?? ''),
                'usage'   => [
                    'input_tokens'  => $input,
                    'output_tokens' => $output,
                    'total_tokens'  => $input + $output,
                ],
            ];
        }

        if ($providerName === 'google') {
            return [
                'content' => (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''),
                'usage'   => [
                    'total_tokens' => (int) ($data['usageMetadata']['totalTokenCount'] ?? 0),
                ],
            ];
        }

        // OpenAI-compatible
        $usage = $data['usage'] ?? [];
        if (!is_array($usage)) {
            $usage = [];
        }

        return [
            'content' => (string) ($data['choices'][0]['message']['content'] ?? ''),
            'usage'   => $usage,
        ];
    }

    private function defaultBaseUrl(string $providerName): string
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

    private function formatError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        // Connection-level failures from Guzzle/cURL — make them less scary for admins.
        if ($e instanceof \Illuminate\Http\Client\ConnectionException) {
            return 'Connection failed: ' . $msg;
        }

        return $msg !== '' ? $msg : (new \ReflectionClass($e))->getShortName();
    }
}
