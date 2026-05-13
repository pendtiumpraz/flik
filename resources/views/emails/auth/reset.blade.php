<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Reset Password - FLiK</title>
</head>
<body style="margin:0;padding:0;background:#0a0a0a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#e5e5e5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#0a0a0a;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0"
                       style="max-width:560px;width:100%;background:#141414;border:1px solid rgba(197,165,90,0.15);border-radius:12px;overflow:hidden;">

                    {{-- Header --}}
                    <tr>
                        <td style="padding:32px 32px 24px 32px;background:linear-gradient(135deg,#1a1a1a 0%,#0a0a0a 100%);border-bottom:1px solid rgba(197,165,90,0.25);text-align:center;">
                            <div style="font-size:11px;letter-spacing:3px;color:#C5A55A;font-weight:700;text-transform:uppercase;margin-bottom:8px;">FLiK</div>
                            <div style="font-size:13px;color:#9ca3af;">Reset Password</div>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:36px 32px 8px 32px;">
                            <h1 style="margin:0 0 16px 0;font-size:24px;font-weight:700;color:#ffffff;line-height:1.3;">
                                Halo {{ $user->name ?? 'sahabat sinema' }},
                            </h1>
                            <p style="margin:0 0 16px 0;font-size:15px;line-height:1.7;color:#d4d4d8;">
                                Kami menerima permintaan reset password untuk akun
                                <strong style="color:#C5A55A;">{{ $user->email }}</strong>.
                            </p>
                            <p style="margin:0 0 24px 0;font-size:15px;line-height:1.7;color:#d4d4d8;">
                                Klik tombol di bawah untuk membuat password baru:
                            </p>
                        </td>
                    </tr>

                    {{-- CTA Button --}}
                    <tr>
                        <td style="padding:8px 32px 24px 32px;" align="center">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" style="border-radius:10px;background:linear-gradient(135deg,#C5A55A,#E8D5A3);">
                                        <a href="{{ $resetUrl }}"
                                           style="display:inline-block;padding:14px 36px;font-size:15px;font-weight:700;color:#0a0a0a;text-decoration:none;border-radius:10px;letter-spacing:0.3px;">
                                            Reset Password Saya
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Fallback link --}}
                    <tr>
                        <td style="padding:0 32px 24px 32px;">
                            <p style="margin:0 0 8px 0;font-size:12px;color:#9ca3af;">
                                Tombol tidak berfungsi? Salin dan tempel tautan ini di browser:
                            </p>
                            <p style="margin:0;font-size:12px;color:#C5A55A;word-break:break-all;line-height:1.6;">
                                {{ $resetUrl }}
                            </p>
                        </td>
                    </tr>

                    {{-- Security note --}}
                    <tr>
                        <td style="padding:0 32px 28px 32px;">
                            <div style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.18);border-radius:8px;padding:14px 16px;">
                                <p style="margin:0 0 8px 0;font-size:12px;color:#9ca3af;line-height:1.7;">
                                    <strong style="color:#C5A55A;">Penting:</strong>
                                    Tautan ini berlaku selama <strong style="color:#ffffff;">{{ $expiresInMinutes }} menit</strong>
                                    dan hanya bisa dipakai satu kali.
                                </p>
                                <p style="margin:0;font-size:12px;color:#9ca3af;line-height:1.7;">
                                    Jika kamu tidak meminta reset password, abaikan email ini — password lama tetap berlaku
                                    dan tidak ada perubahan pada akun kamu. Kalau ini terjadi berulang, sebaiknya ganti password
                                    dari pengaturan akun.
                                </p>
                            </div>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 32px 28px 32px;border-top:1px solid rgba(255,255,255,0.06);text-align:center;">
                            <div style="font-size:11px;color:#6b7280;line-height:1.7;">
                                Email ini dikirim otomatis oleh FLiK · Mohon jangan balas.<br>
                                <span style="color:#C5A55A;font-weight:600;letter-spacing:1px;">FLiK — Rumah Sinema Indonesia</span>
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
