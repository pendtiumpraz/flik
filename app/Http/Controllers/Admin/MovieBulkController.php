<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateMovieSynopsis;
use App\Jobs\TagMovie;
use App\Models\Movie;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * MovieBulkController
 * --------------------------------------------------------------------------
 * Single endpoint that powers every bulk action on the admin movies index
 * (delete / feature / unfeature / trend / untrend / add-genre /
 * regenerate AI tags / regenerate AI synopsis / export CSV).
 *
 * Authorisation is layered: the route is gated by `can:movies.update` (a
 * baseline ability shared by every supported action), and each action then
 * re-authorises against its sharper ability inside {@see self::authorize()}
 * — e.g. `delete` requires `movies.delete` even though the route only
 * required `movies.update`.
 *
 * Cap: 1000 IDs per call. Anything beyond that should be tackled via a
 * queued console command, not a synchronous admin request.
 */
class MovieBulkController extends Controller
{
    /** Hard cap on a single bulk call (prevents accidental "select all" → 10k row delete). */
    private const MAX_IDS = 1000;

    /** Chunk size for the per-action DB writes (keeps a long IN(...) list out of MySQL). */
    private const CHUNK = 100;

    /**
     * Supported actions and their required ability. Used by both the
     * validator (allowed values) and the per-action authorisation step.
     *
     * @var array<string,string>
     */
    private const ACTIONS = [
        'delete' => 'movies.delete',
        'feature' => 'movies.update',
        'unfeature' => 'movies.update',
        'trend' => 'movies.update',
        'untrend' => 'movies.update',
        'add-genre' => 'movies.update',
        'ai-tags' => 'movies.update',
        'ai-synopsis' => 'movies.update',
        'export-csv' => 'movies.view',
    ];

    public function __construct(private AuditLogger $audit) {}

    /**
     * Apply a bulk action to a set of movie IDs (or — when `search` is
     * present — to every movie matching the current search query, capped
     * at {@see self::MAX_IDS}).
     */
    public function apply(Request $request): JsonResponse|RedirectResponse|StreamedResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:'.implode(',', array_keys(self::ACTIONS))],
            'ids' => ['nullable', 'array', 'max:'.self::MAX_IDS],
            'ids.*' => ['integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'params' => ['nullable', 'array'],
        ]);

        $action = (string) $validated['action'];

        // Per-action sharpening of the baseline `can:movies.update` route guard.
        $ability = self::ACTIONS[$action];
        if (! $request->user() || ! $request->user()->can($ability)) {
            abort(403, "Missing ability: {$ability}");
        }

        // Resolve the target set: either the explicit `ids` array OR the
        // matching rows for the current search query. The two paths are
        // mutually exclusive — explicit IDs always win when both are sent.
        $ids = $this->resolveTargetIds($validated);

        if ($ids === [] && $action !== 'export-csv') {
            return $this->respond($request, [
                'success' => false,
                'message' => 'No movies selected.',
                'affected' => 0,
                'queued' => 0,
            ], status: 422, flashType: 'error');
        }

        $params = (array) ($validated['params'] ?? []);

        try {
            $result = match ($action) {
                'delete' => $this->doDelete($ids),
                'feature' => $this->doFlag($ids, 'is_popular', true),
                'unfeature' => $this->doFlag($ids, 'is_popular', false),
                'trend' => $this->doFlag($ids, 'is_trending', true),
                'untrend' => $this->doFlag($ids, 'is_trending', false),
                'add-genre' => $this->doAddGenre($ids, $params),
                'ai-tags' => $this->doDispatch($ids, TagMovie::class, 'ai-batch'),
                'ai-synopsis' => $this->doDispatch($ids, GenerateMovieSynopsis::class, 'ai-batch'),
                'export-csv' => $this->doExportCsv($ids, $request),
            };
        } catch (Throwable $e) {
            Log::error('MovieBulkController: action failed', [
                'action' => $action,
                'count' => count($ids),
                'error' => $e->getMessage(),
            ]);

            return $this->respond($request, [
                'success' => false,
                'message' => 'Bulk action failed: '.$e->getMessage(),
            ], status: 500, flashType: 'error');
        }

        // Streamed CSV bypasses the JSON/flash envelope — the browser triggers
        // a download directly from the response.
        if ($result instanceof StreamedResponse) {
            $this->safeAudit($action, $ids, ['format' => 'csv']);

            return $result;
        }

        $this->safeAudit($action, $ids, $params);

        return $this->respond($request, [
            'success' => true,
            'message' => $this->humanMessage($action, $result),
            'affected' => $result['affected'] ?? 0,
            'queued' => $result['queued'] ?? 0,
        ]);
    }

    // ── Resolvers ────────────────────────────────────────────────────────

    /**
     * Resolve the final list of movie IDs to act on. When `ids` is provided
     * it's used as-is (deduped and clamped). When only `search` is provided
     * we re-run the index query and capture every matching ID up to the cap.
     *
     * @param  array<string,mixed>  $validated
     * @return array<int,int>
     */
    private function resolveTargetIds(array $validated): array
    {
        $ids = $validated['ids'] ?? [];
        if (! empty($ids)) {
            return array_slice(array_values(array_unique(array_map('intval', $ids))), 0, self::MAX_IDS);
        }

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search === '') {
            return [];
        }

        // Mirror AdminController::movies() — keep the resolution logic in
        // lockstep so "Select all matching X" always means the same thing
        // as the visible filter.
        return Movie::query()
            ->where('title', 'like', '%'.$search.'%')
            ->orderBy('title')
            ->limit(self::MAX_IDS)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    // ── Action handlers ──────────────────────────────────────────────────

    /**
     * @param  array<int,int>  $ids
     * @return array{affected:int}
     */
    private function doDelete(array $ids): array
    {
        $affected = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            // Eager-detach pivots first so we don't leave dangling
            // genre_movie / cast_movie rows once the FK cascades fire.
            DB::table('genre_movie')->whereIn('movie_id', $chunk)->delete();
            DB::table('cast_movie')->whereIn('movie_id', $chunk)->delete();

            $affected += Movie::whereIn('id', $chunk)->delete();
        }

        return ['affected' => $affected];
    }

    /**
     * Generic boolean-flag flip used by feature/unfeature/trend/untrend.
     *
     * @param  array<int,int>  $ids
     * @return array{affected:int}
     */
    private function doFlag(array $ids, string $column, bool $value): array
    {
        $affected = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $affected += Movie::whereIn('id', $chunk)->update([$column => $value]);
        }

        return ['affected' => $affected];
    }

    /**
     * Attach a genre to every selected movie without detaching anything else.
     *
     * @param  array<int,int>  $ids
     * @param  array<string,mixed>  $params
     * @return array{affected:int}
     */
    private function doAddGenre(array $ids, array $params): array
    {
        $genreId = (int) ($params['genre_id'] ?? 0);
        if ($genreId <= 0) {
            abort(422, 'add-genre requires params.genre_id');
        }

        $affected = 0;
        // syncWithoutDetaching is idempotent — calling twice with the same
        // genre is a no-op rather than throwing on the unique pivot.
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            Movie::query()->whereIn('id', $chunk)->get()->each(function (Movie $m) use ($genreId, &$affected) {
                $m->genres()->syncWithoutDetaching([$genreId]);
                $affected++;
            });
        }

        return ['affected' => $affected];
    }

    /**
     * Fan out a queued job per movie ID. Used for ai-tags / ai-synopsis so
     * heavy work doesn't block the admin request.
     *
     * @param  array<int,int>  $ids
     * @return array{affected:int, queued:int}
     */
    private function doDispatch(array $ids, string $jobClass, string $queue): array
    {
        $queued = 0;
        foreach (Movie::query()->whereIn('id', $ids)->cursor() as $movie) {
            // TagMovie takes a Movie instance; GenerateMovieSynopsis takes
            // an int ID. Branch on the class name so we hand each its
            // expected constructor arg.
            $payload = $jobClass === TagMovie::class ? $movie : (int) $movie->id;
            $jobClass::dispatch($payload)->onQueue($queue);
            $queued++;
        }

        return ['affected' => 0, 'queued' => $queued];
    }

    /**
     * Stream a CSV download of the selected movies. Headers are written
     * directly to php://output so memory stays flat even at MAX_IDS rows.
     *
     * @param  array<int,int>  $ids
     */
    private function doExportCsv(array $ids, Request $request): StreamedResponse
    {
        $filename = 'movies-bulk-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($ids) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 correctly on Windows.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'title', 'year', 'genres', 'rating', 'status']);

            if ($ids === []) {
                fclose($out);

                return;
            }

            Movie::query()
                ->with('genres:id,name')
                ->whereIn('id', $ids)
                ->orderBy('title')
                ->chunk(self::CHUNK, function ($movies) use ($out) {
                    foreach ($movies as $m) {
                        $flags = [];
                        if ($m->is_popular) {
                            $flags[] = 'popular';
                        }
                        if ($m->is_trending) {
                            $flags[] = 'trending';
                        }

                        fputcsv($out, [
                            $m->id,
                            $m->title,
                            $m->release_date ? $m->release_date->format('Y') : '',
                            $m->genres->pluck('name')->implode('|'),
                            number_format((float) $m->vote_average, 1),
                            $flags === [] ? '' : implode(',', $flags),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ── Plumbing ─────────────────────────────────────────────────────────

    /**
     * Friendly per-action message for the flash bar / JSON response.
     *
     * @param  array<string,mixed>  $result
     */
    private function humanMessage(string $action, array $result): string
    {
        $n = (int) ($result['affected'] ?? 0);
        $q = (int) ($result['queued'] ?? 0);

        return match ($action) {
            'delete' => "{$n} film berhasil dihapus.",
            'feature' => "{$n} film ditandai featured.",
            'unfeature' => "{$n} film dilepas dari featured.",
            'trend' => "{$n} film ditandai trending.",
            'untrend' => "{$n} film dilepas dari trending.",
            'add-genre' => "Genre ditambahkan ke {$n} film.",
            'ai-tags' => "{$q} job AI-tag dijadwalkan.",
            'ai-synopsis' => "{$q} job AI-synopsis dijadwalkan.",
            'export-csv' => 'CSV diekspor.',
            default => 'Bulk action OK.',
        };
    }

    /**
     * AJAX requests want JSON; classic form posts want a redirect-back with
     * a flash message. We sniff the Accept header / X-Requested-With so the
     * same controller serves both.
     *
     * @param  array<string,mixed>  $payload
     */
    private function respond(Request $request, array $payload, int $status = 200, string $flashType = 'success'): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json($payload, $status);
        }

        $msg = (string) ($payload['message'] ?? 'OK');

        return back()->with($flashType, $msg);
    }

    /**
     * Audit-log a bulk action. Failures here must never abort the request
     * (audit infra is best-effort).
     *
     * @param  array<int,int>  $ids
     * @param  array<string,mixed>  $meta
     */
    private function safeAudit(string $action, array $ids, array $meta = []): void
    {
        try {
            $this->audit->log(
                action: 'admin.movies.bulk_'.$action,
                subject: null,
                meta: [
                    'action' => $action,
                    'ids' => array_slice($ids, 0, 200), // cap so audit row JSON doesn't bloat
                    'count' => count($ids),
                    'params' => $meta,
                ],
            );
        } catch (Throwable $e) {
            Log::warning('MovieBulkController: audit write failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
