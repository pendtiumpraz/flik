<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * promo_code_redemptions — append-only ledger of successful redemptions.
 *
 * One row per (user, code, subscription) tuple. The subscription FK is
 * nullable because validation can happen BEFORE a subscription row exists
 * (e.g. checkout preview shows the discount on the plans page). When
 * the Midtrans webhook confirms payment we backfill subscription_id via
 * PromoCodeService::apply().
 *
 * Composite indexes:
 *   - (promo_code_id, redeemed_at) → admin "top codes by week" report
 *   - (user_id, promo_code_id)     → max-uses-per-user gate (hot path
 *     called on every checkout validation)
 *
 * cascadeOnDelete on both FKs: deleting a user or a promo code should
 * not leave dangling ledger rows. discount_applied_idr is denormalised
 * because the source code's `discount_value` can be edited later by an
 * admin — we want the historical receipt to remain accurate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('promo_code_id')
                ->constrained('promo_codes')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('subscription_id')
                ->nullable()
                ->constrained('subscriptions')
                ->cascadeOnDelete();

            // Frozen at redemption time so admin edits to the parent
            // promo never rewrite past receipts.
            $table->decimal('discount_applied_idr', 12, 2);

            $table->timestamp('redeemed_at');

            $table->timestamps();

            $table->index(['promo_code_id', 'redeemed_at']);
            $table->index(['user_id', 'promo_code_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_redemptions');
    }
};
