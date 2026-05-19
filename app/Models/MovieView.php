<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw per-view event log. See the table migration
 * (database/migrations/2026_05_10_090001_create_movie_views_table.php)
 * for the privacy/indexing rationale.
 *
 * Writes go through App\Services\Trending\ViewTracker — never spawn
 * raw MovieView::create() calls in controllers; the tracker handles
 * de-duplication, ip-hash peppering, and device sniffing.
 *
 * @property int $id
 * @property int $movie_id
 * @property int|null $user_id
 * @property string|null $session_id
 * @property string|null $ip_hash
 * @property \Illuminate\Support\Carbon $viewed_at
 * @property int|null $duration_seconds
 * @property string|null $device
 */
class MovieView extends Model
{
    use HasFactory;

    /**
     * Mass-assignable for the writer service. We don't use this from
     * controllers — ViewTracker calls forceCreate() to bypass guards
     * cleanly and so callers can't accidentally double-write a row
     * without the de-dup check.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'movie_id',
        'user_id',
        'session_id',
        'ip_hash',
        'viewed_at',
        'duration_seconds',
        'device',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    /**
     * Disable Eloquent's auto created_at/updated_at — `viewed_at`
     * is the canonical timestamp and the table doesn't carry the
     * Laravel pair (smaller rows, smaller indexes, faster inserts).
     */
    public $timestamps = false;

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
