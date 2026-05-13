<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ChurnPrediction
 * --------------------------------------------------------------------------
 * Latest churn-risk snapshot for a user.
 *
 * @property int                                $user_id
 * @property float                              $risk_score
 * @property string                             $risk_level
 * @property array<string, mixed>|null         $signals
 * @property string|null                        $suggested_action
 * @property \Illuminate\Support\Carbon|null    $computed_at
 */
class ChurnPrediction extends Model
{
    use HasFactory;

    public const LEVEL_LOW      = 'low';
    public const LEVEL_MEDIUM   = 'medium';
    public const LEVEL_HIGH     = 'high';
    public const LEVEL_CRITICAL = 'critical';

    public const LEVELS = [
        self::LEVEL_LOW,
        self::LEVEL_MEDIUM,
        self::LEVEL_HIGH,
        self::LEVEL_CRITICAL,
    ];

    /**
     * SECURITY: ChurnPredictor (a backend AI Task) is the only writer.
     * End users never POST a churn score for themselves. Guarding everything
     * keeps risk_score/risk_level/suggested_action immutable from the HTTP
     * surface so users cannot self-rate "low risk" to dodge retention work.
     *
     * @var array<int, string>
     */
    protected $guarded = ['*'];

    protected $casts = [
        'risk_score'  => 'float',
        'signals'     => 'array',
        'computed_at' => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────
    public function scopeAtRisk(Builder $query): Builder
    {
        return $query->whereIn('risk_level', [self::LEVEL_HIGH, self::LEVEL_CRITICAL]);
    }

    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('risk_level', $level);
    }

    public function scopeOrderedByRisk(Builder $query): Builder
    {
        return $query->orderByDesc('risk_score');
    }

    // ── Helpers ───────────────────────────────────────────────
    /**
     * Map a 0..1 score onto the four-level band.
     *
     *   <0.3    low
     *   <0.6    medium
     *   <0.8    high
     *   >=0.8   critical
     */
    public static function levelFromScore(float $score): string
    {
        return match (true) {
            $score < 0.3 => self::LEVEL_LOW,
            $score < 0.6 => self::LEVEL_MEDIUM,
            $score < 0.8 => self::LEVEL_HIGH,
            default      => self::LEVEL_CRITICAL,
        };
    }

    public function isHighRisk(): bool
    {
        return in_array($this->risk_level, [self::LEVEL_HIGH, self::LEVEL_CRITICAL], true);
    }
}
