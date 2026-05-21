<?php

namespace App\Services\Ai\Tasks;

use App\Models\Comment;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;

/**
 * Auto-moderate user comments via LLM.
 *
 * Pipeline:
 * 1. Build classification prompt (Indonesian context — FLiK is a local streaming platform)
 * 2. Send comment text + movie title to AiClient (default provider)
 * 3. Parse strict-JSON response: {label, confidence, reason}
 * 4. Update comment moderation columns
 * 5. If label != safe AND confidence > 0.7 → flag (hide from public)
 *
 * Returns the parsed AI result so callers (jobs, admin tools) can log/inspect it.
 */
class CommentModerator
{
    /**
     * Confidence threshold above which a non-safe label triggers auto-flag.
     */
    protected const FLAG_THRESHOLD = 0.7;

    /**
     * Allowed label values from the model.
     *
     * @var array<int, string>
     */
    protected const LABELS = ['safe', 'toxic', 'spam', 'off_topic', 'inappropriate'];

    public function __construct(
        protected AiClient $ai
    ) {}

    /**
     * Classify a comment and update its moderation fields.
     *
     * @return array{label: string, confidence: float, reason: string, status: string, flagged: bool}
     */
    public function moderate(Comment $comment): array
    {
        $movieTitle = $comment->movie?->title ?? 'Unknown';
        $body = trim((string) $comment->body);

        $systemPrompt = <<<'SYS'
You're a content moderator for Indonesian streaming platform FLiK. Classify the following comment. Output strict JSON: {"label":"safe|toxic|spam|off_topic|inappropriate", "confidence": 0.0-1.0, "reason": "brief explanation"}
SYS;

        $userPrompt = "Movie: {$movieTitle}\nComment: {$body}";

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
                taskType: 'comment.moderate',
                subject: $comment,
            );

            $parsed = $this->parseResult($response['content'] ?? '');
        } catch (\Throwable $e) {
            Log::warning('CommentModerator failed; defaulting to approved', [
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);

            // Fail-open: keep visible, mark as pending so admin can re-run later.
            $comment->forceFill([
                'moderation_status' => 'pending',
                'moderation_label' => null,
                'moderation_score' => null,
                'moderated_at' => now(),
                'is_visible' => true,
            ])->save();

            return [
                'label' => 'safe',
                'confidence' => 0.0,
                'reason' => 'moderation_failed: ' . $e->getMessage(),
                'status' => 'pending',
                'flagged' => false,
            ];
        }

        $label = $parsed['label'];
        $confidence = $parsed['confidence'];
        $reason = $parsed['reason'];

        $shouldFlag = $label !== 'safe' && $confidence > self::FLAG_THRESHOLD;
        $status = $shouldFlag ? 'flagged' : 'approved';
        $isVisible = !$shouldFlag;

        $comment->forceFill([
            'moderation_status' => $status,
            'moderation_label' => $label,
            'moderation_score' => round($confidence, 2),
            'moderated_at' => now(),
            'is_visible' => $isVisible,
        ])->save();

        return [
            'label' => $label,
            'confidence' => $confidence,
            'reason' => $reason,
            'status' => $status,
            'flagged' => $shouldFlag,
        ];
    }

    /**
     * Extract strict-JSON result from LLM content (tolerates code-fences / surrounding text).
     *
     * @return array{label: string, confidence: float, reason: string}
     */
    protected function parseResult(string $content): array
    {
        $content = trim($content);

        // Strip ``` fences if any
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content) ?? $content;
            $content = trim($content);
        }

        // Try direct decode; otherwise look for first {...} block.
        $decoded = json_decode($content, true);
        if (!is_array($decoded) && preg_match('/\{.*\}/s', $content, $m)) {
            $decoded = json_decode($m[0], true);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Moderator returned non-JSON: ' . substr($content, 0, 200));
        }

        $label = is_string($decoded['label'] ?? null) ? strtolower(trim($decoded['label'])) : 'safe';
        if (!in_array($label, self::LABELS, true)) {
            $label = 'safe';
        }

        $confidence = (float) ($decoded['confidence'] ?? 0.0);
        $confidence = max(0.0, min(1.0, $confidence));

        $reason = is_string($decoded['reason'] ?? null) ? trim($decoded['reason']) : '';
        if ($reason === '') {
            $reason = 'no reason provided';
        }

        return [
            'label' => $label,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }
}
