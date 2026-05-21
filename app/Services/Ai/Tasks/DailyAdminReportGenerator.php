<?php

namespace App\Services\Ai\Tasks;

use App\Models\Comment;
use App\Models\Movie;
use App\Models\Rating;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WatchHistory;
use App\Services\Ai\AiClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Aggregates yesterday's platform metrics and produces a daily executive report
 * with an AI-written narrative (Bahasa Indonesia, 3 paragraphs).
 *
 * Output shape:
 *   [
 *     'date'              => 'Y-m-d',
 *     'date_range'        => ['from' => '...', 'to' => '...'],
 *     'human_date'        => 'Senin, 11 Mei 2026',
 *     'stats'             => [
 *         'new_users'           => int,
 *         'new_subscriptions'   => [
 *             'total' => int,
 *             'by_plan' => [['plan' => 'Premium', 'count' => 7, 'revenue' => 489000], ...],
 *         ],
 *         'total_revenue'       => float (IDR),
 *         'total_revenue_fmt'   => 'Rp 1.234.567',
 *         'mau_active'          => int,   // distinct users active in last 30 days
 *         'dau_active'          => int,   // distinct users with watch activity yesterday
 *         'total_watch_hours'   => float,
 *         'top_movies'          => [['title' => '...', 'views' => 12, 'avg_progress' => 78], ...],
 *         'comments'            => [
 *             'count' => int,
 *             'sentiment' => ['positive' => int, 'neutral' => int, 'negative' => int],
 *         ],
 *         'ratings_count'       => int,
 *         'ratings_avg'         => float,
 *     ],
 *     'narrative'         => string, // AI-generated Bahasa Indonesia executive summary
 *     'narrative_error'   => ?string,
 *     'generated_at'      => 'Y-m-d H:i:s',
 *   ]
 */
class DailyAdminReportGenerator
{
    public function __construct(
        protected AiClient $ai
    ) {}

    /**
     * Generate the report for the day before $date (defaults to today → reports yesterday).
     */
    public function generate(?Carbon $date = null): array
    {
        $reference = $date ? $date->copy()->startOfDay() : Carbon::today();
        $from = $reference->copy()->subDay()->startOfDay();   // yesterday 00:00
        $to   = $reference->copy()->subDay()->endOfDay();     // yesterday 23:59:59

        $stats = [
            'new_users'         => $this->newUsersCount($from, $to),
            'new_subscriptions' => $this->newSubscriptionsBreakdown($from, $to),
            'mau_active'        => $this->mauActive($to),
            'dau_active'        => $this->dauActive($from, $to),
            'total_watch_hours' => $this->totalWatchHours($from, $to),
            'top_movies'        => $this->topMovies($from, $to, 5),
            'comments'          => $this->commentsSummary($from, $to),
            'ratings_count'     => Rating::whereBetween('created_at', [$from, $to])->count(),
            'ratings_avg'       => round((float) Rating::whereBetween('created_at', [$from, $to])->avg('score'), 2),
        ];

        $stats['total_revenue']     = (float) $stats['new_subscriptions']['total_revenue'];
        $stats['total_revenue_fmt'] = 'Rp ' . number_format($stats['total_revenue'], 0, ',', '.');

        $narrative = null;
        $narrativeError = null;
        try {
            $narrative = $this->writeNarrative($stats, $from);
        } catch (\Throwable $e) {
            Log::warning('DailyAdminReportGenerator narrative failed', ['error' => $e->getMessage()]);
            $narrativeError = $e->getMessage();
            $narrative = $this->fallbackNarrative($stats);
        }

        return [
            'date'           => $from->toDateString(),
            'date_range'     => [
                'from' => $from->toDateTimeString(),
                'to'   => $to->toDateTimeString(),
            ],
            'human_date'     => $from->locale('id')->isoFormat('dddd, D MMMM Y'),
            'stats'          => $stats,
            'narrative'      => $narrative,
            'narrative_error' => $narrativeError,
            'generated_at'   => now()->toDateTimeString(),
        ];
    }

    // ── Stat collectors ──────────────────────────────────────────────────

    protected function newUsersCount(Carbon $from, Carbon $to): int
    {
        return User::whereBetween('created_at', [$from, $to])->count();
    }

    /**
     * Subscriptions created yesterday, grouped by plan with revenue per plan.
     * Uses `created_at` (not paid_at) so we count all sign-ups; revenue only counts rows with amount.
     */
    protected function newSubscriptionsBreakdown(Carbon $from, Carbon $to): array
    {
        $rows = Subscription::query()
            ->select('subscription_plan_id', DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(amount),0) as revenue'))
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('subscription_plan_id')
            ->get();

        $plans = SubscriptionPlan::whereIn('id', $rows->pluck('subscription_plan_id')->filter())->get()->keyBy('id');

        $byPlan = $rows->map(function ($row) use ($plans) {
            $plan = $plans->get($row->subscription_plan_id);
            return [
                'plan_id' => $row->subscription_plan_id,
                'plan'    => $plan?->name ?? 'Unknown Plan',
                'count'   => (int) $row->cnt,
                'revenue' => (float) $row->revenue,
                'revenue_fmt' => 'Rp ' . number_format((float) $row->revenue, 0, ',', '.'),
            ];
        })->values()->all();

        return [
            'total'         => array_sum(array_column($byPlan, 'count')),
            'total_revenue' => array_sum(array_column($byPlan, 'revenue')),
            'by_plan'       => $byPlan,
        ];
    }

    /** Distinct users with any watch activity in the last 30 days ending at $to. */
    protected function mauActive(Carbon $to): int
    {
        $window = $to->copy()->subDays(30);
        return (int) WatchHistory::query()
            ->whereBetween('last_watched_at', [$window, $to])
            ->distinct('user_id')
            ->count('user_id');
    }

    /** Distinct users with any watch activity yesterday. */
    protected function dauActive(Carbon $from, Carbon $to): int
    {
        return (int) WatchHistory::query()
            ->whereBetween('last_watched_at', [$from, $to])
            ->distinct('user_id')
            ->count('user_id');
    }

    /**
     * Approximates total watch hours yesterday by summing progress_seconds on rows whose
     * `last_watched_at` falls in the window. This is a coarse estimate (not delta-tracked)
     * but is the closest signal available from WatchHistory's current schema.
     */
    protected function totalWatchHours(Carbon $from, Carbon $to): float
    {
        $totalSec = (int) WatchHistory::query()
            ->whereBetween('last_watched_at', [$from, $to])
            ->sum('progress_seconds');

        return round($totalSec / 3600, 2);
    }

    /** Top N movies by distinct viewer count yesterday. */
    protected function topMovies(Carbon $from, Carbon $to, int $limit = 5): array
    {
        $rows = WatchHistory::query()
            ->select(
                'movie_id',
                DB::raw('COUNT(DISTINCT user_id) as views'),
                DB::raw('AVG(CASE WHEN duration_seconds > 0 THEN (progress_seconds * 100.0 / duration_seconds) ELSE 0 END) as avg_progress'),
            )
            ->whereBetween('last_watched_at', [$from, $to])
            ->groupBy('movie_id')
            ->orderByDesc('views')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $movies = Movie::whereIn('id', $rows->pluck('movie_id'))->get()->keyBy('id');

        return $rows->map(function ($row) use ($movies) {
            $movie = $movies->get($row->movie_id);
            return [
                'movie_id'     => $row->movie_id,
                'title'        => $movie?->title ?? 'Unknown',
                'views'        => (int) $row->views,
                'avg_progress' => (int) round((float) $row->avg_progress),
            ];
        })->values()->all();
    }

    /**
     * Comments count + a *lightweight* keyword-based sentiment classification
     * (no AI tokens spent here — sentiment summary in the narrative comes from AI separately).
     */
    protected function commentsSummary(Carbon $from, Carbon $to): array
    {
        $comments = Comment::query()
            ->whereBetween('created_at', [$from, $to])
            ->pluck('body');

        $positive = $neutral = $negative = 0;

        $posWords = ['bagus', 'keren', 'mantap', 'suka', 'love', 'good', 'great', 'best', 'amazing', 'recommended', 'rekomen', 'seru', 'asik', 'asyik'];
        $negWords = ['jelek', 'buruk', 'parah', 'bosan', 'membosankan', 'boring', 'bad', 'worst', 'gak suka', 'hate', 'sampah', 'lambat', 'lag'];

        foreach ($comments as $body) {
            $b = mb_strtolower((string) $body);
            $hasPos = false;
            $hasNeg = false;
            foreach ($posWords as $w) {
                if (str_contains($b, $w)) { $hasPos = true; break; }
            }
            foreach ($negWords as $w) {
                if (str_contains($b, $w)) { $hasNeg = true; break; }
            }
            if ($hasPos && !$hasNeg)        $positive++;
            elseif ($hasNeg && !$hasPos)    $negative++;
            else                            $neutral++;
        }

        return [
            'count'     => $comments->count(),
            'sentiment' => [
                'positive' => $positive,
                'neutral'  => $neutral,
                'negative' => $negative,
            ],
        ];
    }

    // ── Narrative ────────────────────────────────────────────────────────

    /**
     * Ask the configured AI provider for a 3-paragraph executive summary
     * in Bahasa Indonesia. Stats are serialized as a JSON context block.
     */
    protected function writeNarrative(array $stats, Carbon $forDate): string
    {
        $context = [
            'tanggal'           => $forDate->locale('id')->isoFormat('dddd, D MMMM Y'),
            'pengguna_baru'     => $stats['new_users'],
            'langganan_baru'    => $stats['new_subscriptions']['total'],
            'breakdown_paket'   => $stats['new_subscriptions']['by_plan'],
            'pendapatan_idr'    => $stats['total_revenue'],
            'pendapatan_fmt'    => $stats['total_revenue_fmt'],
            'mau_30_hari'       => $stats['mau_active'],
            'dau_kemarin'       => $stats['dau_active'],
            'total_jam_tonton'  => $stats['total_watch_hours'],
            'film_terlaris'     => $stats['top_movies'],
            'komentar'          => $stats['comments'],
            'rating_baru'       => $stats['ratings_count'],
            'rata_rata_rating'  => $stats['ratings_avg'],
        ];

        $system = 'Anda adalah analis bisnis untuk FLiK, platform streaming film Indonesia. '
            . 'Tugas Anda: menulis ringkasan eksekutif harian dalam Bahasa Indonesia yang formal, '
            . 'tajam, dan to the point untuk dibaca oleh Super Admin. Hindari basa-basi.';

        $user = "Berdasarkan data performa platform berikut (JSON), tulis ringkasan eksekutif **3 paragraf** dalam Bahasa Indonesia:\n\n"
            . "1. **Paragraf 1** - ringkas angka kunci (pengguna baru, langganan baru, pendapatan, jam tonton).\n"
            . "2. **Paragraf 2** - sorot tren dan insight (film terlaris, sentimen komentar, kualitas engagement seperti rata-rata progress tontonan).\n"
            . "3. **Paragraf 3** - 2-3 rekomendasi tindak lanjut konkret untuk tim produk/marketing besok.\n\n"
            . "Gunakan format paragraf biasa (tanpa bullet, tanpa heading). Jangan ulangi angka mentah secara berlebihan, jelaskan maknanya. "
            . "Jika sebuah metrik bernilai 0 atau kosong, sebut secara jujur dan beri saran perbaikan.\n\n"
            . "Data:\n```json\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```";

        $response = $this->ai->chat(
            messages: [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            options: ['max_tokens' => 800, 'temperature' => 0.6],
            taskType: 'admin.daily_report',
        );

        return trim((string) ($response['content'] ?? ''));
    }

    /**
     * Plain-text fallback if AI provider is missing or errors out. Keeps reports useful.
     */
    protected function fallbackNarrative(array $stats): string
    {
        $top = $stats['top_movies'][0]['title'] ?? '-';
        return sprintf(
            "Ringkasan otomatis (mode fallback - narasi AI tidak tersedia).\n\n"
            . "Kemarin tercatat %d pengguna baru, %d langganan baru, dan pendapatan %s. Total jam tonton mencapai %.2f jam dengan %d pengguna aktif harian.\n\n"
            . "Film terlaris adalah '%s'. Total komentar baru: %d (positif: %d, netral: %d, negatif: %d). Aktifkan provider AI di /admin/ai-settings untuk mendapatkan narasi insight otomatis.",
            $stats['new_users'],
            $stats['new_subscriptions']['total'],
            $stats['total_revenue_fmt'],
            $stats['total_watch_hours'],
            $stats['dau_active'],
            $top,
            $stats['comments']['count'],
            $stats['comments']['sentiment']['positive'],
            $stats['comments']['sentiment']['neutral'],
            $stats['comments']['sentiment']['negative'],
        );
    }
}
