<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\BroadcastPushMessage;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Services\Push\WebPushSender;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PushBroadcastController
 * --------------------------------------------------------------------------
 * Admin UI for composing + dispatching Web Push broadcasts. Audience
 * encoding is documented on {@see PushMessage} and resolved server-side
 * by {@see PushSubscription::scopeForAudience()}.
 *
 * Per-route permission is `push.send` (added by RolePermissionSeeder).
 * When VAPID is not configured the controller still serves the index page
 * (so admins can see the warning) but the create form posts back with a
 * helpful error.
 */
class PushBroadcastController extends Controller
{
    public function __construct(
        private readonly WebPushSender $sender,
    ) {
    }

    public function index(): View
    {
        $messages = PushMessage::query()
            ->with('author:id,name,email')
            ->orderByDesc('id')
            ->paginate(20);

        $subscriberStats = [
            'total'         => PushSubscription::query()->count(),
            'authenticated' => PushSubscription::query()->whereNotNull('user_id')->count(),
            'anonymous'     => PushSubscription::query()->whereNull('user_id')->count(),
            'healthy'       => PushSubscription::query()->healthy()->count(),
        ];

        return view('admin.push.index', [
            'messages'        => $messages,
            'subscriberStats' => $subscriberStats,
            'pushEnabled'     => $this->sender->enabled(),
        ]);
    }

    public function create(): View
    {
        return view('admin.push.create', [
            'pushEnabled' => $this->sender->enabled(),
            'audienceOptions' => [
                'all'                => 'Everyone (anonymous + authenticated)',
                'segment:authenticated' => 'Authenticated users only',
                'segment:anonymous'  => 'Anonymous visitors only',
                'role:user'          => 'Role: user',
                'role:admin'         => 'Role: admin',
                'role:super_admin'   => 'Role: super_admin',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $this->sender->enabled()) {
            return back()
                ->withInput()
                ->withErrors(['vapid' => 'Web push is not configured. Run `php artisan flik:push:generate-vapid-keys` and update .env.']);
        }

        $data = $request->validate([
            'title'      => ['required', 'string', 'max:200'],
            'body'       => ['required', 'string', 'max:2000'],
            'icon_url'   => ['nullable', 'url', 'max:500'],
            'badge_url'  => ['nullable', 'url', 'max:500'],
            'action_url' => ['nullable', 'url', 'max:500'],
            'tag'        => ['nullable', 'string', 'max:40'],
            'audience'   => ['required', 'string', 'max:80', 'regex:/^(all|role:[a-z0-9_]+|user:\d+|segment:[a-z0-9_]+)$/i'],
        ]);

        $message = PushMessage::create([
            ...$data,
            'created_by_user_id' => Auth::id(),
        ]);

        BroadcastPushMessage::dispatch($message->id);

        return redirect()
            ->route('admin.push.index')
            ->with('success', "Push broadcast #{$message->id} queued — fan-out in progress.");
    }
}
