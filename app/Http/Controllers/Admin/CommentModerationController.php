<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ModerateComment;
use App\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin review UI for AI-moderated comments.
 *
 * Workflow:
 * - LLM auto-flags comments via App\Services\Ai\Tasks\CommentModerator
 * - Anything not status=approved lands in the moderation queue
 * - Admins approve (restore visibility) or reject (hard-hide)
 */
class CommentModerationController extends Controller
{
    /**
     * Show the moderation queue (flagged + pending + rejected).
     */
    public function queue(Request $request): View
    {
        $status = $request->query('status', 'flagged');
        $validStatuses = ['flagged', 'pending', 'rejected', 'approved', 'all'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'flagged';
        }

        $query = Comment::query()
            ->with(['user:id,name,email', 'movie:id,title,slug'])
            ->latest('moderated_at')
            ->latest('id');

        if ($status !== 'all') {
            $query->where('moderation_status', $status);
        }

        $comments = $query->paginate(25)->withQueryString();

        $counts = [
            'flagged' => Comment::where('moderation_status', 'flagged')->count(),
            'pending' => Comment::where('moderation_status', 'pending')->count(),
            'rejected' => Comment::where('moderation_status', 'rejected')->count(),
            'approved' => Comment::where('moderation_status', 'approved')->count(),
        ];

        return view('admin.comments.queue', [
            'comments' => $comments,
            'status' => $status,
            'counts' => $counts,
        ]);
    }

    /**
     * Approve a flagged comment (restore visibility).
     */
    public function approve(Comment $comment): RedirectResponse
    {
        $comment->forceFill([
            'moderation_status' => 'approved',
            'is_visible' => true,
            'moderated_at' => now(),
        ])->save();

        return back()->with('success', 'Comment #' . $comment->id . ' approved.');
    }

    /**
     * Reject a comment (hide permanently).
     */
    public function reject(Comment $comment): RedirectResponse
    {
        $comment->forceFill([
            'moderation_status' => 'rejected',
            'is_visible' => false,
            'moderated_at' => now(),
        ])->save();

        return back()->with('success', 'Comment #' . $comment->id . ' rejected.');
    }

    /**
     * Re-run the AI moderator against a single comment (useful after prompt tweaks).
     */
    public function rerun(Comment $comment): RedirectResponse
    {
        ModerateComment::dispatch($comment->id);

        return back()->with('success', 'Re-moderation queued for comment #' . $comment->id . '.');
    }
}
