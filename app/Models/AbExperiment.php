<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AbExperiment
 * --------------------------------------------------------------------------
 * One A/B (or A/B/n) experiment.
 *
 * Variants are stored as a flat list of keys (`["control","variant_a"]`)
 * with a parallel `traffic_split` array of weights (e.g. `[0.5,0.5]`).
 * `AbService` zips them together at pick time.
 *
 * @property int                              $id
 * @property string                           $name
 * @property string|null                      $description
 * @property array<int, string>               $variants
 * @property array<int, float>                $traffic_split
 * @property string                           $status
 * @property string                           $primary_metric
 * @property \Illuminate\Support\Carbon|null  $started_at
 * @property \Illuminate\Support\Carbon|null  $ended_at
 */
class AbExperiment extends Model
{
    use HasFactory;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'name',
        'description',
        'variants',
        'traffic_split',
        'status',
        'primary_metric',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'variants'      => 'array',
        'traffic_split' => 'array',
        'started_at'    => 'datetime',
        'ended_at'      => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────
    public function assignments(): HasMany
    {
        return $this->hasMany(AbAssignment::class, 'ab_experiment_id');
    }

    // ── Scopes ────────────────────────────────────────────────
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    // ── Helpers ───────────────────────────────────────────────
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Variant list with normalised weights (sum to 1.0).
     *
     * If `traffic_split` is missing / malformed / mismatched length,
     * we fall back to even split so the picker never explodes.
     *
     * @return array<int, array{key:string, weight:float}>
     */
    public function normalizedVariants(): array
    {
        $variants = is_array($this->variants) ? array_values($this->variants) : [];
        $splits   = is_array($this->traffic_split) ? array_values($this->traffic_split) : [];

        $variants = array_values(array_filter(
            $variants,
            static fn ($v) => is_string($v) && $v !== '',
        ));

        $count = count($variants);
        if ($count === 0) {
            return [];
        }

        // Mismatch / missing → even split.
        if (count($splits) !== $count) {
            $even = 1.0 / $count;
            $splits = array_fill(0, $count, $even);
        }

        // Normalise to sum-to-1 so weight semantics are consistent regardless
        // of whether the admin entered [0.5, 0.5] or [50, 50] or [1, 1].
        $sum = array_sum(array_map('floatval', $splits));
        if ($sum <= 0) {
            $even = 1.0 / $count;
            $splits = array_fill(0, $count, $even);
            $sum = 1.0;
        }

        $out = [];
        foreach ($variants as $i => $key) {
            $out[] = [
                'key'    => $key,
                'weight' => ((float) $splits[$i]) / $sum,
            ];
        }
        return $out;
    }
}
