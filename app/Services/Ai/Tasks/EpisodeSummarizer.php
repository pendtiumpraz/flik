<?php

namespace App\Services\Ai\Tasks;

use App\Models\Episode;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generate a 50-word Indonesian blurb for a single episode given the
 * episode title + parent series synopsis. Persists to
 * `episodes.generated_summary` (+ generated_summary_at) via forceFill().
 *
 * Idempotent: callers may pass `force: true` to overwrite an existing
 * blurb; otherwise the service skips and returns the cached value.
 * Errors are logged + the previous value (or empty string) is returned —
 * never throws — so the admin "AI fill" button stays predictable.
 */
class EpisodeSummarizer
{
    /**
     * Target length budget. Tight on purpose — the UI surface is a
     * mobile-first card byline, not the full overview slot.
     */
    protected const TARGET_WORDS = 50;

    protected const SYSTEM_PROMPT = 'Kamu adalah penulis sinopsis episode TV berbahasa Indonesia. '
        . 'Tulis blurb 1 paragraf (~50 kata, MAKS 60) yang teasing tanpa spoiler besar. '
        . 'Gunakan present tense, bahasa Indonesia yang renyah dan netral. '
        . 'JANGAN sebut nomor episode, JANGAN sebut nomor season, JANGAN tulis "Episode ini bercerita..." '
        . 'Output WAJIB plain text 1 paragraf — tanpa markdown, tanpa quotes, tanpa newline.';

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Generate (or fetch cached) summary for `$episode`.
     *
     * @return string  The persisted blurb, or '' on failure.
     */
    public function summarize(Episode $episode, bool $force = false): string
    {
        if (! $force && ! empty($episode->generated_summary)) {
            return (string) $episode->generated_summary;
        }

        try {
            $messages = [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user',   'content' => $this->buildUserPrompt($episode)],
            ];

            $response = $this->ai->chat(
                $messages,
                [
                    'max_tokens'  => 200,
                    'temperature' => 0.7,
                ],
                'episode.summarize',
                $episode,
            );

            $blurb = $this->postProcess((string) ($response['content'] ?? ''));

            if ($blurb === '') {
                Log::warning('EpisodeSummarizer: empty/invalid output', [
                    'episode_id' => $episode->id,
                    'raw'        => Str::limit((string) ($response['content'] ?? ''), 200),
                ]);
                return '';
            }

            // forceFill: these columns are intentionally OUT of $fillable
            // so admin form posts can't silently overwrite an AI blurb.
            $episode->forceFill([
                'generated_summary'    => $blurb,
                'generated_summary_at' => now(),
            ])->save();

            return $blurb;
        } catch (\Throwable $e) {
            Log::error('EpisodeSummarizer: failed', [
                'episode_id' => $episode->id ?? null,
                'error'      => $e->getMessage(),
            ]);
            return (string) ($episode->generated_summary ?? '');
        }
    }

    /**
     * Build the user-side prompt. Pulls parent series context so the
     * blurb reads in-universe instead of as a generic logline.
     */
    protected function buildUserPrompt(Episode $episode): string
    {
        $episode->loadMissing('movie', 'season');
        $movie = $episode->movie;

        $seriesSynopsis = $movie?->ai_synopsis
            ?: $movie?->overview
            ?: '(sinopsis induk belum tersedia)';

        $payload = [
            'series_title'    => $movie?->title ?? '(tanpa judul)',
            'series_synopsis' => Str::limit((string) $seriesSynopsis, 600, ''),
            'episode_title'   => (string) $episode->title,
            'episode_overview' => Str::limit((string) ($episode->overview ?? ''), 400, ''),
        ];

        return "Tulis blurb episode ~" . self::TARGET_WORDS . " kata berdasarkan konteks berikut. "
            . "Output cukup paragraf blurb saja.\n\n"
            . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Trim stray markdown / quotes / extra whitespace that providers
     * sometimes leak past the system prompt.
     */
    protected function postProcess(string $raw): string
    {
        $s = trim($raw);
        // Strip surrounding code fences if the model wrapped output.
        if (preg_match('/^```(?:\w+)?\s*(.+?)\s*```$/s', $s, $m)) {
            $s = trim($m[1]);
        }
        // Strip wrapping straight or curly quotes.
        $s = trim($s, "\"'\u{201C}\u{201D}\u{2018}\u{2019}\u{00AB}\u{00BB} \t\n\r\0\x0B");
        // Collapse newlines — we want a single paragraph.
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';
        return trim($s);
    }
}
