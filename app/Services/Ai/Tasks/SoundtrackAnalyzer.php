<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use App\Services\Ai\FilmKnowledgeService;
use Illuminate\Support\Facades\Log;

/**
 * Soundtrack Analyzer (O14).
 *
 * Sends movie metadata (via FilmKnowledgeService::formatForAi) to the AI
 * provider with a strict-JSON contract that captures composer, style, mood,
 * key tracks, era authenticity, and a recommendation block — all in
 * Indonesian (names kept in original spelling).
 *
 * Stateless: this analyzer does not persist its results. The caller is free
 * to cache or store the returned array as needed.
 */
class SoundtrackAnalyzer
{
    public function __construct(
        protected AiClient $ai,
        protected FilmKnowledgeService $knowledge,
    ) {}

    /**
     * Analyze the soundtrack of the given movie.
     *
     * @return array{
     *     composer: string,
     *     style: string,
     *     mood: string,
     *     key_tracks: array<int, string>,
     *     era_authenticity: string,
     *     recommendation: string
     * }
     */
    public function analyze(Movie $movie): array
    {
        $movie->loadMissing(['genres', 'castMembers']);
        $context = $this->knowledge->formatForAi($movie);

        $year = $context['year'] ?? '-';
        $title = $context['title'] ?? $movie->title;

        $systemPrompt = "You're a film music expert. Analyze the soundtrack of {$title} ({$year}). "
            . 'Output strict JSON: {"composer": "name if known", '
            . '"style": "genre/era of music", '
            . '"mood": "how music supports tone", '
            . '"key_tracks": [list of memorable cues if any], '
            . '"era_authenticity": "how well it represents the period", '
            . '"recommendation": "if you like this soundtrack, similar films/composers"}. '
            . 'Indonesian. '
            . 'Return ONLY the JSON object — no markdown fences, no prose.';

        $userPrompt = $this->buildUserPrompt($context);

        try {
            $response = $this->ai->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                [
                    'max_tokens'  => 900,
                    'temperature' => 0.5,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('SoundtrackAnalyzer: AI call failed', [
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
            return $this->emptyShape();
        }

        $parsed = $this->parseJson((string) ($response['content'] ?? ''));

        return $this->normalizeShape($parsed);
    }

    /**
     * Compose the user message with the FilmKnowledgeService context block.
     *
     * @param  array<string, mixed> $ctx
     */
    protected function buildUserPrompt(array $ctx): string
    {
        $lines = [
            'Analisis soundtrack film berikut:',
            '',
            'JUDUL          : ' . ($ctx['title'] ?? '-'),
        ];

        if (!empty($ctx['original_title'])) {
            $lines[] = 'JUDUL ASLI     : ' . $ctx['original_title'];
        }

        $lines[] = 'TAHUN          : ' . ($ctx['year'] ?? '-');
        $lines[] = 'GENRE          : ' . (!empty($ctx['genres']) ? implode(', ', $ctx['genres']) : '-');
        $lines[] = 'RATING PENONTON: ' . ($ctx['rating'] !== null ? $ctx['rating'] . '/10' : '-');

        if (!empty($ctx['cast'])) {
            $cast = array_map(
                fn ($c) => '  - ' . $c['name'] . (!empty($c['as']) ? ' sebagai ' . $c['as'] : ''),
                array_slice($ctx['cast'], 0, 5),
            );
            $lines[] = 'PEMERAN UTAMA  :';
            $lines = array_merge($lines, $cast);
        }

        $lines[] = '';
        $lines[] = 'SINOPSIS:';
        $lines[] = $ctx['overview'] ?? '(tidak tersedia)';
        $lines[] = '';
        $lines[] = 'Tugas: Analisis soundtrack/musik film ini sesuai contract JSON. '
            . 'Bahasa Indonesia untuk semua field deskriptif (style, mood, era_authenticity, recommendation). '
            . 'Nama composer dan judul track tetap dalam ejaan aslinya. '
            . 'Kalau composer tidak diketahui dari konteks, tulis "Tidak diketahui". '
            . 'Kembalikan HANYA JSON object, tanpa teks lain.';

        return implode("\n", $lines);
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

        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Coerce the parsed payload into the documented shape.
     *
     * @param  array<string, mixed> $parsed
     * @return array{
     *     composer: string,
     *     style: string,
     *     mood: string,
     *     key_tracks: array<int, string>,
     *     era_authenticity: string,
     *     recommendation: string
     * }
     */
    protected function normalizeShape(array $parsed): array
    {
        return [
            'composer'         => $this->str($parsed['composer'] ?? ''),
            'style'            => $this->str($parsed['style'] ?? ''),
            'mood'             => $this->str($parsed['mood'] ?? ''),
            'key_tracks'       => $this->stringList($parsed['key_tracks'] ?? []),
            'era_authenticity' => $this->str($parsed['era_authenticity'] ?? ''),
            'recommendation'   => $this->str($parsed['recommendation'] ?? ''),
        ];
    }

    /**
     * @return array{
     *     composer: string,
     *     style: string,
     *     mood: string,
     *     key_tracks: array<int, string>,
     *     era_authenticity: string,
     *     recommendation: string
     * }
     */
    protected function emptyShape(): array
    {
        return [
            'composer'         => '',
            'style'            => '',
            'mood'             => '',
            'key_tracks'       => [],
            'era_authenticity' => '',
            'recommendation'   => '',
        ];
    }

    protected function str(mixed $val): string
    {
        return is_string($val) ? trim($val) : '';
    }

    /**
     * @return array<int, string>
     */
    protected function stringList(mixed $val): array
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
            } elseif (is_array($v)) {
                $candidate = $v['title'] ?? $v['name'] ?? $v['track'] ?? null;
                if (is_string($candidate)) {
                    $candidate = trim($candidate);
                    if ($candidate !== '') {
                        $out[] = $candidate;
                    }
                }
            }
        }
        return $out;
    }
}
