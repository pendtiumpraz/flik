<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\AbAssignment;
use App\Models\AbExperiment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Lightweight A/B testing framework (D6).
 *
 * Three primary operations:
 *
 *   - `assign(User, slug)` → string         : returns the variant for the user
 *   - `track(User, slug, value)` → void     : records a conversion
 *   - `report(slug)` → array                : per-variant breakdown
 *
 * Assignment is sticky (per-user, per-experiment) via the unique index on
 * `ab_assignments(experiment_id, user_id)`. Weights from
 * `ab_experiments.variants` are normalised at pick-time so an admin can
 * use 50/50, 1/1, or 30/30/40 interchangeably.
 *
 * Failure mode: if anything goes wrong (experiment missing, paused, no
 * variants, DB unique-violation race) `assign()` returns the FIRST variant
 * key in the experiment as a deterministic fallback — A/B tests should
 * never break the user-facing flow. Errors are logged, not thrown.
 *
 * `track()` is idempotent: re-tracking the same user just overwrites
 * `converted_at` / `conversion_value`. The latest call wins.
 */
class AbTestFramework
{
    /**
     * Deterministic fallback variant when something goes sideways. We use
     * this when a draft/completed/missing experiment is queried at runtime
     * so the calling controller always gets *some* string back.
     */
    protected const FALLBACK_VARIANT = 'control';

    /**
     * Assign (or look up the sticky assignment for) `$user` in
     * `$experimentSlug`. Returns the variant key.
     *
     * Behaviour:
     *   - Experiment missing       → returns FALLBACK_VARIANT (logged).
     *   - Experiment not running   → if user already assigned, return their
     *                                stored variant (so legacy users stay on
     *                                their bucket); otherwise FALLBACK.
     *   - Existing assignment      → return it (sticky).
     *   - No existing assignment   → weighted-random pick + insert.
     */
    public function assign(User $user, string $experimentSlug): string
    {
        $experiment = AbExperiment::query()
            ->where('slug', $experimentSlug)
            ->first();

        if (!$experiment) {
            Log::warning('AbTestFramework: missing experiment', ['slug' => $experimentSlug]);
            return self::FALLBACK_VARIANT;
        }

        // Sticky lookup first — works regardless of experiment status.
        $existing = AbAssignment::query()
            ->forExperiment($experiment->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return $existing->variant;
        }

        // No existing assignment AND experiment is not running → don't mint
        // a new bucket. Return a stable fallback (first variant key).
        if (!$experiment->isRunning()) {
            return $this->firstVariantKey($experiment);
        }

        $variant = $this->pickVariant($experiment);

        try {
            AbAssignment::create([
                'experiment_id' => $experiment->id,
                'user_id'       => $user->id,
                'variant'       => $variant,
                'assigned_at'   => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Race condition: another request inserted concurrently. Re-read
            // and return the persisted variant so both requests agree.
            $persisted = AbAssignment::query()
                ->forExperiment($experiment->id)
                ->where('user_id', $user->id)
                ->first();

            if ($persisted) {
                return $persisted->variant;
            }

            Log::error('AbTestFramework: assign insert failed', [
                'slug'  => $experimentSlug,
                'user'  => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->firstVariantKey($experiment);
        }

        return $variant;
    }

    /**
     * Mark `$user` as having converted in `$experimentSlug`.
     *
     * Idempotent: re-tracking overwrites the conversion timestamp & value.
     * If the user was never assigned to the experiment, this no-ops (we
     * don't backfill an assignment from a conversion — that would corrupt
     * the variant distribution).
     */
    public function track(User $user, string $experimentSlug, float $value = 1.0): void
    {
        $experiment = AbExperiment::query()
            ->where('slug', $experimentSlug)
            ->first();

        if (!$experiment) {
            Log::warning('AbTestFramework: track on missing experiment', ['slug' => $experimentSlug]);
            return;
        }

        $assignment = AbAssignment::query()
            ->forExperiment($experiment->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$assignment) {
            // No-op: don't fabricate an assignment from a conversion event.
            return;
        }

        $assignment->forceFill([
            'converted_at'     => Carbon::now(),
            'conversion_value' => $value,
        ])->save();
    }

    /**
     * Aggregate per-variant report for one experiment.
     *
     * @return array{
     *   experiment: array{slug:string, name:string, status:string, winner:?string},
     *   total_assigned: int,
     *   total_converted: int,
     *   overall_conversion_rate: float,
     *   variants: array<int, array{
     *     variant:string,
     *     assigned:int,
     *     converted:int,
     *     conversion_rate:float,
     *     avg_value:float,
     *     total_value:float
     *   }>
     * }
     */
    public function report(string $experimentSlug): array
    {
        $experiment = AbExperiment::query()
            ->where('slug', $experimentSlug)
            ->firstOrFail();

        // Aggregate in one round trip — group by variant.
        $rows = AbAssignment::query()
            ->forExperiment($experiment->id)
            ->select('variant')
            ->selectRaw('COUNT(*) AS assigned')
            ->selectRaw('SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) AS converted')
            ->selectRaw('SUM(COALESCE(conversion_value, 0)) AS total_value')
            ->groupBy('variant')
            ->get()
            ->keyBy('variant');

        // Seed empty buckets for every defined variant so the report shape
        // is stable even before traffic flows.
        $variants = [];
        $totalAssigned = 0;
        $totalConverted = 0;

        $allKeys = $experiment->variantKeys();
        // Include any orphaned variant keys that exist in assignments but
        // not in the experiment definition (e.g. after someone edited the
        // weights and removed a key).
        foreach ($rows->keys() as $k) {
            if (!in_array($k, $allKeys, true)) {
                $allKeys[] = $k;
            }
        }

        foreach ($allKeys as $key) {
            $row = $rows->get($key);
            $assigned  = (int) ($row->assigned ?? 0);
            $converted = (int) ($row->converted ?? 0);
            $totalVal  = (float) ($row->total_value ?? 0.0);

            $rate = $assigned > 0 ? round(($converted / $assigned) * 100, 2) : 0.0;
            $avg  = $converted > 0 ? round($totalVal / $converted, 2) : 0.0;

            $variants[] = [
                'variant'         => $key,
                'assigned'        => $assigned,
                'converted'       => $converted,
                'conversion_rate' => $rate,
                'avg_value'       => $avg,
                'total_value'     => round($totalVal, 2),
            ];

            $totalAssigned += $assigned;
            $totalConverted += $converted;
        }

        return [
            'experiment' => [
                'slug'   => $experiment->slug,
                'name'   => $experiment->name,
                'status' => $experiment->status,
                'winner' => $experiment->winner_variant,
            ],
            'total_assigned'  => $totalAssigned,
            'total_converted' => $totalConverted,
            'overall_conversion_rate' => $totalAssigned > 0
                ? round(($totalConverted / $totalAssigned) * 100, 2)
                : 0.0,
            'variants' => $variants,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────────────────────────

    /**
     * Weighted-random variant pick.
     *
     * Weights are normalised on the fly (so 1/1, 50/50, and 30/70 all
     * behave consistently). Uses `random_int` for unbiased selection.
     */
    protected function pickVariant(AbExperiment $experiment): string
    {
        $variants = $experiment->normalizedVariants();

        if ($variants === []) {
            Log::error('AbTestFramework: experiment has no usable variants', [
                'slug' => $experiment->slug,
            ]);
            return self::FALLBACK_VARIANT;
        }

        $totalWeight = array_sum(array_column($variants, 'weight'));

        if ($totalWeight <= 0) {
            return $variants[0]['key'];
        }

        // Scale to integer space for random_int. 1e6 gives plenty of
        // resolution even for fractional weights like 0.1/0.9.
        $scale = 1_000_000;
        $pick = random_int(1, $scale);

        $cum = 0;
        foreach ($variants as $v) {
            $cum += (int) round(($v['weight'] / $totalWeight) * $scale);
            if ($pick <= $cum) {
                return $v['key'];
            }
        }

        // Rounding fall-through: return the last variant.
        return $variants[array_key_last($variants)]['key'];
    }

    /**
     * Stable fallback when we can't (or don't want to) mint a new
     * assignment but still need to return *some* variant key.
     */
    protected function firstVariantKey(AbExperiment $experiment): string
    {
        $variants = $experiment->normalizedVariants();
        return $variants[0]['key'] ?? self::FALLBACK_VARIANT;
    }
}
