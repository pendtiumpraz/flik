<?php

declare(strict_types=1);

use App\Models\Setting;

/**
 * Global `setting()` helper — typed lookup against the runtime-editable
 * settings registry. Always defensive: a missing table, missing key,
 * or any thrown exception degrades to the supplied $default so views
 * never blow up on a fresh install.
 *
 * Usage:
 *   {{ setting('site.name', 'FLiK') }}
 *   $perHour = setting('limits.comment_per_hour', 30);
 *
 * NOTE: This intentionally does NOT shadow Laravel's `config()` — config
 * is for static .env-derived values, setting() is for admin-editable
 * runtime values. Different cache tier, different invalidation path.
 */
if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        try {
            return Setting::get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
