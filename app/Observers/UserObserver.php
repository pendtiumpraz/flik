<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * UserObserver — wires post-create side-effects for the auth pipeline.
 *
 * Today: assigns the default 'user' role to every newly-created user when
 * no role assignments exist yet. This is intentionally a no-op when the
 * peer agent's role tables haven't been migrated/seeded — registration
 * must keep working on a fresh install.
 *
 * Registered in AppServiceProvider::boot() via User::observe(self::class).
 */
class UserObserver
{
    /**
     * Default role granted to every newly-registered account. Matches the
     * seeder name used by the RBAC peer agent. Kept as a constant so a
     * single edit re-points the entire onboarding flow.
     */
    public const DEFAULT_ROLE = User::ROLE_USER;

    /**
     * Fires AFTER the User row is committed to the DB. We intentionally
     * use `created` (post-INSERT) rather than `creating` (pre-INSERT) so
     * we have a primary key to attach the pivot row to.
     *
     * Failures are logged but never re-thrown — a missing role table or
     * misconfigured seeder must not break account creation.
     */
    public function created(User $user): void
    {
        try {
            // Skip silently when the RBAC tables / model aren't present
            // yet (peer agent's migrations not yet run on this install).
            if (!class_exists(\App\Models\Role::class) || !Schema::hasTable('role_user')) {
                // continue past — referral code generation below does NOT depend on roles.
            } else {
                // Respect any role explicitly assigned during seeding /
                // factory creation — only seed the default when truly empty.
                if (! $user->roles()->exists()) {
                    $user->assignRole(self::DEFAULT_ROLE);
                }
            }
        } catch (\Throwable $e) {
            // Auth pipeline must keep flowing — log and swallow.
            Log::warning('UserObserver: failed to assign default role on registration', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }

        // ── Refer-a-friend bootstrap ────────────────────────────────
        // Stamp a unique 12-char referral code so the user can start
        // sharing immediately from /referrals. The HasReferrals trait's
        // generator is idempotent — if a factory already set the code,
        // we no-op.
        try {
            if (Schema::hasColumn('users', 'referral_code') && empty($user->referral_code)) {
                $user->generateReferralCode();
            }
        } catch (\Throwable $e) {
            Log::warning('UserObserver: failed to generate referral code', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
