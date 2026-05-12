<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\MovieQuote;
use App\Models\MovieSubtitle;
use App\Services\Ai\AiClient;
use App\Services\Ai\Subtitle\WebVttHelper;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Extract memorable/quotable lines from a movie's subtitle (or fallback to overview).
 *
 * Pipeline:
 * 1. Pick default MovieSubtitle for movie+language (fallback: any active in that lang)
 * 2. Parse the .vtt → list of cues with start timestamps
 * 3. Ask AI to pick the N most memorable cues (returns JSON: cue_index, quote, why)
 * 4. Map each returned cue_index → timestamp_seconds from cue table
 * 5. Persist as MovieQuote rows (replace prior quotes for this movie+language)
 *
 * Fallback: if no subtitle exists, mine $movie->overview for dialog snippets
 * (text inside straight or curly quotes) — best-effort, no timestamps.
 */
class QuoteExtractor
{
    public function __construct(
        protected AiClient $ai,
        protected WebVttHelper $vtt,
    ) {}

    /**
     * Extract memorable quotes and persist them.
     *
     * @return Collection<int, MovieQuote>
     */
    public function extract(Movie $movie, ?string $language = 'id', int $count = 5): Collection
    {
        $language = $language ?: 'id';
        $count = max(1, min(20, $count));

        $subtitle = $this->resolveSubtitle($movie, $language);

        if ($subtitle === null) {
            Log::info('QuoteExtractor: no subtitle found, falling back to overview', [
                'movie_id' => $movie->id,
                'language' => $language,
            ]);

            return $this->fallbackFromOverview($movie, $language);
        }

        $vttRaw = Storage::disk($subtitle->disk)->get($subtitle->webvtt_path);
        if (! $vttRaw) {
            Log::warning('QuoteExtractor: subtitle file missing on disk, falling back', [
                'movie_id' => $movie->id,
                'path' => $subtitle->webvtt_path,
            ]);

            return $this->fallbackFromOverview($movie, $language);
        }

        $cues = $this->vtt->parse($vttRaw);
        if (empty($cues)) {
            return $this->fallbackFromOverview($movie, $language);
        }

        // Build numbered cue list for AI prompt — cap to avoid huge prompts on long films.
        // ~2000 cues is roughly a 2-hour feature; if larger, sample uniformly.
        $numberedCues = $this->buildNumberedCues($cues, 2000);

        $picks = $this->askAiToPickQuotes($numberedCues['text'], $count);

        if (empty($picks)) {
            Log::info('QuoteExtractor: AI returned no usable picks, falling back', [
                'movie_id' => $movie->id,
            ]);

            return $this->fallbackFromOverview($movie, $language);
        }

        return DB::transaction(function () use ($movie, $language, $picks, $cues, $numberedCues) {
            // Replace prior quotes for this movie+language
            MovieQuote::where('movie_id', $movie->id)
                ->where('language_code', $language)
                ->delete();

            $saved = collect();

            foreach ($picks as $pick) {
                $idx = $pick['cue_index'] ?? null;
                $quoteText = trim((string) ($pick['quote'] ?? ''));
                $why = trim((string) ($pick['why'] ?? ''));

                if ($quoteText === '') {
                    continue;
                }

                // cue_index from AI is 1-based and refers to the SAMPLED list,
                // not original cue array indices — map via $numberedCues['map'].
                $cueRef = null;
                if (is_numeric($idx)) {
                    $sampledIdx = (int) $idx - 1;
                    $origIdx = $numberedCues['map'][$sampledIdx] ?? null;
                    if ($origIdx !== null && isset($cues[$origIdx])) {
                        $cueRef = $cues[$origIdx];
                    }
                }

                $timestamp = null;
                if ($cueRef !== null) {
                    $timestamp = $this->vtt->timestampToSeconds($cueRef['start']);
                }

                $quote = MovieQuote::create([
                    'movie_id' => $movie->id,
                    'language_code' => $language,
                    'quote' => $quoteText,
                    'translation' => null,
                    'character_name' => null,
                    'timestamp_seconds' => $timestamp,
                    'context' => $why !== '' ? $why : null,
                    'share_count' => 0,
                ]);

                $saved->push($quote);
            }

            return $saved;
        });
    }

    /**
     * Pick the default subtitle for this movie+language, else first active.
     */
    protected function resolveSubtitle(Movie $movie, string $language): ?MovieSubtitle
    {
        return MovieSubtitle::query()
            ->where('movie_id', $movie->id)
            ->where('language_code', $language)
            ->where('status', 'ready')
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * Build a numbered cue list for the AI prompt.
     * If cues exceed $cap, uniformly sample so prompt stays bounded.
     *
     * @return array{text:string, map: array<int,int>}  map: sampledIndex(0-based) → origCueArrayIndex(0-based)
     */
    protected function buildNumberedCues(array $cues, int $cap): array
    {
        $total = count($cues);
        $indices = range(0, $total - 1);

        if ($total > $cap) {
            // Uniform sampling
            $step = $total / $cap;
            $indices = [];
            for ($i = 0; $i < $cap; $i++) {
                $indices[] = (int) floor($i * $step);
            }
        }

        $lines = [];
        $map = [];
        foreach ($indices as $sampled => $origIdx) {
            $cue = $cues[$origIdx];
            $text = str_replace("\n", ' ', trim($cue['text']));
            $lines[] = sprintf('[%d] %s', $sampled + 1, $text);
            $map[$sampled] = $origIdx;
        }

        return [
            'text' => implode("\n", $lines),
            'map' => $map,
        ];
    }

    /**
     * Send the prompt to the AI and parse the strict-JSON response.
     *
     * @return array<int, array{cue_index:int, quote:string, why:string}>
     */
    protected function askAiToPickQuotes(string $numberedCues, int $count): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a film critic specialised in identifying iconic, quotable dialogue. Respond ONLY with valid JSON. No markdown, no commentary outside the JSON.',
            ],
            [
                'role' => 'user',
                'content' => "From the subtitle text below, extract the {$count} most memorable quotes. "
                    . "Output strict JSON array: [{\"cue_index\": int, \"quote\": \"exact text\", \"why\": \"brief reason\"}]. "
                    . "Skip generic dialog. Pick lines that are quotable, profound, witty, or iconic.\n\n"
                    . "Subtitle cues:\n{$numberedCues}",
            ],
        ];

        try {
            $result = $this->ai->chat($messages, [
                'max_tokens' => 1200,
                'temperature' => 0.4,
            ]);
        } catch (\Throwable $e) {
            Log::error('QuoteExtractor: AI call failed', ['error' => $e->getMessage()]);

            return [];
        }

        return $this->parseJsonPicks($result['content'] ?? '');
    }

    /**
     * Parse the AI response — tolerant of code fences / leading prose.
     *
     * @return array<int, array{cue_index:int, quote:string, why:string}>
     */
    protected function parseJsonPicks(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Strip ```json … ``` fences if present
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
            $raw = $m[1];
        }

        // Extract the first JSON array substring (handles preamble like "Here is the JSON:")
        if (preg_match('/\[\s*\{.*\}\s*\]/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            Log::warning('QuoteExtractor: failed to decode AI JSON', ['raw' => substr($raw, 0, 300)]);

            return [];
        }

        $picks = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $picks[] = [
                'cue_index' => isset($item['cue_index']) ? (int) $item['cue_index'] : 0,
                'quote' => (string) ($item['quote'] ?? ''),
                'why' => (string) ($item['why'] ?? ''),
            ];
        }

        return $picks;
    }

    /**
     * Fallback when no subtitle is usable: scrape quoted dialog from $movie->overview.
     * Best-effort — saves whatever quoted fragments we can find (no timestamps).
     *
     * @return Collection<int, MovieQuote>
     */
    protected function fallbackFromOverview(Movie $movie, string $language): Collection
    {
        $overview = trim((string) $movie->overview);
        if ($overview === '') {
            return collect();
        }

        // Match "straight" and "curly" quoted dialog snippets (min 8 chars)
        $matches = [];
        preg_match_all('/[“"]([^“”"]{8,200})[”"]/u', $overview, $matches);
        $snippets = array_values(array_unique(array_map('trim', $matches[1] ?? [])));

        if (empty($snippets)) {
            return collect();
        }

        return DB::transaction(function () use ($movie, $language, $snippets) {
            MovieQuote::where('movie_id', $movie->id)
                ->where('language_code', $language)
                ->delete();

            $saved = collect();
            foreach ($snippets as $snippet) {
                $quote = MovieQuote::create([
                    'movie_id' => $movie->id,
                    'language_code' => $language,
                    'quote' => $snippet,
                    'translation' => null,
                    'character_name' => null,
                    'timestamp_seconds' => null,
                    'context' => 'Extracted from synopsis (no subtitle available).',
                    'share_count' => 0,
                ]);
                $saved->push($quote);
            }

            return $saved;
        });
    }
}
