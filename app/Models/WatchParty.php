<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class WatchParty extends Model
{
    use HasFactory;

    protected $fillable = [
        'host_id',
        'movie_id',
        'room_code',
        'current_position_seconds',
        'is_playing',
        'started_at',
        'last_updated_at',
        'ended_at',
        'max_members',
    ];

    protected $casts = [
        'current_position_seconds' => 'decimal:3',
        'is_playing' => 'boolean',
        'started_at' => 'datetime',
        'last_updated_at' => 'datetime',
        'ended_at' => 'datetime',
        'max_members' => 'integer',
    ];

    // ── Route binding via room_code ────────────────────────────
    public function getRouteKeyName(): string
    {
        return 'room_code';
    }

    // ── Relations ──────────────────────────────────────────────
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(WatchPartyMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(WatchPartyMember::class)->whereNull('left_at');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'watch_party_members')
            ->withPivot('joined_at', 'left_at')
            ->withTimestamps();
    }

    // ── Scopes ─────────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }

    // ── Helpers ────────────────────────────────────────────────
    public function isHost(?int $userId): bool
    {
        return $userId !== null && (int) $this->host_id === (int) $userId;
    }

    public function isFull(): bool
    {
        return $this->activeMembers()->count() >= (int) $this->max_members;
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    /**
     * True when the party has not been ended. Convenience inverse of
     * {@see hasEnded()} so views can read more naturally:
     * `@if($party->isActive()) ... @endif`.
     */
    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Generate a unique 8-char room code (uppercase alnum, ambiguous chars stripped).
     */
    public static function generateRoomCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I

        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('room_code', $code)->exists());

        return $code;
    }
}
