<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PushMessage
 * --------------------------------------------------------------------------
 * Admin-composed Web Push broadcast. Audience encoding is documented on the
 * matching migration and resolved by {@see PushSubscription::scopeForAudience()}.
 *
 * @property int                                              $id
 * @property string                                           $title
 * @property string                                           $body
 * @property string|null                                      $icon_url
 * @property string|null                                      $badge_url
 * @property string|null                                      $action_url
 * @property string|null                                      $tag
 * @property string                                           $audience
 * @property \Illuminate\Support\Carbon|null                  $sent_at
 * @property int                                              $sent_count
 * @property int                                              $success_count
 * @property int                                              $failure_count
 * @property int|null                                         $created_by_user_id
 * @property \Illuminate\Support\Carbon|null                  $created_at
 * @property \Illuminate\Support\Carbon|null                  $updated_at
 * @property-read \App\Models\User|null                       $author
 */
class PushMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'icon_url',
        'badge_url',
        'action_url',
        'tag',
        'audience',
        'sent_at',
        'sent_count',
        'success_count',
        'failure_count',
        'created_by_user_id',
    ];

    protected $casts = [
        'sent_at'       => 'datetime',
        'sent_count'    => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
    ];

    /** @return BelongsTo<User, self> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Shape the payload the service worker will receive. Centralised here so
     * the broadcaster, test command, and future API consumers all agree.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'title'      => $this->title,
            'body'       => $this->body,
            'icon'       => $this->icon_url ?: '/img/flik-logo.png',
            'badge'      => $this->badge_url ?: '/img/flik-logo.png',
            'tag'        => $this->tag ?: ('flik-' . $this->id),
            'action_url' => $this->action_url,
            'data'       => [
                'message_id' => $this->id,
                'action_url' => $this->action_url,
            ],
        ];
    }

    public function isDelivered(): bool
    {
        return $this->sent_at !== null;
    }
}
