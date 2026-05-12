<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'favorite_genres',
        'favorite_eras',
        'preferred_moods',
        'language_preference',
        'onboarded_at',
    ];

    protected $casts = [
        'favorite_genres'  => 'array',
        'favorite_eras'    => 'array',
        'preferred_moods'  => 'array',
        'onboarded_at'     => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
