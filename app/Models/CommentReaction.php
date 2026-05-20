<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single user's reaction to a single comment.
 *
 * The (comment_id, user_id) unique constraint enforces one row per
 * (user, comment) pair — toggling is therefore an UPDATE not an INSERT.
 * See {@see Comment::toggleReaction()} for the toggle state machine.
 */
class CommentReaction extends Model
{
    use HasFactory;

    /**
     * Loose guarding — every write path (toggleReaction + the controller)
     * already explicitly whitelists `reaction`, so $guarded = [] keeps the
     * model nimble without exposing an attribute mass-assignment vector.
     */
    protected $guarded = [];

    /**
     * Canonical set of allowed reactions. New reactions added here are
     * picked up by the controller validator and the Alpine UI factory
     * automatically — no schema change required (the column is varchar
     * not enum on purpose).
     *
     * @var array<int, string>
     */
    public const REACTIONS = ['like', 'love', 'laugh', 'wow', 'sad', 'angry'];

    /**
     * Display emoji for each reaction. Used by the Blade pill bar and
     * the comment-list "top reaction" summary chip.
     *
     * @var array<string, string>
     */
    public const EMOJI = [
        'like' => "\u{1F44D}",  // thumbs up
        'love' => "\u{2764}\u{FE0F}",   // red heart
        'laugh' => "\u{1F602}", // face with tears of joy
        'wow' => "\u{1F62E}",   // face with open mouth
        'sad' => "\u{1F622}",   // crying face
        'angry' => "\u{1F621}", // pouting face
    ];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
