<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieQuote extends Model
{
    use HasFactory;

    protected $fillable = [
        'movie_id',
        'language_code',
        'quote',
        'translation',
        'character_name',
        'timestamp_seconds',
        'context',
        'share_count',
    ];

    protected $casts = [
        'timestamp_seconds' => 'decimal:3',
        'share_count' => 'integer',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /**
     * Scope quotes for a given language code (defaults to 'id').
     */
    public function scopeLang($query, string $languageCode = 'id')
    {
        return $query->where('language_code', $languageCode);
    }

    /**
     * Atomically bump the share counter.
     */
    public function incrementShare(): int
    {
        return $this->increment('share_count');
    }
}
