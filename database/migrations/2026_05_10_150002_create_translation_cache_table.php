<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-demand AI translation cache.
 *
 * Backs App\Services\Ai\Tasks\TextTranslator: every (target_locale, source_text)
 * pair is hashed (sha256) and stored once — subsequent reads short-circuit the
 * AI call so we never pay twice for the same translation.
 *
 * Lookup contract:
 *   - Unique index on (target_locale, source_hash) → O(1) cache check.
 *   - `last_used_at` is bumped on every read so an LRU sweep can evict stale
 *     entries without losing hot translations.
 *
 * NOTE: We deliberately store `source_text` in full so cache misses can be
 * batch-replayed against a different provider (e.g. re-translating Arabic
 * with a higher-quality model). The hash alone would lose that source.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translation_cache')) {
            return;
        }

        Schema::create('translation_cache', function (Blueprint $table) {
            $table->id();
            $table->string('source_locale', 5);
            $table->string('target_locale', 5);
            $table->text('source_text');
            // sha256 hex digest of canonicalised source — fixed-width column
            // makes the unique index B-tree compact.
            $table->char('source_hash', 64);
            $table->text('translation');
            // Free-form so future providers (DeepL, Google, in-house) drop
            // straight in without a schema migration.
            $table->string('provider')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_used_at')->useCurrent();

            // Cache lookup key — same source in same target reuses the row.
            $table->unique(['target_locale', 'source_hash'], 'translation_cache_lookup_unique');
            // LRU eviction sweep target.
            $table->index('last_used_at', 'translation_cache_lru_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_cache');
    }
};
