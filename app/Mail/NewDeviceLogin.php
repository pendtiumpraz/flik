<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\KnownDevice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * NewDeviceLogin
 * --------------------------------------------------------------------------
 * Branded HTML alert sent on first login from an unrecognised device, or
 * the first login from a country not seen in the user's recent history.
 *
 * Implements ShouldQueue so the listener can dispatch it asynchronously
 * onto the `default` queue without blocking the login response.
 */
class NewDeviceLogin extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly KnownDevice $device,
        public readonly bool $isNewDevice,
        public readonly bool $isNewCountry,
        public readonly ?string $countryName = null,
        public readonly ?string $loginAt = null,
    ) {
    }

    public function envelope(): Envelope
    {
        $appName = (string) (config('app.name') ?: 'FLiK');

        $subject = match (true) {
            $this->isNewDevice && $this->isNewCountry =>
                "Sign-in from new device & new location — {$appName}",
            $this->isNewDevice =>
                "New device signed in to your {$appName} account",
            $this->isNewCountry =>
                "Sign-in from a new location — {$appName}",
            default =>
                "Security alert — {$appName}",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security.new-device-login',
            with: [
                'user'         => $this->user,
                'device'       => $this->device,
                'isNewDevice'  => $this->isNewDevice,
                'isNewCountry' => $this->isNewCountry,
                'countryName'  => $this->countryName,
                'loginAt'      => $this->loginAt ?? now()->toDayDateTimeString(),
                'sessionsUrl'  => url('/profile/sessions'),
            ],
        );
    }
}
