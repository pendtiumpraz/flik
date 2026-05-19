<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#0a0a0a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#e5e5e5;">

    {{-- Preheader: hidden snippet shown in inbox preview pane --}}
    @if(!empty($preheader))
        <div style="display:none;font-size:1px;color:#0a0a0a;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
            {{ $preheader }}
        </div>
    @endif

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#0a0a0a;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0"
                       style="max-width:640px;width:100%;background:#141414;border:1px solid rgba(197,165,90,0.15);border-radius:12px;overflow:hidden;">

                    {{-- ─── Header ─── --}}
                    <tr>
                        <td style="padding:24px 32px;background:linear-gradient(135deg,#1a1a1a 0%,#0a0a0a 100%);border-bottom:1px solid rgba(197,165,90,0.25);">
                            <div style="font-size:24px;font-family:'Outfit',Arial,sans-serif;font-weight:700;color:#C5A55A;letter-spacing:3px;">FLiK</div>
                            <div style="font-size:11px;letter-spacing:2px;color:#777;text-transform:uppercase;margin-top:4px;">Rumah Sinema Indonesia</div>
                        </td>
                    </tr>

                    {{-- ─── Body (admin-composed HTML) ─── --}}
                    <tr>
                        <td style="padding:32px;font-size:15px;line-height:1.65;color:#e5e5e5;">
                            {!! $bodyHtml !!}
                        </td>
                    </tr>

                    {{-- ─── Footer ─── --}}
                    <tr>
                        <td style="padding:24px 32px;background:#0f0f0f;border-top:1px solid rgba(197,165,90,0.15);font-size:11px;color:#666;text-align:center;">
                            <div style="margin-bottom:6px;">
                                Anda menerima email ini karena terdaftar sebagai pelanggan FLiK
                                ({{ $recipient->email }}).
                            </div>
                            <div>
                                &copy; {{ now()->format('Y') }} FLiK — Rumah Sinema Indonesia.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
