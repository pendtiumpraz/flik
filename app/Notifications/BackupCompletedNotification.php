<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail notification sent to super_admins after each backup run.
 *
 * Carries success/failure state, per-step timings, and the remote object
 * path (so the operator can verify the backup actually landed in the
 * configured CDN bucket without SSH-ing into the box).
 */
final class BackupCompletedNotification extends Notification
{
    use Queueable;

    /**
     * @param array{
     *     success: bool,
     *     started_at: string,
     *     finished_at: string,
     *     duration_seconds: float,
     *     steps: array<int, array{name:string, status:string, detail?:string, bytes?:int, ms?:int}>,
     *     remote_path?: ?string,
     *     remote_disk?: ?string,
     *     error?: ?string,
     * } $report
     */
    public function __construct(public readonly array $report)
    {
    }

    /**
     * @return array<int,string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $ok = (bool) ($this->report['success'] ?? false);
        $subject = $ok
            ? '[FLiK] Backup berhasil — ' . ($this->report['finished_at'] ?? '')
            : '[FLiK] Backup GAGAL — ' . ($this->report['finished_at'] ?? '');

        $mail = (new MailMessage())
            ->subject($subject)
            ->greeting($ok ? 'Backup harian sukses.' : 'Backup harian GAGAL.')
            ->line('Durasi total: ' . number_format((float) ($this->report['duration_seconds'] ?? 0), 2) . ' detik')
            ->line('Mulai: ' . ($this->report['started_at'] ?? '-'))
            ->line('Selesai: ' . ($this->report['finished_at'] ?? '-'));

        if (! empty($this->report['remote_path'])) {
            $mail->line('Lokasi remote: ' . ($this->report['remote_disk'] ?? '?')
                . ':' . $this->report['remote_path']);
        }

        if (! empty($this->report['error'])) {
            $mail->line('Error: ' . $this->report['error']);
        }

        $mail->line('--- Detail tahapan ---');
        foreach (($this->report['steps'] ?? []) as $step) {
            $line = sprintf(
                '[%s] %s',
                strtoupper((string) ($step['status'] ?? '?')),
                (string) ($step['name'] ?? '?'),
            );
            if (isset($step['ms'])) {
                $line .= ' — ' . $step['ms'] . 'ms';
            }
            if (isset($step['bytes'])) {
                $line .= ' — ' . $this->humanBytes((int) $step['bytes']);
            }
            if (isset($step['detail']) && $step['detail'] !== '') {
                $line .= ' — ' . $step['detail'];
            }
            $mail->line($line);
        }

        if (! $ok) {
            $mail->error();
        }

        return $mail;
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $b = (float) $bytes;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }
        return number_format($b, 2) . ' ' . $units[$i];
    }
}
