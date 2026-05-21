<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use Illuminate\Support\Str;

/**
 * AI-powered generator for social media post copy (per-platform style).
 *
 * Each platform has different idiomatic conventions:
 *   - twitter    : punchy, ≤280 chars total (caption + hashtags inline). 2-4 hashtags.
 *   - instagram  : narrative + emoji, caption up to 2200 chars, ~10 hashtags (sentence-end block).
 *   - tiktok     : Gen-Z casual, hook-first, trending phrases, ~5 hashtags incl. #fyp.
 *   - facebook   : conversational, slightly longer than Twitter, 2-3 hashtags.
 *
 * Output: ['caption' => string, 'hashtags' => array<string>, 'character_count' => int].
 */
class SocialMediaPostGenerator
{
    public const PLATFORMS = ['instagram', 'twitter', 'tiktok', 'facebook'];

    public const INSTAGRAM_MAX = 2200;
    public const TWITTER_MAX   = 280;
    public const TIKTOK_MAX    = 2200; // tiktok captions cap ~2200
    public const FACEBOOK_MAX  = 2000; // soft cap — engagement drops past ~80 anyway

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Generate a social media post for a movie.
     *
     * @param  Movie   $movie
     * @param  string  $platform  One of self::PLATFORMS (defaults to 'instagram')
     * @return array{caption: string, hashtags: array<int, string>, character_count: int}
     */
    public function generate(Movie $movie, string $platform = 'instagram'): array
    {
        $platform = in_array($platform, self::PLATFORMS, true) ? $platform : 'instagram';

        $systemPrompt = $this->buildSystemPrompt($platform);
        $userPrompt   = $this->buildUserPrompt($movie, $platform);

        $response = $this->ai->chat(
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            options: [
                'temperature' => 0.9,
                'max_tokens'  => $platform === 'twitter' ? 250 : 800,
            ],
            taskType: 'marketing.social_' . $platform,
            subject: $movie,
        );

        return $this->parseAndClamp($response['content'] ?? '', $movie, $platform);
    }

    /**
     * Platform-tailored system prompt.
     */
    protected function buildSystemPrompt(string $platform): string
    {
        $base = 'You\'re a senior social media copywriter for Indonesian streaming platform FLiK. '
            . 'Write in Indonesian (boleh sisipan English secukupnya untuk istilah pop-culture). '
            . 'Output strict JSON only — no code fences, no commentary. '
            . 'JSON shape: {"caption": "string", "hashtags": ["#tag1", "#tag2", ...]}. '
            . 'Hashtags must be relevant, lowercase preferred, no spaces, max 30 chars each.';

        return $base . ' ' . match ($platform) {
            'twitter' => 'Platform: Twitter/X. Caption + hashtags COMBINED total max 280 chars. '
                . 'Tone: tajam, witty, punchline-driven. 2-4 hashtag relevan inline. '
                . 'Hindari emoji berlebihan (maks 1-2). Hook di kalimat pertama.',

            'instagram' => 'Platform: Instagram. Caption boleh panjang (max 2200 chars) dan storytelling. '
                . 'Gunakan emoji secukupnya untuk ritme & visual break. Mulai dengan hook 1 kalimat. '
                . 'Sertakan call-to-action di akhir caption (contoh: "Tonton sekarang di FLiK"). '
                . 'Hashtag 8-12 buah, ditaruh di akhir (jangan inline). Mix bahasa Indonesia + English populer.',

            'tiktok' => 'Platform: TikTok. Tone Gen-Z kasual + trending. '
                . 'Caption pendek-medium (max 2200 chars tapi idealnya 100-200). '
                . 'Mulai dengan hook penasaran. Gunakan emoji untuk vibe. '
                . 'Hashtag 4-6 buah, WAJIB include #fyp dan #foryoupage atau #fypシ. '
                . 'Boleh slang gaul: "vibesnya gokil", "no spoiler", "POV".',

            'facebook' => 'Platform: Facebook. Tone conversational, friendly, slightly longer than Twitter (max 2000 chars). '
                . 'Gaya storytelling ringan, ajak diskusi di kolom komentar. '
                . 'Hashtag 2-3 buah saja (Facebook tidak hashtag-heavy). Emoji secukupnya.',

            default => '',
        };
    }

    /**
     * Movie-specific user prompt.
     */
    protected function buildUserPrompt(Movie $movie, string $platform): string
    {
        $movie->loadMissing('genres');

        $genres   = $movie->genres->pluck('name')->take(4)->join(', ');
        $year     = optional($movie->release_date)->format('Y');
        $rating   = $movie->vote_average ? round((float) $movie->vote_average, 1) . '/10' : null;
        $overview = Str::limit((string) ($movie->overview ?? ''), 500);

        $maxChars = $this->maxCharsFor($platform);

        $lines = [
            "Buat caption + hashtag untuk post {$platform} promo film berikut di FLiK:",
            '',
            'Judul       : ' . $movie->title,
            $movie->original_title && $movie->original_title !== $movie->title ? 'Judul Asli  : ' . $movie->original_title : null,
            $year   ? 'Tahun       : ' . $year   : null,
            $genres ? 'Genre       : ' . $genres : null,
            $rating ? 'Rating      : ' . $rating : null,
            $overview ? 'Sinopsis    : ' . $overview : null,
            '',
            'Constraints:',
            "- Caption length: max {$maxChars} chars" . ($platform === 'twitter' ? ' (HARD limit — termasuk hashtags inline)' : ''),
            '- Hashtags: relevant terhadap genre, judul, dan FLiK platform (#flik, #streamingIndonesia, dll).',
            '- HANYA balas JSON valid: {"caption": "...", "hashtags": ["#tag1", ...]}',
        ];

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    /**
     * Parse AI JSON, enforce length & hashtag hygiene, return final shape.
     *
     * @return array{caption: string, hashtags: array<int, string>, character_count: int}
     */
    protected function parseAndClamp(string $raw, Movie $movie, string $platform): array
    {
        $json = $this->extractJson($raw);

        $caption  = trim((string) ($json['caption'] ?? ''));
        $hashtags = $json['hashtags'] ?? [];

        // Fallbacks
        if ($caption === '') {
            $caption = '🎬 ' . $movie->title . ' — sekarang di FLiK. ' . Str::limit((string) $movie->overview, 200);
        }
        if (!is_array($hashtags) || empty($hashtags)) {
            $hashtags = $this->defaultHashtags($movie);
        }

        $hashtags = $this->normalizeHashtags($hashtags);

        $max = $this->maxCharsFor($platform);

        // Twitter: hashtags count toward the limit (assume inline). Reserve space for them.
        if ($platform === 'twitter') {
            $hashtagStr = ' ' . implode(' ', $hashtags);
            $hashtagLen = mb_strlen($hashtagStr);
            $captionMax = max(40, $max - $hashtagLen);
            $caption    = $this->clamp($caption, $captionMax);

            // Re-measure final combined string
            $final = $caption . $hashtagStr;
            if (mb_strlen($final) > $max) {
                $caption = $this->clamp($caption, $max - $hashtagLen);
            }

            return [
                'caption'         => $caption,
                'hashtags'        => $hashtags,
                'character_count' => mb_strlen($caption . $hashtagStr),
            ];
        }

        // Other platforms: caption + hashtags-block separate. Caption clamped alone.
        $caption = $this->clamp($caption, $max);

        return [
            'caption'         => $caption,
            'hashtags'        => $hashtags,
            'character_count' => mb_strlen($caption),
        ];
    }

    protected function maxCharsFor(string $platform): int
    {
        return match ($platform) {
            'twitter'   => self::TWITTER_MAX,
            'tiktok'    => self::TIKTOK_MAX,
            'facebook'  => self::FACEBOOK_MAX,
            default     => self::INSTAGRAM_MAX,
        };
    }

    /**
     * Default hashtags from movie metadata (graceful AI-failure fallback).
     *
     * @return array<int, string>
     */
    protected function defaultHashtags(Movie $movie): array
    {
        $tags = ['#FLiK', '#StreamingIndonesia'];

        foreach ($movie->genres as $g) {
            $tags[] = '#' . Str::studly($g->name);
        }

        $tags[] = '#' . Str::studly($movie->title);

        return array_values(array_unique($tags));
    }

    /**
     * Sanitise raw hashtag list: enforce # prefix, strip spaces, drop empties, cap at 12.
     *
     * @param  array  $raw
     * @return array<int, string>
     */
    protected function normalizeHashtags(array $raw): array
    {
        $out = [];
        foreach ($raw as $tag) {
            if (!is_string($tag)) continue;

            $tag = trim($tag);
            if ($tag === '') continue;

            // Drop any spaces (hashtags can't have them)
            $tag = preg_replace('/\s+/', '', $tag) ?? '';

            // Ensure single leading #
            $tag = '#' . ltrim($tag, '#');

            // Strip non-allowed chars (keep underscore + alphanumerics + unicode letters)
            $body = preg_replace('/[^\p{L}\p{N}_]/u', '', mb_substr($tag, 1)) ?? '';
            $tag  = '#' . $body;

            if ($tag === '#' || mb_strlen($tag) > 30) continue;

            $out[$tag] = $tag; // dedupe via key
        }
        return array_values(array_slice($out, 0, 12));
    }

    /**
     * Best-effort JSON extraction (handles code fences + leading/trailing prose).
     */
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

    /**
     * Multibyte-safe hard clamp.
     */
    protected function clamp(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }
}
