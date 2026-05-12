<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieAiReview extends Model
{
    use HasFactory;

    public const PERSPECTIVES = ['critic', 'casual', 'family', 'academic'];

    public const PERSPECTIVE_LABELS = [
        'critic'   => 'Kritikus Film',
        'casual'   => 'Movie Blogger',
        'family'   => 'Orang Tua',
        'academic' => 'Akademisi',
    ];

    protected $fillable = [
        'movie_id',
        'perspective',
        'title',
        'body',
        'rating',
        'provider_used',
        'generated_at',
    ];

    protected $casts = [
        'rating'       => 'decimal:1',
        'generated_at' => 'datetime',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function getPerspectiveLabelAttribute(): string
    {
        return self::PERSPECTIVE_LABELS[$this->perspective] ?? ucfirst($this->perspective);
    }
}
