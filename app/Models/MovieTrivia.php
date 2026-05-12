<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieTrivia extends Model
{
    use HasFactory;

    protected $table = 'movie_trivia';

    public const CATEGORY_PRODUCTION    = 'production';
    public const CATEGORY_CAST          = 'cast';
    public const CATEGORY_RECEPTION     = 'reception';
    public const CATEGORY_BEHIND_SCENES = 'behind_scenes';
    public const CATEGORY_EASTER_EGG    = 'easter_egg';
    public const CATEGORY_CULTURAL      = 'cultural';

    public const CATEGORIES = [
        self::CATEGORY_PRODUCTION,
        self::CATEGORY_CAST,
        self::CATEGORY_RECEPTION,
        self::CATEGORY_BEHIND_SCENES,
        self::CATEGORY_EASTER_EGG,
        self::CATEGORY_CULTURAL,
    ];

    protected $fillable = [
        'movie_id',
        'fact',
        'category',
        'sort_order',
        'source_url',
        'is_verified',
    ];

    protected $casts = [
        'movie_id'    => 'integer',
        'sort_order'  => 'integer',
        'is_verified' => 'boolean',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function scopeVerified($q)
    {
        return $q->where('is_verified', true);
    }

    public function scopeOfCategory($q, string $category)
    {
        return $q->where('category', $category);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }
}
