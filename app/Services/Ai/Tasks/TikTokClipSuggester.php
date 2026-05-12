<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AI-powered TikTok clip suggester.
 *
 * Reuses TrailerSuggester to identify high-energy 30-second windows (subtitle /
 * audio-loudness driven), then asks the LLM to write a Gen-Z Indonesian caption
 * for each window — emoji friendly, hashtag-optimised for TikTok virality.
 *
 * Output shape per window:
 *   [
 *     'start_seconds' => float,
 *     'end_seconds'   => float,
 *     'caption'       => string (max 150 chars, Indonesian, Gen-Z slang),
 *     'hashtags'      => string[] (always includes #fyp #flikindo + 3 relevant),
 *     'hook_text'     => string  (first-line scroll-stopper, max 60 chars),
 *   ]
 *
 * Notes:
 *   - Does NOT persist anything itself — the trailer windows are persisted by
 *     TrailerSuggester::suggest(); this service only annotates them with copy.
 *   - Gracefully degrades to a heuristic caption if the AI call fails.
 */
class TikTokClipSuggester
{
    public const MAX_CAPTION_CHARS = 150;
    public const MAX_HOOK_CHARS    = 60;

    /** Hashtags every TikTok clip should carry by FLiK convention. */
    public const REQUIRED_HASHTAGS = ['#fyp', '#flikindo'];

    public function __construct(
        protected AiClient $ai,
        protected TrailerSuggester $trailerSuggester,
    ) {}

    /**
     * Suggest TikTok-ready clip windows for the given movie.
     *
     * @param  Movie  $movie
     * @param  int    $count  Number of clips to surface (default 3).
     * @return array<int, array{
     *     start_seconds: float,
     *     end_seconds: float,
     *     caption: string,
     *     hashtags: array<int, string>,
     *     hook_text: string
     * }>
     */
    public function suggest(Movie $movie, int $count = 3): array
    {
        $count = max(1, min(10, $count));

        $movie->loadMissing('genres');

        // Reuse trailer windows — subtitle-driven or audio-loudness driven.
        // (TrailerSuggester persists MovieTrailerSuggestion records; we only need the windows.)
        $windows = $this->trailerSuggester->suggest($movie, $count);

        if ($windows->isEmpty()) {
            Log::info('TikTokClipSuggester: no trailer windows found', ['movie_id' => $movie->id]);
            return [];
        }

        $out = [];
        foreach ($windows as $window) {
            $out[] = $this->annotate($movie, $window);
        }

        return $out;
    }

    /**
     * Generate caption + hashtags + hook for a single trailer window.
     *
     * @param  Movie  $movie
     * @param  \App\Models\MovieTrailerSuggestion  $window
     * @return array{
     *     start_seconds: float,
     *     end_seconds: float,
     *     caption: string,
     *     hashtags: array<int, string>,
     *     hook_text: string
     * }
     */
    protected function annotate(Movie $movie, $window): array
    {
        $start = (float) $window->start_seconds;
        $end   = (float) $window->end_seconds;

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $this->buildSystemPrompt()],
                    ['role' => 'user',   'content' => $this->buildUserPrompt($movie, $window)],
                ],
                options: [
                    'temperature' => 0.95,
                    'max_tokens'  => 400,
                ],
            );

            $parsed = $this->parseAndClamp(
                $response['content'] ?? '',
                $movie,
            );

            return [
                'start_seconds' => $start,
                'end_seconds'   => $end,
                'caption'       => $parsed['caption'],
                'hashtags'      => $parsed['hashtags'],
                'hook_text'     => $parsed['hook_text'],
            ];
        } catch (\Throwable $e) {
            Log::warning('TikTokClipSuggester AI annotation failed — using fallback', [
                'movie_id' => $movie->id,
                'window'   => [$start, $end],
                'error'    => $e->getMessage(),
            ]);

            return [
                'start_seconds' => $start,
                'end_seconds'   => $end,
                'caption'       => $this->fallbackCaption($movie),
                'hashtags'      => $this->fallbackHashtags($movie),
                'hook_text'     => $this->fallbackHook($movie),
            ];
        }
    }

    protected function buildSystemPrompt(): string
    {
        return 'You\'re a Gen-Z social media editor for Indonesian streaming platform FLiK. '
            . 'Write viral TikTok captions in Bahasa Indonesia. '
            . 'Tone: Gen-Z slang gaul ("anjir", "literally", "nggak nyangka", "POV", "vibes nya gila"), '
            . 'emoji friendly tapi tidak berlebihan, hook di kalimat pertama. '
            . 'Output STRICT JSON only — no code fences, no commentary. '
            . 'JSON shape: {"hook_text": "string max ' . self::MAX_HOOK_CHARS . ' chars", '
            . '"caption": "string max ' . self::MAX_CAPTION_CHARS . ' chars termasuk emoji", '
            . '"hashtags": ["#tag1","#tag2",...]}. '
            . 'Hashtags WAJIB ada #fyp dan #flikindo, plus minimal 3 hashtag relevan lain '
            . '(genre, judul film, mood, tema). Total 5-8 hashtags. Lowercase, no spasi.';
    }

    protected function buildUserPrompt(Movie $movie, $window): string
    {
        $genres   = $movie->genres->pluck('name')->take(4)->join(', ');
        $year     = optional($movie->release_date)->format('Y');
        $overview = Str::limit((string) ($movie->overview ?? ''), 300);

        $start = (float) $window->start_seconds;
        $end   = (float) $window->end_seconds;
        $reason = $window->reason ?? 'high-energy scene';

        $lines = [
            'Buat caption TikTok untuk klip pendek (' . round($end - $start, 1) . ' detik) dari film berikut:',
            '',
            'Judul       : ' . $movie->title,
            $year   ? 'Tahun       : ' . $year   : null,
            $genres ? 'Genre       : ' . $genres : null,
            $overview ? 'Sinopsis    : ' . $overview : null,
            '',
            'Klip       : detik ' . round($start, 1) . '–' . round($end, 1),
            'Kenapa menarik: ' . $reason,
            '',
            'Aturan output:',
            '- Bahasa Indonesia Gen-Z, boleh sisipan slang/English populer.',
            '- Hook (kalimat pertama) WAJIB scroll-stopping. Max ' . self::MAX_HOOK_CHARS . ' chars.',
            '- Caption max ' . self::MAX_CAPTION_CHARS . ' chars (sudah termasuk emoji, BELUM termasuk hashtag).',
            '- Hashtags: WAJIB #fyp dan #flikindo + minimal 3 hashtag relevan (genre/judul/mood).',
            '- HANYA balas JSON valid. Tanpa code fence.',
        ];

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    /**
     * @return array{caption:string, hashtags:array<int,string>, hook_text:string}
     */
    protected function parseAndClamp(string $raw, Movie $movie): array
    {
        $json = $this->extractJson($raw);

        $hook     = trim((string) ($json['hook_text'] ?? ''));
        $caption  = trim((string) ($json['caption']   ?? ''));
        $hashtags = $json['hashtags'] ?? [];

        if ($hook === '') {
            $hook = $this->fallbackHook($movie);
        }
        if ($caption === '') {
            $caption = $this->fallbackCaption($movie);
        }
        if (!is_array($hashtags) || empty($hashtags)) {
            $hashtags = $this->fallbackHashtags($movie);
        }

        $hashtags = $this->normalizeHashtags($hashtags);
        $hashtags = $this->ensureRequired($hashtags);

        return [
            'hook_text' => $this->clamp($hook, self::MAX_HOOK_CHARS),
            'caption'   => $this->clamp($caption, self::MAX_CAPTION_CHARS),
            'hashtags'  => array_slice($hashtags, 0, 8),
        ];
    }

    /** @param array<int,mixed> $raw @return array<int,string> */
    protected function normalizeHashtags(array $raw): array
    {
        $out = [];
        foreach ($raw as $tag) {
            if (!is_string($tag)) continue;

            $tag = trim($tag);
            if ($tag === '') continue;

            $tag = preg_replace('/\s+/', '', $tag) ?? '';
            $tag = '#' . ltrim($tag, '#');

            $body = preg_replace('/[^\p{L}\p{N}_]/u', '', mb_substr($tag, 1)) ?? '';
            $tag  = '#' . mb_strtolower($body);

            if ($tag === '#' || mb_strlen($tag) > 30) continue;

            $out[$tag] = $tag;
        }
        return array_values($out);
    }

    /**
     * Ensure #fyp + #flikindo are present (front-loaded). Case-insensitive check.
     *
     * @param  array<int,string>  $tags
     * @return array<int,string>
     */
    protected function ensureRequired(array $tags): array
    {
        $lower = array_map(fn ($t) => mb_strtolower($t), $tags);
        $out   = $tags;

        foreach (self::REQUIRED_HASHTAGS as $req) {
            if (!in_array(mb_strtolower($req), $lower, true)) {
                array_unshift($out, $req);
                $lower = array_map(fn ($t) => mb_strtolower($t), $out);
            }
        }

        // Dedupe (case-insensitive) preserving order
        $seen = [];
        $final = [];
        foreach ($out as $t) {
            $k = mb_strtolower($t);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $final[] = $t;
        }
        return $final;
    }

    protected function fallbackCaption(Movie $movie): string
    {
        return '🎬 ' . $movie->title . ' tuh literally wajib tonton sih. Vibes-nya beda banget 🔥 #FLiK';
    }

    protected function fallbackHook(Movie $movie): string
    {
        return 'POV: lo nemu film ' . Str::limit($movie->title, 30, '') . ' di FLiK 👀';
    }

    /** @return array<int,string> */
    protected function fallbackHashtags(Movie $movie): array
    {
        $tags = self::REQUIRED_HASHTAGS;
        $tags[] = '#flik';
        foreach ($movie->genres as $g) {
            $tags[] = '#' . mb_strtolower(Str::slug($g->name, ''));
        }
        $tags[] = '#' . mb_strtolower(Str::slug($movie->title, ''));
        $tags[] = '#filmindonesia';
        return $this->normalizeHashtags($tags);
    }

    /** Best-effort JSON extraction (handles code fences + leading/trailing prose). */
    protected function extractJson(string $raw): array
    {
        $clean = trim($raw);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        $first = strpos($clean, '{');
        $last  = strrpos($clean, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $clean = substr($clean, $first, $last - $first + 1);
        }

        $decoded = json_decode($clean, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Multibyte-safe hard clamp. */
    protected function clamp(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
