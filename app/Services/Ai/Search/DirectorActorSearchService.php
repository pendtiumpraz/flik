<?php

namespace App\Services\Ai\Search;

use App\Models\Movie;
use App\Services\Ai\AiClient;
use App\Services\Ai\FilmKnowledgeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * K6 — Director / Actor Search.
 *
 * Two paths:
 *   • Actor    → direct DB query against `casts.name LIKE %name%` via the cast_movie pivot.
 *   • Director → director isn't stored as a separate column in `movies`. Instead we ask the
 *                AI to list films directed by the given person, then match each title back
 *                against the catalog via FilmKnowledgeService.
 *
 * `auto` mode runs the actor path first (zero AI cost) and falls back to the director path
 * only when no cast match is found. Each returned movie has `_match_type` (actor/director)
 * and, for directors, `_ai_guess_title` stamped on it for the view.
 *
 * Returns Collection<Movie>.
 */
class DirectorActorSearchService
{
    /**
     * Hard cap on titles we'll ask the model to enumerate for a director.
     */
    protected const MAX_DIRECTOR_TITLES = 20;

    public function __construct(
        protected AiClient $ai,
        protected FilmKnowledgeService $knowledge,
    ) {
    }

    /**
     * Search films by a person's name.
     *
     * @param  string  $personName  Free-form name (e.g. "Christopher Nolan", "joko anwar").
     * @param  string  $type        'director' | 'actor' | 'auto'
     * @return Collection<int, Movie>
     */
    public function searchByPerson(string $personName, string $type = 'auto'): Collection
    {
        $personName = trim($personName);
        if ($personName === '') {
            return collect();
        }

        $type = in_array($type, ['director', 'actor', 'auto'], true) ? $type : 'auto';

        $actorMatches = ($type === 'actor' || $type === 'auto')
            ? $this->searchByActor($personName)
            : collect();

        $directorMatches = ($type === 'director' || ($type === 'auto' && $actorMatches->isEmpty()))
            ? $this->searchByDirector($personName)
            : collect();

        // Merge, deduping by movie id, actor matches first.
        $merged = collect();
        $seen = [];
        foreach ([$actorMatches, $directorMatches] as $bucket) {
            foreach ($bucket as $movie) {
                if (isset($seen[$movie->id])) {
                    continue;
                }
                $seen[$movie->id] = true;
                $merged->push($movie);
            }
        }

        return $merged->values();
    }

    /**
     * Direct cast lookup via the cast_movie pivot.
     *
     * @return Collection<int, Movie>
     */
    protected function searchByActor(string $name): Collection
    {
        $needle = $name;

        return Movie::query()
            ->with(['genres', 'castMembers'])
            ->whereHas('castMembers', function ($q) use ($needle) {
                $q->where('name', 'LIKE', "%{$needle}%");
            })
            ->orderByDesc('popularity')
            ->limit(50)
            ->get()
            ->map(function (Movie $m) {
                $m->setAttribute('_match_type', 'actor');
                return $m;
            });
    }

    /**
     * AI-driven director lookup.
     * Asks the model for a list of films directed by the person, then maps each
     * title back to a catalog entry.
     *
     * @return Collection<int, Movie>
     */
    protected function searchByDirector(string $name): Collection
    {
        $titles = $this->fetchDirectorFilmography($name);
        if (empty($titles)) {
            return collect();
        }

        $matches = collect();
        $seen = [];

        foreach ($titles as $title) {
            $movie = $this->knowledge->findClosestByTitle($title)
                ?? $this->knowledge->findByTitle($title);

            if ($movie === null) {
                continue;
            }
            if (isset($seen[$movie->id])) {
                continue;
            }
            $seen[$movie->id] = true;

            $movie->loadMissing(['genres', 'castMembers']);
            $movie->setAttribute('_match_type', 'director');
            $movie->setAttribute('_ai_guess_title', $title);
            $matches->push($movie);
        }

        return $matches->values();
    }

    /**
     * Ask the AI for a director's filmography. Returns a list of titles
     * (no further filtering — caller maps to catalog).
     *
     * @return list<string>
     */
    protected function fetchDirectorFilmography(string $name): array
    {
        $cap = self::MAX_DIRECTOR_TITLES;
        $system = "You are a film database assistant. Given a director's name, list up to {$cap} films they directed.\n"
            . "Use the original international title (English) when known.\n"
            . "Respond ONLY with a JSON array of strings — no prose, no markdown, no code fences.\n"
            . 'Example: ["Inception","Interstellar","The Dark Knight"]' . "\n"
            . "If you don't recognise the name as a film director, return [].";

        try {
            $response = $this->ai->chat(
                messages: [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => "Director: {$name}"],
                ],
                options: [
                    'max_tokens' => 400,
                    'temperature' => 0.2,
                ],
            );

            return $this->parseTitles((string) ($response['content'] ?? ''));
        } catch (\Throwable $e) {
            Log::warning('DirectorActorSearchService: AI call failed', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return list<string>
     */
    protected function parseTitles(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $raw = $m[0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $titles = [];
        foreach ($decoded as $t) {
            if (is_string($t) && trim($t) !== '') {
                $titles[] = trim($t);
            }
        }

        return array_slice(array_values(array_unique($titles)), 0, self::MAX_DIRECTOR_TITLES);
    }
}
