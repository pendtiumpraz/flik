<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single directed edge in the follower graph.
 *
 * Most call sites should NOT touch this model directly — use the
 * {@see \App\Models\Concerns\Follows} trait methods (`follow`, `unfollow`,
 * `isFollowing`) which encapsulate idempotency, cache invalidation, and
 * the self-follow guard. This model is exposed mainly for query scopes
 * (e.g. activity-feed JOINs on `follower_id`).
 */
class Follow extends Model
{
    use HasFactory;

    protected $fillable = ['follower_id', 'followed_id'];

    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    public function followed(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_id');
    }
}
