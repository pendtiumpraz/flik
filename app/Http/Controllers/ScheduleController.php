<?php

namespace App\Http\Controllers;

use App\Models\Movie;
use App\Models\MovieSchedule;
use App\Services\Ai\Tasks\BestTimeSuggester;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * ScheduleController — "Save for Friday Night"
 *
 * Manages per-user calendar entries pinning films to specific date/times.
 * Hooks into BestTimeSuggester for AI-generated slot recommendations and
 * exposes a per-row .ics export for Google/Apple Calendar import.
 */
class ScheduleController extends Controller
{
    /**
     * GET /my-schedule — list the user's upcoming schedules.
     */
    public function index(): View|Factory
    {
        $userId = (int) Auth::id();

        $schedules = MovieSchedule::with(['movie.genres'])
            ->where('user_id', $userId)
            ->upcoming()
            ->paginate(20)
            ->withQueryString();

        // Past sessions (last 30 days) are a useful secondary section.
        $past = MovieSchedule::with('movie')
            ->where('user_id', $userId)
            ->where(function ($q) {
                $q->where('scheduled_for', '<', now())
                  ->orWhereNotNull('watched_at');
            })
            ->where('scheduled_for', '>=', now()->subDays(30))
            ->orderByDesc('scheduled_for')
            ->limit(8)
            ->get();

        return view('schedule.index', [
            'schedules' => $schedules,
            'past'      => $past,
        ]);
    }

    /**
     * GET /my-schedule/create/{movie} — show the schedule form + AI suggestions.
     */
    public function create(Movie $movie, BestTimeSuggester $suggester): View|Factory
    {
        $user = Auth::user();

        // AI suggestions are best-effort; the suggester itself catches and
        // falls back, so this should always return a 3-element array.
        $suggestions = $suggester->suggest($user, $movie);

        // Default date/time picker value: nearest upcoming Friday at 20:00,
        // or today+2h if Friday is more than a week off.
        $defaultDateTime = now()->next(Carbon::FRIDAY)->setTime(20, 0);
        if ($defaultDateTime->gt(now()->addDays(7))) {
            $defaultDateTime = now()->addHours(2)->minute(0)->second(0);
        }

        return view('schedule.create', [
            'movie'           => $movie->loadMissing('genres'),
            'suggestions'     => $suggestions,
            'defaultDateTime' => $defaultDateTime->format('Y-m-d\TH:i'),
            'minDateTime'     => now()->format('Y-m-d\TH:i'),
            'maxDateTime'     => now()->addDays(60)->format('Y-m-d\TH:i'),
        ]);
    }

    /**
     * POST /my-schedule/{movie} — persist a new schedule.
     */
    public function store(Request $request, Movie $movie): RedirectResponse
    {
        $data = $request->validate([
            'scheduled_for' => ['required', 'date', 'after:' . now()->subMinutes(5)->toDateTimeString()],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        $schedule = MovieSchedule::create([
            'user_id'       => Auth::id(),
            'movie_id'      => $movie->id,
            'scheduled_for' => Carbon::parse($data['scheduled_for']),
            'notes'         => $data['notes'] ?? null,
        ]);

        return redirect()
            ->route('schedule.index')
            ->with('success', "Sip! {$movie->title} dijadwalkan untuk {$schedule->scheduled_for->translatedFormat('l, d M Y H:i')}.");
    }

    /**
     * DELETE /my-schedule/{schedule} — cancel a schedule.
     */
    public function destroy(MovieSchedule $schedule): RedirectResponse
    {
        // MovieSchedulePolicy::delete() — owner only, admin override via Gate::before.
        $this->authorize('delete', $schedule);

        $title = $schedule->movie?->title ?? 'film';
        $schedule->delete();

        return redirect()
            ->route('schedule.index')
            ->with('success', "Jadwal {$title} dibatalkan.");
    }

    /**
     * GET /my-schedule/{schedule}/ics — return an iCalendar (.ics) file
     * for import into Google / Apple / Outlook calendars.
     */
    public function ics(MovieSchedule $schedule): Response
    {
        // MovieSchedulePolicy::view() — .ics download leaks scheduled
        // viewing time + private notes; gate it as a read of the model.
        $this->authorize('view', $schedule);

        $schedule->loadMissing('movie.genres');
        $movie = $schedule->movie;

        // Duration: prefer film duration, else default 2h.
        $durationSeconds = (int) ($movie?->duration_seconds ?: 7200);
        $start = $schedule->scheduled_for->copy()->utc();
        $end   = $start->copy()->addSeconds($durationSeconds);

        $summary = $this->icsEscape('FLiK: ' . ($movie?->title ?? 'Film'));

        $descriptionLines = [];
        if ($movie?->title) {
            $descriptionLines[] = 'Film: ' . $movie->title;
        }
        if ($movie?->genres && $movie->genres->isNotEmpty()) {
            $descriptionLines[] = 'Genre: ' . $movie->genres->pluck('name')->join(', ');
        }
        if (!empty($schedule->notes)) {
            $descriptionLines[] = 'Catatan: ' . $schedule->notes;
        }
        $descriptionLines[] = 'Tonton di FLiK — Rumah Sinema Indonesia.';
        $description = $this->icsEscape(implode("\n", $descriptionLines));

        $url = $movie?->slug
            ? url('/movie/' . $movie->slug)
            : url('/my-schedule');

        $uid = sprintf(
            'flik-schedule-%d-%d@%s',
            $schedule->id,
            $schedule->updated_at?->timestamp ?? $schedule->created_at?->timestamp ?? time(),
            parse_url(config('app.url') ?? 'flik.local', PHP_URL_HOST) ?: 'flik.local',
        );

        $now = now()->utc();

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//FLiK//Save for Friday Night//ID',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now->format('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $end->format('Ymd\THis\Z'),
            'SUMMARY:' . $summary,
            'DESCRIPTION:' . $description,
            'URL:' . $url,
            // 60-minute pre-event alarm (matches our reminder cron window).
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'DESCRIPTION:Reminder: ' . $summary,
            'TRIGGER:-PT60M',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $filename = 'flik-schedule-' . $schedule->id . '.ics';

        return response($ics, 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-store, max-age=0',
        ]);
    }

    /**
     * Escape a string for use inside an .ics text field (RFC 5545 §3.3.11).
     */
    protected function icsEscape(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            ';'  => '\\;',
            ','  => '\\,',
            "\r\n" => '\\n',
            "\n" => '\\n',
            "\r" => '\\n',
        ]);
    }
}
