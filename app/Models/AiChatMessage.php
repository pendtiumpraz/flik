<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in an AI chat thread. Stores both sides (user + bot) so the
 * full transcript can be replayed on widget reopen + fed back to the model
 * for context continuity.
 */
class AiChatMessage extends Model
{
    use HasFactory;

    public const UPDATED_AT = null; // append-only — only created_at

    protected $fillable = [
        'ai_chat_session_id',
        'user_id',
        'role',
        'text',
        'provider',
        'model',
        'used_web_search',
        'web_sources',
        'context_films',
    ];

    protected $casts = [
        'used_web_search' => 'boolean',
        'web_sources' => 'array',
        'context_films' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiChatSession::class, 'ai_chat_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
