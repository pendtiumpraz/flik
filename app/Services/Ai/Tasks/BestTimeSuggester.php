<?php

namespace App\Services\Ai\Tasks;

use App\Models\Movie;
use App\Models\User;
use App\Models\WatchHistory;
use App\Services\Ai\AiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * BestTimeSuggester (Save for Friday Night)
 *
 * Suggests 3 ideal viewing slots in the next 7 days for a given (user, movie)
 * pair, grounded in the user's recent watch-history time-of-day patterns.
 *
 * The AI's job is purely creative reasoning over a structured payload —
 * we pre-compute the user's habit signal locally (avg hour, weekday vs
 * weekend split, late-night vs evening vs afternoon bucket counts) so
 * the model doesn't have to invent demographics.
 *
 * Pipeline:
 *   1. Load last 30 WatchHistory rows → bucket by hour-of-day + weekday.
 *   2. Build an Indonesian-language user prompt with film metadata.
 *   3. Ask the model for strict JSON: 3 objects of {datetime, reason}.
 *   4. Decode + validate. On failure → graceful local fallback (deterministic
 *      slots picked from the user's top buckets).
 *
 * @return list<array{datetime: string, reason: string}>  ISO 8601 + Indonesian rationale
 */
class BestTimeSuggester
{
    /**
     * How many recent watch sessions to consider when building the habit profile.
     */
    protected const HISTORY_WINDOW = 30;

    /**
     * Number of slots to recommend per call.
     */
    protected const SLOT_COUNT = 3;

    public function __construct(
        protected AiClient $ai,
    ) {}

    /**
     * Suggest 3 ideal viewing slots for (user, movie).
     *
     * @return list<array{datetime: string, reason: string}>
     */
    public function suggest(User $user, Movie $movie): array
    {
        $profile = $this->buildHabitProfile($user);

        $payload = $this->buildMoviePayload($movie);

        try {
            $aiSlots = $this->askAi($profile, $payload);
            if (!empty($aiSlots)) {
                return $aiSlots;
            }
        } catch (\Throwable $e) {
            Log::warning('BestTimeSuggester: AI suggestion failed', [
                'user_id'  => $user->id,
                'movie_id' => $movie->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return $this->localFallbackSlots($profile);
    }

    // ───────────────────────────────────────────────────────────────────
    // HABIT PROFILE
    // ───────────────────────────────────────────────────────────────────

    /**
     * Build a compact profile of when the user typically watches.
     *
     * @return array{
     *     buckets: array{morning:int, afternoon:int, evening:int, late_night:int},
     *     dominant: string,
     *     weekend_pct: int,
     *     avg_hour: int|null,
     *     sample_size: int
     * }
     */
    protected function buildHabitProfile(User $user): array
    {
        $rows = WatchHistory::query()
            ->where('user_id', $user->id)
            ->whereNotNull('last_watched_at')
            ->orderByDesc('last_watched_at')
            ->limit(self::HISTORY_WINDOW)
            ->get(['last_watched_at']);

        $buckets    = ['morning' => 0, 'afternoon' => 0, 'evening' => 0, 'late_night' => 0];
        $hourSum    = 0;
        $hourCount  = 0;
        $weekendN   = 0;

        foreach ($rows as $row) {
            /** @var \Illuminate\Support\Carbon $ts */
            $ts = $row->last_watched_at;
            $hour = (int) $ts->format('G');

            $buckets[$this->bucketForHour($hour)]++;
            $hourSum   += $hour;
            $hourCount++;

            if (in_array((int) $ts->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY, Carbon::FRIDAY], true)) {
                // Treat Fri night onward as the "weekend" cluster — that's the
                // sweet spot for this feature ("Save for Friday Night").
                $weekendN++;
            }
        }

        $dominant = $hourCount === 0
            ? 'evening' // sensible default
            : array_keys($buckets, max($buckets))[0];

        return [
            'buckets'     => $buckets,
            'dominant'    => $dominant,
            'weekend_pct' => $hourCount === 0 ? 0 : (int) round(($weekendN / $hourCount) * 100),
            'avg_hour'    => $hourCount === 0 ? null : (int) round($hourSum / $hourCount),
            'sample_size' => $hourCount,
        ];
    }

    protected function bucketForHour(int $hour): string
    {
        return match (true) {
            $hour >= 5  && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 17 => 'afternoon',
            $hour >= 17 && $hour < 22 => 'evening',
            default                   => 'late_night',
        };
    }

    // ───────────────────────────────────────────────────────────────────
    // MOVIE PAYLOAD
    // ───────────────────────────────────────────────────────────────────

    /**
     * @return array{title: string, duration_minutes: int|null, genres: list<string>}
     */
    protected function buildMoviePayload(Movie $movie): array
    {
        $movie->loadMissing('genres');

        $durationMinutes = null;
        if (!empty($movie->duration_seconds)) {
            $durationMinutes = (int) max(1, round(((int) $movie->duration_seconds) / 60));
        }

        return [
            'title'            => $movie->title ?? '(tanpa judul)',
            'duration_minutes' => $durationMinutes,
            'genres'           => $movie->genres
                ->pluck('name')
                ->filter()
                ->map(fn ($n) => (string) $n)
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    // ───────────────────────────────────────────────────────────────────
    // AI CALL
    // ───────────────────────────────────────────────────────────────────

    /**
     * @param  array{title: string, duration_minutes: int|null, genres: list<string>}  $movie
     * @return list<array{datetime: string, reason: string}>
     */
    protected function askAi(array $profile, array $movie): array
    {
        $title    = $movie['title'];
        $duration = $movie['duration_minutes'] !== null
            ? $movie['duration_minutes'] . ' menit'
            : 'durasi tidak diketahui';
        $genres   = empty($movie['genres']) ? 'umum' : implode(', ', $movie['genres']);

        $now    = now();
        $window = [
            'from' => $now->copy()->toIso8601String(),
            'to'   => $now->copy()->addDays(7)->toIso8601String(),
        ];

        $habitLine = sprintf(
            'pola tontonan: pagi=%d, siang=%d, malam=%d, larut=%d (n=%d); dominan=%s; weekend≈%d%%; rata-rata jam=%s',
            $profile['buckets']['morning'],
            $profile['buckets']['afternoon'],
            $profile['buckets']['evening'],
            $profile['buckets']['late_night'],
            $profile['sample_size'],
            $profile['dominant'],
            $profile['weekend_pct'],
            $profile['avg_hour'] === null ? '-' : (string) $profile['avg_hour'],
        );

        $system = <<<SYS
Anda adalah asisten penjadwal nonton FLiK berbahasa Indonesia.
Berdasarkan kebiasaan pengguna dan karakter film, sarankan 3 slot waktu menonton yang ideal dalam 7 hari ke depan.

Aturan WAJIB:
- Output STRICT JSON ARRAY tanpa markdown fence, tanpa kata pengantar.
- Tepat 3 elemen.
- Setiap elemen: {"datetime": "<ISO 8601 lokal Asia/Jakarta, format YYYY-MM-DDTHH:MM:SS+07:00>", "reason": "<1-2 kalimat Bahasa Indonesia natural>"}.
- Datetime harus berada dalam rentang yang diberikan.
- Slot harus mempertimbangkan durasi film (selesai sebelum tengah malam jika bukan film horror/thriller, sesuaikan hari dengan kebiasaan pengguna).
- Reason harus menjelaskan KENAPA slot itu cocok (mood, weekend, durasi, dsb.) dalam Bahasa Indonesia santai.
SYS;

        $user = "Film: {$title} ({$duration}, genre={$genres}).\n"
            . "Rentang waktu: {$window['from']} sampai {$window['to']} (zona waktu Asia/Jakarta).\n"
            . "Profil pengguna: {$habitLine}.\n\n"
            . "Berikan 3 slot waktu nonton paling pas.";

        $response = $this->ai->chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            [
                'max_tokens'  => 400,
                'temperature' => 0.6,
            ],
            taskType: 'chat.schedule.best_time',
            subject: null,
        );

        $content = trim((string) ($response['content'] ?? ''));
        if ($content === '') {
            return [];
        }

        return $this->parseSlots($content);
    }

    /**
     * Extract and validate the JSON-array-of-slots from the model output.
     *
     * @return list<array{datetime: string, reason: string}>
     */
    protected function parseSlots(string $content): array
    {
        // Strip markdown fences if the model snuck one in.
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/im', '', $content) ?? $content;
        $content = trim($content);

        if (!str_starts_with($content, '[')) {
            if (preg_match('/\[[\s\S]*\]/', $content, $m)) {
                $content = $m[0];
            }
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) continue;

            $dt     = isset($row['datetime']) && is_string($row['datetime']) ? trim($row['datetime']) : '';
            $reason = isset($row['reason'])   && is_string($row['reason'])   ? trim($row['reason'])   : '';

            if ($dt === '' || $reason === '') continue;

            // Best-effort parse; skip rows we can't read.
            try {
                $parsed = Carbon::parse($dt);
            } catch (\Throwable) {
                continue;
            }

            // Discard slots outside the 7-day window.
            if ($parsed->isPast() || $parsed->gt(now()->addDays(7)->addHour())) {
                continue;
            }

            $out[] = [
                'datetime' => $parsed->toIso8601String(),
                'reason'   => $reason,
            ];

            if (count($out) >= self::SLOT_COUNT) break;
        }

        return $out;
    }

    // ───────────────────────────────────────────────────────────────────
    // LOCAL FALLBACK
    // ───────────────────────────────────────────────────────────────────

    /**
     * Deterministic, AI-free slot picker — used when the model fails or
     * returns junk. Picks Friday/Saturday evening + the user's dominant
     * bucket on the nearest day.
     *
     * @return list<array{datetime: string, reason: string}>
     */
    protected function localFallbackSlots(array $profile): array
    {
        $dominantHour = match ($profile['dominant']) {
            'morning'    => 9,
            'afternoon'  => 14,
            'evening'    => 20,
            default      => 22, // late_night
        };

        $now = now();

        // Next Friday at 8pm — the brand promise of the feature.
        $friday = $now->copy()->next(Carbon::FRIDAY)->setTime(20, 0);
        if ($friday->lessThanOrEqualTo($now)) {
            $friday->addWeek();
        }

        // Next Saturday in the user's dominant bucket.
        $saturday = $now->copy()->next(Carbon::SATURDAY)->setTime($dominantHour, 0);
        if ($saturday->lessThanOrEqualTo($now)) {
            $saturday->addWeek();
        }

        // Tomorrow in the dominant bucket as a flexible weekday option.
        $tomorrow = $now->copy()->addDay()->setTime($dominantHour, 0);

        return [
            [
                'datetime' => $friday->toIso8601String(),
                'reason'   => 'Jumat malam jam 8 — slot klasik nonton santai akhir pekan.',
            ],
            [
                'datetime' => $saturday->toIso8601String(),
                'reason'   => sprintf(
                    'Sabtu jam %d sore/malam, cocok dengan kebiasaan nontonmu di waktu %s.',
                    $dominantHour,
                    $profile['dominant'],
                ),
            ],
            [
                'datetime' => $tomorrow->toIso8601String(),
                'reason'   => 'Besok di jam favoritmu — kalau nggak sabar nunggu weekend.',
            ],
        ];
    }
}
