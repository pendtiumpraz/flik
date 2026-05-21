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
 * Variants are stored either as a flat list of keys (`["control","variant_a"]`)
 * with a parallel `traffic_split` array of weights (e.g. `[0.5,0.5]`), OR
 * as an array of `[key, weight]` objects produced by the admin Create form
 * (`AbTestController::store`). The {@see self::normalizedVariants()} helper
 * recognises both shapes and returns a uniform `[{key, weight}]` list.
 *
 * @property int                              $id
 * @property string|null                      $slug
 * @property string                           $name
 * @property string|null                      $description
 * @property string|null                      $hypothesis
 * @property array<int, mixed>                $variants
 * @property array<int, float>|null           $traffic_split
 * @property string                           $status
 * @property string|null                      $primary_metric
 * @property string|null                      $winner_variant
 * @property \Illuminate\Support\Carbon|null  $started_at
 * @property \Illuminate\Support\Carbon|null  $ended_at
 */
class AbExperiment extends Model
{
    use HasFactory;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ACTIVE    = 'active';
    /**
     * Lifecycle alias surfaced by `AbTestController` (the controller was
     * originally written against a "running" status). We keep both the
     * canonical `active` constant AND a `running` alias so existing code
     * referencing `STATUS_RUNNING` keeps working, and the framework can
     * accept either status value as "experiment is live".
     */
    public const STATUS_RUNNING   = 'running';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_RUNNING,
        self::STATUS_PAUSED,
        self::STATUS_COMPLETED,
    ];

    /**
     * Statuses where the framework is allowed to mint NEW assignments.
     * Both `active` and `running` qualify so legacy data + new lifecycle
     * commands interoperate without a data migration.
     */
    public const RUNNING_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_RUNNING,
    ];

    protected $fillable = [
        'slug',
        'name',
        'description',
        'hypothesis',
        'variants',
        'traffic_split',
        'status',
        'primary_metric',
        'winner_variant',
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

    /**
     * True when this experiment is allowed to mint NEW assignments.
     *
     * Accepts both the canonical `active` status and the `running` alias
     * so the framework / controller can use either name interchangeably.
     */
    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING_STATUSES, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Flat list of variant keys defined on this experiment.
     *
     * Handles both supported `variants` shapes:
     *   - Flat list:  `["control","variant_a"]`
     *   - Objects:    `[{"key":"control","weight":0.5}, ...]`
     *
     * @return array<int, string>
     */
    public function variantKeys(): array
    {
        $raw = is_array($this->variants) ? $this->variants : [];
        $out = [];

        foreach ($raw as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = $v;
                continue;
            }
            if (is_array($v) && isset($v['key']) && is_string($v['key']) && $v['key'] !== '') {
                $out[] = $v['key'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Variant list with normalised weights (sum to 1.0).
     *
     * Supports two storage shapes:
     *   (a) Flat keys + parallel `traffic_split`:
     *       `variants: ["control","variant_a"]`,
     *       `traffic_split: [0.5, 0.5]`
     *   (b) Self-contained object list (admin Create form):
     *       `variants: [{"key":"control","weight":50}, {"key":"variant_a","weight":50}]`
     *
     * Either shape produces the same `[{key, weight}]` output with weights
     * summing to 1.0. Missing / malformed `traffic_split` falls back to an
     * even split so the picker never explodes.
     *
     * @return array<int, array{key:string, weight:float}>
     */
    public function normalizedVariants(): array
    {
        $raw = is_array($this->variants) ? array_values($this->variants) : [];

        if ($raw === []) {
            return [];
        }

        // ── Shape (b): list of {key, weight} objects ───────────────────
        // Detected when the first non-string entry is an array with a
        // `key` index. We honour the embedded weight directly and ignore
        // `traffic_split` (the object shape is self-describing).
        $isObjectShape = false;
        foreach ($raw as $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $isObjectShape = true;
                break;
            }
        }

        if ($isObjectShape) {
            $entries = [];
            foreach ($raw as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $key    = isset($entry['key']) && is_string($entry['key']) ? $entry['key'] : '';
                $weight = (float) ($entry['weight'] ?? 0);
                if ($key === '' || $weight <= 0) {
                    continue;
                }
                $entries[] = ['key' => $key, 'weight' => $weight];
            }

            if ($entries === []) {
                return [];
            }

            $sum = array_sum(array_column($entries, 'weight'));
            if ($sum <= 0) {
                $even = 1.0 / count($entries);
                foreach ($entries as $i => $e) {
                    $entries[$i]['weight'] = $even;
                }
                return $entries;
            }

            foreach ($entries as $i => $e) {
                $entries[$i]['weight'] = $e['weight'] / $sum;
            }
            return $entries;
        }

        // ── Shape (a): parallel string keys + traffic_split weights ────
        $variants = array_values(array_filter(
            $raw,
            static fn ($v) => is_string($v) && $v !== '',
        ));
        $splits = is_array($this->traffic_split) ? array_values($this->traffic_split) : [];

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
