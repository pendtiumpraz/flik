<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Augment `comments` with AI spoiler-detection columns.
 *
 * Note: `is_spoiler` already exists (created in 2026_03_07_100004) as a user-self-flag.
 * The AI detector overwrites the same column when it disagrees, while the two new
 * columns record confidence + check timestamp so we can re-run / audit.
 *
 * Idempotent: every column add is guarded by `Schema::hasColumn`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            // `is_spoiler` already exists from the baseline migration. Only add it
            // if a future fresh-install ever ships without it (defensive).
            if (! Schema::hasColumn('comments', 'is_spoiler')) {
                $table->boolean('is_spoiler')->default(false);
            }

            if (! Schema::hasColumn('comments', 'spoiler_confidence')) {
                $table->decimal('spoiler_confidence', 4, 3)
                    ->nullable()
                    ->after('is_spoiler');
            }

            if (! Schema::hasColumn('comments', 'spoiler_checked_at')) {
                $table->timestamp('spoiler_checked_at')
                    ->nullable()
                    ->after('spoiler_confidence');
            }
        });

        // Index `is_spoiler` for moderation queue / public list filters.
        // Guard against re-runs by inspecting the schema manager.
        $indexes = collect(Schema::getIndexes('comments'))
            ->pluck('name')
            ->all();

        if (! in_array('comments_is_spoiler_index', $indexes, true)) {
            Schema::table('comments', function (Blueprint $table) {
                $table->index('is_spoiler');
            });
        }
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $indexes = collect(Schema::getIndexes('comments'))
                ->pluck('name')
                ->all();

            if (in_array('comments_is_spoiler_index', $indexes, true)) {
                $table->dropIndex(['is_spoiler']);
            }

            if (Schema::hasColumn('comments', 'spoiler_checked_at')) {
                $table->dropColumn('spoiler_checked_at');
            }

            if (Schema::hasColumn('comments', 'spoiler_confidence')) {
                $table->dropColumn('spoiler_confidence');
            }
            // Do NOT drop `is_spoiler` — it predates this migration.
        });
    }
};
