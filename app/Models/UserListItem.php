<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot row tying a {@see UserList} to a {@see Movie} with per-item
 * metadata (manual ordering position + owner's free-text note).
 *
 * All writes go through {@see UserList::addMovie} / removeMovie / reorder
 * so the parent's denormalised `items_count` stays in sync. Writing rows
 * directly here is fine for migrations/seeders but will skew the counter.
 */
class UserListItem extends Model
{
    use HasFactory;

    /** Item rows have no untrusted external inputs — mass-assign is fine. */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'added_at' => 'datetime',
        'position' => 'integer',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(UserList::class, 'user_list_id');
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }
}
