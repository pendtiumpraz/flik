<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-recipient tracking row for an email campaign.
 *
 * One row per resolved audience member, created at enqueue time by
 * App\Services\Email\CampaignDispatcher. `tracking_id` is the lookup
 * key for the unauthenticated open-pixel + click-redirect endpoints —
 * treat it as a capability token (never log/leak it).
 *
 * @property int $id
 * @property int $email_campaign_id
 * @property int|null $user_id
 * @property string $email
 * @property string $tracking_id
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $opened_at
 * @property \Illuminate\Support\Carbon|null $first_clicked_at
 * @property \Illuminate\Support\Carbon|null $bounced_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property string|null $error_reason
 */
class EmailRecipient extends Model
{
    use HasFactory;

    /**
     * SECURITY: system-controlled rows — writes only happen from the dispatcher,
     * the send job, and the public tracking controller. End users never POST
     * payloads that land here directly. We guard the tracking columns so a
     * crafted request can't backdate or zero-out an opened/clicked timestamp.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'tracking_id',
        'sent_at',
        'opened_at',
        'first_clicked_at',
        'bounced_at',
        'failed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sent_at'          => 'datetime',
        'opened_at'        => 'datetime',
        'first_clicked_at' => 'datetime',
        'bounced_at'       => 'datetime',
        'failed_at'        => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(EmailLinkClick::class);
    }
}
