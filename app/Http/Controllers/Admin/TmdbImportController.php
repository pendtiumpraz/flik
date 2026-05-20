<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportFromTmdb;
use App\Models\Movie;
use App\Services\Tmdb\MovieImporter;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Throwable;

/**
 * Admin TMDB import wizard.
 *
 * Three entrypoints power the wizard UI:
 *   - {@see search()}   — typeahead-style JSON search for autocompletion.
 *   - {@see preview()}  — JSON preview of the normalised TMDB payload (no DB writes).
 *   - {@see import()}   — synchronous OR queued import; returns either the new
 *                          movie URL or a queued-job confirmation.
 *
 * All endpoints gate on the existing `movies.create` permission so a Content
 * Editor with movies.create can import without needing a new permission slug.
 * Bulk import shares the same gate but adds a per-call dispatch cap so a
 * runaway operator can't blast TMDB with 10k IDs in a single POST.
 */
class TmdbImportController extends Controller
{
    /**
     * Hard cap on bulk import payload — protects against the obvious
     * "paste a million IDs" footgun. Operators with bigger backlogs can
     * just submit multiple batches.
     */
    private const BULK_LIMIT = 100;

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly MovieImporter $importer,
    ) {
    }

    /**
     * Wizard page (search bar + import form). Renders a "configure TMDB_KEY"
     * notice instead of the wizard when no credential is set.
     */
    public function index(): \Illuminate\Contracts\View\View
    {
        return view('admin.tmdb-import.index', [
            'enabled' => $this->tmdb->enabled(),
        ]);
    }

    /**
     * Bulk import landing page (paste IDs + queue).
     */
    public function bulkIndex(): \Illuminate\Contracts\View\View
    {
        return view('admin.tmdb-import.bulk', [
            'enabled' => $this->tmdb->enabled(),
            'limit' => self::BULK_LIMIT,
        ]);
    }

    /**
     * JSON search endpoint for the wizard's typeahead.
     *
     *   GET /admin/tmdb-import/search?q=dune&type=movie[&year=2024]
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => 'required|string|min:2|max:120',
            'type' => 'nullable|in:movie,tv',
            'year' => 'nullable|integer|min:1900|max:2100',
        ]);

        if (! $this->tmdb->enabled()) {
            return response()->json(['enabled' => false, 'results' => []], 200);
        }

        $type = $data['type'] ?? 'movie';
        $year = isset($data['year']) ? (int) $data['year'] : null;

        $results = $type === 'tv'
            ? $this->tmdb->searchTv($data['q'], $year)
            : $this->tmdb->searchMovie($data['q'], $year);

        // Slim the payload down to what the typeahead actually renders, so
        // we don't ship TMDB's enormous per-hit JSON over the wire.
        $slim = array_slice(array_map(function (array $r) use ($type) {
            $title = $type === 'tv'
                ? (string) ($r['name'] ?? '')
                : (string) ($r['title'] ?? '');
            $date = $type === 'tv'
                ? (string) ($r['first_air_date'] ?? '')
                : (string) ($r['release_date'] ?? '');
            return [
                'tmdb_id' => (int) ($r['id'] ?? 0),
                'title' => $title,
                'year' => $date !== '' ? mb_substr($date, 0, 4) : '',
                'overview' => mb_substr((string) ($r['overview'] ?? ''), 0, 280),
                'poster_url' => ! empty($r['poster_path'])
                    ? $this->tmdb->imageUrl((string) $r['poster_path'], 'w185')
                    : null,
                'popularity' => isset($r['popularity']) ? (float) $r['popularity'] : null,
                'vote_average' => isset($r['vote_average']) ? round((float) $r['vote_average'], 1) : null,
            ];
        }, $results), 0, 12);

        return response()->json([
            'enabled' => true,
            'type' => $type,
            'query' => $data['q'],
            'results' => array_values(array_filter($slim, fn ($r) => $r['tmdb_id'] > 0)),
        ]);
    }

    /**
     * JSON preview of a single TMDB id (no DB writes).
     *
     *   GET /admin/tmdb-import/preview?tmdb_id=438631&type=movie
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tmdb_id' => 'required|integer|min:1',
            'type' => 'nullable|in:movie,tv',
        ]);

        if (! $this->tmdb->enabled()) {
            return response()->json([
                'enabled' => false,
                'message' => 'TMDB API key not configured. Set TMDB_KEY in .env.',
            ], 503);
        }

        $type = $data['type'] ?? 'movie';
        $preview = $this->importer->preview((int) $data['tmdb_id'], $type);
        if ($preview === null) {
            return response()->json([
                'enabled' => true,
                'found' => false,
                'message' => "TMDB returned no data for {$type} #{$data['tmdb_id']}.",
            ], 404);
        }

        // Surface "already imported" so the wizard can prompt for overwrite.
        $existing = Movie::where('tmdb_id', $preview['tmdb_id'])->first();
        $preview['already_imported'] = $existing !== null;
        $preview['existing_movie'] = $existing
            ? [
                'id' => $existing->id,
                'slug' => $existing->slug,
                'title' => $existing->title,
                'edit_url' => route('admin.movies.edit', $existing),
            ]
            : null;

        return response()->json([
            'enabled' => true,
            'found' => true,
            'preview' => $preview,
        ]);
    }

    /**
     * Run (or queue) the import.
     *
     *   POST /admin/tmdb-import
     *       tmdb_id=438631
     *       type=movie
     *       queue=true|false
     *       options[download_images]=1
     *       options[translate_synopsis]=0
     *       options[overwrite_fields]=0
     *       options[import_seasons]=0
     *       options[import_episodes]=0
     */
    public function import(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'tmdb_id' => 'required|integer|min:1',
            'type' => 'nullable|in:movie,tv',
            'queue' => 'nullable|boolean',
            'options' => 'nullable|array',
            'options.download_images' => 'nullable|boolean',
            'options.translate_synopsis' => 'nullable|boolean',
            'options.overwrite_fields' => 'nullable|boolean',
            'options.import_seasons' => 'nullable|boolean',
            'options.import_episodes' => 'nullable|boolean',
            'options.set_content_type' => 'nullable|in:auto,movie,series',
        ]);

        if (! $this->tmdb->enabled()) {
            return $this->failResponse($request, 'TMDB API key not configured.', 503);
        }

        $tmdbId = (int) $data['tmdb_id'];
        $type = $data['type'] ?? 'movie';
        $options = $this->normaliseOptions($data['options'] ?? []);
        $queue = (bool) ($data['queue'] ?? false);

        if ($queue) {
            Bus::dispatch(new ImportFromTmdb(
                tmdbId: $tmdbId,
                type: $type,
                options: $options,
                userId: $request->user()?->id,
            ));

            return $this->jsonOrBack($request, [
                'queued' => true,
                'tmdb_id' => $tmdbId,
                'type' => $type,
                'message' => "Import queued for TMDB {$type} #{$tmdbId}. It will appear in the Movies list once the worker processes it.",
            ], successFlash: "TMDB {$type} #{$tmdbId} queued for import (queue: ai-batch).");
        }

        try {
            $movie = $this->importer->import($tmdbId, $type, $options);
        } catch (Throwable $e) {
            return $this->failResponse($request, 'Import failed: '.$e->getMessage(), 422);
        }

        return $this->jsonOrBack($request, [
            'queued' => false,
            'movie' => [
                'id' => $movie->id,
                'slug' => $movie->slug,
                'title' => $movie->title,
                'edit_url' => route('admin.movies.edit', $movie),
            ],
            'message' => "Imported \"{$movie->title}\" from TMDB.",
        ], successFlash: "Film \"{$movie->title}\" berhasil di-import dari TMDB.", redirectRoute: route('admin.movies.edit', $movie));
    }

    /**
     * Bulk dispatch a list of TMDB IDs (one job per id).
     *
     *   POST /admin/tmdb-import/bulk
     *       ids=601, 602, 603, …      OR
     *       ids_text="601\n602\n603"
     *       type=movie
     *       options[...]              (forwarded to every job)
     */
    public function bulkImport(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'ids' => 'nullable|array',
            'ids.*' => 'integer|min:1',
            'ids_text' => 'nullable|string|max:8000',
            'type' => 'nullable|in:movie,tv',
            'options' => 'nullable|array',
            'options.download_images' => 'nullable|boolean',
            'options.translate_synopsis' => 'nullable|boolean',
            'options.overwrite_fields' => 'nullable|boolean',
            'options.import_seasons' => 'nullable|boolean',
            'options.import_episodes' => 'nullable|boolean',
        ]);

        if (! $this->tmdb->enabled()) {
            return $this->failResponse($request, 'TMDB API key not configured.', 503);
        }

        $ids = $this->collectIds($data['ids'] ?? [], $data['ids_text'] ?? null);
        if (empty($ids)) {
            return $this->failResponse($request, 'No valid TMDB IDs provided.', 422);
        }
        if (count($ids) > self::BULK_LIMIT) {
            return $this->failResponse(
                $request,
                'Too many IDs in one batch (limit '.self::BULK_LIMIT.'). Split into smaller batches.',
                422,
            );
        }

        $type = $data['type'] ?? 'movie';
        $options = $this->normaliseOptions($data['options'] ?? []);
        $delaySeconds = 0;

        foreach ($ids as $id) {
            // Stagger dispatch with a 2s delay between jobs to avoid hammering
            // TMDB's per-second rate limit on a single batch.
            Bus::dispatch((new ImportFromTmdb(
                tmdbId: $id,
                type: $type,
                options: $options,
                userId: $request->user()?->id,
            ))->delay(now()->addSeconds($delaySeconds)));
            $delaySeconds += 2;
        }

        return $this->jsonOrBack($request, [
            'queued_count' => count($ids),
            'tmdb_ids' => $ids,
            'message' => count($ids).' import jobs queued (one every 2s on the ai-batch queue).',
        ], successFlash: count($ids).' film(s) queued from TMDB.');
    }

    // ──────────────────────────────────────────────────────────────────
    // INTERNALS
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normaliseOptions(array $raw): array
    {
        return [
            'download_images' => filter_var($raw['download_images'] ?? true, FILTER_VALIDATE_BOOL),
            'translate_synopsis' => filter_var($raw['translate_synopsis'] ?? false, FILTER_VALIDATE_BOOL),
            'overwrite_fields' => filter_var($raw['overwrite_fields'] ?? false, FILTER_VALIDATE_BOOL),
            'import_seasons' => filter_var($raw['import_seasons'] ?? false, FILTER_VALIDATE_BOOL),
            'import_episodes' => filter_var($raw['import_episodes'] ?? false, FILTER_VALIDATE_BOOL),
            'set_content_type' => in_array(($raw['set_content_type'] ?? 'auto'), ['auto', 'movie', 'series'], true)
                ? $raw['set_content_type']
                : 'auto',
        ];
    }

    /**
     * Merge array-form IDs and free-text IDs (one per line, comma-separated)
     * into a deduped int list.
     *
     * @param  array<int|string, mixed>  $arrayIds
     * @return array<int, int>
     */
    private function collectIds(array $arrayIds, ?string $text): array
    {
        $ints = [];
        foreach ($arrayIds as $v) {
            $i = (int) $v;
            if ($i > 0) {
                $ints[] = $i;
            }
        }
        if ($text !== null && $text !== '') {
            $tokens = preg_split('/[\s,;]+/', $text) ?: [];
            foreach ($tokens as $t) {
                $t = trim((string) $t);
                if ($t === '') {
                    continue;
                }
                if (ctype_digit($t)) {
                    $ints[] = (int) $t;
                }
            }
        }
        return array_values(array_unique(array_filter($ints, fn ($i) => $i > 0)));
    }

    /**
     * Render JSON for XHR clients and redirect-with-flash for regular form posts.
     *
     * @param  array<string, mixed>  $payload
     */
    private function jsonOrBack(
        Request $request,
        array $payload,
        string $successFlash = '',
        ?string $redirectRoute = null,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($payload);
        }
        $back = $redirectRoute ? redirect($redirectRoute) : back();
        return $successFlash !== '' ? $back->with('success', $successFlash) : $back;
    }

    private function failResponse(Request $request, string $message, int $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => $message], $status);
        }
        return back()->with('error', $message)->withInput();
    }
}
