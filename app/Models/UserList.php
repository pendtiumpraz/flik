<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * User-curated, shareable movie list ("playlist for films").
 *
 * Distinct concept from {@see Watchlist} — that is a strictly-private flat
 * "movies I want to watch later" pivot, scoped to one user with no metadata.
 * A UserList has a title, description, optional cover, visibility,
 * follower graph, and manual ordering of items.
 *
 * SECURITY:
 *   - $guarded explicitly EXCLUDES the three denormalised counter columns
 *     (items_count, followers_count, views_count) so a forged form payload
 *     cannot inflate a list's apparent popularity. Counter writes only
 *     happen through the helper methods on this class via forceFill().
 *   - Visibility is enforced at TWO layers: (a) the {@see UserListPolicy}
 *     guards CRUD/follow actions; (b) the {@see UserList::isVisibleTo}
 *     helper drives view-level rendering. Never bypass both.
 *
 * @property int $id
 * @property int $user_id
 * @property string $slug
 * @property string $title
 * @property ?string $description
 * @property ?int $cover_movie_id
 * @property string $visibility
 * @property bool $is_featured
 * @property int $items_count
 * @property int $followers_count
 * @property int $views_count
 */
class UserList extends Model
{
    use HasFactory;

    /**
     * Counters are system-managed via helper methods on this model — they
     * MUST NOT be mass-assigned from controllers. user_id is also blocked
     * so an authed user can't "donate" a list to another account by
     * smuggling user_id in the form payload (the controller sets it
     * explicitly via the relation).
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'items_count',
        'followers_count',
        'views_count',
        'user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_featured' => 'boolean',
        'items_count' => 'integer',
        'followers_count' => 'integer',
        'views_count' => 'integer',
    ];

    /** Three-state visibility taxonomy — keep in sync with the enum migration. */
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_UNLISTED = 'unlisted';

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_UNLISTED,
        self::VISIBILITY_PRIVATE,
    ];

    /**
     * Routes use the slug, scoped under the username segment. Laravel's
     * implicit binding does the lookup; the {@see UserListController::show}
     * resolves the user-scoped binding to keep slugs unique per owner.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Relations ────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Items in display order. The (user_list_id, position) index makes this
     * a single-key range scan with no filesort.
     */
    public function items(): HasMany
    {
        return $this->hasMany(UserListItem::class)->orderBy('position');
    }

    /**
     * Many-to-many through the items pivot. The `position` pivot column is
     * deliberately exposed so views can render items without two queries.
     */
    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'user_list_items')
            ->withPivot('position', 'note', 'added_at')
            ->withTimestamps()
            ->orderBy('user_list_items.position');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Movie::class, 'cover_movie_id');
    }

    /**
     * Followers (users who have subscribed to this list).
     */
    public function followers(): HasMany
    {
        return $this->hasMany(UserListFollower::class);
    }

    public function followerUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_list_followers')
            ->withTimestamps();
    }

    // ── Scopes ───────────────────────────────────────────────────

    /** Only public lists — appears on /lists browse page. */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_PUBLIC);
    }

    /**
     * "Listed" = public OR unlisted. Used by share-link reads where the
     * direct URL is the entry point and we do NOT want to leak private
     * lists, but unlisted lists are reachable to anyone with the link.
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query->whereIn('visibility', [self::VISIBILITY_PUBLIC, self::VISIBILITY_UNLISTED]);
    }

    /** Featured shelf for the home page. */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * "Lists this viewer can see in a browse context" — public lists plus
     * private ones owned by the viewer. Use on collection-style endpoints
     * (index, profile tabs), NOT on direct show() reads — those go through
     * {@see isVisibleTo} so unlisted-with-link still works.
     */
    public function scopeForUser(Builder $query, ?User $viewer): Builder
    {
        if ($viewer === null) {
            return $query->where('visibility', self::VISIBILITY_PUBLIC);
        }

        return $query->where(function (Builder $q) use ($viewer) {
            $q->where('visibility', self::VISIBILITY_PUBLIC)
                ->orWhere('user_id', $viewer->id);
        });
    }

    // ── Visibility & ownership helpers ───────────────────────────

    /**
     * Authoritative visibility check for show() reads.
     *
     * Rules:
     *   - public   → anyone (including guests).
     *   - unlisted → anyone who already has the URL (we don't list it).
     *   - private  → only the owner (and super-admin via Gate::before).
     */
    public function isVisibleTo(?User $viewer): bool
    {
        if ($this->visibility === self::VISIBILITY_PUBLIC
            || $this->visibility === self::VISIBILITY_UNLISTED) {
            return true;
        }

        // Private from here on.
        if ($viewer === null) {
            return false;
        }

        return (int) $this->user_id === (int) $viewer->id
            || (method_exists($viewer, 'isSuperAdmin') && $viewer->isSuperAdmin());
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && (int) $this->user_id === (int) $user->id;
    }

    // ── Item management ──────────────────────────────────────────

    /**
     * Append (or insert at) a movie. Idempotent: if the movie is already in
     * the list, the existing row is returned without incrementing the
     * counter.
     *
     * @param  ?int  $position  When null, the movie goes to the end of the
     *                          list (current max position + 1).
     */
    public function addMovie(Movie $movie, ?string $note = null, ?int $position = null): UserListItem
    {
        return DB::transaction(function () use ($movie, $note, $position) {
            $existing = UserListItem::query()
                ->where('user_list_id', $this->id)
                ->where('movie_id', $movie->id)
                ->first();

            if ($existing !== null) {
                // Idempotent: update note when caller supplied one, otherwise
                // return as-is. No counter bump.
                if ($note !== null) {
                    $existing->update(['note' => $note]);
                }

                return $existing;
            }

            if ($position === null) {
                $max = (int) UserListItem::query()
                    ->where('user_list_id', $this->id)
                    ->max('position');
                $position = $max + 1;
            }

            $item = UserListItem::create([
                'user_list_id' => $this->id,
                'movie_id' => $movie->id,
                'position' => $position,
                'note' => $note,
                'added_at' => now(),
            ]);

            // forceFill — bypass $guarded for the counter write.
            $this->forceFill(['items_count' => $this->items_count + 1])->save();

            return $item;
        });
    }

    /**
     * Remove a movie from this list. Returns true when something was
     * deleted, false when the movie wasn't a member.
     */
    public function removeMovie(Movie $movie): bool
    {
        return DB::transaction(function () use ($movie) {
            $deleted = UserListItem::query()
                ->where('user_list_id', $this->id)
                ->where('movie_id', $movie->id)
                ->delete();

            if ($deleted > 0) {
                // Defensive: clamp to 0 in case a manual SQL fix or partial
                // failure left the counter ahead of reality.
                $next = max(0, $this->items_count - $deleted);
                $this->forceFill(['items_count' => $next])->save();

                return true;
            }

            return false;
        });
    }

    /**
     * Re-order items by movie ID. Movies not in the supplied array keep
     * their existing position (they fall to the bottom of the visible
     * order). Movies in `$movieIds` that aren't already in this list are
     * silently ignored (race-safe).
     *
     * @param  array<int, int>  $movieIds  Movie IDs in their new order.
     */
    public function reorder(array $movieIds): void
    {
        DB::transaction(function () use ($movieIds) {
            $position = 1;
            foreach ($movieIds as $movieId) {
                $movieId = (int) $movieId;
                if ($movieId <= 0) {
                    continue;
                }
                UserListItem::query()
                    ->where('user_list_id', $this->id)
                    ->where('movie_id', $movieId)
                    ->update(['position' => $position]);
                $position++;
            }
        });
    }

    // ── Follower graph ───────────────────────────────────────────

    /**
     * Subscribe a user to this list. Idempotent — re-following is a no-op.
     * Returns true only when a NEW follower row was created.
     */
    public function follow(User $user): bool
    {
        if (! Schema::hasTable('user_list_followers')) {
            return false;
        }

        // Owner following their own list would inflate the counter
        // pointlessly — block at the model level.
        if ($this->isOwnedBy($user)) {
            return false;
        }

        return DB::transaction(function () use ($user) {
            $existing = UserListFollower::query()
                ->where('user_list_id', $this->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing !== null) {
                return false;
            }

            UserListFollower::create([
                'user_list_id' => $this->id,
                'user_id' => $user->id,
            ]);

            $this->forceFill(['followers_count' => $this->followers_count + 1])->save();

            return true;
        });
    }

    /**
     * Unfollow. Returns the number of rows actually removed (0 or 1).
     */
    public function unfollow(User $user): int
    {
        if (! Schema::hasTable('user_list_followers')) {
            return 0;
        }

        return DB::transaction(function () use ($user) {
            $deleted = UserListFollower::query()
                ->where('user_list_id', $this->id)
                ->where('user_id', $user->id)
                ->delete();

            if ($deleted > 0) {
                $next = max(0, $this->followers_count - $deleted);
                $this->forceFill(['followers_count' => $next])->save();
            }

            return (int) $deleted;
        });
    }

    public function isFollowedBy(?User $user): bool
    {
        if ($user === null || ! Schema::hasTable('user_list_followers')) {
            return false;
        }

        return UserListFollower::query()
            ->where('user_list_id', $this->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    // ── Slug + URL helpers ───────────────────────────────────────

    /**
     * Build a slug unique to the OWNER (not globally) from a free-text
     * title. Appends `-2`, `-3`, ... when a collision exists. Called by
     * the controller when persisting create/update.
     */
    public static function generateSlugFor(User $owner, string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            // Title was all-unicode/emoji; fall back to a random token so
            // the URL is still valid.
            $base = 'list-'.Str::random(6);
        }
        $base = Str::limit($base, 80, '');

        $slug = $base;
        $suffix = 1;
        while (
            static::query()
                ->where('user_id', $owner->id)
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $suffix++;
            // Keep within the 80-char column even after appending the suffix.
            $slug = Str::limit($base, 80 - strlen('-'.$suffix), '').'-'.$suffix;
        }

        return $slug;
    }

    /**
     * Cover URL — explicit cover wins, otherwise null (the view falls back
     * to a 4-poster mosaic of the first items).
     */
    public function getCoverUrlAttribute(): ?string
    {
        return $this->cover?->effective_backdrop_url
            ?? $this->cover?->backdrop_url
            ?? $this->cover?->poster_url;
    }

    /**
     * URL to this list's public page. Owner's username is required — falls
     * back to user id when the owner hasn't claimed a handle.
     */
    public function publicUrl(): string
    {
        $userKey = $this->user?->username ?: (string) $this->user_id;

        return url('/lists/'.$userKey.'/'.$this->slug);
    }
}
