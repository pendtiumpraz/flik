<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Email campaign — a single bulk-send broadcast.
 *
 * Lifecycle:
 *   draft → queued → sending → sent
 *           ↓
 *        cancelled
 *
 * Drafts are the only status that allow edits; the admin UI enforces this.
 * `audience_estimated` is a snapshot at create time — recompute via
 * /admin/email-campaigns/preview-audience for a fresh count.
 *
 * Counters (send_count/open_count/click_count/bounce_count) are denormalised
 * aggregates kept in sync by SendCampaignEmail + EmailTrackingController so
 * the index view can render funnel %ages without a per-row aggregate query.
 *
 * @property int $id
 * @property string $name
 * @property string $subject
 * @property string|null $preheader
 * @property string $html_body
 * @property string|null $plain_body
 * @property array<string, mixed> $segment_definition
 * @property int $audience_estimated
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $scheduled_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property int $send_count
 * @property int $open_count
 * @property int $click_count
 * @property int $bounce_count
 * @property int|null $created_by_user_id
 */
class EmailCampaign extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_QUEUED,
        self::STATUS_SENDING,
        self::STATUS_SENT,
        self::STATUS_CANCELLED,
    ];

    /**
     * SECURITY: writes come from the admin EmailCampaignController only.
     * `status`, `sent_at`, and the four counter columns are system-owned —
     * the controller never mass-assigns them. Use $guarded so a forged
     * form payload can't, e.g., force-mark a campaign as 'sent' to
     * skip the dispatcher.
     *
     * @var array<int, string>
     */
    protected $guarded = [
        'id',
        'status',
        'sent_at',
        'send_count',
        'open_count',
        'click_count',
        'bounce_count',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'segment_definition'  => 'array',
        'audience_estimated'  => 'integer',
        'scheduled_at'        => 'datetime',
        'sent_at'             => 'datetime',
        'send_count'          => 'integer',
        'open_count'          => 'integer',
        'click_count'         => 'integer',
        'bounce_count'        => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailRecipient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // ── Helpers ───────────────────────────────────────────────

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_SENDING], true);
    }

    /**
     * Open-rate as a fraction of total sends, 0-100.
     */
    public function openRate(): float
    {
        if ($this->send_count <= 0) {
            return 0.0;
        }
        return round(($this->open_count / $this->send_count) * 100, 1);
    }

    /**
     * Click-through rate as a fraction of total sends, 0-100.
     */
    public function clickRate(): float
    {
        if ($this->send_count <= 0) {
            return 0.0;
        }
        return round(($this->click_count / $this->send_count) * 100, 1);
    }
}
