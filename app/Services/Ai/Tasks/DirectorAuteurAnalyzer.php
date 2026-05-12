<?php

namespace App\Services\Ai\Tasks;

use App\Models\DirectorAnalysis;
use App\Services\Ai\AiClient;
use App\Services\Ai\WebSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Director Auteur Analysis (O12).
 *
 * Pipeline:
 *   1. Web-search "{name} film director auteur style" via WebSearchService
 *      (Wikipedia first, DuckDuckGo fallback).
 *   2. Inject the search snippets as grounding context into a strict-JSON
 *      AI prompt that asks for a 6-field auteur breakdown in Indonesian
 *      (signature_style / recurring_themes / frequent_collaborators /
 *       influence / essential_films / trivia).
 *   3. Persist the result in `director_analyses` (one row per director name)
 *      and cache the parsed array for 7 days keyed by the normalised name.
 *
 * Re-running for an existing director updates the row in place AND refreshes
 * the cache. The 7-day cache only short-circuits the AI call — it does not
 * gate persistence, so re-analyses always return current data.
 */
class DirectorAuteurAnalyzer
{
    /** Cache TTL for the parsed auteur analysis. */
    protected const CACHE_TTL_DAYS = 7;

    public function __construct(
        protected AiClient $ai,
        protected WebSearchService $search,
    ) {}

    /**
     * Generate (or load from cache) the auteur analysis for a director.
     *
     * @param  string $directorName  Free-form director name (e.g. "Christopher Nolan").
     * @param  int|null $count       Reserved for future use — number of essential films
     *                               to favour in the prompt. Defaults to 5.
     * @return array{
     *     director_name: string,
     *     slug: string,
     *     analysis: array<string, mixed>,
     *     source_urls: array<int, string>,
     *     cached: bool,
     *     generated_at: ?string
     * }
     */
    public function analyze(string $directorName, ?int $count = 5): array
    {
        $directorName = trim($directorName);
        if ($directorName === '') {
            throw new \InvalidArgumentException('Director name is required.');
        }

        $essentialCount = max(3, min(10, $count ?? 5));
        $cacheKey = $this->cacheKey($directorName);

        return Cache::remember(
            $cacheKey,
            now()->addDays(self::CACHE_TTL_DAYS),
            fn () => $this->analyzeFresh($directorName, $essentialCount),
        );
    }

    /**
     * Force a fresh analysis bypassing the cache. Returns the same payload
     * as analyze() and primes the cache afterwards.
     *
     * @return array{
     *     director_name: string,
     *     slug: string,
     *     analysis: array<string, mixed>,
     *     source_urls: array<int, string>,
     *     cached: bool,
     *     generated_at: ?string
     * }
     */
    public function refresh(string $directorName, ?int $count = 5): array
    {
        $directorName = trim($directorName);
        if ($directorName === '') {
            throw new \InvalidArgumentException('Director name is required.');
        }

        $essentialCount = max(3, min(10, $count ?? 5));
        $cacheKey = $this->cacheKey($directorName);

        Cache::forget($cacheKey);

        $payload = $this->analyzeFresh($directorName, $essentialCount);
        Cache::put($cacheKey, $payload, now()->addDays(self::CACHE_TTL_DAYS));

        return $payload;
    }

    /**
     * Run the full pipeline (search → AI → persist). No cache lookup.
     *
     * @return array{
     *     director_name: string,
     *     slug: string,
     *     analysis: array<string, mixed>,
     *     source_urls: array<int, string>,
     *     cached: bool,
     *     generated_at: ?string
     * }
     */
    protected function analyzeFresh(string $directorName, int $essentialCount): array
    {
        // ━━━ 1. Web search for grounding ━━━
        $query = "{$directorName} film director auteur style";
        $results = [];
        try {
            $results = $this->search->search($query, 5);
        } catch (\Throwable $e) {
            Log::warning('DirectorAuteurAnalyzer: web search failed, continuing without context', [
                'director' => $directorName,
                'error'    => $e->getMessage(),
            ]);
        }

        $sourceUrls = array_values(array_filter(array_map(
            fn ($r) => is_array($r) ? ($r['url'] ?? null) : null,
            $results,
        )));

        // ━━━ 2. AI call (strict JSON) ━━━
        $systemPrompt = $this->buildSystemPrompt($directorName);
        $userPrompt   = $this->buildUserPrompt($directorName, $results, $essentialCount);

        $analysis = [];
        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                [
                    'max_tokens'  => 1800,
                    'temperature' => 0.45,
                ],
            );

            $analysis = $this->parseJson($response['content'] ?? '');
        } catch (\Throwable $e) {
            Log::error('DirectorAuteurAnalyzer: AI call failed', [
                'director' => $directorName,
                'error'    => $e->getMessage(),
            ]);
            // Fall through with empty analysis — caller still gets a usable shape.
        }

        $analysis = $this->normalizeAnalysisShape($analysis);

        // ━━━ 3. Persist (idempotent upsert by director_name) ━━━
        $row = DirectorAnalysis::updateOrCreate(
            ['director_name' => $directorName],
            [
                'data'         => $analysis,
                'source_urls'  => $sourceUrls,
                'generated_at' => now(),
            ],
        );

        return [
            'director_name' => $row->director_name,
            'slug'          => $row->slug,
            'analysis'      => $analysis,
            'source_urls'   => $sourceUrls,
            'cached'        => false,
            'generated_at'  => $row->generated_at?->toIso8601String(),
        ];
    }

    /**
     * Build the system prompt enforcing the strict JSON shape.
     */
    protected function buildSystemPrompt(string $directorName): string
    {
        return "You're a film academic. Analyze the auteur style of director {$directorName}. "
            . 'Output strict JSON: {"signature_style": "visual/directing trademarks", '
            . '"recurring_themes": [list of themes], '
            . '"frequent_collaborators": [actors/cinematographers], '
            . '"influence": "who influenced them, who they influenced", '
            . '"essential_films": [{ "title": "...", "year": 2000, "why_essential": "..." }], '
            . '"trivia": [3 fun facts]}. '
            . 'Indonesian language for fields except names. '
            . 'Return ONLY the JSON object — no markdown fences, no prose, no preamble.';
    }

    /**
     * Build the user prompt with web search context.
     *
     * @param  array<int, array{title?:string, snippet?:string, url?:string}> $results
     */
    protected function buildUserPrompt(string $directorName, array $results, int $essentialCount): string
    {
        $context = "Konteks dari Wikipedia / web search untuk director '{$directorName}':\n";
        if (empty($results)) {
            $context .= "(tidak ada hasil pencarian tersedia — gunakan pengetahuan internal anda)\n";
        } else {
            foreach ($results as $i => $r) {
                $context .= sprintf(
                    "\n[%d] %s\nURL: %s\n%s\n",
                    $i + 1,
                    $r['title'] ?? '',
                    $r['url'] ?? '',
                    trim((string) ($r['snippet'] ?? '')),
                );
            }
        }

        return $context . "\n\n"
            . "Tugas: Analisis gaya auteur dari sutradara '{$directorName}'. "
            . "Sertakan {$essentialCount} film esensial dalam essential_films. "
            . "Trivia harus 3 fakta menarik. "
            . "Bahasa Indonesia untuk semua field deskriptif (signature_style, recurring_themes, "
            . "influence, why_essential, trivia). Nama orang/film tetap dalam ejaan aslinya. "
            . "Kembalikan HANYA objek JSON, tanpa teks lain.";
    }

    /**
     * Tolerantly parse the AI response into an associative array.
     * Strips markdown fences and surrounding prose.
     *
     * @return array<string, mixed>
     */
    protected function parseJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Strip ```json … ``` fences
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        // Extract first JSON object substring (handles preamble / trailing prose)
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Guarantee every documented key exists with a sane default so
     * downstream views can render without isset() checks.
     *
     * @param  array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    protected function normalizeAnalysisShape(array $analysis): array
    {
        $normalized = [
            'signature_style'        => is_string($analysis['signature_style'] ?? null)
                ? trim($analysis['signature_style'])
                : '',
            'recurring_themes'       => $this->toStringList($analysis['recurring_themes'] ?? []),
            'frequent_collaborators' => $this->toStringList($analysis['frequent_collaborators'] ?? []),
            'influence'              => is_string($analysis['influence'] ?? null)
                ? trim($analysis['influence'])
                : '',
            'essential_films'        => [],
            'trivia'                 => $this->toStringList($analysis['trivia'] ?? []),
        ];

        foreach (($analysis['essential_films'] ?? []) as $film) {
            if (!is_array($film)) {
                continue;
            }
            $title = isset($film['title']) ? trim((string) $film['title']) : '';
            if ($title === '') {
                continue;
            }
            $normalized['essential_films'][] = [
                'title'         => $title,
                'year'          => isset($film['year']) && is_numeric($film['year'])
                    ? (int) $film['year']
                    : null,
                'why_essential' => isset($film['why_essential'])
                    ? trim((string) $film['why_essential'])
                    : '',
            ];
        }

        return $normalized;
    }

    /**
     * Coerce a value into a flat list of non-empty strings.
     *
     * @return array<int, string>
     */
    protected function toStringList(mixed $val): array
    {
        if (!is_array($val)) {
            return [];
        }
        $out = [];
        foreach ($val as $v) {
            if (is_string($v)) {
                $v = trim($v);
                if ($v !== '') {
                    $out[] = $v;
                }
            } elseif (is_array($v) && isset($v['name']) && is_string($v['name'])) {
                $name = trim($v['name']);
                if ($name !== '') {
                    $out[] = $name;
                }
            }
        }
        return $out;
    }

    protected function cacheKey(string $directorName): string
    {
        return 'director_auteur:' . md5(mb_strtolower(trim($directorName)));
    }
}
