<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature Flags — runtime-editable toggles for rolling new features out
 * gradually (by role, percentage, individual user list, or auth status).
 *
 * Two boolean axes deliberately separated:
 *   - `is_enabled` is the MASTER kill switch. False ⇒ always off, regardless
 *      of strategy. This lets ops kill a flag instantly without re-picking
 *      its strategy on the next ramp-up.
 *   - `strategy` decides WHO sees the feature when the master is on.
 *
 * Strategy config is stored as JSON so each strategy can carry whatever
 * shape it needs (`{"roles":["admin"]}`, `{"percentage":25}`,
 * `{"user_ids":[1,2,3]}`) without schema churn for every new bucket type.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feature_flags')) {
            return;
        }

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            // Machine identifier — dot/underscore separated, used in code
            // as `feature('tv_series_section')`. Tight max keeps it usable
            // as an array key everywhere without truncation surprises.
            $table->string('key', 80)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            // Master kill switch — short-circuits evaluate() to false.
            $table->boolean('is_enabled')->default(false);
            // 'off' | 'on' | 'role' | 'percentage' | 'users' | 'authed' | 'guests'
            // Stored as string (not native enum) so adding a new strategy
            // is a code change, not a migration.
            $table->string('strategy', 16)->default('off');
            // Strategy-specific payload. Examples per strategy:
            //   role        => {"roles":["admin","moderator"]}
            //   percentage  => {"percentage":25}
            //   users       => {"user_ids":[1,2,3]}
            //   off/on/authed/guests => null (no config needed)
            $table->json('strategy_config')->nullable();
            // Stamped the first time the flag flipped from off → on (or
            // a rollout strategy was activated). Used in admin UI to
            // show "ramping since …".
            $table->timestamp('rollout_started_at')->nullable();
            $table->timestamps();

            // Hot path is FeatureManager::enabled() looking up by key.
            // Already covered by the unique above, but the explicit index
            // documents intent. The (is_enabled, strategy) index speeds
            // up the admin list filter ("show me everything enabled").
            $table->index('key');
            $table->index(['is_enabled', 'strategy']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
