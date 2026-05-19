<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single click event captured by the tracking redirect.
 *
 * Multiple rows per recipient are expected — every redirect hit writes one.
 * Campaign-level click_count counts UNIQUE recipients (via the recipient's
 * first_clicked_at flag); this table is the per-link breakdown that powers
 * the report page's top-links chart.
 *
 * @property int $id
 * @property int $email_recipient_id
 * @property string $original_url
 * @property \Illuminate\Support\Carbon $clicked_at
 */
class EmailLinkClick extends Model
{
    use HasFactory;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'email_recipient_id',
        'original_url',
        'clicked_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(EmailRecipient::class, 'email_recipient_id');
    }
}
