<?php

declare(strict_types=1);

namespace App\Services\Features;

use App\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Thin orchestration layer around {@see FeatureFlag} — gives the rest
 * of the app a single entry point so controllers/views don't reach
 * directly into the model.
 *
 * Why a service AND model helpers? The service is what gets injected
 * into controllers (testable, mockable), the model helpers are what
 * the `feature()` global + `@feature` Blade directive call (no DI
 * available in Blade compilation context).
 */
class FeatureManager
{
    /**
     * Convenience facade-like accessor — `Feature::active('key')` style
     * usage in controllers that don't want to inject the service.
     * Resolves to the singleton bound in AppServiceProvider.
     */
    public static function active(string $key, ?User $user = null): bool
    {
        return app(self::class)->enabled($key, $user);
    }

    /**
     * True when the flag exists, its master switch is on, AND the
     * configured strategy resolves to true for the given user.
     *
     * Missing flag ⇒ false (conservative default: a typo in the
     * feature name MUST NOT silently turn a feature on).
     */
    public function enabled(string $key, ?User $user = null): bool
    {
        $user ??= auth()->user();

        $flag = FeatureFlag::findByKey($key);

        if ($flag === null) {
            return false;
        }

        return $flag->evaluate($user);
    }

    /**
     * Snapshot of EVERY known flag for the given user. Useful for the
     * frontend bootstrap payload ("here's what's on for you, render
     * accordingly") and for the admin dashboard preview.
     *
     * @return array<string, bool>
     */
    public function allFor(?User $user): array
    {
        if (! Schema::hasTable('feature_flags')) {
            return [];
        }

        $user ??= auth()->user();

        $out = [];
        FeatureFlag::query()
            ->select(['id', 'key', 'is_enabled', 'strategy', 'strategy_config'])
            ->orderBy('key')
            ->get()
            ->each(function (FeatureFlag $flag) use (&$out, $user): void {
                $out[$flag->key] = $flag->evaluate($user);
            });

        return $out;
    }

    /**
     * Admin-callable: flip the master switch ON. Idempotent — no-op if
     * the flag is already enabled. Does NOT change the strategy.
     */
    public function enable(string $key): void
    {
        $flag = FeatureFlag::query()->where('key', $key)->first();
        if ($flag === null) {
            return;
        }

        $flag->is_enabled = true;
        if ($flag->rollout_started_at === null) {
            $flag->rollout_started_at = now();
        }
        $flag->save();
    }

    /**
     * Admin-callable: flip the master switch OFF.
     */
    public function disable(string $key): void
    {
        $flag = FeatureFlag::query()->where('key', $key)->first();
        if ($flag === null) {
            return;
        }

        $flag->is_enabled = false;
        $flag->save();
    }
}
