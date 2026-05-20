<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings — runtime-editable key/value config registry.
 *
 * Replaces the "drop a value in .env, redeploy" workflow for site-level
 * knobs (brand name, social handles, comment-per-hour limit, etc.).
 *
 * Notes on the columns:
 *   - `key` uses dotted namespaces (e.g. `social.twitter`) so callers can
 *     group reads with `Setting::group('social')` and the admin UI can
 *     tab by the leading segment.
 *   - `value` is plain text — the typed cast happens at the model layer
 *     (Setting::get / accessor). Storing as text means a single column
 *     can serve booleans, ints, JSON blobs, etc. without per-type tables.
 *   - `is_public` gates whether the value is safe to ship to the
 *     unauthenticated frontend (e.g. via a settings.public JSON dump).
 *   - `is_secret` marks values the admin UI should MASK (think: API
 *     credentials that bled into settings instead of .env). Always
 *     stored plaintext — the bit is presentation-only.
 *   - `validation_rules` is a literal Laravel validation rule string
 *     (e.g. `"required|email"`) applied by the SettingsController
 *     bulk-update action before persisting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('settings')) {
            return;
        }

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            // Dotted namespace, e.g. 'site.name', 'limits.comment_per_hour'.
            // Wider than feature_flags.key because some integration knobs
            // get verbose (`integrations.midtrans.client_key_public`).
            $table->string('key', 120)->unique();
            // NULLable so flagging a setting "not configured" is distinct
            // from setting it to an empty string. Reading code treats
            // null as "use the documented default".
            $table->text('value')->nullable();
            // 'string' | 'int' | 'float' | 'bool' | 'json' | 'array'
            // String not enum for future-proofing (e.g. add 'datetime').
            $table->string('type', 12)->default('string');
            // UI tab grouping — branding, social, limits, features, email,
            // legal, ai, integrations, etc.
            $table->string('group', 40)->default('general');
            $table->text('description')->nullable();
            // True ⇒ may be exposed in window.SITE_CONFIG, settings.public
            // API endpoint, etc. False ⇒ admin/server use only.
            $table->boolean('is_public')->default(false);
            // True ⇒ admin UI shows masked input (••••), value still stored
            // in plaintext. NOT a substitute for encryption — for secrets
            // that need encrypted at rest, use ai_providers or .env.
            $table->boolean('is_secret')->default(false);
            // Laravel validation rule string applied by the bulk-update form.
            $table->string('validation_rules', 200)->nullable();
            $table->timestamps();

            $table->index('group');
            $table->index('is_public');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
