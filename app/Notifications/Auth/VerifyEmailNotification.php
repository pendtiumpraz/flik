<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * FLiK-branded email-verification notification.
 *
 * Reuses Laravel's built-in {@see BaseVerifyEmail} signing helpers — the
 * verification URL is still generated through `URL::temporarySignedRoute`
 * with the configured `auth.verification.expire` window, so the existing
 * `\Illuminate\Foundation\Auth\EmailVerificationRequest` keeps working
 * without any change. We only swap the rendered HTML for a branded one.
 */
final class VerifyEmailNotification extends BaseVerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Verifikasi Email - FLiK')
            ->view('emails.auth.verify', [
                'user' => $notifiable,
                'verificationUrl' => $verificationUrl,
                'expiresInMinutes' => (int) Config::get('auth.verification.expire', 60),
            ]);
    }

    /**
     * Mirror Laravel's signed-URL helper so we generate the exact same URL
     * shape the framework's `verification.verify` named route expects.
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1((string) $notifiable->getEmailForVerification()),
            ]
        );
    }
}
