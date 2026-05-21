<?php

declare(strict_types=1);

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

if (! function_exists('infra')) {
    /**
     * Read a dynamic infrastructure setting directly from the `settings`
     * table — bypassing the config() override chain. Use this when you
     * want explicit "give me the DB-stored value, ignore .env defaults"
     * semantics.
     *
     * For most code paths prefer `config('services.midtrans.server_key')`
     * etc. — DynamicInfrastructureProvider already overrides those at
     * boot from the same DB rows. `infra()` is for cases where you need
     * the raw setting without the config() detour OR you want to read
     * an infra key that isn't in DynamicInfrastructureProvider::MAP yet.
     */
    function infra(string $key, mixed $default = null): mixed
    {
        try {
            if (! Schema::hasTable('settings')) {
                return $default;
            }
            $val = Setting::get($key, null);
            return ($val === null || $val === '') ? $default : $val;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
