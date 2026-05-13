@php
    /** @var \App\Models\User $user */
    /** @var \App\Models\KnownDevice $device */
    /** @var bool $isNewDevice */
    /** @var bool $isNewCountry */
    /** @var string|null $countryName */
    /** @var string $loginAt */
    /** @var string $sessionsUrl */

    $appName = config('app.name', 'FLiK');
    $deviceLabel = $device->display_name;
    $locationLabel = $countryName ?: ($device->country ?: 'Unknown location');

    $headlineLabel = match (true) {
        $isNewDevice && $isNewCountry => 'New device · New location',
        $isNewDevice => 'New device sign-in',
        $isNewCountry => 'New location sign-in',
        default => 'Security activity',
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>{{ $appName }} security alert</title>
</head>
<body style="margin:0;padding:0;background:#0a0a0a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#e5e5e5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#0a0a0a;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0"
                       style="max-width:640px;width:100%;background:#141414;border:1px solid rgba(197,165,90,0.15);border-radius:12px;overflow:hidden;">

                    {{-- ─── Header ─────────────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:28px 32px;background:linear-gradient(135deg,#1a1a1a 0%,#0a0a0a 100%);border-bottom:1px solid rgba(197,165,90,0.25);">
                            <div style="font-size:11px;letter-spacing:3px;color:#C5A55A;font-weight:700;text-transform:uppercase;margin-bottom:6px;">
                                {{ $appName }} Security
                            </div>
                            <div style="font-size:22px;font-weight:700;color:#ffffff;line-height:1.3;">
                                {{ $headlineLabel }}
                            </div>
                            <div style="font-size:13px;color:#9ca3af;margin-top:4px;">
                                Hi {{ $user->name ?: 'there' }},
                            </div>
                        </td>
                    </tr>

                    {{-- ─── Lead paragraph ─────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:24px 32px 8px 32px;">
                            <p style="margin:0 0 12px 0;font-size:14px;line-height:1.7;color:#d4d4d8;">
                                We noticed your {{ $appName }} account
                                @if ($isNewDevice && $isNewCountry)
                                    was just signed in from a <strong style="color:#ffffff;">new device</strong> in a <strong style="color:#ffffff;">new location</strong>.
                                @elseif ($isNewDevice)
                                    was just signed in from a <strong style="color:#ffffff;">device we haven't seen before</strong>.
                                @elseif ($isNewCountry)
                                    was just signed in from a <strong style="color:#ffffff;">country we haven't seen recently</strong>.
                                @else
                                    had unusual sign-in activity.
                                @endif
                                If this was you, no action is needed — and you can mark this device as trusted to silence future alerts.
                            </p>
                        </td>
                    </tr>

                    {{-- ─── Device card ────────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:12px 32px 8px 32px;">
                            <div style="font-size:11px;letter-spacing:2px;color:#C5A55A;font-weight:600;text-transform:uppercase;margin-bottom:10px;">Sign-in details</div>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
                                   style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.06);border-radius:8px;overflow:hidden;">
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.04);">
                                        <div style="font-size:10px;letter-spacing:1.5px;color:#9ca3af;text-transform:uppercase;font-weight:600;">Device</div>
                                        <div style="font-size:14px;color:#ffffff;font-weight:600;margin-top:4px;">{{ $deviceLabel }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.04);">
                                        <div style="font-size:10px;letter-spacing:1.5px;color:#9ca3af;text-transform:uppercase;font-weight:600;">IP address</div>
                                        <div style="font-size:14px;color:#e5e5e5;margin-top:4px;font-family:'SFMono-Regular',Consolas,monospace;">{{ $device->ip }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;border-bottom:1px solid rgba(255,255,255,0.04);">
                                        <div style="font-size:10px;letter-spacing:1.5px;color:#9ca3af;text-transform:uppercase;font-weight:600;">Location</div>
                                        <div style="font-size:14px;color:#e5e5e5;margin-top:4px;">{{ $locationLabel }}</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 16px;">
                                        <div style="font-size:10px;letter-spacing:1.5px;color:#9ca3af;text-transform:uppercase;font-weight:600;">Time</div>
                                        <div style="font-size:14px;color:#e5e5e5;margin-top:4px;">{{ $loginAt }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ─── CTA ────────────────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:20px 32px 8px 32px;">
                            <div style="background:linear-gradient(180deg,rgba(197,165,90,0.06) 0%,rgba(197,165,90,0.02) 100%);border:1px solid rgba(197,165,90,0.18);border-radius:10px;padding:18px 20px;">
                                <div style="font-size:14px;color:#ffffff;font-weight:600;margin-bottom:6px;">Wasn't you?</div>
                                <p style="margin:0 0 14px 0;font-size:13px;color:#d4d4d8;line-height:1.6;">
                                    Sign in to {{ $appName }} and review your active sessions. From there you can revoke this device, change your password, and turn on two-factor authentication.
                                </p>
                                <a href="{{ $sessionsUrl }}"
                                   style="display:inline-block;background:#C5A55A;color:#0a0a0a;font-weight:700;font-size:13px;letter-spacing:0.5px;text-transform:uppercase;text-decoration:none;padding:10px 18px;border-radius:6px;">
                                    Review sessions
                                </a>
                            </div>
                        </td>
                    </tr>

                    {{-- ─── Footer ─────────────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:24px 32px 28px 32px;border-top:1px solid rgba(255,255,255,0.06);margin-top:16px;">
                            <div style="font-size:11px;color:#6b7280;line-height:1.6;text-align:center;">
                                You're receiving this because a sign-in occurred on your {{ $appName }} account.<br>
                                If you recognise this activity, you can ignore this email.<br>
                                <span style="color:#C5A55A;font-weight:600;letter-spacing:1px;">{{ $appName }} — Rumah Sinema Indonesia</span>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
