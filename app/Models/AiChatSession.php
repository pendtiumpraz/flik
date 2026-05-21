<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One per-user AI chat thread. The chatbot widget always loads the user's
 * MOST RECENT session on open (continuity), then writes new messages into
 * it. The user can also explicitly start a new session via the +New button.
 */
class AiChatSession extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'title', 'messages_count', 'last_message_at'];

    protected $casts = [
        'last_message_at' => 'datetime',
        'messages_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class)->orderBy('created_at');
    }

    /**
     * Resolve the most recent session for the given user, or null when the
     * user has never chatted before.
     */
    public static function latestFor(int $userId): ?self
    {
        return self::query()
            ->where('user_id', $userId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();
    }
}
