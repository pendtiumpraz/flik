<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Services\Features\FeatureManager;

/**
 * Facade-style shorthand for FeatureManager so callers can write
 * `Feature::active('new_ui')` without needing to import the service.
 *
 * Not a real Laravel facade — we don't go through Facade::resolveFacadeInstance
 * because there's nothing to mock here that the underlying service doesn't
 * already cover. This is just static sugar over `app(FeatureManager::class)`.
 *
 * Usage:
 *   use App\Support\Feature;
 *
 *   if (Feature::active('tv_series_section')) { ... }
 *   if (Feature::active('beta_section', $user)) { ... }
 */
final class Feature
{
    /**
     * Is the named flag active for the given (or current) user?
     */
    public static function active(string $key, ?User $user = null): bool
    {
        return app(FeatureManager::class)->enabled($key, $user);
    }

    /**
     * Negation helper — slightly clearer at call sites than `!Feature::active(...)`.
     */
    public static function inactive(string $key, ?User $user = null): bool
    {
        return ! self::active($key, $user);
    }

    /**
     * All flags for the user, as ['key' => bool] map.
     *
     * @return array<string, bool>
     */
    public static function all(?User $user = null): array
    {
        return app(FeatureManager::class)->allFor($user);
    }
}
