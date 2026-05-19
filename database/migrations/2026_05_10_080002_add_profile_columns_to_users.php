<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public-profile columns for the /u/{username} social layer.
 *
 *   - bio:          short user-supplied biography rendered on /u/{username}.
 *   - avatar_path:  uploaded avatar (separate from OAuth provider URLs which
 *                   live on the legacy `avatar` / provider attributes — those
 *                   stay untouched so existing rendering keeps working).
 *   - cover_path:   profile banner image.
 *   - is_public:    opt-out of the public profile. Defaults to TRUE because
 *                   the public profile IS the feature; we still respect
 *                   per-row privacy when set to false.
 *   - allow_dm:     reserved for the DM feature; defaults to TRUE so DMs
 *                   are opt-out, not opt-in (matches social-app norms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Defensive: skip columns that another peer agent may have
            // already added (the users table sees a lot of swarm traffic).
            if (! Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('username');
            }
            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('bio');
            }
            if (! Schema::hasColumn('users', 'cover_path')) {
                $table->string('cover_path')->nullable()->after('avatar_path');
            }
            if (! Schema::hasColumn('users', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('cover_path');
            }
            if (! Schema::hasColumn('users', 'allow_dm')) {
                $table->boolean('allow_dm')->default(true)->after('is_public');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['bio', 'avatar_path', 'cover_path', 'is_public', 'allow_dm'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
