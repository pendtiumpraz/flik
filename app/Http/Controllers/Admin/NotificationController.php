<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Services\Notifications\AdminNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Admin Notification UI + API surface.
 *
 * All routes in this controller are mounted inside the `auth + can:admin`
 * route group (see routes/web.php), so the outer gate is already applied.
 * Per-action authorisation deliberately keeps the bare `admin` gate — a
 * notification bell that demanded a finer permission would defeat the
 * purpose of fanning out to multiple staff roles.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly AdminNotifier $notifier,
    ) {
    }

    /**
     * Paginated inbox view. Filters honoured:
     *   - `category` — exact match
     *   - `severity` — `info|warning|critical`
     *   - `state`    — `unread|read|all` (default all)
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $filters = [
            'category' => trim((string) $request->input('category', '')) ?: null,
            'severity' => in_array($request->input('severity'), AdminNotification::SEVERITIES, true)
                ? (string) $request->input('severity')
                : null,
            'state' => in_array($request->input('state'), ['unread', 'read', 'all'], true)
                ? (string) $request->input('state')
                : 'all',
        ];

        $query = AdminNotification::query()
            ->forUser($user)
            ->with(['reads' => fn ($q) => $q->where('user_id', $user->id)]);

        if ($filters['category']) {
            $query->where('category', $filters['category']);
        }
        if ($filters['severity']) {
            $query->where('severity', $filters['severity']);
        }
        if ($filters['state'] === 'unread') {
            $query->unreadFor($user);
        } elseif ($filters['state'] === 'read') {
            $query->whereHas('reads', fn ($q) => $q->where('user_id', $user->id));
        }

        $notifications = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Distinct category list for the filter chips — scoped to what
        // THIS user can see so the dropdown never offers a category that
        // would yield zero results.
        $categories = AdminNotification::query()
            ->forUser($user)
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $unreadCount = $this->notifier->unreadCountFor($user);

        return view('admin.notifications.index', [
            'notifications' => $notifications,
            'categories'    => $categories,
            'filters'       => $filters,
            'unreadCount'   => $unreadCount,
            'severities'    => AdminNotification::SEVERITIES,
        ]);
    }

    /**
     * Single-notification JSON detail. Marks the row read as a side-effect
     * because "I opened it" is the canonical read signal.
     */
    public function show(Request $request, AdminNotification $adminNotification): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        // Defence in depth: even though channels are auth-gated, an admin
        // could still craft `/admin/notifications/{id}` for a notification
        // they aren't in the audience for. Reject with 403.
        $this->authorizeAudience($adminNotification, $user);

        $this->notifier->markAsRead($adminNotification, $user);

        return response()->json([
            'id'         => $adminNotification->id,
            'category'   => $adminNotification->category,
            'title'      => $adminNotification->title,
            'message'    => $adminNotification->message,
            'severity'   => $adminNotification->severity,
            'meta'       => $adminNotification->meta,
            'action_url' => $adminNotification->action_url,
            'created_at' => optional($adminNotification->created_at)->toIso8601String(),
            'read'       => true,
        ]);
    }

    /**
     * Mark a single notification as read for the current user.
     * Returns 204 No Content per the public contract.
     */
    public function markRead(Request $request, AdminNotification $adminNotification): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->authorizeAudience($adminNotification, $user);
        $this->notifier->markAsRead($adminNotification, $user);

        return response()->noContent();
    }

    /**
     * Bulk-mark every visible-and-unread notification for the current user.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $count = $this->notifier->markAllReadFor($user);

        return response()->json(['count' => $count]);
    }

    /**
     * Polling fallback for the bell widget when broadcasting is offline.
     * Cheap, cached for 30s in the service — safe to hit every few seconds.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return response()->json([
            'count' => $this->notifier->unreadCountFor($user),
        ]);
    }

    /**
     * 403 if the given notification's audience does not include this user.
     * Belt-and-braces — channels.php already gates the websocket auth.
     */
    private function authorizeAudience(AdminNotification $n, $user): void
    {
        $matches = AdminNotification::query()
            ->forUser($user)
            ->whereKey($n->id)
            ->exists();

        abort_unless($matches, 403, 'Notification not addressed to your role.');
    }
}
