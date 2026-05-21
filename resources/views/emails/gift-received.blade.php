<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kamu menerima hadiah FLiK!</title>
</head>
<body style="margin:0;padding:0;background:#0a0a0a;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#e5e5e5">
    {{-- Outer wrapper — keeps the design centered + capped on wide clients --}}
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#0a0a0a">
        <tr>
            <td align="center" style="padding:32px 16px">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600"
                       style="max-width:600px;background:#141414;border:1px solid #2a2a2a;border-radius:14px;overflow:hidden">

                    {{-- Header --}}
                    <tr>
                        <td align="center"
                            style="padding:32px 24px 24px;background:linear-gradient(135deg,rgba(197,165,90,0.22),rgba(197,165,90,0.04));border-bottom:1px solid #2a2a2a">
                            <h1 style="margin:0;font-size:28px;font-weight:800;letter-spacing:6px;color:#C5A55A;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif">
                                FLiK
                            </h1>
                            <p style="margin:6px 0 0;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#888">
                                Rumah Sinema Indonesia
                            </p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px 32px 8px">
                            <h2 style="margin:0 0 12px;font-size:24px;font-weight:700;color:#ffffff;line-height:1.3">
                                @if(!empty($gift->recipient_name))
                                    Halo {{ $gift->recipient_name }},
                                @else
                                    Halo,
                                @endif
                            </h2>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#cccccc">
                                Kamu baru saja menerima hadiah <strong style="color:#C5A55A">FLiK {{ $plan->name ?? 'Premium' }}</strong>
                                @if(!empty($gift->duration_days))
                                    selama <strong>{{ (int) $gift->duration_days }} hari</strong>
                                @endif!
                            </p>
                            <p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#999999">
                                Nikmati pilihan film terbaik dari Cinema Indonesia tanpa iklan, kualitas HD,
                                dengan subtitle multi-bahasa.
                            </p>
                        </td>
                    </tr>

                    {{-- Code block --}}
                    <tr>
                        <td style="padding:0 32px">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                   style="background:#0a0a0a;border:1px solid rgba(197,165,90,0.3);border-radius:12px">
                                <tr>
                                    <td align="center" style="padding:24px 16px">
                                        <p style="margin:0 0 10px;font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#C5A55A;font-weight:600">
                                            Kode Hadiah Kamu
                                        </p>
                                        <p style="margin:0;font-size:24px;font-weight:800;letter-spacing:4px;color:#ffffff;font-family:'Courier New',Courier,monospace">
                                            {{ $code }}
                                        </p>
                                        <p style="margin:14px 0 0;font-size:12px;color:#777">
                                            Berlaku hingga
                                            <strong style="color:#e5e5e5">
                                                {{ optional($gift->expires_at)->translatedFormat('d M Y') ?? '90 hari ke depan' }}
                                            </strong>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Personal message --}}
                    @if(!empty($gift->personal_message))
                        <tr>
                            <td style="padding:24px 32px 0">
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                       style="background:rgba(197,165,90,0.06);border-left:3px solid #C5A55A;border-radius:6px">
                                    <tr>
                                        <td style="padding:14px 18px">
                                            <p style="margin:0 0 4px;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#C5A55A;font-weight:600">
                                                Pesan untuk kamu
                                            </p>
                                            <p style="margin:0;font-size:14px;line-height:1.6;color:#e5e5e5;font-style:italic">
                                                "{{ $gift->personal_message }}"
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- CTA --}}
                    <tr>
                        <td align="center" style="padding:28px 32px 16px">
                            <a href="{{ url(route('gift.redeem-form', [], false)) }}"
                               style="display:inline-block;padding:14px 36px;background:linear-gradient(90deg,#C5A55A,#E8D5A3);color:#000000;font-size:15px;font-weight:700;text-decoration:none;border-radius:10px;letter-spacing:0.5px">
                                Tukarkan Sekarang &rarr;
                            </a>
                            <p style="margin:14px 0 0;font-size:12px;color:#777">
                                Atau salin kode di atas dan tempel di halaman
                                <a href="{{ url(route('gift.redeem-form', [], false)) }}"
                                   style="color:#C5A55A;text-decoration:underline">
                                    redeem hadiah
                                </a>.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:24px 32px 32px;border-top:1px solid #2a2a2a;background:#0f0f0f">
                            <p style="margin:0 0 6px;font-size:11px;color:#666;line-height:1.6">
                                Hadiah ini diberikan oleh <strong style="color:#999">{{ $gift->purchaser_email ?? 'sahabat kamu' }}</strong>.
                                Kode bersifat sekali pakai dan akan kadaluwarsa jika tidak ditukar tepat waktu.
                            </p>
                            <p style="margin:12px 0 0;font-size:11px;color:#555">
                                &copy; {{ date('Y') }} FLiK &mdash; Rumah Sinema Indonesia &middot;
                                <a href="{{ url('/') }}" style="color:#777;text-decoration:none">{{ parse_url(url('/'), PHP_URL_HOST) }}</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
