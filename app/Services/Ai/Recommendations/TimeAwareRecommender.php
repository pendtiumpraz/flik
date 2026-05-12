<?php

namespace App\Services\Ai\Recommendations;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\Rating;
use App\Models\User;
use App\Models\WatchHistory;
use App\Models\Watchlist;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Time-of-day aware recommender.
 *
 * Picks a "slot" based on the user's local hour (Asia/Jakarta by default) and
 * filters / scores the catalog against slot-appropriate genres + duration caps,
 * then re-weights by overlap with the user's own genre taste pulled from
 * watch history, ratings, and watchlist.
 *
 * Pure logic — no DI other than an optional now-provider closure for testability.
 * Returns a Collection<int, Movie>, eager-loaded with genres.
 */
class TimeAwareRecommender
{
    public const SLOT_MORNING   = 'morning';
    public const SLOT_AFTERNOON = 'afternoon';
    public const SLOT_EVENING   = 'evening';
    public const SLOT_LATE      = 'late_night';
    public const SLOT_OVERNIGHT = 'overnight';

    public const TIMEZONE = 'Asia/Jakarta';

    /** @var (\Closure(): Carbon)|null */
    protected $nowProvider;

    /**
     * @param  (\Closure(): Carbon)|null  $nowProvider  Optional override for "current time" — useful in tests.
     */
    public function __construct(?\Closure $nowProvider = null)
    {
        $this->nowProvider = $nowProvider;
    }

    /**
     * Public entrypoint.
     *
     * @return Collection<int, Movie>
     */
    public function recommendByTimeOfDay(User $user, ?Carbon $now = null, int $count = 12): Collection
    {
        $now = $this->resolveNow($now);
        $slot = $this->slotFor($now);
        $config = $this->slotConfig($slot);

        $userGenreWeights = $this->buildUserGenreWeights($user);
        $excluded = $this->excludedMovieIds($user);

        $candidates = $this->fetchCandidates($config, $excluded, $count);

        $scored = $this->scoreCandidates($candidates, $config, $userGenreWeights);

        return $scored->take($count)->values();
    }

    /**
     * Determine which slot a given Carbon time belongs to.
     */
    public function slotFor(Carbon $now): string
    {
        $hour = (int) $now->copy()->setTimezone(self::TIMEZONE)->format('G');

        return match (true) {
            $hour >= 5  && $hour < 11 => self::SLOT_MORNING,
            $hour >= 11 && $hour < 17 => self::SLOT_AFTERNOON,
            $hour >= 17 && $hour < 21 => self::SLOT_EVENING,
            $hour >= 21 && $hour < 24 => self::SLOT_LATE,
            default                   => self::SLOT_OVERNIGHT, // 0–4
        };
    }

    /**
     * Human-readable label for a slot (Indonesian).
     */
    public function slotLabel(string $slot): string
    {
        return match ($slot) {
            self::SLOT_MORNING   => 'Pagi — ringan & menyegarkan',
            self::SLOT_AFTERNOON => 'Siang — drama & petualangan',
            self::SLOT_EVENING   => 'Prime Time — film unggulan malam ini',
            self::SLOT_LATE      => 'Larut malam — sinema dalam',
            self::SLOT_OVERNIGHT => 'Dini hari — santai & kontemplatif',
            default              => 'Cocok ditonton sekarang',
        };
    }

    /**
     * Slot configuration: which genres to prefer, max duration in seconds, label.
     *
     * @return array{
     *   slot: string,
     *   label: string,
     *   genre_keywords: array<int, string>,
     *   max_duration_seconds: ?int,
     *   min_duration_seconds: ?int,
     * }
     */
    public function slotConfig(string $slot): array
    {
        return match ($slot) {
            self::SLOT_MORNING => [
                'slot'                 => $slot,
                'label'                => $this->slotLabel($slot),
                'genre_keywords'       => ['comedy', 'komedi', 'animation', 'animasi', 'family', 'keluarga', 'adventure'],
                'max_duration_seconds' => 100 * 60, // < 100 min
                'min_duration_seconds' => null,
            ],
            self::SLOT_AFTERNOON => [
                'slot'                 => $slot,
                'label'                => $this->slotLabel($slot),
                'genre_keywords'       => ['drama', 'adventure', 'petualangan', 'fantasy', 'fantasi', 'biography', 'biografi'],
                'max_duration_seconds' => null,
                'min_duration_seconds' => null,
            ],
            self::SLOT_EVENING => [
                'slot'                 => $slot,
                'label'                => $this->slotLabel($slot),
                'genre_keywords'       => ['action', 'aksi', 'thriller', 'crime', 'kriminal', 'sci-fi', 'science fiction'],
                'max_duration_seconds' => null,
                'min_duration_seconds' => 80 * 60, // full-length
            ],
            self::SLOT_LATE => [
                'slot'                 => $slot,
                'label'                => $this->slotLabel($slot),
                'genre_keywords'       => ['horror', 'horor', 'thriller', 'mystery', 'misteri', 'noir', 'psychological'],
                'max_duration_seconds' => null,
                'min_duration_seconds' => null,
            ],
            self::SLOT_OVERNIGHT => [
                'slot'                 => $slot,
                'label'                => $this->slotLabel($slot),
                'genre_keywords'       => ['documentary', 'dokumenter', 'romance', 'romantis', 'music', 'musikal', 'drama'],
                'max_duration_seconds' => null,
                'min_duration_seconds' => null,
            ],
            default => [
                'slot'                 => $slot,
                'label'                => $this->slotLabel($slot),
                'genre_keywords'       => [],
                'max_duration_seconds' => null,
                'min_duration_seconds' => null,
            ],
        };
    }

    // ────────────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────────────

    protected function resolveNow(?Carbon $now): Carbon
    {
        if ($now instanceof Carbon) {
            return $now->copy();
        }
        if ($this->nowProvider !== null) {
            $resolved = ($this->nowProvider)();
            if ($resolved instanceof Carbon) {
                return $resolved->copy();
            }
        }
        return Carbon::now(self::TIMEZONE);
    }

    /**
     * Genre IDs whose name matches any of the slot's keywords (case-insensitive substring).
     *
     * @param  array<int, string>  $keywords
     * @return array<int, int>
     */
    protected function slotGenreIds(array $keywords): array
    {
        if (empty($keywords)) {
            return [];
        }

        $genres = Genre::query()
            ->get(['id', 'name'])
            ->filter(function ($g) use ($keywords) {
                $name = mb_strtolower((string) $g->name);
                foreach ($keywords as $kw) {
                    if ($name !== '' && str_contains($name, mb_strtolower($kw))) {
                        return true;
                    }
                }
                return false;
            });

        return $genres->pluck('id')->all();
    }

    /**
     * Build a normalized genre-id => weight map from user's recent signals.
     *
     * @return array<int, float>
     */
    protected function buildUserGenreWeights(User $user): array
    {
        $weights = [];

        // Watch history (last 60)
        $histories = WatchHistory::with('movie.genres')
            ->where('user_id', $user->id)
            ->orderByDesc('last_watched_at')
            ->limit(60)
            ->get();

        foreach ($histories as $h) {
            if (!$h->movie) {
                continue;
            }
            $w = $h->completed ? 1.5 : 0.8;
            foreach ($h->movie->genres as $g) {
                $weights[$g->id] = ($weights[$g->id] ?? 0) + $w;
            }
        }

        // Ratings (strong signal)
        $ratings = Rating::with('movie.genres')
            ->where('user_id', $user->id)
            ->get();

        foreach ($ratings as $r) {
            if (!$r->movie) {
                continue;
            }
            $score = (float) $r->score;
            if ($score > 5) {
                $score = $score / 2;
            }
            if ($score >= 3.5) {
                $w = ($score - 2.5) * 1.5;
                foreach ($r->movie->genres as $g) {
                    $weights[$g->id] = ($weights[$g->id] ?? 0) + $w;
                }
            }
        }

        // Watchlist (intent)
        $watchlist = Watchlist::with('movie.genres')
            ->where('user_id', $user->id)
            ->get();

        foreach ($watchlist as $wl) {
            if (!$wl->movie) {
                continue;
            }
            foreach ($wl->movie->genres as $g) {
                $weights[$g->id] = ($weights[$g->id] ?? 0) + 0.7;
            }
        }

        return $weights;
    }

    /**
     * Already-consumed movie IDs we should NOT re-recommend.
     *
     * @return array<int, int>
     */
    protected function excludedMovieIds(User $user): array
    {
        $watched = WatchHistory::where('user_id', $user->id)
            ->where('completed', true)
            ->pluck('movie_id')
            ->all();

        return array_values(array_unique(array_map('intval', $watched)));
    }

    /**
     * @param  array{slot:string,label:string,genre_keywords:array<int,string>,max_duration_seconds:?int,min_duration_seconds:?int}  $config
     * @param  array<int, int>  $excluded
     * @return Collection<int, Movie>
     */
    protected function fetchCandidates(array $config, array $excluded, int $count): Collection
    {
        $slotGenreIds = $this->slotGenreIds($config['genre_keywords']);

        $query = Movie::query()->with('genres');

        if (!empty($excluded)) {
            $query->whereNotIn('id', $excluded);
        }

        if (!empty($slotGenreIds)) {
            $query->whereHas('genres', fn ($q) => $q->whereIn('genres.id', $slotGenreIds));
        }

        // Duration filtering — only applied when the column has data on a row.
        // Movies with NULL duration_seconds are always allowed through (so a partial
        // catalog isn't punished). Tighter filtering then happens in scoring.
        if ($config['max_duration_seconds'] !== null) {
            $max = $config['max_duration_seconds'];
            $query->where(function ($q) use ($max) {
                $q->whereNull('duration_seconds')->orWhere('duration_seconds', '<=', $max);
            });
        }
        if ($config['min_duration_seconds'] !== null) {
            $min = $config['min_duration_seconds'];
            $query->where(function ($q) use ($min) {
                $q->whereNull('duration_seconds')->orWhere('duration_seconds', '>=', $min);
            });
        }

        return $query
            ->orderByDesc('popularity')
            ->orderByDesc('vote_average')
            ->limit(max($count * 4, 40))
            ->get();
    }

    /**
     * Score = (slot_genre_match × 3) + (user_genre_overlap × 2) + popularity_norm + rating_norm.
     *
     * @param  Collection<int, Movie>     $candidates
     * @param  array<string, mixed>       $config
     * @param  array<int, float>          $userGenreWeights
     * @return Collection<int, Movie>     Sorted by score desc.
     */
    protected function scoreCandidates(Collection $candidates, array $config, array $userGenreWeights): Collection
    {
        $slotGenreIds = $this->slotGenreIds($config['genre_keywords']);
        $slotSet = array_flip($slotGenreIds);

        $totalUserWeight = array_sum(array_map('abs', $userGenreWeights));
        if ($totalUserWeight <= 0) {
            $totalUserWeight = 1.0;
        }

        return $candidates
            ->map(function (Movie $movie) use ($slotSet, $userGenreWeights, $totalUserWeight) {
                $slotMatch = 0;
                $userOverlap = 0.0;
                foreach ($movie->genres as $g) {
                    if (isset($slotSet[$g->id])) {
                        $slotMatch++;
                    }
                    $userOverlap += ($userGenreWeights[$g->id] ?? 0);
                }
                $userOverlapNorm = $userOverlap / $totalUserWeight; // ~0..1

                $popularity = min(1.0, ((float) $movie->popularity) / 1000.0);
                $rating = ((float) $movie->vote_average) / 10.0;

                $score = ($slotMatch * 3.0)
                       + ($userOverlapNorm * 2.0)
                       + $popularity
                       + $rating;

                $movie->setAttribute('time_aware_score', round($score, 3));
                return $movie;
            })
            ->sortByDesc(fn (Movie $m) => (float) $m->getAttribute('time_aware_score'))
            ->values();
    }
}
