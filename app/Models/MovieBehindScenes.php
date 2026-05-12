<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One narrative section of a film's "Behind the Scenes" article.
 * Up to 6 rows per movie (one per SECTIONS value).
 */
class MovieBehindScenes extends Model
{
    use HasFactory;

    protected $table = 'movie_behind_scenes';

    public const SECTION_PRODUCTION      = 'production';
    public const SECTION_CASTING         = 'casting';
    public const SECTION_FILMING         = 'filming';
    public const SECTION_POST_PRODUCTION = 'post_production';
    public const SECTION_RECEPTION       = 'reception';
    public const SECTION_LEGACY          = 'legacy';

    public const SECTIONS = [
        self::SECTION_PRODUCTION,
        self::SECTION_CASTING,
        self::SECTION_FILMING,
        self::SECTION_POST_PRODUCTION,
        self::SECTION_RECEPTION,
        self::SECTION_LEGACY,
    ];

    /**
     * Indonesian display labels for UI tabs / headings.
     *
     * @var array<string,string>
     */
    public const SECTION_LABELS = [
        self::SECTION_PRODUCTION      => 'Produksi',
        self::SECTION_CASTING         => 'Casting',
        self::SECTION_FILMING         => 'Syuting',
        self::SECTION_POST_PRODUCTION => 'Pasca-Produksi',
        self::SECTION_RECEPTION       => 'Penerimaan',
        self::SECTION_LEGACY          => 'Warisan',
    ];

    protected $fillable = [
        'movie_id',
        'section',
        'title',
        'content',
        'source_urls',
        'sort_order',
        'generated_at',
    ];

    protected $casts = [
        'movie_id'     => 'integer',
        'sort_order'   => 'integer',
        'source_urls'  => 'array',
        'generated_at' => 'datetime',
    ];

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function scopeOfSection($q, string $section)
    {
        return $q->where('section', $section);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Indonesian label for the section enum value.
     */
    public function getSectionLabelAttribute(): string
    {
        return self::SECTION_LABELS[$this->section] ?? ucfirst((string) $this->section);
    }
}
