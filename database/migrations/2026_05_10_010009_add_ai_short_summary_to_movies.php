<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (!Schema::hasColumn('movies', 'ai_short_summary')) {
                $table->text('ai_short_summary')->nullable()->after('overview');
            }

            if (!Schema::hasColumn('movies', 'ai_short_summary_generated_at')) {
                $table->timestamp('ai_short_summary_generated_at')->nullable()->after('ai_short_summary');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table) {
            if (Schema::hasColumn('movies', 'ai_short_summary_generated_at')) {
                $table->dropColumn('ai_short_summary_generated_at');
            }

            if (Schema::hasColumn('movies', 'ai_short_summary')) {
                $table->dropColumn('ai_short_summary');
            }
        });
    }
};
