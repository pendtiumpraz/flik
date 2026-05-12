<?php

namespace App\Services\Ai\Tasks;

use App\Models\Cast;
use App\Services\Ai\AiClient;
use App\Services\Ai\WebSearchService;
use Illuminate\Support\Facades\Log;

/**
 * X-Ray (O14) — Enrich a Cast member with biographical data.
 *
 * Pipeline:
 *   1. WebSearchService → fetch Wikipedia entry for the actor
 *   2. AiClient → extract structured JSON {bio, birth_date, nationality, wikipedia_url}
 *      grounded ONLY in the Wikipedia content. Bio is ~200 words in Bahasa Indonesia.
 *   3. Persist to the cast row + stamp bio_generated_at for idempotency.
 *
 * Idempotent: skipped when bio_generated_at is already set.
 */
class CastBiographyEnricher
{
    public function __construct(
        protected AiClient $ai,
        protected WebSearchService $search,
    ) {}

    /**
     * Enrich the cast member in-place. Returns the (possibly refreshed) model.
     */
    public function enrich(Cast $cast): Cast
    {
        // ━━━ 0. Idempotency guard ━━━
        if ($cast->bio_generated_at !== null) {
            return $cast;
        }

        $name = trim((string) $cast->name);
        if ($name === '') {
            return $cast;
        }

        // ━━━ 1. Wikipedia search ━━━
        $query = $name . ' actor';
        $results = $this->search->search($query, 4);

        if (empty($results)) {
            Log::info('CastBiographyEnricher: no web results', [
                'cast_id' => $cast->id,
                'name'    => $name,
            ]);
            return $cast;
        }

        // ━━━ 2. AI extraction ━━━
        $systemPrompt = 'You are a film industry researcher. Based ONLY on the Wikipedia content provided, '
            . 'extract a structured biography for the actor. '
            . 'Output strict JSON: {"bio":"...","birth_date":"YYYY-MM-DD or null","nationality":"...","wikipedia_url":"..."}. '
            . 'The "bio" field MUST be ~200 words in Bahasa Indonesia, factual, no speculation. '
            . 'If a field is not present in the source, use null. '
            . 'Return ONLY the JSON object — no prose, no markdown code fences.';

        $userPrompt = $this->buildUserPrompt($name, $results);

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                [
                    'max_tokens'  => 1200,
                    'temperature' => 0.3,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('CastBiographyEnricher: AI call failed', [
                'cast_id' => $cast->id,
                'name'    => $name,
                'error'   => $e->getMessage(),
            ]);
            return $cast;
        }

        $parsed = $this->parsePayload((string) ($response['content'] ?? ''));

        if ($parsed === null || empty($parsed['bio'])) {
            Log::warning('CastBiographyEnricher: AI returned no parseable bio', [
                'cast_id' => $cast->id,
                'raw'     => mb_substr((string) ($response['content'] ?? ''), 0, 400),
            ]);
            return $cast;
        }

        // ━━━ 3. Persist ━━━
        // Prefer the Wikipedia URL the AI cited; fall back to first search hit.
        $wikiUrl = $this->normalizeUrl($parsed['wikipedia_url'] ?? null)
            ?? $this->normalizeUrl($results[0]['url'] ?? null);

        $cast->forceFill([
            'bio'              => trim((string) $parsed['bio']),
            'wikipedia_url'    => $wikiUrl,
            'birth_date'       => $this->normalizeDate($parsed['birth_date'] ?? null),
            'nationality'      => $this->normalizeString($parsed['nationality'] ?? null),
            'bio_generated_at' => now(),
        ])->save();

        return $cast->refresh();
    }

    /**
     * Compose user prompt with actor name + Wikipedia snippets.
     *
     * @param  array<int, array{title:string, snippet:string, url:string}>  $results
     */
    protected function buildUserPrompt(string $name, array $results): string
    {
        $wiki = "Wikipedia / web search results untuk \"{$name}\":\n";
        foreach ($results as $i => $r) {
            $wiki .= sprintf(
                "\n[%d] %s\nURL: %s\n%s\n",
                $i + 1,
                $r['title'] ?? '',
                $r['url'] ?? '',
                trim((string) ($r['snippet'] ?? '')),
            );
        }

        return $wiki . "\n\n"
            . "Tugas: Ekstrak biografi terstruktur untuk aktor '{$name}'. "
            . "Field 'bio' WAJIB sekitar 200 kata dalam Bahasa Indonesia, faktual, "
            . "berdasarkan konten di atas saja. Kembalikan HANYA JSON object.";
    }

    /**
     * Parse AI response content into the expected shape.
     * Tolerates markdown code fences and surrounding prose.
     *
     * @return array{bio?:string, birth_date?:?string, nationality?:?string, wikipedia_url?:?string}|null
     */
    protected function parsePayload(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;

        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        if (preg_match('/\{[\s\S]*\}/', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') return null;

        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') return null;
        return mb_substr($value, 0, 255);
    }

    protected function normalizeUrl(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') return null;
        if (!preg_match('~^https?://~i', $value)) return null;
        return mb_substr($value, 0, 255);
    }
}
