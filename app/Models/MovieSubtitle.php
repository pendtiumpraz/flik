<?php

namespace App\Models;

use App\Services\Ai\Subtitle\LanguageCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MovieSubtitle extends Model
{
    use HasFactory;

    protected $fillable = [
        'movie_id', 'episode_id', 'language_code', 'label', 'webvtt_path', 'disk',
        'is_auto_generated', 'is_translated', 'source_language',
        'generator_model', 'variant', 'status', 'error_message',
        'cue_count', 'duration_seconds', 'cost_usd', 'is_default', 'is_active',
    ];

    protected $casts = [
        'is_auto_generated' => 'boolean',
        'is_translated' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'cost_usd' => 'decimal:6',
    ];

    public function movie()
    {
        return $this->belongsTo(Movie::class);
    }

    public function episode()
    {
        return $this->belongsTo(Episode::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->where('status', 'ready');
    }

    /**
     * Get language metadata from catalog.
     */
    public function getLanguageMetaAttribute(): array
    {
        return LanguageCatalog::get($this->language_code) ?? [
            'name' => $this->language_code,
            'native' => $this->language_code,
            'rtl' => false,
        ];
    }

    public function getNativeNameAttribute(): string
    {
        return LanguageCatalog::nativeName($this->language_code);
    }

    public function getIsRtlAttribute(): bool
    {
        return LanguageCatalog::isRtl($this->language_code);
    }

    /**
     * Public-accessible URL for the .vtt file.
     */
    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->webvtt_path);
    }
}
