<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * promo_codes — marketing-issued discount tokens redeemed at checkout.
 *
 * Storage strategy:
 *   - `code` is persisted in UPPERCASE so a unique index gives us a
 *     case-insensitive lookup on every collation (PromoCodeService
 *     `strtoupper()`s the user input before searching). This avoids
 *     relying on database-specific collation tricks like utf8mb4_ci.
 *   - `discount_type` is a string-enum: 'percentage' (0-100), 'fixed'
 *     (IDR amount), 'free_trial_days' (days). The single
 *     `discount_value` column carries the magnitude in all three
 *     cases — the type tells you how to interpret it.
 *   - `applies_to_plans` is JSON of subscription_plan IDs; NULL means
 *     "valid for every plan". Stored as JSON (not pivot) because the
 *     set is tiny (<10 plans) and rarely queried server-side — the
 *     gate runs in PHP inside isRedeemableBy().
 *   - `max_uses` NULL = unlimited; `max_uses_per_user` defaults to 1
 *     so legacy "single-use" semantics are the safe default.
 *   - `is_first_time_only` gates a promo to users with zero past PAID
 *     subscriptions (free-plan rows don't count — see PromoCode
 *     ::isRedeemableBy()).
 *
 * The (is_active, expires_at) composite index serves the hot path
 * "list active codes that haven't expired" used by both the admin
 * listing and validateCode().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();

            // Always stored uppercase. Unique => case-insensitive lookup
            // when the service uppercases the input before querying.
            $table->string('code', 40)->unique();

            $table->string('name', 120);
            $table->text('description')->nullable();

            // Stored as VARCHAR (not native ENUM) so we can add new
            // discount types in a follow-up migration without a
            // schema-altering ENUM redefinition.
            $table->string('discount_type', 20); // percentage | fixed | free_trial_days

            // 12,2 covers IDR amounts well past the largest realistic
            // promo (>9 billion rupiah) while still allowing fractional
            // percentages like 12.50%.
            $table->decimal('discount_value', 12, 2);

            // NULL = applies to every plan.
            $table->json('applies_to_plans')->nullable();

            // NULL = unlimited total usage.
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedSmallInteger('max_uses_per_user')->default(1);

            // Some promos are intentionally annual-only or 3-month-only.
            $table->unsignedSmallInteger('min_subscription_months')->default(1);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_first_time_only')->default(false);

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Hot path: "show me active codes that aren't expired".
            $table->index(['is_active', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
