<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per "was this article helpful?" vote.
 *
 * Voting rules (enforced in HelpController::feedback):
 *   - Authenticated users: 1 vote per article (unique index on
 *     `(help_article_id, user_id)`).
 *   - Anonymous users: 1 vote per article per IP-hash (deduped in the
 *     controller against `ip_hash` because partial unique indexes aren't
 *     portable across MySQL/SQLite).
 */
class HelpArticleFeedback extends Model
{
    /**
     * Created-only ledger row — no updated_at column on this table.
     */
    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_helpful' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(HelpArticle::class, 'help_article_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
