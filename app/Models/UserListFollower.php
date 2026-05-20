<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Edge: a {@see User} follows a {@see UserList}.
 *
 * Writes are routed through {@see UserList::follow} / unfollow so the
 * parent's denormalised `followers_count` stays in sync.
 */
class UserListFollower extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function list(): BelongsTo
    {
        return $this->belongsTo(UserList::class, 'user_list_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
