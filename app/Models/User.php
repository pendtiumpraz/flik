<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'is_admin',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_admin' => 'boolean',
    ];

    // ── Mutators ──────────────────────────────────────────────
    public function setPasswordAttribute($password)
    {
        $this->attributes['password'] = bcrypt($password);
    }

    // ── Relations ─────────────────────────────────────────────
    public function watchlist()
    {
        return $this->hasMany(Watchlist::class);
    }

    public function watchlistMovies()
    {
        return $this->belongsToMany(Movie::class, 'watchlists')->withTimestamps();
    }

    public function watchHistories()
    {
        return $this->hasMany(WatchHistory::class);
    }

    public function ratings()
    {
        return $this->hasMany(Rating::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->active()->latest();
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function coins()
    {
        return $this->hasMany(Coin::class);
    }

    public function level()
    {
        return $this->hasOne(UserLevel::class);
    }

    public function achievements()
    {
        return $this->belongsToMany(Achievement::class, 'user_achievements')
            ->withPivot('unlocked_at')
            ->withTimestamps();
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class)->latest();
    }

    // ── Helpers ───────────────────────────────────────────────
    public function getCoinBalanceAttribute(): int
    {
        return Coin::balanceFor($this->id);
    }

    public function hasInWatchlist(int $movieId): bool
    {
        return $this->watchlist()->where('movie_id', $movieId)->exists();
    }

    public function hasRated(int $movieId): bool
    {
        return $this->ratings()->where('movie_id', $movieId)->exists();
    }

    public function getRatingFor(int $movieId): ?Rating
    {
        return $this->ratings()->where('movie_id', $movieId)->first();
    }

    public function getOrCreateLevel(): UserLevel
    {
        return $this->level ?? UserLevel::create([
            'user_id' => $this->id,
            'level' => 1,
            'xp' => 0,
            'total_coins' => 0,
        ]);
    }

    public function unreadNotificationCount(): int
    {
        return $this->notifications()->unread()->count();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscriptions()->active()->exists();
    }

    public function currentPlan(): ?SubscriptionPlan
    {
        $sub = $this->subscriptions()->active()->with('plan')->first();
        return $sub?->plan;
    }
}
