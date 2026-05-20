<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * MaintenanceState
 * --------------------------------------------------------------------------
 * Singleton row (id = 1) that drives the App-level maintenance switch
 * exposed at /admin/maintenance. See the migration for the rationale of
 * persisting this in the DB instead of as a file marker.
 *
 * NOTE: This is independent of Laravel's native `php artisan down` (which
 * writes storage/framework/down). Both work — the framework's PreventRequests
 * DuringMaintenance middleware still runs first in the global stack and
 * short-circuits when its file is present. The custom middleware
 * {@see \App\Http\Middleware\CheckCustomMaintenance} layers on top and
 * gives us per-IP / per-role bypass + a friendly themed message.
 */
class MaintenanceState extends Model
{
    public $timestamps = false;

    protected $table = 'maintenance_state';

    /**
     * SECURITY: the only legitimate writes go through `enable()` /
     * `disable()` / `update()` on this class, which already build the
     * payload from server-trusted inputs. Mass-assignment is therefore
     * fully closed off — even if a controller accidentally passes the
     * raw request, no field changes.
     */
    protected $guarded = ['*'];

    protected $casts = [
        'is_enabled'      => 'boolean',
        'allow_ips'       => 'array',
        'allow_roles'     => 'array',
        'scheduled_until' => 'datetime',
        'enabled_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    /**
     * Singleton accessor. Always returns the id=1 row, creating it lazily
     * if the seed migration somehow never ran (e.g. fresh DB during tests
     * that bypass the seeder block).
     *
     * Defensive: the middleware that calls this runs on EVERY request, so
     * a missing table cannot raise — we return a transient in-memory row
     * with is_enabled=false instead.
     */
    public static function current(): self
    {
        if (! Schema::hasTable('maintenance_state')) {
            $state = new self();
            $state->id = 1;
            $state->is_enabled = false;

            return $state;
        }

        /** @var self|null $row */
        $row = self::query()->find(1);
        if ($row !== null) {
            return $row;
        }

        // Seed missed somehow — create the singleton row now so subsequent
        // requests find it. forceCreate because $guarded = ['*'].
        return self::query()->forceCreate([
            'id'         => 1,
            'is_enabled' => false,
            'updated_at' => now(),
        ]);
    }

    public function isEnabled(): bool
    {
        return (bool) $this->is_enabled;
    }

    /**
     * Flip maintenance ON.
     *
     * @param  array<int, string>  $allowIps    raw IPv4/IPv6 strings
     * @param  array<int, string>  $allowRoles  role names; defaults to ['super_admin']
     */
    public function enable(
        User $actor,
        ?string $message = null,
        array $allowIps = [],
        array $allowRoles = ['super_admin'],
        ?Carbon $scheduledUntil = null,
    ): void {
        // Normalise inputs — drop blanks, dedupe, re-index. We tolerate
        // the admin pasting a multi-line textarea and feeding the result
        // in directly without trimming.
        $allowIps = array_values(array_unique(array_filter(array_map(
            static fn ($ip) => trim((string) $ip),
            $allowIps,
        ))));
        $allowRoles = array_values(array_unique(array_filter(array_map(
            static fn ($r) => trim((string) $r),
            $allowRoles,
        ))));

        // Guard against locking the operator out: if super_admin isn't in
        // the allow list, force it in. The admin can still narrow further
        // (e.g. ['super_admin', 'admin']) but never remove super_admin.
        if (! in_array('super_admin', $allowRoles, true)) {
            $allowRoles[] = 'super_admin';
        }

        $this->forceFill([
            'is_enabled'         => true,
            'message'            => $message,
            'allow_ips'          => $allowIps,
            'allow_roles'        => $allowRoles,
            'scheduled_until'    => $scheduledUntil,
            'enabled_by_user_id' => $actor->getKey(),
            'enabled_at'         => now(),
            'updated_at'         => now(),
        ])->save();
    }

    /**
     * Flip maintenance OFF without dropping the configured message or
     * allow lists, so the next `enable()` can reuse them if the admin
     * only passes the toggle.
     */
    public function disable(): void
    {
        $this->forceFill([
            'is_enabled'         => false,
            'enabled_by_user_id' => null,
            'enabled_at'         => null,
            'updated_at'         => now(),
        ])->save();
    }

    /**
     * Edit message / allow lists / schedule WITHOUT changing the on/off
     * bit. Used by the "Save settings" button so an admin can pre-stage
     * the next outage notice while the site is still up.
     *
     * @param  array<int, string>|null  $allowIps
     * @param  array<int, string>|null  $allowRoles
     */
    public function updateConfig(
        ?string $message = null,
        ?array $allowIps = null,
        ?array $allowRoles = null,
        ?Carbon $scheduledUntil = null,
    ): void {
        $payload = ['updated_at' => now()];

        if ($message !== null) {
            $payload['message'] = $message;
        }
        if ($allowIps !== null) {
            $payload['allow_ips'] = array_values(array_unique(array_filter(array_map(
                static fn ($ip) => trim((string) $ip),
                $allowIps,
            ))));
        }
        if ($allowRoles !== null) {
            $roles = array_values(array_unique(array_filter(array_map(
                static fn ($r) => trim((string) $r),
                $allowRoles,
            ))));
            if (! in_array('super_admin', $roles, true)) {
                $roles[] = 'super_admin';
            }
            $payload['allow_roles'] = $roles;
        }
        $payload['scheduled_until'] = $scheduledUntil;

        $this->forceFill($payload)->save();
    }

    /**
     * Decide whether the given (user, ip) tuple may bypass the kill switch.
     *
     * Bypass rules — ANY one is sufficient:
     *   1. IP is in allow_ips.
     *   2. User holds any role listed in allow_roles (legacy + pivot
     *      checks honoured via {@see User::hasRole()}).
     *   3. User is a super-admin (defence in depth even if super_admin
     *      somehow got stripped from allow_roles).
     */
    public function userCanBypass(?User $user, string $ip): bool
    {
        $ips = $this->allow_ips ?? [];
        if (in_array($ip, $ips, true)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        // Super-admin always bypasses — see comment in enable() about the
        // hard guarantee that super_admin can always reach /admin to flip
        // the switch back off.
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $roles = $this->allow_roles ?? [];
        if ($roles === []) {
            return false;
        }

        return $user->hasRole($roles);
    }

    public function enabledBy()
    {
        return $this->belongsTo(User::class, 'enabled_by_user_id');
    }
}
