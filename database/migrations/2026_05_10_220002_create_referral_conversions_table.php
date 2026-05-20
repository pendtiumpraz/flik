<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * referral_conversions — the audit + reward ledger for the refer-a-friend
 * program. One row per (referrer, referred) pair, lifecycle:
 *
 *   pending   → row created when referred user signs up via /r/{code}
 *   qualified → referred user finished onboarding (or first watch)
 *   rewarded  → referred user activated a paid subscription
 *   expired   → 90-day pending window passed without qualification
 *
 * UNIQUE (referrer_user_id, referred_user_id) guarantees idempotency:
 * a user can only be "the referred" once across the whole table, even
 * if they re-click a referral link in another browser session.
 *
 * The (status, created_at) index serves the cron sweep that expires
 * stale pendings and the admin filter "show me last-week's qualifieds".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_conversions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('referrer_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('referred_user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // pending | qualified | rewarded | expired.
            // String column (not native enum) so we can add a new state
            // (e.g. 'refunded') in a follow-up migration without an
            // ENUM redefinition that re-writes every row.
            $table->string('status', 16)->default('pending');

            // Set once the referred user converts on a paid plan. NULL
            // before that. ON DELETE SET NULL so cancelling a subscription
            // never cascades into deleting the reward audit row.
            $table->foreignId('subscription_id')
                ->nullable()
                ->constrained('subscriptions')
                ->nullOnDelete();

            // Reward magnitudes are persisted on the conversion (not
            // looked up from config at read time) so historical rows
            // keep telling the truth even if marketing changes the
            // reward schedule next quarter.
            $table->unsignedInteger('referrer_reward_coins')->default(0);
            $table->unsignedInteger('referrer_reward_xp')->default(0);
            $table->unsignedInteger('referred_reward_coins')->default(0);
            $table->unsignedInteger('referred_reward_xp')->default(0);

            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();

            $table->timestamps();

            // Hard-stop double-attribution. A second click on a referral
            // link by an already-attributed user is rejected at insert
            // time, not at app-layer (which could race).
            $table->unique(['referrer_user_id', 'referred_user_id'], 'referral_conv_pair_unique');

            // Cron sweep + admin filter.
            $table->index(['status', 'created_at'], 'referral_conv_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_conversions');
    }
};
