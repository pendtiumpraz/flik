<?php

namespace App\Services\Ai\Search;

use App\Exceptions\SsrfException;
use App\Models\AiProvider;
use App\Models\Movie;
use App\Services\Ai\FilmKnowledgeService;
use App\Services\Security\SsrfGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * K2 — Image Search.
 *
 * Pipeline:
 *   1. Send a user-supplied still / poster / promotional image to Gemini Flash-Lite vision.
 *   2. Ask the model to identify which film(s) the image is from with a confidence rating.
 *   3. Cross-reference each candidate title against the catalog via FilmKnowledgeService.
 *
 * Returns Collection<Movie> of catalog matches (deduped, original AI confidence preserved
 * on the model under `_ai_confidence` for the view layer).
 *
 * Degrades gracefully: returns an empty collection when no Gemini provider is configured,
 * the image is invalid, or the AI call fails.
 */
class ImageSearchService
{
    /**
     * Hard cap on candidate titles requested from the model.
     */
    protected const MAX_CANDIDATES = 5;

    public function __construct(
        protected FilmKnowledgeService $knowledge,
        protected SsrfGuard $ssrfGuard = new SsrfGuard(),
    ) {
    }

    /**
     * Identify a film from a base64-encoded image and return matching catalog entries.
     *
     * @param  string  $imageBase64  Raw base64 (no `data:` prefix). Caller is responsible for sanitising.
     * @param  string  $mimeType     MIME type of the image (default image/jpeg).
     * @return Collection<int, Movie>
     */
    public function searchByImage(string $imageBase64, string $mimeType = 'image/jpeg'): Collection
    {
        $imageBase64 = trim($imageBase64);

        if ($imageBase64 === '') {
            return collect();
        }

        $provider = $this->pickGeminiProvider();
        if ($provider === null) {
            Log::info('ImageSearchService: no Gemini provider configured, skipping');
            return collect();
        }

        $candidates = $this->identifyCandidates($provider, $imageBase64, $mimeType);

        if (empty($candidates)) {
            return collect();
        }

        // Cross-reference candidates against the catalog. Preserve confidence ordering.
        $matches = collect();
        $seen = [];

        foreach ($candidates as $candidate) {
            $title = (string) ($candidate['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $movie = $this->knowledge->findClosestByTitle($title)
                ?? $this->knowledge->findByTitle($title);

            if ($movie === null) {
                continue;
            }
            if (isset($seen[$movie->id])) {
                continue;
            }
            $seen[$movie->id] = true;

            // Stamp confidence + AI guess on the model so the view can label the card.
            $movie->loadMissing('genres');
            $movie->setAttribute('_ai_confidence', (float) ($candidate['confidence'] ?? 0.0));
            $movie->setAttribute('_ai_guess_title', $title);

            $matches->push($movie);
        }

        return $matches->values();
    }

    /**
     * Ask Gemini vision to list candidate films for an image.
     *
     * @return list<array{title: string, confidence: float}>
     */
    protected function identifyCandidates(AiProvider $provider, string $imageBase64, string $mimeType): array
    {
        $base = rtrim($provider->base_url ?: 'https://generativelanguage.googleapis.com/v1beta', '/');
        $endpoint = $base . '/models/' . $provider->model . ':generateContent?key=' . $provider->api_key;

        $prompt = "You are a film identification expert. Identify which film this image is from.\n"
            . "Consider posters, promotional stills, screenshots, key scenes, characters, costumes, lighting, and composition.\n"
            . "List up to " . self::MAX_CANDIDATES . " candidate films, ordered most → least likely.\n\n"
            . "Respond with ONLY a JSON array (no prose, no markdown, no code fences) in this exact shape:\n"
            . "[{\"title\":\"Film Title\",\"confidence\":0.92},{\"title\":\"Other Film\",\"confidence\":0.34}]\n\n"
            . "Use the original international title (English) when known. `confidence` is 0.0–1.0.\n"
            . "If the image is clearly NOT a film still (random photo, meme, blank), return an empty array [].";

        $payload = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => [
                        'mime_type' => $mimeType ?: 'image/jpeg',
                        'data' => $imageBase64,
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 400,
            ],
        ];

        try {
            $this->ssrfGuard->assertUrlAllowed($endpoint);

            $response = Http::timeout(45)
                ->connectTimeout(5)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'allow_redirects' => ['max' => 3, 'protocols' => ['http', 'https'], 'strict' => true],
                ])
                ->post($endpoint, $payload);

            if (!$response->successful()) {
                Log::warning('ImageSearchService: Gemini call failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 200),
                ]);
                return [];
            }

            $provider->update(['last_used_at' => now()]);

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return $this->parseCandidatesJson($text);
        } catch (SsrfException $e) {
            Log::warning('ImageSearchService: SSRF guard blocked Gemini endpoint', [
                'error' => $e->getMessage(),
            ]);
            return [];
        } catch (\Throwable $e) {
            Log::warning('ImageSearchService: Gemini exception', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Parse the model's reply into a sanitised candidate list.
     *
     * @return list<array{title: string, confidence: float}>
     */
    protected function parseCandidatesJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Strip code fences if present.
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        // Pull the first JSON array from the body if there's surrounding prose.
        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $candidates = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $confidence = (float) ($row['confidence'] ?? 0.0);
            $confidence = max(0.0, min(1.0, $confidence));

            $candidates[] = [
                'title' => $title,
                'confidence' => $confidence,
            ];
        }

        // Sort by confidence desc and cap.
        usort($candidates, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    /**
     * Find an active Gemini provider. Prefers Flash-Lite, falls back to any active Google provider.
     */
    protected function pickGeminiProvider(): ?AiProvider
    {
        $flashLite = AiProvider::where('provider', 'google')
            ->where('is_active', true)
            ->where('model', 'like', '%flash-lite%')
            ->orderBy('priority')
            ->first();

        if ($flashLite) {
            return $flashLite;
        }

        return AiProvider::where('provider', 'google')
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();
    }
}
