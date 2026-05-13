<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Rules\CaptchaPassed;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function store(Request $request)
    {
        $this->authorize('create', Comment::class);

        // Cloudflare Turnstile is only required for high-risk comment posts:
        //   - the route group enforces auth, so there are no guests here, but
        //   - brand-new accounts (< 24h old) get CAPTCHA'd once they've posted
        //     >= 3 comments in the last hour to defeat throwaway-account spam.
        // For established accounts the rule is omitted entirely (no widget
        // rendered, no extra latency). When TURNSTILE_* env vars are unset
        // CaptchaPassed is a graceful no-op anyway.
        if ($this->requiresCaptcha($request)) {
            $request->validate([
                'cf-turnstile-response' => ['nullable', 'string', new CaptchaPassed],
            ]);
        }

        $request->validate([
            'movie_id' => 'required|exists:movies,id',
            'body' => 'required|string|max:2000',
            'parent_id' => 'nullable|exists:comments,id',
            'is_spoiler' => 'boolean',
        ]);

        Comment::create([
            'user_id' => auth()->id(),
            'movie_id' => $request->movie_id,
            'parent_id' => $request->parent_id,
            'body' => $request->body,
            'is_spoiler' => $request->boolean('is_spoiler'),
        ]);

        return back()->with('success', 'Komentar berhasil ditambahkan!');
    }

    public function destroy(Comment $comment)
    {
        // CommentPolicy::delete() — owner only, admin override via Gate::before.
        $this->authorize('delete', $comment);

        $comment->delete();
        return back()->with('success', 'Komentar dihapus!');
    }

    /**
     * True when the current user is "fresh" enough to warrant a CAPTCHA on
     * comment submission. Heuristic:
     *   - account created within the past 24 hours, AND
     *   - has already posted >= 3 comments in the last hour.
     *
     * Catches the typical spam pattern (mass-register, hammer comments)
     * without inconveniencing legitimate long-time users. The matching
     * predicate is also exposed to the view so the Blade form can decide
     * whether to render the Turnstile widget — see
     * {@see \App\View\Composers\CommentCaptchaComposer} if/when added; for
     * now the component itself self-suppresses when the user is established.
     */
    private function requiresCaptcha(Request $request): bool
    {
        $user = $request->user();
        if ($user === null) {
            // Defence-in-depth: route group already requires auth, but if the
            // controller is ever wired in a guest-allowed context, force the
            // CAPTCHA so we don't accidentally regress.
            return true;
        }

        // Brand-new accounts only. `created_at` may be null in unit tests.
        if ($user->created_at === null || $user->created_at->lt(now()->subDay())) {
            return false;
        }

        $recentCount = Comment::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('created_at', '>=', now()->subHour())
            ->count();

        return $recentCount >= 3;
    }
}
