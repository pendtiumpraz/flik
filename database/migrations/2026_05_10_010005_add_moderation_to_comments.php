<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->enum('moderation_status', ['pending', 'approved', 'flagged', 'rejected'])
                ->default('approved')
                ->after('is_spoiler');
            $table->string('moderation_label')->nullable()->after('moderation_status');
            $table->decimal('moderation_score', 4, 2)->nullable()->after('moderation_label');
            $table->timestamp('moderated_at')->nullable()->after('moderation_score');
            $table->boolean('is_visible')->default(true)->after('moderated_at');

            $table->index('moderation_status');
            $table->index(['movie_id', 'is_visible']);
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['movie_id', 'is_visible']);
            $table->dropIndex(['moderation_status']);
            $table->dropColumn([
                'moderation_status',
                'moderation_label',
                'moderation_score',
                'moderated_at',
                'is_visible',
            ]);
        });
    }
};
