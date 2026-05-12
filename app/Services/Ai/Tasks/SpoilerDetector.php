<?php

declare(strict_types=1);

namespace App\Services\Ai\Tasks;

use App\Models\Comment;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * AI-driven spoiler detection for user comments.
 *
 * Pipeline:
 * 1. Build a movie-aware classification prompt (film title is the grounding context).
 * 2. Send comment text to AiClient (default provider).
 * 3. Parse strict-JSON response: {is_spoiler: bool, confidence: 0.0-1.0, reason: string}.
 * 4. Persist verdict on the Comment row (overrides the user-self-flag if AI is confident).
 * 5. Return the parsed payload so callers (jobs, admin tools) can log/inspect it.
 *
 * Spoiler = reveals plot twist, ending, character death, key reveal.
 */
class SpoilerDetector
{
    /**
     * Confidence floor below which we keep the user's self-flag and only stamp the timestamp.
     */
    protected const OVERRIDE_THRESHOLD = 0.7;

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Detect spoiler content in a comment and persist the verdict.
     *
     * @return array{is_spoiler: bool, confidence: float, reason: string, persisted: bool}
     */
    public function detect(Comment $comment): array
    {
        $movieTitle = $comment->movie?->title ?? 'Unknown';
        $body = trim((string) $comment->body);

        if ($body === '') {
            return $this->persistResult($comment, false, 0.0, 'empty_body');
        }

        $systemPrompt = sprintf(
            'Detect if comment contains spoilers about film %s. '
            . 'Strict JSON: {is_spoiler: bool, confidence: 0.0-1.0, reason: string}. '
            . 'Spoiler = reveals plot twist, ending, character death, key reveal. '
            . 'Output WAJIB strict JSON tanpa markdown fence.',
            $movieTitle,
        );

        $userPrompt = sprintf("Film: %s\nKomentar: %s", $movieTitle, $body);

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                options: [
                    'max_tokens' => 200,
                    'temperature' => 0.0,
                ],
                taskType: 'comment.spoiler_detect',
                subject: $comment,
            );
        } catch (\Throwable $e) {
            Log::warning('SpoilerDetector AI call failed', [
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);

            // Fail-open: leave existing user-flag intact, just stamp the timestamp.
            $comment->forceFill([
                'spoiler_checked_at' => now(),
            ])->save();

            return [
                'is_spoiler' => (bool) $comment->is_spoiler,
                'confidence' => 0.0,
                'reason' => 'detector_failed: ' . $e->getMessage(),
                'persisted' => false,
            ];
        }

        $parsed = $this->parseResult((string) ($response['content'] ?? ''));

        return $this->persistResult(
            $comment,
            $parsed['is_spoiler'],
            $parsed['confidence'],
            $parsed['reason'],
        );
    }

    /**
     * Persist the verdict on the comment row.
     *
     * @return array{is_spoiler: bool, confidence: float, reason: string, persisted: bool}
     */
    protected function persistResult(Comment $comment, bool $isSpoiler, float $confidence, string $reason): array
    {
        $userFlag = (bool) $comment->is_spoiler;

        // Only flip the boolean when the AI is confident enough; otherwise keep user's
        // self-flag (a user who admits to spoilers should be trusted).
        $finalFlag = $confidence >= self::OVERRIDE_THRESHOLD
            ? $isSpoiler
            : ($userFlag || $isSpoiler);

        $comment->forceFill([
            'is_spoiler' => $finalFlag,
            'spoiler_confidence' => round($confidence, 3),
            'spoiler_checked_at' => now(),
        ])->save();

        return [
            'is_spoiler' => $finalFlag,
            'confidence' => $confidence,
            'reason' => $reason,
            'persisted' => true,
        ];
    }

    /**
     * Extract strict-JSON verdict from LLM content (tolerates code-fences / surrounding text).
     *
     * @return array{is_spoiler: bool, confidence: float, reason: string}
     */
    protected function parseResult(string $content): array
    {
        $content = trim($content);

        // Strip ``` fences if any
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content) ?? $content;
            $content = trim($content);
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) && preg_match('/\{.*\}/s', $content, $m)) {
            $decoded = json_decode($m[0], true);
        }

        if (! is_array($decoded)) {
            Log::warning('SpoilerDetector returned non-JSON', [
                'preview' => substr($content, 0, 200),
            ]);

            return [
                'is_spoiler' => false,
                'confidence' => 0.0,
                'reason' => 'parse_failed',
            ];
        }

        $isSpoiler = filter_var(
            $decoded['is_spoiler'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;

        $confidence = (float) ($decoded['confidence'] ?? 0.0);
        $confidence = max(0.0, min(1.0, $confidence));

        $reason = is_string($decoded['reason'] ?? null) ? trim($decoded['reason']) : '';
        if ($reason === '') {
            $reason = 'no reason provided';
        }

        return [
            'is_spoiler' => $isSpoiler,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }
}
