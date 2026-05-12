<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YearInReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'year',
        'stats',
        'narrative',
        'generated_at',
        'shared_count',
    ];

    protected $casts = [
        'year'         => 'integer',
        'stats'        => 'array',
        'generated_at' => 'datetime',
        'shared_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Atomically bump the share counter and return the new value.
     */
    public function incrementShare(): int
    {
        $this->increment('shared_count');

        return (int) $this->shared_count;
    }
}
