<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\MovieBehindScenes;
use App\Services\Ai\AiClient;
use App\Services\Ai\WebSearchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generate "Behind the Scenes" narrative for a film using:
 *   1. WebSearchService → Wikipedia + DuckDuckGo for production trivia
 *   2. AiClient → produce 6 grounded narrative sections as strict JSON
 *
 * Idempotent: existing rows for the movie are deleted then re-inserted in
 * one transaction. All copy is written in Bahasa Indonesia.
 */
class BehindScenesGenerator
{
    /**
     * Section enum values allowed in the AI output (mirrors migration enum).
     */
    protected const ALLOWED_SECTIONS = [
        MovieBehindScenes::SECTION_PRODUCTION,
        MovieBehindScenes::SECTION_CASTING,
        MovieBehindScenes::SECTION_FILMING,
        MovieBehindScenes::SECTION_POST_PRODUCTION,
        MovieBehindScenes::SECTION_RECEPTION,
        MovieBehindScenes::SECTION_LEGACY,
    ];

    /**
     * Default fallback titles per section (Indonesian).
     *
     * @var array<string,string>
     */
    protected const DEFAULT_TITLES = [
        MovieBehindScenes::SECTION_PRODUCTION      => 'Awal Mula Produksi',
        MovieBehindScenes::SECTION_CASTING         => 'Proses Casting',
        MovieBehindScenes::SECTION_FILMING         => 'Di Lokasi Syuting',
        MovieBehindScenes::SECTION_POST_PRODUCTION => 'Pasca-Produksi',
        MovieBehindScenes::SECTION_RECEPTION       => 'Penerimaan Kritikus & Penonton',
        MovieBehindScenes::SECTION_LEGACY          => 'Warisan Film',
    ];

    public function __construct(
        protected AiClient $ai,
        protected WebSearchService $search,
    ) {}

    /**
     * Generate up to 6 Behind-the-Scenes sections for the given movie.
     *
     * @return Collection<int, MovieBehindScenes>
     */
    public function generate(Movie $movie): Collection
    {
        // ━━━ 1. Build query and run web search ━━━
        $year       = $movie->release_date?->format('Y');
        $titleQuery = trim($movie->original_title ?: $movie->title);
        $query      = trim(sprintf(
            '%s %s behind the scenes production making',
            $titleQuery,
            $year ?? ''
        ));

        $results = $this->search->search($query, 6);

        if (empty($results)) {
            Log::info('BehindScenesGenerator: no web results', [
                'movie_id' => $movie->id,
                'query'    => $query,
            ]);
            return collect();
        }

        $sourceUrls = collect($results)
            ->pluck('url')
            ->filter(fn ($u) => is_string($u) && $u !== '')
            ->values()
            ->all();

        // ━━━ 2. Build prompts ━━━
        $allowed      = implode('|', self::ALLOWED_SECTIONS);
        $systemPrompt = sprintf(
            'You are a cinema journalist. Based on the Wikipedia + web-search content provided, write a Behind-the-Scenes narrative for the film "%s". '
            . 'Structure the article into EXACTLY 6 sections in this order: production, casting, filming, post_production, reception, legacy. '
            . 'Each section must contain a short Indonesian title (max 8 words) plus a 100–150 word narrative paragraph in Bahasa Indonesia. '
            . 'Output STRICT JSON array — no prose, no markdown fences: '
            . '[{"section":"%s","title":"...","content":"..."}]. '
            . 'NO speculation: include only facts present in the provided sources. If a section has no usable info, still include it but write a short factual note acknowledging the lack of public information.',
            $movie->title,
            $allowed,
        );

        $userPrompt = $this->buildUserPrompt($movie, $results);

        // ━━━ 3. Call AI ━━━
        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                [
                    'max_tokens'  => 3500,
                    'temperature' => 0.4,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('BehindScenesGenerator: AI call failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
            return collect();
        }

        $sections = $this->parseSections($response['content'] ?? '');

        if (empty($sections)) {
            Log::warning('BehindScenesGenerator: AI returned no parseable sections', [
                'movie_id' => $movie->id,
                'raw'      => mb_substr((string) ($response['content'] ?? ''), 0, 400),
            ]);
            return collect();
        }

        // ━━━ 4. Build canonical row set in section order ━━━
        // Map AI output by section — last one wins on duplicates.
        $bySection = [];
        foreach ($sections as $item) {
            $sec = $this->normalizeSection($item['section'] ?? null);
            $bySection[$sec] = $item;
        }

        $now  = now();
        $rows = [];
        foreach (self::ALLOWED_SECTIONS as $idx => $sectionKey) {
            $item    = $bySection[$sectionKey] ?? null;
            $title   = trim((string) ($item['title']   ?? ''));
            $content = trim((string) ($item['content'] ?? ''));

            if ($title === '') {
                $title = self::DEFAULT_TITLES[$sectionKey];
            }
            if ($content === '') {
                // Skip empty sections rather than insert hollow rows.
                continue;
            }

            $rows[] = [
                'movie_id'     => $movie->id,
                'section'      => $sectionKey,
                'title'        => mb_substr($title, 0, 255),
                'content'      => $content,
                'source_urls'  => json_encode($sourceUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'sort_order'   => $idx,
                'generated_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        if (empty($rows)) {
            return collect();
        }

        // ━━━ 5. Idempotent write: deleteFirst → insertBatch ━━━
        DB::transaction(function () use ($movie, $rows) {
            MovieBehindScenes::where('movie_id', $movie->id)->delete();
            MovieBehindScenes::insert($rows);
        });

        return MovieBehindScenes::where('movie_id', $movie->id)
            ->ordered()
            ->get();
    }

    /**
     * Compose the user prompt with movie metadata + search snippets.
     *
     * @param  array<int, array{title:string, snippet:string, url:string}>  $results
     */
    protected function buildUserPrompt(Movie $movie, array $results): string
    {
        $meta = sprintf(
            "Film metadata:\n- Title: %s\n- Original title: %s\n- Year: %s\n- Overview: %s",
            $movie->title,
            $movie->original_title ?: $movie->title,
            $movie->release_date?->format('Y') ?: 'unknown',
            mb_substr((string) $movie->overview, 0, 400),
        );

        $sources = "Wikipedia / web search results:\n";
        foreach ($results as $i => $r) {
            $sources .= sprintf(
                "\n[%d] %s\nURL: %s\n%s\n",
                $i + 1,
                $r['title']   ?? '',
                $r['url']     ?? '',
                trim((string) ($r['snippet'] ?? '')),
            );
        }

        $allowed = implode('|', self::ALLOWED_SECTIONS);

        return $meta . "\n\n" . $sources . "\n\n"
            . "Tugas: Tulis narasi Behind-the-Scenes untuk film ini berdasarkan sumber di atas. "
            . "Buat 6 bagian sesuai urutan ({$allowed}). "
            . "Setiap bagian: judul singkat (Bahasa Indonesia, max 8 kata) + narasi 100-150 kata dalam Bahasa Indonesia. "
            . "Hanya gunakan fakta dari sumber. Jika sebuah bagian tidak ada info di sumber, tulis catatan singkat bahwa info publik untuk bagian itu terbatas. "
            . "Kembalikan HANYA JSON array, tanpa teks lain.";
    }

    /**
     * Parse the AI response content into section items.
     * Tolerates markdown code fences and surrounding prose.
     *
     * @return array<int, array{section:?string, title:?string, content:?string}>
     */
    protected function parseSections(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Strip markdown code fences if present.
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        // Find the first JSON array in the string.
        if (preg_match('/\[\s*\{.*\}\s*\]/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $section = $item['section'] ?? null;
            $title   = $item['title']   ?? null;
            $content = $item['content'] ?? null;

            if (!is_string($content) || trim($content) === '') {
                continue;
            }

            $out[] = [
                'section' => is_string($section) ? $section : null,
                'title'   => is_string($title)   ? $title   : null,
                'content' => $content,
            ];
        }

        return $out;
    }

    protected function normalizeSection(mixed $section): string
    {
        if (!is_string($section)) {
            return MovieBehindScenes::SECTION_PRODUCTION;
        }

        $s = strtolower(trim($section));
        $s = str_replace([' ', '-'], '_', $s);

        return in_array($s, self::ALLOWED_SECTIONS, true)
            ? $s
            : MovieBehindScenes::SECTION_PRODUCTION;
    }
}
