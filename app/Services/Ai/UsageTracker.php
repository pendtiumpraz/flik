<?php

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Models\AiUsageLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Records AI usage (tokens, cost, latency) per call.
 *
 * Pricing table is per million tokens (MTok) in USD. Costs are estimated from
 * provider+model name; unknown models fall back to a conservative default
 * so we don't silently log $0 for paid calls.
 *
 * Wire into AiClient by:
 *   1. Capturing `microtime(true)` before the HTTP call
 *   2. Resolving UsageTracker via app(UsageTracker::class)
 *   3. Calling ->track(...) in both success & catch branches
 */
class UsageTracker
{
    /**
     * Prices per 1M tokens in USD: [input, output].
     * Keys are matched via str_contains() against lowercased model name.
     * Order matters — more specific keys (e.g. "gpt-5-mini") must come BEFORE
     * less specific ones (e.g. "gpt-5").
     */
    protected const PRICING_PER_MTOK = [
        // ── OpenAI ────────────────────────────────────────────────
        'gpt-5.5'              => [10.00, 30.00],
        'gpt-5.4-nano'         => [0.10,  0.40],
        'gpt-5.4-mini'         => [0.25,  2.00],
        'gpt-5.4'              => [3.00,  12.00],
        'gpt-5-mini'           => [0.15,  0.60],
        'gpt-5'                => [2.50,  10.00],
        'gpt-4o-mini'          => [0.15,  0.60],
        'gpt-4o'               => [2.50,  10.00],
        'whisper'              => [0.006, 0.006], // per minute, approximated

        // ── Anthropic Claude ──────────────────────────────────────
        'claude-opus-4-7'      => [15.00, 75.00],
        'claude-sonnet-4-6'    => [3.00,  15.00],
        'claude-haiku-4-5'     => [0.80,  4.00],
        'claude-3-5-sonnet'    => [3.00,  15.00],
        'claude-3-5-haiku'     => [0.80,  4.00],

        // ── DeepSeek ──────────────────────────────────────────────
        'deepseek-v4-pro'      => [0.55,  2.20],
        'deepseek-v4-flash'    => [0.14,  0.28],
        'deepseek-reasoner'    => [0.55,  2.19],
        'deepseek-chat'        => [0.14,  0.28],

        // ── Google Gemini ─────────────────────────────────────────
        'gemini-3.0-flash'     => [0.10,  0.40],
        'gemini-2.5-pro'       => [1.25,  10.00],
        'gemini-2.5-flash-lite'=> [0.05,  0.20],
        'gemini-2.5-flash'     => [0.30,  2.50],

        // ── Groq (Llama hosted) ───────────────────────────────────
        'llama-4-maverick'     => [0.50,  0.77],
        'llama-4-scout'        => [0.11,  0.34],
        'llama-3.3-70b'        => [0.59,  0.79],
        'llama-3.1-8b'         => [0.05,  0.08],

        // ── Mistral ───────────────────────────────────────────────
        'mistral-large'        => [2.00,  6.00],
        'mistral-small'        => [0.20,  0.60],
        'codestral'            => [0.30,  0.90],
    ];

    /** Conservative fallback if model name not matched. */
    protected const FALLBACK_PRICE_PER_MTOK = [1.00, 3.00];

    /**
     * Persist a usage record and update the provider's running totals.
     *
     * @param  AiProvider  $provider
     * @param  string      $taskType   Free-form label, e.g. "chat.recommend", "subtitle.translate"
     * @param  int|null    $inTokens   Prompt tokens (null treated as 0)
     * @param  int|null    $outTokens  Completion tokens (null treated as 0)
     * @param  int|null    $latencyMs  Wall-clock latency in milliseconds
     * @param  bool        $success    Whether the call succeeded
     * @param  string|null $error      Error message (truncated)
     * @param  Model|null  $subject    Optional related model (Movie, User, etc.)
     */
    public function track(
        AiProvider $provider,
        string $taskType,
        ?int $inTokens,
        ?int $outTokens,
        ?int $latencyMs = null,
        bool $success = true,
        ?string $error = null,
        ?Model $subject = null,
        bool $cacheHit = false,
    ): AiUsageLog {
        $in  = max(0, (int) ($inTokens ?? 0));
        $out = max(0, (int) ($outTokens ?? 0));
        $cost = $this->computeCost($provider->model, $in, $out);

        try {
            $log = AiUsageLog::create([
                'ai_provider_id' => $provider->id,
                'task_type'      => $taskType,
                'subject_type'   => $subject?->getMorphClass(),
                'subject_id'     => $subject?->getKey(),
                'input_tokens'   => $in,
                'output_tokens'  => $out,
                'cost_usd'       => $cost,
                'latency_ms'     => $latencyMs,
                'cache_hit'      => $cacheHit,
                'success'        => $success,
                'error_message'  => $error ? mb_substr($error, 0, 2000) : null,
            ]);

            // Roll up totals on the provider row (matches existing AiClient pattern).
            $provider->update([
                'last_used_at'      => now(),
                'total_tokens_used' => $provider->total_tokens_used + $in + $out,
                'total_cost_usd'    => (float) $provider->total_cost_usd + $cost,
            ]);

            return $log;
        } catch (\Throwable $e) {
            // Tracking must never break the calling code.
            Log::warning('UsageTracker failed to persist', [
                'provider_id' => $provider->id,
                'task_type'   => $taskType,
                'error'       => $e->getMessage(),
            ]);

            // Return an unsaved instance so callers can still chain if they want.
            return new AiUsageLog([
                'ai_provider_id' => $provider->id,
                'task_type'      => $taskType,
                'input_tokens'   => $in,
                'output_tokens'  => $out,
                'cost_usd'       => $cost,
            ]);
        }
    }

    /**
     * Compute USD cost from token counts and a model name.
     * Public so admin tooling / unit tests can probe pricing.
     */
    public function computeCost(string $modelName, int $inTokens, int $outTokens): float
    {
        [$inPrice, $outPrice] = $this->resolvePricing($modelName);

        $cost = ($inTokens / 1_000_000) * $inPrice
              + ($outTokens / 1_000_000) * $outPrice;

        // 6-decimal precision matches migration column.
        return round($cost, 6);
    }

    /**
     * @return array{0: float, 1: float}  [input_price_per_mtok, output_price_per_mtok]
     */
    protected function resolvePricing(string $modelName): array
    {
        $needle = mb_strtolower($modelName);

        foreach (self::PRICING_PER_MTOK as $key => $price) {
            if (str_contains($needle, $key)) {
                return $price;
            }
        }

        return self::FALLBACK_PRICE_PER_MTOK;
    }
}
