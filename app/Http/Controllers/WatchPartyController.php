<?php

namespace App\Http\Controllers;

use App\Events\WatchPartyChat;
use App\Events\WatchPartySync;
use App\Models\Movie;
use App\Models\WatchParty;
use App\Models\WatchPartyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Watch Party rooms — host owns playback, members follow over Pusher.
 *
 * Pusher is treated as optional infrastructure: when BROADCAST_DRIVER is
 * not `pusher` (or PUSHER_APP_ID is unset) the UI surfaces a "feature
 * requires Pusher setup" notice rather than 500-ing on event dispatch.
 */
class WatchPartyController extends Controller
{
    /**
     * Cheap env probe so every action can short-circuit when Pusher
     * isn't configured. We accept any non-null broadcaster the app may
     * be using (pusher/ably/redis) — only `null`/`log` count as "off".
     */
    private function pusherConfigured(): bool
    {
        $driver = config('broadcasting.default');

        if (in_array($driver, ['null', 'log'], true)) {
            return false;
        }

        if ($driver === 'pusher') {
            return ! empty(config('broadcasting.connections.pusher.app_id'))
                && ! empty(config('broadcasting.connections.pusher.key'));
        }

        // ably/redis: trust the configured driver
        return true;
    }

    /**
     * GET /watch-party/create/{movie} — render the "Mulai Watch Party" form.
     *
     * Tiny page: just a confirm button (+ optional max_members slider) so the
     * host can review the movie poster before generating a room code.
     */
    public function createForm(Movie $movie): View
    {
        return view('watch-party.create', [
            'movie' => $movie->loadMissing('genres'),
            'pusherEnabled' => $this->pusherConfigured(),
        ]);
    }

    /**
     * GET /watch-party/join — render the "enter room code" form.
     *
     * Accepts an optional `?code=ABCD1234` query string so invite links
     * can pre-fill the input.
     */
    public function joinForm(Request $request): View
    {
        return view('watch-party.join', [
            'prefillCode' => strtoupper(trim((string) $request->query('code', ''))),
            'pusherEnabled' => $this->pusherConfigured(),
        ]);
    }

    /**
     * POST /watch-party/join — accept room_code from the join form and
     * redirect into the room. The actual membership row is created on the
     * room view (so deep-linked invitees behave the same way).
     */
    public function joinByCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room_code' => 'required|string|size:8',
        ]);

        $code = strtoupper($data['room_code']);

        $party = WatchParty::where('room_code', $code)->first();

        if (! $party) {
            return back()
                ->withInput()
                ->with('error', 'Room code tidak ditemukan.');
        }

        if ($party->hasEnded()) {
            return back()
                ->withInput()
                ->with('error', 'Watch Party tersebut sudah berakhir.');
        }

        if ($party->isFull() && ! $party->isHost(auth()->id()) && ! $party->members()->where('user_id', auth()->id())->exists()) {
            return back()
                ->withInput()
                ->with('error', 'Watch Party sudah penuh.');
        }

        return redirect()->route('watch-party.show', ['roomCode' => $party->room_code]);
    }

    /**
     * POST /watch-party — Host creates a new room for a Movie.
     */
    public function create(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'movie_id' => 'required|integer|exists:movies,id',
            'max_members' => 'nullable|integer|min:2|max:20',
        ]);

        $movie = Movie::findOrFail($data['movie_id']);
        $hostId = auth()->id();

        $party = DB::transaction(function () use ($movie, $data, $hostId) {
            $party = WatchParty::create([
                'host_id' => $hostId,
                'movie_id' => $movie->id,
                'room_code' => WatchParty::generateRoomCode(),
                'current_position_seconds' => 0,
                'is_playing' => false,
                'started_at' => now(),
                'last_updated_at' => now(),
                'max_members' => $data['max_members'] ?? 8,
            ]);

            // Host is also a member row for uniform presence handling.
            WatchPartyMember::create([
                'watch_party_id' => $party->id,
                'user_id' => $hostId,
                'joined_at' => now(),
            ]);

            return $party;
        });

        return redirect()
            ->route('watch-party.show', ['roomCode' => $party->room_code])
            ->with('success', 'Watch Party dibuat! Bagikan kode: ' . $party->room_code);
    }

    /**
     * GET /watch-party/{roomCode} — Render the room.
     */
    public function show(string $roomCode): View|RedirectResponse
    {
        $party = WatchParty::with(['movie.genres', 'host', 'activeMembers.user'])
            ->where('room_code', $roomCode)
            ->firstOrFail();

        if ($party->hasEnded()) {
            return redirect()
                ->route('velflix.index')
                ->with('error', 'Watch Party ini sudah berakhir.');
        }

        $userId = auth()->id();
        $isHost = $party->isHost($userId);

        // Auto-add caller as a member if not already (so deep-linked invitees
        // can simply visit the URL without a separate "join" click).
        $membership = $party->members()->where('user_id', $userId)->first();

        if (! $membership) {
            if ($party->isFull()) {
                return redirect()
                    ->route('velflix.index')
                    ->with('error', 'Watch Party sudah penuh.');
            }

            WatchPartyMember::create([
                'watch_party_id' => $party->id,
                'user_id' => $userId,
                'joined_at' => now(),
            ]);
        } elseif ($membership->left_at !== null) {
            $membership->update(['left_at' => null, 'joined_at' => now()]);
        }

        return view('watch-party.show', [
            'party' => $party->fresh(['movie.genres', 'host', 'activeMembers.user']),
            'movie' => $party->movie,
            'isHost' => $isHost,
            'pusherEnabled' => $this->pusherConfigured(),
            'pusherKey' => config('broadcasting.connections.pusher.key'),
            'pusherCluster' => config('broadcasting.connections.pusher.options.cluster'),
        ]);
    }

    /**
     * POST /watch-party/{roomCode}/join — Idempotent join.
     */
    public function join(string $roomCode, Request $request): JsonResponse|RedirectResponse
    {
        $party = WatchParty::where('room_code', $roomCode)->firstOrFail();

        if ($party->hasEnded()) {
            return $this->respond($request, ['error' => 'Watch Party sudah berakhir.'], 410);
        }

        $userId = auth()->id();

        $membership = $party->members()->where('user_id', $userId)->first();

        if ($membership) {
            if ($membership->left_at !== null) {
                $membership->update(['left_at' => null, 'joined_at' => now()]);
            }
        } else {
            if ($party->isFull()) {
                return $this->respond($request, ['error' => 'Room sudah penuh.'], 403);
            }

            WatchPartyMember::create([
                'watch_party_id' => $party->id,
                'user_id' => $userId,
                'joined_at' => now(),
            ]);
        }

        $this->safeBroadcast(new WatchPartySync(
            roomCode: $party->room_code,
            action: 'join',
            position: (float) $party->current_position_seconds,
            userId: $userId,
            extra: ['user_name' => auth()->user()->name ?? 'Anonim'],
        ));

        return $this->respond($request, [
            'ok' => true,
            'room_code' => $party->room_code,
            'redirect' => route('watch-party.show', ['roomCode' => $party->room_code]),
        ]);
    }

    /**
     * POST /watch-party/{roomCode}/leave — Soft-leave (member only).
     * If the host leaves, the room is ended for everyone.
     */
    public function leave(string $roomCode, Request $request): JsonResponse|RedirectResponse
    {
        $party = WatchParty::where('room_code', $roomCode)->firstOrFail();
        $userId = auth()->id();

        if ($party->isHost($userId)) {
            $party->update(['ended_at' => now()]);

            $this->safeBroadcast(new WatchPartySync(
                roomCode: $party->room_code,
                action: 'leave',
                position: (float) $party->current_position_seconds,
                userId: $userId,
                extra: ['ended' => true, 'reason' => 'host_left'],
            ));

            return $this->respond($request, [
                'ok' => true,
                'ended' => true,
                'redirect' => route('velflix.index'),
            ]);
        }

        $party->members()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);

        $this->safeBroadcast(new WatchPartySync(
            roomCode: $party->room_code,
            action: 'leave',
            position: (float) $party->current_position_seconds,
            userId: $userId,
            extra: ['user_name' => auth()->user()->name ?? 'Anonim'],
        ));

        return $this->respond($request, [
            'ok' => true,
            'redirect' => route('velflix.index'),
        ]);
    }

    /**
     * POST /watch-party/{roomCode}/sync — Host pushes a play/pause/seek action.
     */
    public function sync(string $roomCode, Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:play,pause,seek',
            'position' => 'required|numeric|min:0',
        ]);

        $party = WatchParty::where('room_code', $roomCode)->firstOrFail();

        if ($party->hasEnded()) {
            return response()->json(['error' => 'Watch Party sudah berakhir.'], 410);
        }

        // WatchPartyPolicy::sync() — host only. We use Gate::denies (vs
        // $this->authorize) so we can return JSON rather than the default
        // 403 redirect that Authorize throws.
        if (! \Gate::forUser($request->user())->allows('sync', $party)) {
            return response()->json(['error' => 'Hanya host yang boleh mengontrol playback.'], 403);
        }

        $party->update([
            'current_position_seconds' => $data['position'],
            'is_playing' => $data['action'] === 'play',
            'last_updated_at' => now(),
        ]);

        if (! $this->pusherConfigured()) {
            return response()->json([
                'ok' => true,
                'broadcasted' => false,
                'warning' => 'Pusher belum dikonfigurasi — peserta lain tidak akan melihat perubahan playback.',
            ]);
        }

        $this->safeBroadcast(new WatchPartySync(
            roomCode: $party->room_code,
            action: $data['action'],
            position: (float) $data['position'],
            userId: auth()->id(),
        ));

        return response()->json([
            'ok' => true,
            'broadcasted' => true,
        ]);
    }

    /**
     * POST /watch-party/{roomCode}/chat — Quick text broadcast.
     */
    public function chat(string $roomCode, Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:500',
        ]);

        $party = WatchParty::where('room_code', $roomCode)->firstOrFail();

        if ($party->hasEnded()) {
            return response()->json(['error' => 'Watch Party sudah berakhir.'], 410);
        }

        // WatchPartyPolicy::chat() — host or active member. Same JSON-vs-
        // redirect rationale as sync(): use Gate::allows manually.
        if (! \Gate::forUser($request->user())->allows('chat', $party)) {
            return response()->json(['error' => 'Bukan anggota room.'], 403);
        }

        $userId = auth()->id();

        if (! $this->pusherConfigured()) {
            return response()->json([
                'ok' => true,
                'broadcasted' => false,
                'warning' => 'Chat membutuhkan setup Pusher.',
            ]);
        }

        $message = trim($data['message']);
        $userName = auth()->user()->name ?? 'Anonim';

        // Dispatch dedicated chat event (cleaner separation from playback sync).
        // Also fan out via WatchPartySync action=chat for backwards compat with
        // the existing watch-party.js client that listens on a single binding.
        $this->safeBroadcastEvent(new WatchPartyChat(
            roomCode: $party->room_code,
            message: $message,
            userName: $userName,
            userId: $userId,
        ));

        $this->safeBroadcast(new WatchPartySync(
            roomCode: $party->room_code,
            action: 'chat',
            position: (float) $party->current_position_seconds,
            userId: $userId,
            extra: [
                'message' => $message,
                'user_name' => $userName,
            ],
        ));

        return response()->json(['ok' => true, 'broadcasted' => true]);
    }

    /**
     * POST /watch-party/{roomCode}/end — Host terminates the room.
     *
     * Distinct from `leave` which only soft-leaves a member; this always
     * marks the entire room as ended (host-only). Members get an `ended`
     * sync event so their players can detach + redirect.
     */
    public function end(string $roomCode, Request $request): JsonResponse|RedirectResponse
    {
        $party = WatchParty::where('room_code', $roomCode)->firstOrFail();

        // WatchPartyPolicy::end() — host only.
        if (! \Gate::forUser($request->user())->allows('end', $party)) {
            return $this->respond($request, ['error' => 'Hanya host yang boleh mengakhiri Watch Party.'], 403);
        }

        if (! $party->hasEnded()) {
            $party->update(['ended_at' => now()]);

            $this->safeBroadcast(new WatchPartySync(
                roomCode: $party->room_code,
                action: 'leave',
                position: (float) $party->current_position_seconds,
                userId: auth()->id(),
                extra: ['ended' => true, 'reason' => 'host_ended'],
            ));
        }

        return $this->respond($request, [
            'ok' => true,
            'ended' => true,
            'redirect' => route('velflix.index'),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * Dispatch a broadcast event, swallowing connection errors so a flaky
     * Pusher endpoint never breaks the HTTP response. Errors are logged.
     */
    private function safeBroadcast(WatchPartySync $event): void
    {
        if (! $this->pusherConfigured()) {
            return;
        }

        try {
            broadcast($event);
        } catch (\Throwable $e) {
            \Log::warning('WatchParty broadcast failed', [
                'room' => $event->roomCode,
                'action' => $event->action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return JSON for AJAX callers, redirect for HTML form callers.
     */
    private function respond(Request $request, array $payload, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json($payload, $status);
        }

        if (isset($payload['redirect'])) {
            return redirect($payload['redirect'])
                ->with(isset($payload['error']) ? 'error' : 'success', $payload['error'] ?? 'OK');
        }

        if (isset($payload['error'])) {
            return back()->with('error', $payload['error']);
        }

        return back()->with('success', 'OK');
    }
}
