<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user, per-movie DRM playback session.
 *
 * Backed by migration `2026_05_10_020003_create_drm_sessions_table`.
 *
 * `session_token` is handed to the player and presented on every key
 * request. `content_key` is the AES-128 content key, stored encrypted
 * via Laravel's built-in `encrypted` cast (AES-256-CBC under APP_KEY).
 *
 * `last_key_request_at` + `key_request_count` enable rate-limiting and
 * replay-attack detection at the key delivery endpoint.
 */
class DrmSession extends Model
{
    use HasFactory;

    /**
     * SECURITY: drm_sessions stores the content_key + session_token used to
     * unlock playback. Only DrmTokenService / DrmKeyService write here. End
     * users never POST data into this table. Guarding everything closes
     * off any accidental Eloquent::create($input) path that could let an
     * attacker forge a session_token / extend expires_at.
     *
     * @var array<int, string>
     */
    protected $guarded = ['*'];

    protected $casts = [
        // Built-in symmetric encryption — APP_KEY rotation requires reencryption.
        'content_key' => 'encrypted',
        'last_key_request_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'key_request_count' => 'integer',
    ];

    protected $hidden = [
        'content_key',
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
     * Sessions that are not revoked and not yet expired.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }
}
