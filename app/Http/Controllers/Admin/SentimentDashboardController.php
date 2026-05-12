<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Movie;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard for comment-sentiment analytics.
 *
 * Read-only view: aggregates the `sentiment*` columns populated by
 * App\Services\Ai\Tasks\CommentSentimentAnalyzer (via the
 * AnalyzeCommentSentiment job).
 *
 * Route bound via /admin/sentiment[/{movie}] — when {movie} is omitted the
 * caller passes null and we render the global view.
 */
class SentimentDashboardController extends Controller
{
    /**
     * Number of recent positive/negative comments to surface.
     */
    protected const RECENT_LIMIT = 10;

    /**
     * Trend window (days).
     */
    protected const TREND_DAYS = 30;

    public function index(?Movie $movie = null)
    {
        // When the route is hit without a model param Laravel still gives us
        // an empty Movie instance — treat un-persisted as "global".
        $scopedMovie = ($movie && $movie->exists) ? $movie : null;

        $overall = $this->overallStats();
        $perMovie = $scopedMovie ? null : $this->perMovieStats();
        $scopedStats = $scopedMovie ? $this->scopedStats($scopedMovie) : null;
        $trend = $this->trendByDate($scopedMovie);
        [$recentPositive, $recentNegative] = $this->recentExamples($scopedMovie);

        return view('admin.sentiment.dashboard', [
            'scopedMovie' => $scopedMovie,
            'overall' => $overall,
            'scopedStats' => $scopedStats,
            'perMovie' => $perMovie,
            'trend' => $trend,
            'recentPositive' => $recentPositive,
            'recentNegative' => $recentNegative,
            'trendDays' => self::TREND_DAYS,
        ]);
    }

    /**
     * Platform-wide aggregate counts + averages.
     *
     * @return array{
     *   total:int, analysed:int, pending:int,
     *   positive:int, negative:int, neutral:int, mixed:int,
     *   avg_score:float|null, coverage:float
     * }
     */
    protected function overallStats(): array
    {
        $row = Comment::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(sentiment) AS analysed')
            ->selectRaw("SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) AS positive")
            ->selectRaw("SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) AS negative")
            ->selectRaw("SUM(CASE WHEN sentiment = 'neutral'  THEN 1 ELSE 0 END) AS neutral")
            ->selectRaw("SUM(CASE WHEN sentiment = 'mixed'    THEN 1 ELSE 0 END) AS mixed")
            ->selectRaw('AVG(sentiment_score) AS avg_score')
            ->first();

        $total = (int) ($row->total ?? 0);
        $analysed = (int) ($row->analysed ?? 0);

        return [
            'total' => $total,
            'analysed' => $analysed,
            'pending' => max(0, $total - $analysed),
            'positive' => (int) ($row->positive ?? 0),
            'negative' => (int) ($row->negative ?? 0),
            'neutral' => (int) ($row->neutral ?? 0),
            'mixed' => (int) ($row->mixed ?? 0),
            'avg_score' => $row->avg_score !== null ? round((float) $row->avg_score, 3) : null,
            'coverage' => $total > 0 ? round(($analysed / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Per-movie breakdown: title + per-sentiment counts + avg score.
     * Limited to movies that actually have analysed comments.
     *
     * @return Collection<int, object{
     *   movie_id:int, title:string, slug:?string,
     *   total:int, positive:int, negative:int, neutral:int, mixed:int,
     *   avg_score:float|null
     * }>
     */
    protected function perMovieStats(): Collection
    {
        return Comment::query()
            ->join('movies', 'movies.id', '=', 'comments.movie_id')
            ->whereNotNull('comments.sentiment')
            ->groupBy('comments.movie_id', 'movies.title', 'movies.slug')
            ->orderByDesc(DB::raw('COUNT(comments.id)'))
            ->limit(50)
            ->get([
                'comments.movie_id',
                'movies.title',
                'movies.slug',
                DB::raw('COUNT(comments.id) AS total'),
                DB::raw("SUM(CASE WHEN comments.sentiment = 'positive' THEN 1 ELSE 0 END) AS positive"),
                DB::raw("SUM(CASE WHEN comments.sentiment = 'negative' THEN 1 ELSE 0 END) AS negative"),
                DB::raw("SUM(CASE WHEN comments.sentiment = 'neutral'  THEN 1 ELSE 0 END) AS neutral"),
                DB::raw("SUM(CASE WHEN comments.sentiment = 'mixed'    THEN 1 ELSE 0 END) AS mixed"),
                DB::raw('AVG(comments.sentiment_score) AS avg_score'),
            ])
            ->map(function ($r): object {
                return (object) [
                    'movie_id' => (int) $r->movie_id,
                    'title' => (string) $r->title,
                    'slug' => $r->slug,
                    'total' => (int) $r->total,
                    'positive' => (int) $r->positive,
                    'negative' => (int) $r->negative,
                    'neutral' => (int) $r->neutral,
                    'mixed' => (int) $r->mixed,
                    'avg_score' => $r->avg_score !== null ? round((float) $r->avg_score, 3) : null,
                ];
            });
    }

    /**
     * Aggregate stats scoped to a single movie.
     *
     * @return array{
     *   total:int, positive:int, negative:int, neutral:int, mixed:int,
     *   avg_score:float|null
     * }
     */
    protected function scopedStats(Movie $movie): array
    {
        $row = Comment::query()
            ->where('movie_id', $movie->id)
            ->whereNotNull('sentiment')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) AS positive")
            ->selectRaw("SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) AS negative")
            ->selectRaw("SUM(CASE WHEN sentiment = 'neutral'  THEN 1 ELSE 0 END) AS neutral")
            ->selectRaw("SUM(CASE WHEN sentiment = 'mixed'    THEN 1 ELSE 0 END) AS mixed")
            ->selectRaw('AVG(sentiment_score) AS avg_score')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'positive' => (int) ($row->positive ?? 0),
            'negative' => (int) ($row->negative ?? 0),
            'neutral' => (int) ($row->neutral ?? 0),
            'mixed' => (int) ($row->mixed ?? 0),
            'avg_score' => $row->avg_score !== null ? round((float) $row->avg_score, 3) : null,
        ];
    }

    /**
     * Daily sentiment trend over the last N days.
     *
     * Always returns one row per day in the window (zero-filled when nothing
     * was analysed that day) — keeps the front-end loop simple.
     *
     * @return Collection<int, object{
     *   date:string, positive:int, negative:int, neutral:int, mixed:int,
     *   avg_score:float|null, total:int
     * }>
     */
    protected function trendByDate(?Movie $movie): Collection
    {
        $start = Carbon::now()->subDays(self::TREND_DAYS - 1)->startOfDay();

        $query = Comment::query()
            ->whereNotNull('sentiment_analyzed_at')
            ->where('sentiment_analyzed_at', '>=', $start);

        if ($movie) {
            $query->where('movie_id', $movie->id);
        }

        $rows = $query
            ->selectRaw('DATE(sentiment_analyzed_at) AS day')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) AS positive")
            ->selectRaw("SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) AS negative")
            ->selectRaw("SUM(CASE WHEN sentiment = 'neutral'  THEN 1 ELSE 0 END) AS neutral")
            ->selectRaw("SUM(CASE WHEN sentiment = 'mixed'    THEN 1 ELSE 0 END) AS mixed")
            ->selectRaw('AVG(sentiment_score) AS avg_score')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        // Zero-fill the window so the view can render a clean strip.
        $out = collect();
        for ($i = 0; $i < self::TREND_DAYS; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $r = $rows->get($day);
            $out->push((object) [
                'date' => $day,
                'total' => (int) ($r->total ?? 0),
                'positive' => (int) ($r->positive ?? 0),
                'negative' => (int) ($r->negative ?? 0),
                'neutral' => (int) ($r->neutral ?? 0),
                'mixed' => (int) ($r->mixed ?? 0),
                'avg_score' => isset($r->avg_score) && $r->avg_score !== null
                    ? round((float) $r->avg_score, 3)
                    : null,
            ]);
        }

        return $out;
    }

    /**
     * Most recent positive + negative analysed comments (optionally per-movie).
     *
     * @return array{0: Collection<int, Comment>, 1: Collection<int, Comment>}
     */
    protected function recentExamples(?Movie $movie): array
    {
        $base = Comment::query()
            ->with(['user:id,name', 'movie:id,title,slug'])
            ->whereNotNull('sentiment_analyzed_at');

        if ($movie) {
            $base->where('movie_id', $movie->id);
        }

        $positive = (clone $base)
            ->where('sentiment', 'positive')
            ->orderByDesc('sentiment_analyzed_at')
            ->limit(self::RECENT_LIMIT)
            ->get();

        $negative = (clone $base)
            ->where('sentiment', 'negative')
            ->orderByDesc('sentiment_analyzed_at')
            ->limit(self::RECENT_LIMIT)
            ->get();

        return [$positive, $negative];
    }
}
