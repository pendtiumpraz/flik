<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AbAssignment
 * --------------------------------------------------------------------------
 * Sticky bucket for one (experiment, identity) pair, plus the conversion
 * event. Identity is either `user_id` (logged-in) or `session_id` (anonymous).
 *
 * @property int                              $id
 * @property int                              $ab_experiment_id
 * @property int|null                         $user_id
 * @property string|null                      $session_id
 * @property string                           $variant
 * @property bool                             $converted
 * @property \Illuminate\Support\Carbon|null  $converted_at
 */
class AbAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'ab_experiment_id',
        'user_id',
        'session_id',
        'variant',
        'converted',
        'converted_at',
    ];

    protected $casts = [
        'converted'    => 'boolean',
        'converted_at' => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────
    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'ab_experiment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────
    public function scopeConverted(Builder $query): Builder
    {
        return $query->where('converted', true);
    }

    public function scopeForExperiment(Builder $query, int $experimentId): Builder
    {
        return $query->where('ab_experiment_id', $experimentId);
    }

    public function scopeVariant(Builder $query, string $variant): Builder
    {
        return $query->where('variant', $variant);
    }
}
