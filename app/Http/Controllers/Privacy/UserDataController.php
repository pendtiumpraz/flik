<?php

declare(strict_types=1);

namespace App\Http\Controllers\Privacy;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareDataExport;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Privacy\UserDataEraser;
use App\Services\Privacy\UserDataExporter;
use App\Services\Security\TwoFactorService;
use App\Support\SecurityEvents;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UserDataController
 * --------------------------------------------------------------------------
 * Mounts the GDPR self-service endpoints under /privacy/*:
 *
 *   GET    /privacy                          — landing card
 *   GET    /privacy/export                   — request an export (queues a job)
 *   GET    /privacy/export/download/{file}   — signed-URL gated download
 *   GET    /privacy/delete-account           — confirmation form
 *   DELETE /privacy/delete-account           — actually erase + log out
 *
 * Re-authentication is required before any destructive action: the user
 * must re-enter their password, AND if 2FA is enabled they must also
 * provide a fresh TOTP code. Hashes are checked with Hash::check (not
 * the throttled login pipeline) because we already have an authenticated
 * session — the goal is anti-CSRF-on-stolen-session, not anti-bruteforce.
 */
class UserDataController extends Controller
{
    public function __construct(
        protected AuditLogger $audit,
        protected TwoFactorService $tfa,
    ) {
    }

    // ── Landing ───────────────────────────────────────────────────

    public function index(): View
    {
        return view('privacy.index', [
            'user' => Auth::user(),
        ]);
    }

    // ── Export ────────────────────────────────────────────────────

    /**
     * GET /privacy/export — show the export request page. The form on
     * this page POSTs back to /privacy/export which queues the job and
     * redirects with a flash. Splitting GET/POST keeps the URL safe to
     * bookmark / refresh.
     */
    public function exportForm(): View
    {
        return view('privacy.export', [
            'user' => Auth::user(),
        ]);
    }

    /**
     * POST /privacy/export — queue the export job. We do NOT run the
     * exporter inline because it can take several seconds for power
     * users (thousands of watch-history rows). The user sees a flash
     * message and gets a Notification when the file is ready.
     */
    public function exportRequest(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        PrepareDataExport::dispatch($user);

        // Dual-write: keep the legacy 'gdpr.export.requested' row for any
        // existing dashboards/reports while ALSO emitting the canonical
        // SecurityEvents::DATA_EXPORT_REQUESTED so the security view can
        // flag it under "Security only" + colour-code by severity.
        $this->audit->log(
            action: 'gdpr.export.requested',
            subject: $user,
            user: $user,
        );
        $this->audit->security(
            event: SecurityEvents::DATA_EXPORT_REQUESTED,
            subject: $user,
            meta: ['email' => $user->email],
            user: $user,
        );

        return redirect()
            ->route('privacy.index')
            ->with('success', 'Permintaan ekspor data Anda sedang diproses. Anda akan menerima notifikasi saat file siap diunduh (biasanya dalam beberapa menit).');
    }

    /**
     * GET /privacy/export/download/{filename} — signed-URL gated download.
     *
     * The route is wrapped in 'signed' middleware so a tampered or expired
     * URL returns 403 before this controller is even hit. We additionally
     * verify the filename belongs to the authenticated user — defence in
     * depth against URL leaks (signed link forwarded to a different account).
     */
    public function exportDownload(Request $request, string $filename): StreamedResponse|SymfonyResponse
    {
        /** @var User $user */
        $user = Auth::user();

        // Filename pattern: user_{id}_{timestamp}.json — enforced server-side
        // so a guessed filename for someone else's id is rejected here.
        if (! preg_match('/^user_(\d+)_\d+\.json$/', $filename, $m)) {
            abort(404);
        }
        if ((int) $m[1] !== (int) $user->id) {
            abort(403);
        }

        $path = UserDataExporter::EXPORT_DIR.'/'.$filename;
        if (! Storage::disk('private')->exists($path)) {
            abort(404, 'Export file not found or expired.');
        }

        $this->audit->log(
            action: 'gdpr.export.downloaded',
            subject: $user,
            meta: ['filename' => $filename],
            user: $user,
        );
        $this->audit->security(
            event: SecurityEvents::DATA_EXPORT_DOWNLOADED,
            subject: $user,
            meta: ['filename' => $filename, 'email' => $user->email],
            user: $user,
        );

        return Storage::disk('private')->download(
            $path,
            'flik-data-export-'.$user->id.'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    // ── Delete account ────────────────────────────────────────────

    /**
     * GET /privacy/delete-account — render the scary red confirmation form.
     */
    public function confirmDelete(): View
    {
        /** @var User $user */
        $user = Auth::user();

        return view('privacy.delete', [
            'user'             => $user,
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
        ]);
    }

    /**
     * DELETE /privacy/delete-account — perform the erasure.
     *
     * Order of operations:
     *   1. validate(password, [otp], reason, confirm phrase)
     *   2. Hash::check the current password
     *   3. if 2FA on, verify the TOTP code via Google2FA
     *   4. UserDataEraser::erase()  (DB transaction inside)
     *   5. Auth::logout() + invalidate the session
     *   6. redirect home with success flash
     */
    public function delete(Request $request, UserDataEraser $eraser): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $rules = [
            'password'      => ['required', 'string'],
            'reason'        => ['nullable', 'string', 'max:500'],
            'confirmation'  => ['required', 'string', 'in:HAPUS AKUN SAYA,DELETE MY ACCOUNT'],
        ];
        if ($user->hasTwoFactorEnabled()) {
            $rules['otp'] = ['required', 'string', 'size:6'];
        }
        $validated = $request->validate($rules);

        if (! Hash::check((string) $validated['password'], (string) $user->password)) {
            return back()
                ->withInput($request->except('password', 'otp'))
                ->withErrors(['password' => 'Password salah. / Incorrect password.']);
        }

        if ($user->hasTwoFactorEnabled()) {
            $verified = $this->tfa->verify(
                (string) $user->two_factor_secret,
                (string) ($validated['otp'] ?? ''),
            );
            if (! $verified) {
                return back()
                    ->withInput($request->except('password', 'otp'))
                    ->withErrors(['otp' => 'Kode 2FA tidak valid. / Invalid 2FA code.']);
            }
        }

        $reason = (string) ($validated['reason'] ?? 'No reason provided');

        // Snapshot ID/email BEFORE the eraser runs — the audit row needs
        // them and the user model is gone after the transaction commits.
        $deletedId = (int) $user->id;
        $deletedEmail = (string) $user->email;

        // Erase BEFORE logout so the AuditLogger can still attribute the
        // 'gdpr.user.erased' row to this user (the eraser logs the user
        // explicitly, but request()->ip() / userAgent() also need the
        // active request — both still in scope here).
        $summary = $eraser->erase($user, $reason);

        // Critical-severity security row — fires AFTER the eraser so the
        // ACCOUNT_DELETED event can carry the per-table summary as evidence.
        // The user is null because the row is gone; meta carries the IDs.
        try {
            $this->audit->security(
                event: SecurityEvents::ACCOUNT_DELETED,
                meta: [
                    'erased_user_id' => $deletedId,
                    'erased_email'   => $deletedEmail,
                    'reason'         => $reason,
                    'self_initiated' => true,
                    'summary'        => $summary['summary'] ?? [],
                ],
            );
        } catch (\Throwable $e) {
            \Log::warning('UserDataController: ACCOUNT_DELETED audit failed', [
                'erased_user_id' => $deletedId,
                'error' => $e->getMessage(),
            ]);
        }

        // Logout + nuke session — no FK to a now-deleted user row.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')
            ->with('success', 'Akun Anda telah dihapus permanen. Terima kasih telah menggunakan FLiK.');
    }

}
