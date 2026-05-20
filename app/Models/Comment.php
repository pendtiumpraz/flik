<?php

namespace App\Models;

use App\Services\Security\HtmlSanitizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'movie_id', 'parent_id', 'body', 'likes_count', 'is_spoiler'];

    protected $casts = [
        'is_spoiler' => 'boolean',
        'spoiler_confidence' => 'float',
        'spoiler_checked_at' => 'datetime',
        'reactions_count' => 'integer',
    ];

    /**
     * Sanitize comment body on assignment. Storing pre-sanitized HTML
     * means downstream consumers (admin moderation queue, sentiment
     * dashboard, AI spoiler detector) all see the same trusted output
     * — and even if a future Blade template forgets to escape, there's
     * no script tag in the database to leak in the first place.
     *
     * The sanitizer preserves legitimate inline formatting like
     * <strong>, <em>, and validated <a href> while stripping every
     * dangerous tag/attribute. See {@see HtmlSanitizer}.
     */
    public function setBodyAttribute(?string $value): void
    {
        $this->attributes['body'] = app(HtmlSanitizer::class)->sanitize($value);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movie()
    {
        return $this->belongsTo(Movie::class);
    }

    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id')->latest();
    }

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * All emoji reactions left on this comment by any user.
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }

    /**
     * Cache key for the per-comment reaction count map. Bumped by the
     * observer on every write so consumers always see a fresh value.
     */
    public function reactionsCacheKey(): string
    {
        return 'comment:' . $this->getKey() . ':reactions_by_type';
    }

    /**
     * Reaction-type → count map, 5 minute cache. Missing types are
     * omitted (callers should default to 0). Backs the pill counts in
     * the comment Blade and the broadcast payload.
     *
     * @return array<string, int>
     */
    public function reactionsByType(): array
    {
        return Cache::remember(
            $this->reactionsCacheKey(),
            now()->addMinutes(5),
            function (): array {
                /** @var array<int, object{reaction: string, count: int}> $rows */
                $rows = $this->reactions()
                    ->select('reaction', DB::raw('COUNT(*) as count'))
                    ->groupBy('reaction')
                    ->get()
                    ->all();

                $out = [];
                foreach ($rows as $row) {
                    $out[(string) $row->reaction] = (int) $row->count;
                }

                return $out;
            }
        );
    }

    /**
     * Returns the reaction name the given user left on this comment, or
     * null if they have not reacted (or are not authenticated). Used by
     * the Blade to highlight the user's active pill.
     */
    public function reactionByUser(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $row = $this->reactions()
            ->where('user_id', $user->getAuthIdentifier())
            ->first(['reaction']);

        return $row?->reaction;
    }

    /**
     * Toggle the given user's reaction on this comment.
     *
     *   - no existing reaction → create
     *   - same reaction already exists → delete (toggle off)
     *   - different reaction exists → update to new value
     *
     * Returns the post-toggle state so the controller can echo it back
     * to the client without an additional round-trip:
     *
     *   ['counts' => ['love' => 12, ...], 'user' => 'love'|null]
     *
     * The observer (CommentReactionObserver) takes care of recomputing
     * reactions_count / top_reaction and busting the per-comment cache.
     *
     * @return array{counts: array<string, int>, user: ?string}
     */
    public function toggleReaction(User $user, string $reaction): array
    {
        if (! in_array($reaction, CommentReaction::REACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown reaction: {$reaction}");
        }

        $existing = $this->reactions()
            ->where('user_id', $user->getAuthIdentifier())
            ->first();

        $userReaction = null;

        if ($existing === null) {
            $this->reactions()->create([
                'user_id' => $user->getAuthIdentifier(),
                'reaction' => $reaction,
            ]);
            $userReaction = $reaction;
        } elseif ($existing->reaction === $reaction) {
            // Toggle off — same reaction clicked again.
            $existing->delete();
            $userReaction = null;
        } else {
            // Swap — different reaction selected.
            $existing->update(['reaction' => $reaction]);
            $userReaction = $reaction;
        }

        // Observer already ran during create/update/delete above and
        // busted reactionsCacheKey(), so this fetch is fresh.
        return [
            'counts' => $this->reactionsByType(),
            'user' => $userReaction,
        ];
    }
}
