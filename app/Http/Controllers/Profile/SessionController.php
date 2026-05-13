<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\KnownDevice;
use App\Services\Security\SessionManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Manage active sessions" UI under /profile/sessions.
 *
 * Reads the `sessions` table (filled by SESSION_DRIVER=database) and
 * lets the signed-in user revoke individual devices or "logout
 * everywhere except this device".
 */
class SessionController extends Controller
{
    public function __construct(private readonly SessionManager $manager) {}

    /**
     * GET /profile/sessions — list devices for auth user.
     */
    public function index(): View
    {
        $userId = (int) auth()->id();

        $sessions = $this->manager->listForUser($userId);
        $currentSessionId = $this->manager->currentId();

        // Trusted-devices side panel — populated by LoginAlertService on
        // every successful login. Users can mark a row as trusted to
        // suppress future "new device" alerts, or forget it to make the
        // next login from that fingerprint re-alert.
        $knownDevices = KnownDevice::query()
            ->where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->limit(20)
            ->get();

        return view('profile.sessions', [
            'sessions' => $sessions,
            'currentSessionId' => $currentSessionId,
            'knownDevices' => $knownDevices,
        ]);
    }

    /**
     * POST /profile/devices/{device}/trust — flip the trusted flag
     * on a KnownDevice the user owns.
     */
    public function trustDevice(KnownDevice $device): RedirectResponse
    {
        // KnownDevicePolicy::update() — strict ownership; admin override.
        $this->authorize('update', $device);

        $device->update(['trusted' => true]);

        return redirect()
            ->route('profile.sessions.index')
            ->with('success', 'Device telah ditandai sebagai trusted. Tidak ada notifikasi login baru untuk device ini.');
    }

    /**
     * DELETE /profile/devices/{device} — "forget" the device. The next
     * login from the same fingerprint will be treated as new and
     * trigger the alert flow again.
     */
    public function forgetDevice(KnownDevice $device): RedirectResponse
    {
        // KnownDevicePolicy::delete() — strict ownership; admin override.
        $this->authorize('delete', $device);

        $device->delete();

        return redirect()
            ->route('profile.sessions.index')
            ->with('success', 'Device dilupakan. Login berikutnya dari device tersebut akan memicu notifikasi keamanan.');
    }

    /**
     * DELETE /profile/sessions/{id} — revoke a single session.
     *
     * Refuses to revoke the current session via this endpoint —
     * that's what the standard logout button is for, and self-
     * revoking would 419 the redirect on the next request.
     */
    public function destroy(Request $request, string $sessionId): RedirectResponse
    {
        $userId = (int) auth()->id();

        if ($this->manager->current($sessionId)) {
            return redirect()
                ->route('profile.sessions.index')
                ->with('error', 'Tidak bisa mencabut sesi aktif. Gunakan tombol Log Out untuk keluar dari device ini.');
        }

        $ok = $this->manager->revoke($sessionId, $userId);

        return redirect()
            ->route('profile.sessions.index')
            ->with($ok ? 'success' : 'error', $ok
                ? 'Sesi berhasil dicabut. Device tersebut telah keluar.'
                : 'Sesi tidak ditemukan atau bukan milik Anda.');
    }

    /**
     * POST /profile/sessions/destroy-all — bulk revoke (keep current).
     */
    public function destroyAll(Request $request): RedirectResponse
    {
        $userId = (int) auth()->id();
        $currentSessionId = $this->manager->currentId();

        if ($currentSessionId === null) {
            return redirect()
                ->route('profile.sessions.index')
                ->with('error', 'Tidak dapat menentukan sesi aktif. Coba muat ulang halaman.');
        }

        $count = $this->manager->revokeAllExceptCurrent($userId, $currentSessionId);

        return redirect()
            ->route('profile.sessions.index')
            ->with('success', "Berhasil keluar dari {$count} device lain.");
    }
}
