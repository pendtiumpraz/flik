<?php

namespace App\Console\Commands;

use App\Models\Movie;
use App\Models\User;
use App\Models\WatchHistory;
use App\Services\Ai\AiClient;
use App\Services\Ai\Tasks\EmailPersonalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send AI-personalized weekly digest emails to users who were active last week.
 *
 * Run manually:
 *   php artisan flik:ai:weekly-digest
 *   php artisan flik:ai:weekly-digest --limit=200 --dry-run
 *
 * Schedule (in app/Console/Kernel.php → schedule()):
 *   $schedule->command('flik:ai:weekly-digest')->weeklyOn(1, '08:00');
 */
class AiSendWeeklyDigest extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flik:ai:weekly-digest
        {--limit=0 : Maximum number of users to email this run (0 = unlimited)}
        {--days=7 : Look-back window for "active last week" (default 7)}
        {--dry-run : Build everything but do not actually send mail}
        {--user= : Only send to the user with this email (for testing)}';

    /**
     * @var string
     */
    protected $description = 'Send AI-personalized weekly digest to users active in the last 7 days';

    public function handle(EmailPersonalizer $personalizer, AiClient $ai): int
    {
        // EmailPersonalizer is created without a constructor arg in the container,
        // so it gets a null AiClient and runs in fallback-only mode. Re-bind explicitly.
        $personalizer = new EmailPersonalizer($ai);

        $lookbackDays = max(1, (int) $this->option('days'));
        $limit        = max(0, (int) $this->option('limit'));
        $dryRun       = (bool) $this->option('dry-run');
        $only         = $this->option('user');

        $since = Carbon::now()->subDays($lookbackDays);

        $usersQuery = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '');

        if ($only) {
            $usersQuery->where('email', $only);
        } else {
            // Active = at least one WatchHistory row in the lookback window.
            $usersQuery->whereHas('watchHistories', function ($q) use ($since) {
                $q->where('last_watched_at', '>=', $since);
            });
        }

        if ($limit > 0) {
            $usersQuery->limit($limit);
        }

        $total = (clone $usersQuery)->count();
        $this->info("Found {$total} active user(s) in the last {$lookbackDays} day(s).");

        if ($total === 0) {
            return self::SUCCESS;
        }

        // Pre-fetch new releases this week — same pool for every recipient,
        // but each user gets a personalized re-rank below.
        $newReleases = $this->fetchNewReleases($since, 24);
        $this->line('New releases pool: ' . $newReleases->count() . ' titles.');

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $usersQuery->chunkById(100, function (Collection $users) use (
            &$sent, &$failed, &$skipped,
            $personalizer, $newReleases, $dryRun, $progress
        ) {
            foreach ($users as $user) {
                try {
                    $picks = $this->pickMoviesFor($user, $newReleases, 3);

                    if ($picks->isEmpty()) {
                        $skipped++;
                        $progress->advance();
                        continue;
                    }

                    $movieContext = $picks->map(fn (Movie $m) => [
                        'title' => $m->title,
                        'slug'  => $m->slug,
                        'year'  => $m->release_date?->format('Y'),
                    ])->values()->all();

                    $context = [
                        'intent'  => EmailPersonalizer::INTENT_DIGEST,
                        'movies'  => $movieContext,
                        'cta'     => 'Tonton di FLiK',
                        'cta_url' => url('/movies'),
                    ];

                    $subject = $personalizer->personalizeSubject(
                        $user,
                        template: 'Rekomendasi minggu ini buat kamu',
                        context: $context,
                    );

                    $body = $personalizer->personalizeBody(
                        $user,
                        intent: EmailPersonalizer::INTENT_DIGEST,
                        context: $context,
                    );

                    if ($dryRun) {
                        $sent++;
                        $progress->advance();
                        Log::info('AiSendWeeklyDigest dry-run', [
                            'to'      => $user->email,
                            'subject' => $subject,
                            'body'    => $body,
                        ]);
                        continue;
                    }

                    Mail::raw($body, function ($message) use ($user, $subject) {
                        $message->to($user->email, $user->name)->subject($subject);
                    });

                    $sent++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('AiSendWeeklyDigest failed for user', [
                        'user_id' => $user->id,
                        'email'   => $user->email,
                        'error'   => $e->getMessage(),
                    ]);
                }

                $progress->advance();
            }
        });

        $progress->finish();
        $this->newLine(2);

        $this->info("Digest run complete. Sent: {$sent}  Failed: {$failed}  Skipped: {$skipped}  Mode: " . ($dryRun ? 'dry-run' : 'live'));

        return self::SUCCESS;
    }

    /**
     * Fetch the catalog's newest releases as the candidate pool.
     *
     * @return Collection<int, Movie>
     */
    protected function fetchNewReleases(Carbon $since, int $limit): Collection
    {
        return Movie::with('genres')
            ->where(function ($q) use ($since) {
                $q->where('created_at', '>=', $since->copy()->subDays(30))
                  ->orWhere('release_date', '>=', $since->copy()->subMonths(3));
            })
            ->orderByDesc('release_date')
            ->orderByDesc('popularity')
            ->limit($limit)
            ->get();
    }

    /**
     * Pick up to $count movies from $pool that best match $user's top genres.
     * Falls back to the popularity-ordered head of the pool if no genre overlap.
     *
     * @param  Collection<int, Movie>  $pool
     * @return Collection<int, Movie>
     */
    protected function pickMoviesFor(User $user, Collection $pool, int $count): Collection
    {
        if ($pool->isEmpty()) {
            return collect();
        }

        $topGenres = $this->topGenresFor($user, 5);

        if (empty($topGenres)) {
            return $pool->take($count)->values();
        }

        $scored = $pool->map(function (Movie $m) use ($topGenres) {
            $movieGenres = $m->genres->pluck('name')
                ->map(fn ($g) => mb_strtolower((string) $g))
                ->all();
            $overlap = count(array_intersect($topGenres, $movieGenres));
            $m->_digest_score = $overlap * 3 + ((float) $m->popularity) / 100.0;
            return $m;
        })->sortByDesc('_digest_score');

        return $scored->take($count)->values();
    }

    /**
     * @return list<string>  Genre names (lowercased).
     */
    protected function topGenresFor(User $user, int $limit): array
    {
        $histories = WatchHistory::with('movie.genres')
            ->where('user_id', $user->id)
            ->orderByDesc('last_watched_at')
            ->limit(30)
            ->get();

        $counts = [];
        foreach ($histories as $h) {
            foreach (($h->movie?->genres ?? []) as $genre) {
                $name = mb_strtolower((string) $genre->name);
                if ($name === '') continue;
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        arsort($counts);
        return array_values(array_slice(array_keys($counts), 0, $limit));
    }
}
