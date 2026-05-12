<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRecommendation extends Model
{
    use HasFactory;

    public const SOURCE_CONTENT_BASED = 'content_based';
    public const SOURCE_COLLABORATIVE = 'collaborative';
    public const SOURCE_AI_CURATED    = 'ai_curated';

    protected $fillable = [
        'user_id',
        'movie_id',
        'score',
        'reason',
        'source',
        'batch_id',
        'generated_at',
    ];

    protected $casts = [
        'score'        => 'decimal:3',
        'generated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Scope: latest batch for a given user.
     */
    public function scopeLatestBatchFor($query, int $userId)
    {
        $latestBatch = static::where('user_id', $userId)
            ->orderByDesc('generated_at')
            ->value('batch_id');

        return $query->where('user_id', $userId)
            ->where('batch_id', $latestBatch)
            ->orderByDesc('score');
    }
}
