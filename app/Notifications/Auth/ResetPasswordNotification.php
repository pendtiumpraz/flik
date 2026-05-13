<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;

/**
 * FLiK-branded password-reset notification.
 *
 * The base {@see BaseResetPassword} class still owns the URL build (so the
 * standard Laravel `password.reset` named route + token-broker validation
 * stays intact). We only override the rendered HTML to match the brand.
 */
final class ResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);

        return (new MailMessage)
            ->subject('Reset Password - FLiK')
            ->view('emails.auth.reset', [
                'user' => $notifiable,
                'resetUrl' => $resetUrl,
                'expiresInMinutes' => (int) Config::get('auth.passwords.users.expire', 60),
            ]);
    }

    /**
     * Mirror Laravel's URL helper so we honour any `ResetPassword::createUrlUsing`
     * customisations (e.g. SPA front-end deep links) registered elsewhere.
     */
    protected function resetUrl($notifiable): string
    {
        if (self::$createUrlCallback) {
            return call_user_func(self::$createUrlCallback, $notifiable, $this->token);
        }

        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));
    }
}
