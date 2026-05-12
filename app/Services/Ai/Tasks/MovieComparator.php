<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use App\Services\Ai\FilmKnowledgeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * AI-powered side-by-side comparison of two films.
 *
 * Returns a structured array consumable directly by the comparison view:
 *   [
 *     'comparison' => [
 *       'plot' => string, 'themes' => string, 'style' => string,
 *       'performances' => string, 'verdict' => string,
 *     ],
 *     'watch_recommendation' => string,
 *     'movies' => [ 'a' => formatForAi(...), 'b' => formatForAi(...) ],
 *     'provider' => 'deepseek:deepseek-chat',
 *   ]
 *
 * On AI failure / unparsable response, fields are filled with a graceful
 * Indonesian "tidak tersedia" notice rather than throwing — the view layer
 * never has to handle exceptions.
 */
class MovieComparator
{
    /**
     * Strict-JSON system prompt (Indonesian). Mirrors the contract documented
     * by the caller (FLiK) so the response can be safely json_decoded and
     * rendered field-by-field in the comparison table.
     */
    protected const SYSTEM_PROMPT = "Anda adalah kritikus film senior berbahasa Indonesia. Tulis perbandingan side-by-side dua film. "
        . "Gunakan bahasa Indonesia yang lugas, kritis, dan informatif (2-4 kalimat per bidang). "
        . "Output WAJIB strict JSON tanpa markdown fence, tanpa prosa pembuka/penutup, persis skema berikut:\n"
        . '{"comparison":{"plot":"how they compare narratively","themes":"shared/contrasting themes","style":"visual & directing style","performances":"acting comparison","verdict":"which is stronger and why, or are they incomparable"},"watch_recommendation":"who would prefer which"}';

    public function __construct(
        protected AiClient $ai,
        protected FilmKnowledgeService $knowledge,
    ) {}

    /**
     * Compare two films and return a structured payload for the view.
     *
     * @throws InvalidArgumentException when both ids are the same film
     * @return array{
     *     comparison: array{plot:string,themes:string,style:string,performances:string,verdict:string},
     *     watch_recommendation: string,
     *     movies: array{a: array<string,mixed>, b: array<string,mixed>},
     *     provider: string,
     * }
     */
    public function compare(Movie $a, Movie $b): array
    {
        if ($a->id === $b->id) {
            throw new InvalidArgumentException('Cannot compare a movie with itself.');
        }

        // Eager-load the relations FilmKnowledgeService::formatForAi() reads.
        $a->loadMissing(['genres', 'castMembers']);
        $b->loadMissing(['genres', 'castMembers']);

        $ctxA = $this->knowledge->formatForAi($a);
        $ctxB = $this->knowledge->formatForAi($b);

        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user',   'content' => $this->buildUserPrompt($ctxA, $ctxB)],
        ];

        $providerUsed = 'unknown';
        $parsed = null;

        try {
            $response = $this->ai->chat($messages, [
                'max_tokens'  => 1100,
                'temperature' => 0.55,
            ]);

            $providerUsed = trim(
                ($response['provider'] ?? 'unknown') . ':' . ($response['model'] ?? 'unknown'),
                ':'
            );

            $parsed = $this->parseAndValidate((string) ($response['content'] ?? ''));

            if ($parsed === null) {
                Log::warning('MovieComparator: unparsable AI response', [
                    'movie_a' => $a->id,
                    'movie_b' => $b->id,
                    'raw'     => Str::limit((string) ($response['content'] ?? ''), 400),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('MovieComparator: AI call failed', [
                'movie_a' => $a->id,
                'movie_b' => $b->id,
                'error'   => $e->getMessage(),
            ]);
        }

        if ($parsed === null) {
            $parsed = $this->fallbackPayload();
        }

        return [
            'comparison'           => $parsed['comparison'],
            'watch_recommendation' => $parsed['watch_recommendation'],
            'movies'               => ['a' => $ctxA, 'b' => $ctxB],
            'provider'             => $providerUsed,
        ];
    }

    /**
     * Compose the user message with both movies' metadata.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    protected function buildUserPrompt(array $a, array $b): string
    {
        return "Bandingkan dua film berikut. Kembalikan HANYA objek JSON sesuai skema sistem.\n\n"
            . "FILM A:\n" . $this->formatMovieBlock($a) . "\n\n"
            . "FILM B:\n" . $this->formatMovieBlock($b) . "\n";
    }

    /**
     * @param  array<string,mixed>  $m
     */
    protected function formatMovieBlock(array $m): string
    {
        $lines = [];
        $lines[] = 'Judul         : ' . ($m['title'] ?? '-');
        if (!empty($m['original_title'])) {
            $lines[] = 'Judul Asli    : ' . $m['original_title'];
        }
        $lines[] = 'Tahun         : ' . ($m['year'] ?? '-');
        $lines[] = 'Genre         : ' . (!empty($m['genres']) ? implode(', ', $m['genres']) : '-');
        $lines[] = 'Rating        : ' . ($m['rating'] !== null ? $m['rating'] . '/10' : '-');

        if (!empty($m['cast'])) {
            $cast = array_map(
                fn ($c) => $c['name'] . (!empty($c['as']) ? ' (' . $c['as'] . ')' : ''),
                $m['cast']
            );
            $lines[] = 'Pemeran Utama : ' . implode(', ', $cast);
        }

        $lines[] = 'Sinopsis      : ' . ($m['overview'] ?? '-');

        return implode("\n", $lines);
    }

    /**
     * Parse the AI response into the strict shape the view expects.
     * Returns NULL if the body isn't JSON-decodable into the right schema.
     *
     * @return array{
     *     comparison: array{plot:string,themes:string,style:string,performances:string,verdict:string},
     *     watch_recommendation: string,
     * }|null
     */
    protected function parseAndValidate(string $raw): ?array
    {
        $json = $this->extractJson($raw);
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $comp = $decoded['comparison'] ?? null;
        if (!is_array($comp)) {
            return null;
        }

        $fields = ['plot', 'themes', 'style', 'performances', 'verdict'];
        $clean = [];
        foreach ($fields as $f) {
            $v = $comp[$f] ?? '';
            if (!is_string($v) && !is_numeric($v)) {
                $v = '';
            }
            $clean[$f] = trim((string) $v);
        }

        // If everything is empty, treat as failure so caller can fall back.
        if (count(array_filter($clean, fn ($v) => $v !== '')) === 0) {
            return null;
        }

        $rec = $decoded['watch_recommendation'] ?? '';
        if (!is_string($rec) && !is_numeric($rec)) {
            $rec = '';
        }

        return [
            'comparison'           => $clean,
            'watch_recommendation' => trim((string) $rec),
        ];
    }

    /**
     * Strip markdown fences and locate the first {...} block in the AI response.
     */
    protected function extractJson(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $raw, $m)) {
            $raw = $m[1];
        }

        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($raw, $start, $end - $start + 1);
    }

    /**
     * Graceful default payload when the AI provider is unreachable / returns junk.
     *
     * @return array{
     *     comparison: array{plot:string,themes:string,style:string,performances:string,verdict:string},
     *     watch_recommendation: string,
     * }
     */
    protected function fallbackPayload(): array
    {
        $msg = 'Perbandingan AI tidak tersedia saat ini. Silakan coba lagi beberapa saat lagi.';

        return [
            'comparison' => [
                'plot'         => $msg,
                'themes'       => $msg,
                'style'        => $msg,
                'performances' => $msg,
                'verdict'      => $msg,
            ],
            'watch_recommendation' => $msg,
        ];
    }
}
