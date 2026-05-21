<?php

namespace App\Services\Ai\Tasks;

use App\Models\Comment;
use App\Services\Ai\AiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Sentiment-analyse user comments via the default AI provider.
 *
 * Two entry points:
 *   - analyze(Comment) ........... single comment, single round-trip
 *   - analyzeBulk(Collection) .... batched (one prompt, many comments) — far cheaper
 *
 * In both cases the comment(s) are persisted in-place with:
 *   sentiment              positive|negative|neutral|mixed
 *   sentiment_score        float in [-1.0, 1.0]
 *   sentiment_analyzed_at  now()
 *
 * Errors are caught and logged — never thrown — so the queue worker keeps draining.
 */
class CommentSentimentAnalyzer
{
    /**
     * Allowed sentiment labels. Anything else from the model gets coerced to 'neutral'.
     *
     * @var array<int, string>
     */
    protected const LABELS = ['positive', 'negative', 'neutral', 'mixed'];

    /**
     * Maximum comments per bulk AI call. Above this the caller should chunk —
     * keeps a single failure from invalidating hundreds of analyses and stays
     * well under typical provider context budgets.
     */
    public const BULK_CHUNK_SIZE = 40;

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Analyse one comment.
     *
     * @return array{sentiment: string, score: float}
     */
    public function analyze(Comment $comment): array
    {
        $body = trim((string) $comment->body);
        if ($body === '') {
            return $this->persist($comment, 'neutral', 0.0);
        }

        $movieTitle = $comment->movie?->title ?? 'Unknown';

        $systemPrompt = <<<'SYS'
You are a sentiment classifier for an Indonesian streaming platform (FLiK). Classify the user's feeling about the FILM (not the moderation status). Output strict JSON only — no prose, no markdown fences. Schema: {"sentiment":"positive|negative|neutral|mixed","score": -1.0 to 1.0}. Score is on the negative↔positive axis: -1.0 = very negative, 0.0 = neutral, +1.0 = very positive. "mixed" = comment praises and criticises (score near 0). The text may be Indonesian, English, or mixed.
SYS;

        $userPrompt = "Movie: {$movieTitle}\nComment: {$body}";

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                options: [
                    'max_tokens' => 100,
                    'temperature' => 0.0,
                ],
                taskType: 'comment.sentiment_single',
                subject: $comment,
            );

            $parsed = $this->parseSingle($response['content'] ?? '');
        } catch (\Throwable $e) {
            Log::warning('CommentSentimentAnalyzer single-call failed', [
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);

            return ['sentiment' => 'neutral', 'score' => 0.0];
        }

        return $this->persist($comment, $parsed['sentiment'], $parsed['score']);
    }

    /**
     * Batch-analyse a collection of comments in a single AI call.
     *
     * The prompt asks the model to return a JSON array where each element is
     * keyed by `index` — same ordinal as the input list. We tolerate the model
     * returning items out of order or with gaps; missing items are skipped.
     *
     * @param  Collection<int, Comment>  $comments
     */
    public function analyzeBulk(Collection $comments): void
    {
        $comments = $comments->values()->filter(fn (Comment $c) => trim((string) $c->body) !== '');
        if ($comments->isEmpty()) {
            return;
        }

        // Chunk to keep a single failure (or runaway response) bounded.
        $comments->chunk(self::BULK_CHUNK_SIZE)->each(function (Collection $chunk): void {
            $this->analyzeChunk($chunk->values());
        });
    }

    /**
     * Inner worker for a single bulk chunk.
     *
     * @param  Collection<int, Comment>  $chunk  zero-indexed
     */
    protected function analyzeChunk(Collection $chunk): void
    {
        $count = $chunk->count();

        // Build the per-comment list given to the model.
        $items = $chunk->map(function (Comment $c, int $i): array {
            return [
                'index' => $i,
                'movie' => $c->movie?->title ?? 'Unknown',
                'text' => mb_substr(trim((string) $c->body), 0, 800),
            ];
        })->all();

        $systemPrompt = "You are a sentiment classifier for an Indonesian streaming platform (FLiK). "
            . "Classify sentiment of these {$count} comments. "
            . "Output JSON array with same order: "
            . "[{\"index\":int, \"sentiment\":\"positive|negative|neutral|mixed\", \"score\": -1.0 to 1.0}]. "
            . "Score axis: -1.0 = very negative, 0.0 = neutral, +1.0 = very positive. "
            . "Comments may be Indonesian, English, or mixed. "
            . "Return ONLY the JSON array — no prose, no markdown fences.";

        $userPrompt = "Comments to classify (one object per item, same order):\n"
            . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                options: [
                    // Generous token budget — ~25 tokens/item plus framing.
                    'max_tokens' => min(4000, 80 + ($count * 40)),
                    'temperature' => 0.0,
                ],
                taskType: 'comment.sentiment_bulk',
                // No single subject — bulk run covers many comments. The
                // chunked dispatch keeps any single failure bounded; the
                // log row is enough for "which run".
            );

            $results = $this->parseBulk($response['content'] ?? '', $count);
        } catch (\Throwable $e) {
            Log::warning('CommentSentimentAnalyzer bulk call failed', [
                'count' => $count,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($results as $idx => $result) {
            $comment = $chunk->get($idx);
            if (!$comment instanceof Comment) {
                continue;
            }
            $this->persist($comment, $result['sentiment'], $result['score']);
        }
    }

    /**
     * Persist the parsed result onto the comment, returning the normalised values.
     *
     * @return array{sentiment: string, score: float}
     */
    protected function persist(Comment $comment, string $sentiment, float $score): array
    {
        $sentiment = $this->normaliseLabel($sentiment);
        $score = $this->clampScore($score);

        // Update directly via the query builder so we don't trip model events
        // (and so `$casts` doesn't need updating in the model — the columns are
        // simple scalars on read).
        Comment::query()
            ->whereKey($comment->id)
            ->update([
                'sentiment' => $sentiment,
                'sentiment_score' => $score,
                'sentiment_analyzed_at' => now(),
            ]);

        $comment->setAttribute('sentiment', $sentiment);
        $comment->setAttribute('sentiment_score', $score);
        $comment->setAttribute('sentiment_analyzed_at', now());

        return ['sentiment' => $sentiment, 'score' => $score];
    }

    /**
     * Parse a single-comment response.
     *
     * @return array{sentiment: string, score: float}
     */
    protected function parseSingle(string $content): array
    {
        $json = $this->extractFirstJson($content, expectArray: false);
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (!is_array($decoded)) {
            throw new \RuntimeException('Sentiment analyzer returned non-JSON: ' . mb_substr($content, 0, 200));
        }

        return [
            'sentiment' => $this->normaliseLabel((string) ($decoded['sentiment'] ?? 'neutral')),
            'score' => $this->clampScore((float) ($decoded['score'] ?? 0.0)),
        ];
    }

    /**
     * Parse a bulk response — returns a map keyed by the input index.
     *
     * @return array<int, array{sentiment: string, score: float}>
     */
    protected function parseBulk(string $content, int $expected): array
    {
        $json = $this->extractFirstJson($content, expectArray: true);
        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (!is_array($decoded)) {
            Log::warning('CommentSentimentAnalyzer bulk parse: non-JSON', [
                'expected' => $expected,
                'raw' => mb_substr($content, 0, 400),
            ]);

            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $idx = $item['index'] ?? null;
            if (!is_int($idx) && !(is_string($idx) && ctype_digit($idx))) {
                continue;
            }
            $idx = (int) $idx;
            if ($idx < 0 || $idx >= $expected) {
                continue;
            }

            $out[$idx] = [
                'sentiment' => $this->normaliseLabel((string) ($item['sentiment'] ?? 'neutral')),
                'score' => $this->clampScore((float) ($item['score'] ?? 0.0)),
            ];
        }

        return $out;
    }

    /**
     * Pull the first JSON object/array out of the model's response.
     * Tolerates markdown fences and surrounding prose.
     */
    protected function extractFirstJson(string $raw, bool $expectArray): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw, $m)) {
            $raw = $m[1];
        }

        $open = $expectArray ? '[' : '{';
        $close = $expectArray ? ']' : '}';

        $start = strpos($raw, $open);
        $end = strrpos($raw, $close);
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($raw, $start, $end - $start + 1);
    }

    protected function normaliseLabel(string $value): string
    {
        $s = strtolower(trim($value));
        return in_array($s, self::LABELS, true) ? $s : 'neutral';
    }

    protected function clampScore(float $value): float
    {
        if (is_nan($value) || !is_finite($value)) {
            return 0.0;
        }
        return round(max(-1.0, min(1.0, $value)), 3);
    }
}
