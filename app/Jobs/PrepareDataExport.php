<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Services\Privacy\UserDataExporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * PrepareDataExport
 * --------------------------------------------------------------------------
 * Background-runs UserDataExporter::export() and drops an in-app
 * Notification when the JSON is ready, with a 24-hour signed download URL.
 *
 * Routed to the `default` queue (small files, low priority — does not
 * justify the dedicated `ai-batch` worker). One retry on failure: if the
 * second attempt also fails, the failed() hook drops a user-visible
 * error notification so they know to try again rather than waiting
 * forever.
 */
class PrepareDataExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * Building a JSON dump for a power user with thousands of watch-history
     * rows is still IO-bounded by the DB, not the encoder — 5 minutes is
     * a generous cap that should never realistically be hit.
     */
    public int $timeout = 300;

    public function __construct(
        public User $user,
    ) {
        $this->onQueue('default');
    }

    public function handle(UserDataExporter $exporter): void
    {
        $url = $exporter->export($this->user);

        Notification::create([
            'user_id'    => $this->user->id,
            'type'       => 'privacy.export.ready',
            'title'      => 'Ekspor data Anda siap diunduh',
            'message'    => 'File JSON berisi seluruh data akun Anda telah disiapkan. '
                            .'Tautan unduhan berlaku selama 24 jam dan hanya dapat diakses oleh Anda.',
            'action_url' => $url,
        ]);

        Log::info('GDPR data export prepared', [
            'user_id' => $this->user->id,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PrepareDataExport job failed', [
            'user_id' => $this->user->id ?? null,
            'error'   => $e->getMessage(),
        ]);

        // Best-effort user notice — if the user row itself was deleted
        // mid-flight (extremely unlikely) the create() will throw on FK
        // and we just log it.
        try {
            Notification::create([
                'user_id'    => $this->user->id,
                'type'       => 'privacy.export.failed',
                'title'      => 'Ekspor data gagal disiapkan',
                'message'    => 'Permintaan ekspor data Anda gagal diproses. Silakan coba lagi atau hubungi dukungan.',
                'action_url' => route('privacy.export.request'),
            ]);
        } catch (\Throwable $inner) {
            Log::warning('Failed to write failure notification for export', [
                'user_id' => $this->user->id ?? null,
                'error'   => $inner->getMessage(),
            ]);
        }
    }
}
