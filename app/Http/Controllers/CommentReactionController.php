<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\CommentReaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Single-action controller for toggling a user's reaction on a comment.
 *
 * Wiring: POST /comments/{comment}/react, behind 'auth' + 'throttle:comments'
 * (same limiter as the existing comment.store route so a user can't
 * spam reactions any faster than they can spam comments). The toggle
 * state machine lives on the Comment model — see
 * {@see Comment::toggleReaction()}.
 *
 * Response shape (consumed by the Alpine factory commentReactions()):
 *
 *   {
 *     "success": true,
 *     "reaction_user": "love" | null,
 *     "counts": {"love": 12, "laugh": 3},
 *     "total": 15
 *   }
 */
class CommentReactionController extends Controller
{
    public function toggle(Request $request, Comment $comment): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            // The route middleware guarantees this, but we keep a
            // defensive 401 in case the controller is ever wired into
            // a guest-allowed context in the future.
            return response()->json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $data = $request->validate([
            'reaction' => ['required', 'string', 'in:' . implode(',', CommentReaction::REACTIONS)],
        ]);

        try {
            $state = $comment->toggleReaction($user, $data['reaction']);
        } catch (\InvalidArgumentException $e) {
            // Should never hit (validator already filtered) — log and 422.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::warning('CommentReaction toggle failed', [
                'comment_id' => $comment->id,
                'user_id' => $user->getAuthIdentifier(),
                'reaction' => $data['reaction'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Could not save reaction. Try again.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'reaction_user' => $state['user'],
            'counts' => $state['counts'],
            'total' => array_sum($state['counts']),
        ]);
    }
}
