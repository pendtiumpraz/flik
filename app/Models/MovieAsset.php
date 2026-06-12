<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MovieAsset extends Model
{
    use HasFactory;

    public const TYPE_SLIDER = 'slider';     // Cinematic ~2.4:1 (1920×800)

    public const TYPE_POSTER = 'poster';     // Portrait 2:3 (500×750)

    public const TYPE_BACKDROP = 'backdrop'; // Landscape 16:9 (1920×1080)

    public const TYPES = [
        self::TYPE_SLIDER => 'Slider (Hero) — 1920×800',
        self::TYPE_POSTER => 'Poster (Card) — 500×750',
        self::TYPE_BACKDROP => 'Backdrop (Detail BG) — 1920×1080',
    ];

    protected $fillable = [
        'movie_id', 'type', 'path', 'label', 'is_active', 'sort_order', 'rotation_hours',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'rotation_hours' => 'integer',
    ];

    public function movie()
    {
        return $this->belongsTo(Movie::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOfType($q, string $type)
    {
        return $q->where('type', $type);
    }

    /**
     * Get the resolved URL (handle full URLs vs storage paths).
     */
    public function getUrlAttribute(): string
    {
        if (! $this->path) {
            return '';
        }
        if (str_starts_with($this->path, 'http')) {
            return $this->path;
        }

        return (string) \App\Support\MediaDisk::url($this->path);
    }
}
