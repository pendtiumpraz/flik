<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_lists
 * --------------------------------------------------------------------------
 * User-curated, shareable movie lists ("playlists" for films). DISTINCT from
 * the legacy `watchlists` table which is a strictly-private "movies I want to
 * watch later" flat list per user. A `user_list` is:
 *
 *   - sharable (visibility: public | unlisted | private)
 *   - follow-able (other users can subscribe to it)
 *   - ordered (manual reorder via the items table)
 *   - branded (title + description + optional cover_movie)
 *
 * Counters (`items_count`, `followers_count`, `views_count`) are denormalised
 * for cheap card-grid rendering. The model writes them on every add/remove
 * and follow/unfollow under a transaction; never lazy-counted at read time.
 *
 * Slug uniqueness is per-USER, not global — two users may both own a list
 * called "Best of 2025". The URL is /lists/{user:username}/{list:slug} so
 * the username scope keeps URLs distinct.
 *
 * Indexes:
 *   - (user_id, slug) UNIQUE — drives the route-model-binding lookup.
 *   - (visibility, items_count) — drives the "popular public lists" filter
 *     on the public index page (visibility=public ORDER BY items_count DESC).
 *   - (is_featured) — drives the home-page curated-lists shelf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_lists', function (Blueprint $table) {
            $table->id();

            // Owner. Cascade on delete: a removed account also wipes their
            // public lists (GDPR-friendly + avoids orphaned URLs).
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // 80 chars is enough room for a long-form slug like
            // "the-definitive-ranking-of-indonesian-horror-2010-2025".
            $table->string('slug', 80);

            // 160 chars matches Twitter-ish title length. The UI form caps
            // at 120 but we leave headroom for emoji + unicode expansion.
            $table->string('title', 160);

            $table->text('description')->nullable();

            // Optional hand-picked cover poster. nullOnDelete so deleting a
            // movie does not delete the lists that featured it as cover —
            // those simply fall back to the 4-poster mosaic.
            $table->foreignId('cover_movie_id')
                ->nullable()
                ->constrained('movies')
                ->nullOnDelete();

            // Three-state visibility instead of a plain bool so we can later
            // add 'friends_only' without another migration. Enum is cheaper
            // than a separate lookup table for a closed taxonomy.
            $table->enum('visibility', ['public', 'unlisted', 'private'])
                ->default('public');

            // Admin-curated promotion. The home-page shelf prefers featured
            // lists; falls back to most-followed when none are featured.
            $table->boolean('is_featured')->default(false);

            // Denormalised counters — see class docblock for the contract.
            $table->integer('items_count')->default(0);
            $table->integer('followers_count')->default(0);
            $table->integer('views_count')->default(0);

            $table->timestamps();

            // Per-user slug uniqueness. The URL pattern is
            // /lists/{user:username}/{list:slug}; the username segment
            // disambiguates across users so the unique key only needs to
            // hold within a single owner.
            $table->unique(['user_id', 'slug'], 'user_lists_user_slug_unique');

            // Popularity-sorted public browse query.
            $table->index(['visibility', 'items_count']);

            // Featured-shelf lookup.
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_lists');
    }
};
