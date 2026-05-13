<?php

namespace App\Models;

use App\Services\Security\HtmlSanitizer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'movie_id', 'parent_id', 'body', 'likes_count', 'is_spoiler'];

    protected $casts = [
        'is_spoiler' => 'boolean',
        'spoiler_confidence' => 'float',
        'spoiler_checked_at' => 'datetime',
    ];

    /**
     * Sanitize comment body on assignment. Storing pre-sanitized HTML
     * means downstream consumers (admin moderation queue, sentiment
     * dashboard, AI spoiler detector) all see the same trusted output
     * — and even if a future Blade template forgets to escape, there's
     * no script tag in the database to leak in the first place.
     *
     * The sanitizer preserves legitimate inline formatting like
     * <strong>, <em>, and validated <a href> while stripping every
     * dangerous tag/attribute. See {@see HtmlSanitizer}.
     */
    public function setBodyAttribute(?string $value): void
    {
        $this->attributes['body'] = app(HtmlSanitizer::class)->sanitize($value);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movie()
    {
        return $this->belongsTo(Movie::class);
    }

    public function parent()
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(Comment::class, 'parent_id')->latest();
    }

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }
}
