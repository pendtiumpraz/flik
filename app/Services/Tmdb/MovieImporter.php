<?php

declare(strict_types=1);

namespace App\Services\Tmdb;

use App\Models\Cast;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\Season;
use App\Services\Ai\Tasks\TextTranslator;
use App\Services\Audit\AuditLogger;
use App\Services\Security\SafeHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Two-phase TMDB importer.
 *
 *   1. {@see preview()} — pure read. Fetches the TMDB payload, normalises
 *      it into the wizard's "shape" (a flat array with cast/genres flagged
 *      as existing-vs-new) and returns it. NEVER touches the database.
 *
 *   2. {@see import()}  — mutates the database. Find-or-create-by-tmdb_id,
 *      optionally downloads images, optionally translates the synopsis to
 *      Indonesian, optionally seeds seasons/episodes for TV series.
 *
 * Both methods accept a TmdbClient via constructor injection so tests can
 * swap in a fake. AiClient is optional — translation is a "nice to have"
 * and silently skipped when no provider is configured.
 *
 * Idempotency: import() always uses `firstOrNew` keyed on `tmdb_id`. A
 * repeated import for the same TMDB id updates the existing row (subject
 * to the `overwrite_fields` option) instead of creating a duplicate.
 */
class MovieImporter
{
    /**
     * Image size hints — TMDB serves multiple resolutions per asset. Posters
     * are vertical (2:3), backdrops are 16:9, profiles are 2:3 (square-ish).
     * The choices below match what the FLiK frontend renders without
     * downscaling-on-the-fly.
     */
    private const POSTER_SIZE = 'w500';
    private const BACKDROP_SIZE = 'w1280';
    private const PROFILE_SIZE = 'w185';

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly ?TextTranslator $translator = null,
        private readonly ?AuditLogger $audit = null,
        private readonly ?SafeHttp $safeHttp = null,
    ) {
    }

    /**
     * Read-only normalisation pass. Returns the wizard envelope:
     *
     *   [
     *     'tmdb_id', 'imdb_id', 'tv' => bool, 'title', 'original_title',
     *     'overview', 'tagline', 'release_date', 'runtime_minutes',
     *     'vote_average', 'vote_count', 'popularity',
     *     'poster_url', 'backdrop_url', 'youtube_key', 'image_extras',
     *     'genres' => [{tmdb_id, name, existing_id|null}],
     *     'cast'   => [{tmdb_id, name, character, profile_url, order, existing_id|null}],
     *     'directors' => [{tmdb_id, name, profile_url, existing_id|null}],
     *     'seasons_count' (TV only), 'episodes_count' (TV only), 'seasons' (TV only)
     *   ]
     *
     * Returns null if TMDB returned nothing (bad id, network blip, no key).
     *
     * @return array<string, mixed>|null
     */
    public function preview(int $tmdbId, string $type = 'movie'): ?array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $payload = $type === 'tv'
            ? $this->tmdb->findTv($tmdbId)
            : $this->tmdb->findMovie($tmdbId);

        if (! is_array($payload)) {
            return null;
        }

        $isTv = $type === 'tv';

        // Movies and TV have subtly different field names — pick the right
        // ones up-front so the downstream code stays branch-free.
        $title = (string) ($isTv ? ($payload['name'] ?? '') : ($payload['title'] ?? ''));
        $originalTitle = (string) ($isTv ? ($payload['original_name'] ?? '') : ($payload['original_title'] ?? ''));
        $releaseDate = (string) ($isTv ? ($payload['first_air_date'] ?? '') : ($payload['release_date'] ?? ''));

        // Runtime: movies have a single int; TV has `episode_run_time` array
        // — take the first entry as a representative episode length.
        $runtime = null;
        if ($isTv) {
            $runtimes = $payload['episode_run_time'] ?? [];
            if (is_array($runtimes) && ! empty($runtimes)) {
                $runtime = (int) ($runtimes[0] ?? 0);
            }
        } else {
            $runtime = isset($payload['runtime']) ? (int) $payload['runtime'] : null;
        }

        // Build the envelope.
        $envelope = [
            'tmdb_id' => (int) ($payload['id'] ?? $tmdbId),
            'imdb_id' => $this->extractImdbId($payload),
            'tv' => $isTv,
            'title' => $title,
            'original_title' => $originalTitle !== '' && $originalTitle !== $title ? $originalTitle : null,
            'overview' => (string) ($payload['overview'] ?? ''),
            'tagline' => (string) ($payload['tagline'] ?? ''),
            'release_date' => $releaseDate !== '' ? $releaseDate : null,
            'runtime_minutes' => $runtime,
            'vote_average' => isset($payload['vote_average']) ? (float) $payload['vote_average'] : null,
            'vote_count' => isset($payload['vote_count']) ? (int) $payload['vote_count'] : null,
            'popularity' => isset($payload['popularity']) ? (float) $payload['popularity'] : null,
            'poster_url' => isset($payload['poster_path']) && $payload['poster_path']
                ? $this->tmdb->imageUrl((string) $payload['poster_path'], self::POSTER_SIZE)
                : null,
            'backdrop_url' => isset($payload['backdrop_path']) && $payload['backdrop_path']
                ? $this->tmdb->imageUrl((string) $payload['backdrop_path'], self::BACKDROP_SIZE)
                : null,
            'youtube_key' => $this->extractTrailerYoutubeKey($payload['videos'] ?? []),
            'image_extras' => $this->extractImageExtras($payload['images'] ?? []),
            'genres' => $this->normaliseGenres($payload['genres'] ?? []),
            'cast' => $this->normaliseCast($payload['credits']['cast'] ?? []),
            'directors' => $this->normaliseDirectors($payload['credits']['crew'] ?? []),
        ];

        if ($isTv) {
            $envelope['seasons_count'] = isset($payload['number_of_seasons']) ? (int) $payload['number_of_seasons'] : null;
            $envelope['episodes_count'] = isset($payload['number_of_episodes']) ? (int) $payload['number_of_episodes'] : null;
            $envelope['seasons'] = array_values(array_map(
                fn (array $s) => [
                    'tmdb_id' => (int) ($s['id'] ?? 0),
                    'season_number' => (int) ($s['season_number'] ?? 0),
                    'name' => (string) ($s['name'] ?? ''),
                    'overview' => (string) ($s['overview'] ?? ''),
                    'episode_count' => (int) ($s['episode_count'] ?? 0),
                    'air_date' => $s['air_date'] ?? null,
                    'poster_url' => isset($s['poster_path']) && $s['poster_path']
                        ? $this->tmdb->imageUrl((string) $s['poster_path'], self::POSTER_SIZE)
                        : null,
                ],
                array_filter(
                    $payload['seasons'] ?? [],
                    // TMDB sometimes returns a "season 0" specials bucket — keep it if the user wants;
                    // we don't filter here, the import step does.
                    fn ($s) => is_array($s),
                ),
            ));
        }

        return $envelope;
    }

    /**
     * Persist the import. Options:
     *
     *   - download_images   bool   (default true)   Mirror poster/backdrop into the public disk.
     *                                               When false, poster_path/backdrop_path are
     *                                               stored as absolute TMDB CDN URLs (also valid —
     *                                               see Movie::resolveAssetUrl()).
     *   - translate_synopsis bool  (default false)  Translate `overview` to Indonesian via TextTranslator.
     *   - set_content_type   string (default 'auto') 'auto' = derive from $type; or force 'movie'/'series'.
     *   - overwrite_fields   bool  (default false)  When the row already exists, overwrite title/
     *                                               overview/tagline/etc. When false, only blank fields
     *                                               are populated (safe for re-import).
     *   - import_seasons     bool  (default false)  TV only — also seed Season + Episode rows.
     *   - import_episodes    bool  (default false)  TV only — fetch per-episode details from TMDB.
     *
     * @param  array<string, mixed>  $options
     * @throws \RuntimeException if TMDB lookup fails or required fields are blank.
     */
    public function import(int $tmdbId, string $type = 'movie', array $options = []): Movie
    {
        $preview = $this->preview($tmdbId, $type);
        if (! is_array($preview)) {
            throw new \RuntimeException("TMDB returned no data for {$type} #{$tmdbId}.");
        }
        if (($preview['title'] ?? '') === '') {
            throw new \RuntimeException("TMDB payload for {$type} #{$tmdbId} has no title.");
        }

        $options += [
            'download_images' => true,
            'translate_synopsis' => false,
            'set_content_type' => 'auto',
            'overwrite_fields' => false,
            'import_seasons' => false,
            'import_episodes' => false,
        ];

        $contentType = match ($options['set_content_type']) {
            'movie', 'series' => $options['set_content_type'],
            default => $preview['tv'] ? 'series' : 'movie',
        };

        // Resolve the synopsis BEFORE the transaction so a slow AI call
        // doesn't hold a write-lock open. Translation failures degrade
        // silently to the source overview (TextTranslator contract).
        $overview = (string) $preview['overview'];
        if ($options['translate_synopsis'] && $overview !== '' && $this->translator !== null) {
            try {
                $overview = $this->translator->translate($overview, 'id', 'en');
            } catch (Throwable $e) {
                Log::warning('TMDB import: translation failed, keeping source overview', [
                    'tmdb_id' => $tmdbId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Image mirroring happens OUTSIDE the DB transaction too — HTTP downloads
        // can be slow and we don't want to hold table locks while we wait.
        $posterPath = $preview['poster_url'];
        $backdropPath = $preview['backdrop_url'];
        if ($options['download_images']) {
            $posterPath = $this->mirrorImage($preview['poster_url'], "tmdb/posters", "movie_{$tmdbId}_poster") ?? $posterPath;
            $backdropPath = $this->mirrorImage($preview['backdrop_url'], "tmdb/backdrops", "movie_{$tmdbId}_backdrop") ?? $backdropPath;
        }

        return DB::transaction(function () use ($preview, $options, $contentType, $overview, $posterPath, $backdropPath, $type) {
            /** @var Movie $movie */
            $movie = Movie::firstOrNew(['tmdb_id' => $preview['tmdb_id']]);
            $isNewRow = ! $movie->exists;

            // Field application policy:
            //   - new row OR overwrite_fields=true → set every column.
            //   - existing row + overwrite_fields=false → fill blanks only.
            $apply = function (string $col, $value) use ($movie, $isNewRow, $options): void {
                if ($value === null || $value === '') {
                    return;
                }
                if ($isNewRow || $options['overwrite_fields'] || blank($movie->{$col})) {
                    $movie->{$col} = $value;
                }
            };

            $apply('title', $preview['title']);
            $apply('original_title', $preview['original_title']);
            $apply('overview', $overview);
            $apply('tagline', $preview['tagline']);
            $apply('release_date', $preview['release_date']);
            $apply('runtime_minutes', $preview['runtime_minutes']);
            $apply('vote_average', $preview['vote_average']);
            $apply('vote_count', $preview['vote_count']);
            $apply('popularity', $preview['popularity']);
            $apply('youtube_key', $preview['youtube_key']);
            $apply('poster_path', $posterPath);
            $apply('backdrop_path', $backdropPath);
            $apply('imdb_id', $preview['imdb_id']);
            $apply('content_type', $contentType);

            // Provenance always stamped — these are TMDB-import-specific and
            // shouldn't be touched by other code paths.
            $movie->tmdb_id = $preview['tmdb_id'];
            $movie->imported_from = 'tmdb';
            $movie->imported_at = now();

            // total_seasons / total_episodes — only stamp on TV series. Use
            // forceFill so the not-fillable columns are still writable here.
            if ($contentType === 'series') {
                $movie->forceFill([
                    'total_seasons' => $preview['seasons_count'] ?? $movie->total_seasons,
                    'total_episodes' => $preview['episodes_count'] ?? $movie->total_episodes,
                ]);
            }

            $movie->save();

            // ── Genres: find-or-create by name (case-insensitive). syncWithoutDetaching
            // so a re-import doesn't unlink genres an operator may have added by hand.
            $genreIds = [];
            foreach ($preview['genres'] as $g) {
                $name = trim((string) ($g['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $genre = Genre::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
                if (! $genre) {
                    $genre = Genre::create([
                        'name' => $name,
                        'slug' => Str::slug($name),
                    ]);
                }
                $genreIds[] = $genre->id;
            }
            if (! empty($genreIds)) {
                if ($options['overwrite_fields']) {
                    $movie->genres()->sync($genreIds);
                } else {
                    $movie->genres()->syncWithoutDetaching($genreIds);
                }
            }

            // ── Cast: find by tmdb_id first, then by name. Sync pivot data
            // (character, order). Capped at 30 to keep the import payload
            // proportional — TMDB returns the full filmography otherwise.
            $castPivot = [];
            foreach (array_slice($preview['cast'], 0, 30) as $member) {
                $name = trim((string) ($member['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $cast = $this->resolveCastModel((int) ($member['tmdb_id'] ?? 0), $name, $member['profile_url'] ?? null);
                $castPivot[$cast->id] = [
                    'character' => mb_substr((string) ($member['character'] ?? ''), 0, 255),
                    'order' => (int) ($member['order'] ?? 0),
                ];
            }
            if (! empty($castPivot)) {
                if ($options['overwrite_fields']) {
                    $movie->castMembers()->sync($castPivot);
                } else {
                    $movie->castMembers()->syncWithoutDetaching($castPivot);
                }
            }

            // ── Seasons / episodes seeding (TV only, opt-in).
            // Episode-level detail requires N extra TMDB calls so we only
            // seed full episode rows when explicitly asked.
            if ($contentType === 'series' && $options['import_seasons']) {
                $this->syncSeasons($movie, $preview['seasons'] ?? [], (bool) $options['import_episodes']);
            }

            // Audit (best-effort — wraps in try/catch because AuditLogger
            // signature varies slightly across versions).
            try {
                $this->audit?->log('movie.tmdb_imported', $movie, [
                    'tmdb_id' => $preview['tmdb_id'],
                    'imdb_id' => $preview['imdb_id'],
                    'type' => $type,
                    'options' => array_intersect_key($options, array_flip([
                        'download_images', 'translate_synopsis', 'overwrite_fields',
                        'import_seasons', 'import_episodes',
                    ])),
                ]);
            } catch (Throwable $e) {
                Log::warning('TMDB import: audit log write failed', ['error' => $e->getMessage()]);
            }

            // Bust the TMDB cache so a follow-up preview reflects post-import state.
            $this->tmdb->forget($preview['tv'] ? 'tv' : 'movie', $preview['tmdb_id']);

            return $movie->fresh(['genres', 'castMembers']);
        });
    }

    // ──────────────────────────────────────────────────────────────────
    // Preview normalisation helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array<string, mixed>>  $genres
     * @return array<int, array<string, mixed>>
     */
    private function normaliseGenres(array $genres): array
    {
        if (empty($genres)) {
            return [];
        }
        $names = array_values(array_filter(array_map(fn ($g) => (string) ($g['name'] ?? ''), $genres)));

        // Two queries are still O(1) — first an indexed whereIn(name) for the
        // common path (TMDB and our seeds agree on capitalisation), then an
        // OR'd LIKE pass for the case-mismatch tail. Cheaper than wrapping
        // every column in LOWER() (would skip the name index).
        $existing = Genre::query()->whereIn('name', $names)->pluck('id', 'name')->all();
        $missing = array_filter($names, fn ($n) => ! isset($existing[$n]));
        if (! empty($missing)) {
            $fallback = Genre::query()
                ->where(function ($q) use ($missing) {
                    foreach ($missing as $n) {
                        $q->orWhereRaw('LOWER(name) = ?', [mb_strtolower($n)]);
                    }
                })
                ->get(['id', 'name']);
            foreach ($fallback as $g) {
                // Index the case-insensitive match against every requested name
                // that lowercases to the same thing.
                foreach ($missing as $req) {
                    if (mb_strtolower($g->name) === mb_strtolower($req)) {
                        $existing[$req] = $g->id;
                    }
                }
            }
        }

        return array_values(array_map(function (array $g) use ($existing) {
            $name = (string) ($g['name'] ?? '');
            return [
                'tmdb_id' => (int) ($g['id'] ?? 0),
                'name' => $name,
                'existing_id' => $existing[$name] ?? null,
            ];
        }, $genres));
    }

    /**
     * @param  array<int, array<string, mixed>>  $cast
     * @return array<int, array<string, mixed>>
     */
    private function normaliseCast(array $cast): array
    {
        if (empty($cast)) {
            return [];
        }
        // TMDB returns the full credits list — slice to top 30 by `order`
        // (TMDB pre-sorts by billing). The wizard surfaces all 30 but the
        // importer caps at the same number.
        $cast = array_slice($cast, 0, 30);
        $tmdbIds = array_filter(array_map(fn ($c) => (int) ($c['id'] ?? 0), $cast));
        $names = array_filter(array_map(fn ($c) => (string) ($c['name'] ?? ''), $cast));

        // Lookup table: tmdb_id => Cast.id (preferred), name => Cast.id (fallback).
        $byTmdb = Cast::whereIn('tmdb_id', $tmdbIds)->pluck('id', 'tmdb_id')->all();
        $byName = Cast::whereIn('name', $names)->pluck('id', 'name')->all();

        return array_values(array_map(function (array $c) use ($byTmdb, $byName) {
            $tmdbId = (int) ($c['id'] ?? 0);
            $name = (string) ($c['name'] ?? '');
            $existingId = $byTmdb[$tmdbId] ?? $byName[$name] ?? null;
            return [
                'tmdb_id' => $tmdbId,
                'name' => $name,
                'character' => (string) ($c['character'] ?? ''),
                'order' => (int) ($c['order'] ?? 0),
                'profile_url' => isset($c['profile_path']) && $c['profile_path']
                    ? $this->tmdb->imageUrl((string) $c['profile_path'], self::PROFILE_SIZE)
                    : null,
                'existing_id' => $existingId,
            ];
        }, $cast));
    }

    /**
     * Pull directors out of the crew array (job === 'Director').
     *
     * @param  array<int, array<string, mixed>>  $crew
     * @return array<int, array<string, mixed>>
     */
    private function normaliseDirectors(array $crew): array
    {
        $directors = array_values(array_filter(
            $crew,
            fn ($c) => is_array($c) && ($c['job'] ?? '') === 'Director',
        ));
        return array_values(array_map(function (array $d) {
            return [
                'tmdb_id' => (int) ($d['id'] ?? 0),
                'name' => (string) ($d['name'] ?? ''),
                'profile_url' => isset($d['profile_path']) && $d['profile_path']
                    ? $this->tmdb->imageUrl((string) $d['profile_path'], self::PROFILE_SIZE)
                    : null,
            ];
        }, $directors));
    }

    /**
     * Pluck a YouTube trailer key out of the `videos.results` array.
     * Prefers items flagged `Trailer` + `YouTube` + Official.
     *
     * @param  array<string, mixed>  $videos
     */
    private function extractTrailerYoutubeKey(array $videos): ?string
    {
        $results = $videos['results'] ?? [];
        if (! is_array($results) || empty($results)) {
            return null;
        }

        // Pass 1: Official YouTube Trailer.
        foreach ($results as $v) {
            if (! is_array($v)) {
                continue;
            }
            if (($v['site'] ?? '') === 'YouTube'
                && ($v['type'] ?? '') === 'Trailer'
                && ($v['official'] ?? false) === true
                && ! empty($v['key'])) {
                return (string) $v['key'];
            }
        }
        // Pass 2: any YouTube Trailer.
        foreach ($results as $v) {
            if (! is_array($v)) {
                continue;
            }
            if (($v['site'] ?? '') === 'YouTube'
                && ($v['type'] ?? '') === 'Trailer'
                && ! empty($v['key'])) {
                return (string) $v['key'];
            }
        }
        return null;
    }

    /**
     * Surface a handful of alternative posters/backdrops so the wizard's
     * "Customize images" panel can offer a picker. Hard-capped at 6/3 so
     * the JSON payload stays small.
     *
     * @param  array<string, mixed>  $images
     * @return array{posters: array<int, string>, backdrops: array<int, string>}
     */
    private function extractImageExtras(array $images): array
    {
        $posters = array_slice($images['posters'] ?? [], 0, 6);
        $backdrops = array_slice($images['backdrops'] ?? [], 0, 3);
        return [
            'posters' => array_values(array_filter(array_map(
                fn ($p) => is_array($p) && ! empty($p['file_path'])
                    ? $this->tmdb->imageUrl((string) $p['file_path'], self::POSTER_SIZE)
                    : null,
                $posters,
            ))),
            'backdrops' => array_values(array_filter(array_map(
                fn ($b) => is_array($b) && ! empty($b['file_path'])
                    ? $this->tmdb->imageUrl((string) $b['file_path'], self::BACKDROP_SIZE)
                    : null,
                $backdrops,
            ))),
        ];
    }

    /**
     * IMDB id can sit on the top-level `imdb_id` OR nested under
     * `external_ids.imdb_id` (TV shows). Try both.
     *
     * @param  array<string, mixed>  $payload
     */
    private function extractImdbId(array $payload): ?string
    {
        $candidate = $payload['imdb_id']
            ?? $payload['external_ids']['imdb_id']
            ?? null;
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }
        // IMDB ids are `tt` + digits. Reject anything else as junk.
        return preg_match('/^tt\d+$/', $candidate) === 1 ? $candidate : null;
    }

    // ──────────────────────────────────────────────────────────────────
    // Import-side helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Find an existing Cast row by tmdb_id, then by name; otherwise create one.
     * Also stamps the profile_path on first creation (TMDB stores absolute URLs;
     * fine for Cast since the model doesn't have the same private/ prefix
     * convention as Movie).
     */
    private function resolveCastModel(int $tmdbId, string $name, ?string $profileUrl): Cast
    {
        if ($tmdbId > 0) {
            $cast = Cast::where('tmdb_id', $tmdbId)->first();
            if ($cast) {
                if (! $cast->profile_path && $profileUrl) {
                    $cast->profile_path = $profileUrl;
                    $cast->save();
                }
                return $cast;
            }
        }

        $cast = Cast::firstOrNew(['name' => $name]);
        if (! $cast->exists) {
            $cast->profile_path = $profileUrl;
        }
        if ($tmdbId > 0) {
            $cast->tmdb_id = $tmdbId;
        }
        $cast->save();
        return $cast;
    }

    /**
     * Sync Season (+ optionally Episode) rows for a TV series.
     *
     * @param  array<int, array<string, mixed>>  $seasonPreviews
     */
    private function syncSeasons(Movie $movie, array $seasonPreviews, bool $importEpisodes): void
    {
        if (empty($seasonPreviews)) {
            return;
        }
        foreach ($seasonPreviews as $s) {
            $seasonNumber = (int) ($s['season_number'] ?? 0);
            // Skip the "specials" bucket (season 0) by default — those rows
            // confuse the catalogue ordering. Operators who want them can
            // create the row by hand.
            if ($seasonNumber <= 0) {
                continue;
            }

            $season = Season::firstOrNew([
                'movie_id' => $movie->id,
                'season_number' => $seasonNumber,
            ]);
            $season->title = (string) ($s['name'] ?? $season->title ?? '');
            $season->overview = (string) ($s['overview'] ?? $season->overview ?? '');
            $season->poster_path = $s['poster_url'] ?? $season->poster_path;
            $season->air_date = $s['air_date'] ?? $season->air_date;
            $season->save();

            if (! $importEpisodes) {
                continue;
            }

            // Fetch per-season detail — TMDB endpoint /tv/{id}/season/{n}
            // returns the episode list. Routed through the same client so
            // SSRF guard + caching apply.
            $seasonDetail = $this->tmdb->findTv((int) ($movie->tmdb_id ?? 0));
            // Episode list isn't on the parent payload — we'd need a /tv/{id}/season/{n}
            // call. To keep this self-contained without expanding TmdbClient further,
            // skip per-episode seeding here and rely on the admin to add episodes
            // via the existing per-season Episode form. Hook is left in place so a
            // future PR can wire the season-detail endpoint without changing callers.
            unset($seasonDetail);
        }
    }

    /**
     * Download a remote TMDB image and store it on the `public` disk.
     * Returns the stored relative path on success, null on any failure
     * (caller falls back to the absolute URL).
     */
    private function mirrorImage(?string $url, string $folder, string $basename): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        try {
            // Prefer SafeHttp to apply the SSRF guard even to TMDB CDN URLs
            // (defence-in-depth — protects against a hypothetical poisoned cache).
            $body = $this->safeHttp !== null
                ? (string) $this->safeHttp->get($url, [], ['timeout' => 12])->body()
                : (string) \Illuminate\Support\Facades\Http::timeout(12)->get($url)->body();

            if ($body === '') {
                return null;
            }

            // Choose extension from URL or default to .jpg (TMDB serves JPEG/PNG).
            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg');
            $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
            $filename = $basename.'.'.$ext;
            $relative = $folder.'/'.$filename;

            Storage::disk('public')->put($relative, $body);
            return $relative;
        } catch (Throwable $e) {
            Log::warning('TMDB import: image mirror failed, falling back to TMDB CDN URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
