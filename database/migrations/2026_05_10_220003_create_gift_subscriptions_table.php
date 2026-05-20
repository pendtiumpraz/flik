<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gift_subscriptions — pre-paid redemption codes someone buys for someone
 * else. Anonymous purchase is allowed (purchased_by_user_id nullable) so
 * a non-account holder can grab a gift card without signing up first;
 * we still capture the purchaser's email for the Midtrans receipt.
 *
 * Lifecycle:
 *   1. row created with `paid_at = NULL` when the buyer hits checkout
 *   2. Midtrans webhook stamps `paid_at` → triggers `SendGiftEmailJob`
 *   3. recipient clicks the link in their email → enters `code` on
 *      `/gift/redeem` → controller calls
 *      {@see \App\Services\Billing\GiftSubscriptionService::redeem()}
 *   4. that service stamps `redeemed_by_user_id` + `redeemed_at` and
 *      creates the actual {@see \App\Models\Subscription} row
 *
 * `expires_at` defaults to 90 days from purchase — un-redeemed gifts
 * past that are swept by `flik:gift:expire-unredeemed`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_subscriptions', function (Blueprint $table) {
            $table->id();

            // 12-char alpha-num token. Stored UPPERCASE — the service
            // strtoupper()s the user input before lookup, so the unique
            // index gives us case-insensitive matching on every collation.
            $table->char('code', 12)->unique();

            $table->foreignId('plan_id')
                ->constrained('subscription_plans')
                ->cascadeOnDelete();

            // Duration is captured at purchase time so a plan price/length
            // change next month does NOT shorten an already-paid gift.
            $table->unsignedInteger('duration_days')->default(30);

            // Anonymous purchase OK — NULL when buyer never signed up.
            $table->foreignId('purchased_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Receipt email — always captured regardless of auth state.
            $table->string('purchaser_email');

            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_email')->nullable();
            $table->text('personal_message')->nullable();

            $table->decimal('amount_paid_idr', 12, 2);
            $table->timestamp('paid_at')->nullable();

            // Set once the gift is redeemed. ON DELETE SET NULL keeps the
            // historical record alive if the recipient closes their account.
            $table->foreignId('redeemed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('redeemed_at')->nullable();

            // Default is 90 days from creation. The model's `create` event
            // back-fills this when callers omit it.
            $table->timestamp('expires_at')->nullable();

            $table->string('midtrans_order_id')->nullable();
            $table->string('midtrans_transaction_id')->nullable();

            $table->timestamps();

            // (code) is already unique. Add a (recipient_email, redeemed_at)
            // index for the "show me unredeemed gifts I was given" lookup
            // that the recipient might run after losing their email.
            $table->index(['recipient_email', 'redeemed_at'], 'gift_recipient_redeemed_idx');
            $table->index('midtrans_order_id', 'gift_midtrans_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_subscriptions');
    }
};
