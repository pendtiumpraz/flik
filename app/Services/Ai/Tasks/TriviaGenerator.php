<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\MovieTrivia;
use App\Services\Ai\AiClient;
use App\Services\Ai\WebSearchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generate movie trivia (8 verified facts) using:
 *   1. WebSearchService → fetch Wikipedia article(s) about the film
 *   2. AiClient → extract facts as strict JSON, grounded ONLY in the Wikipedia content
 *
 * Idempotent: existing rows for the movie are deleted then reinserted in one batch.
 * Facts are written in Bahasa Indonesia.
 */
class TriviaGenerator
{
    /**
     * Categories allowed in the AI output (mirrors enum in migration).
     */
    protected const ALLOWED_CATEGORIES = [
        MovieTrivia::CATEGORY_PRODUCTION,
        MovieTrivia::CATEGORY_CAST,
        MovieTrivia::CATEGORY_RECEPTION,
        MovieTrivia::CATEGORY_BEHIND_SCENES,
        MovieTrivia::CATEGORY_EASTER_EGG,
        MovieTrivia::CATEGORY_CULTURAL,
    ];

    public function __construct(
        protected AiClient $ai,
        protected WebSearchService $search,
    ) {}

    /**
     * Generate up to $count trivia rows for the given movie.
     *
     * @return Collection<int, MovieTrivia>
     */
    public function generate(Movie $movie, int $count = 8): Collection
    {
        $count = max(1, min(20, $count));

        // ━━━ 1. Build Wikipedia query and fetch ━━━
        $year       = $movie->release_date?->format('Y');
        $titleQuery = trim($movie->original_title ?: $movie->title);
        $query      = $year
            ? "{$titleQuery} {$year} film"
            : "{$titleQuery} film";

        $results = $this->search->search($query, 5);

        if (empty($results)) {
            Log::info('TriviaGenerator: no web results', [
                'movie_id' => $movie->id,
                'query'    => $query,
            ]);
            return collect();
        }

        // First result wins as primary source URL.
        $primarySourceUrl = $results[0]['url'] ?? null;

        // ━━━ 2. Build prompts ━━━
        $systemPrompt = sprintf(
            'You are a film trivia expert. Based on the Wikipedia content provided, extract %d interesting facts about this film. '
            . 'Output strict JSON array: [{"fact":"...","category":"production|cast|reception|behind_scenes|easter_egg|cultural"}]. '
            . 'Facts must be VERIFIED from the Wikipedia content — no speculation. In Indonesian. '
            . 'Return ONLY the JSON array, no prose, no markdown code fences.',
            $count
        );

        $userPrompt = $this->buildUserPrompt($movie, $results, $count);

        // ━━━ 3. Call AI ━━━
        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                options: [
                    'max_tokens'  => 1800,
                    'temperature' => 0.3,
                ],
                taskType: 'trivia.generate',
                subject: $movie,
            );
        } catch (\Throwable $e) {
            Log::error('TriviaGenerator: AI call failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
            return collect();
        }

        $facts = $this->parseFacts($response['content'] ?? '');

        if (empty($facts)) {
            Log::warning('TriviaGenerator: AI returned no parseable facts', [
                'movie_id' => $movie->id,
                'raw'      => mb_substr((string) ($response['content'] ?? ''), 0, 400),
            ]);
            return collect();
        }

        // ━━━ 4. Idempotent write: deleteFirst → insertBatch ━━━
        $now = now();
        $rows = [];
        foreach (array_slice($facts, 0, $count) as $idx => $item) {
            $fact     = trim((string) ($item['fact'] ?? ''));
            $category = $this->normalizeCategory($item['category'] ?? null);
            if ($fact === '') continue;

            $rows[] = [
                'movie_id'    => $movie->id,
                'fact'        => $fact,
                'category'    => $category,
                'sort_order'  => $idx,
                'source_url'  => $primarySourceUrl,
                'is_verified' => false,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        if (empty($rows)) {
            return collect();
        }

        DB::transaction(function () use ($movie, $rows) {
            MovieTrivia::where('movie_id', $movie->id)->delete();
            MovieTrivia::insert($rows);
        });

        return MovieTrivia::where('movie_id', $movie->id)
            ->ordered()
            ->get();
    }

    /**
     * Compose the user prompt with movie metadata + Wikipedia snippets.
     *
     * @param array<int, array{title:string, snippet:string, url:string}> $results
     */
    protected function buildUserPrompt(Movie $movie, array $results, int $count): string
    {
        $meta = sprintf(
            "Film metadata:\n- Title: %s\n- Original title: %s\n- Year: %s\n- Overview: %s",
            $movie->title,
            $movie->original_title ?: $movie->title,
            $movie->release_date?->format('Y') ?: 'unknown',
            mb_substr((string) $movie->overview, 0, 400),
        );

        $wiki = "Wikipedia / web search results:\n";
        foreach ($results as $i => $r) {
            $wiki .= sprintf(
                "\n[%d] %s\nURL: %s\n%s\n",
                $i + 1,
                $r['title'] ?? '',
                $r['url'] ?? '',
                trim((string) ($r['snippet'] ?? '')),
            );
        }

        $allowedList = implode('|', self::ALLOWED_CATEGORIES);

        return $meta . "\n\n" . $wiki . "\n\n"
            . "Tugas: Ekstrak {$count} fakta menarik tentang film ini dari konten di atas. "
            . "Setiap fakta HARUS bisa diverifikasi dari konten yang diberikan. "
            . "Tulis fakta dalam Bahasa Indonesia yang jelas dan ringkas (1–2 kalimat). "
            . "Kategori valid: {$allowedList}. "
            . "Kembalikan HANYA JSON array, tanpa teks lain.";
    }

    /**
     * Parse AI response content into array of {fact, category}.
     * Tolerates markdown code fences and surrounding prose.
     *
     * @return array<int, array{fact:string, category:?string}>
     */
    protected function parseFacts(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];

        // Strip markdown code fences if present.
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        // Find the first JSON array in the string.
        if (preg_match('/\[\s*\{.*\}\s*\]/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) continue;
            $fact = $item['fact'] ?? null;
            if (!is_string($fact) || trim($fact) === '') continue;
            $out[] = [
                'fact'     => trim($fact),
                'category' => isset($item['category']) && is_string($item['category'])
                    ? $item['category']
                    : null,
            ];
        }

        return $out;
    }

    protected function normalizeCategory(mixed $category): string
    {
        if (!is_string($category)) {
            return MovieTrivia::CATEGORY_PRODUCTION;
        }

        $c = strtolower(trim($category));
        $c = str_replace([' ', '-'], '_', $c);

        return in_array($c, self::ALLOWED_CATEGORIES, true)
            ? $c
            : MovieTrivia::CATEGORY_PRODUCTION;
    }
}
