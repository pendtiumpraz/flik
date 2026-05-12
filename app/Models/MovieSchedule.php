<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MovieSchedule
 *
 * A per-user calendar entry pinning a film to a specific date/time
 * ("Save for Friday Night"). Powers /my-schedule + the 1-hour reminder cron.
 *
 * @property int                              $id
 * @property int                              $user_id
 * @property int                              $movie_id
 * @property \Illuminate\Support\Carbon       $scheduled_for
 * @property string|null                      $notes
 * @property \Illuminate\Support\Carbon|null  $reminder_sent_at
 * @property \Illuminate\Support\Carbon|null  $watched_at
 * @property \Illuminate\Support\Carbon       $created_at
 * @property \Illuminate\Support\Carbon       $updated_at
 */
class MovieSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'movie_id',
        'scheduled_for',
        'notes',
        'reminder_sent_at',
        'watched_at',
    ];

    protected $casts = [
        'scheduled_for'    => 'datetime',
        'reminder_sent_at' => 'datetime',
        'watched_at'       => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    // ── Scopes ────────────────────────────────────────────────

    /**
     * Upcoming schedules: scheduled_for is in the future and the user
     * hasn't marked it watched yet. Ordered soonest-first.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where('scheduled_for', '>=', now())
            ->whereNull('watched_at')
            ->orderBy('scheduled_for');
    }

    /**
     * Schedules whose reminder window has opened (within the next hour)
     * and that haven't been reminded yet.
     */
    public function scopeDueForReminder(Builder $query): Builder
    {
        return $query
            ->whereNull('reminder_sent_at')
            ->whereNull('watched_at')
            ->where('scheduled_for', '>', now()->subMinutes(5))
            ->where('scheduled_for', '<=', now()->addHour());
    }

    // ── Helpers ───────────────────────────────────────────────

    public function isPast(): bool
    {
        return $this->scheduled_for !== null && $this->scheduled_for->isPast();
    }
}
