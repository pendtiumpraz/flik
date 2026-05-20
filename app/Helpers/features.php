<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Features\FeatureManager;

/**
 * Global `feature()` helper — single-line gate for "is this flag on
 * for this user?". Wraps {@see FeatureManager} so the implementation
 * stays swappable.
 *
 * Autoloaded via composer.json autoload.files so it's available before
 * service providers boot (necessary for usage in early-stage middleware).
 *
 * Usage:
 *   if (feature('new_player_ui')) { ... }
 *   if (feature('beta_section', $user)) { ... }
 *
 * Always defensive: missing service container (early boot), missing
 * table (fresh install), or any thrown exception inside the resolver
 * all degrade to `false` so a typo or partial install never breaks
 * the public site.
 */
if (! function_exists('feature')) {
    function feature(string $key, ?User $user = null): bool
    {
        try {
            // app() will throw if the container isn't booted yet
            // (extremely rare — service providers must be registered
            // before user code runs). Catch and default to false.
            /** @var FeatureManager $manager */
            $manager = app(FeatureManager::class);
        } catch (\Throwable) {
            return false;
        }

        try {
            return $manager->enabled($key, $user);
        } catch (\Throwable) {
            return false;
        }
    }
}
