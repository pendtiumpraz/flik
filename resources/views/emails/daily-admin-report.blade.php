<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>FLiK Daily Admin Report - {{ $report['human_date'] }}</title>
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
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td>
                                        <div style="font-size:11px;letter-spacing:3px;color:#C5A55A;font-weight:700;text-transform:uppercase;margin-bottom:6px;">FLiK Executive Brief</div>
                                        <div style="font-size:22px;font-weight:700;color:#ffffff;line-height:1.3;">Daily Admin Report</div>
                                        <div style="font-size:13px;color:#9ca3af;margin-top:4px;">{{ $report['human_date'] }}</div>
                                    </td>
                                    <td align="right" valign="top">
                                        <div style="display:inline-block;padding:6px 12px;background:rgba(197,165,90,0.12);border:1px solid rgba(197,165,90,0.35);border-radius:999px;font-size:11px;color:#C5A55A;font-weight:600;letter-spacing:0.5px;">
                                            {{ $report['date'] }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ─── KPI Grid ───────────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:24px 32px 8px 32px;">
                            <div style="font-size:11px;letter-spacing:2px;color:#C5A55A;font-weight:600;text-transform:uppercase;margin-bottom:14px;">Key Metrics</div>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    @php
                                        $kpis = [
                                            ['label' => 'New Users',         'value' => number_format($report['stats']['new_users'])],
                                            ['label' => 'New Subscriptions', 'value' => number_format($report['stats']['new_subscriptions']['total'])],
                                            ['label' => 'Revenue',           'value' => $report['stats']['total_revenue_fmt']],
                                            ['label' => 'Watch Hours',       'value' => number_format($report['stats']['total_watch_hours'], 2)],
                                            ['label' => 'DAU',               'value' => number_format($report['stats']['dau_active'])],
                                            ['label' => 'MAU (30d)',         'value' => number_format($report['stats']['mau_active'])],
                                        ];
                                    @endphp
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                @foreach (array_chunk($kpis, 3) as $row)
                                    <tr>
                                        @foreach ($row as $kpi)
                                            <td width="33.33%" style="padding:6px;" valign="top">
                                                <div style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:14px 16px;">
                                                    <div style="font-size:10px;letter-spacing:1.5px;color:#9ca3af;text-transform:uppercase;font-weight:600;">{{ $kpi['label'] }}</div>
                                                    <div style="font-size:18px;font-weight:700;color:#ffffff;margin-top:6px;line-height:1.2;">{{ $kpi['value'] }}</div>
                                                </div>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>

                    {{-- ─── AI Narrative ───────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:20px 32px 8px 32px;">
                            <div style="font-size:11px;letter-spacing:2px;color:#C5A55A;font-weight:600;text-transform:uppercase;margin-bottom:10px;">Executive Summary</div>
                            <div style="background:linear-gradient(180deg,rgba(197,165,90,0.06) 0%,rgba(197,165,90,0.02) 100%);border:1px solid rgba(197,165,90,0.18);border-radius:10px;padding:18px 20px;">
                                @foreach (preg_split('/\n\s*\n/', trim($report['narrative'])) as $paragraph)
                                    @if (trim($paragraph) !== '')
                                        <p style="margin:0 0 12px 0;font-size:14px;line-height:1.7;color:#d4d4d8;">{!! nl2br(e(trim($paragraph))) !!}</p>
                                    @endif
                                @endforeach
                                @if (!empty($report['narrative_error']))
                                    <div style="margin-top:10px;font-size:11px;color:#f59e0b;font-style:italic;">Catatan: narasi AI gagal dibuat ({{ $report['narrative_error'] }}) - fallback otomatis ditampilkan.</div>
                                @endif
                            </div>
                        </td>
                    </tr>

                    {{-- ─── Subscriptions by Plan ──────────────────────────────── --}}
                    @if (!empty($report['stats']['new_subscriptions']['by_plan']))
                        <tr>
                            <td style="padding:20px 32px 8px 32px;">
                                <div style="font-size:11px;letter-spacing:2px;color:#C5A55A;font-weight:600;text-transform:uppercase;margin-bottom:10px;">New Subscriptions by Plan</div>
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.06);border-radius:8px;overflow:hidden;">
                                    <tr style="background:rgba(197,165,90,0.08);">
                                        <th align="left"  style="padding:10px 14px;font-size:11px;letter-spacing:1px;color:#C5A55A;font-weight:600;text-transform:uppercase;">Plan</th>
                                        <th align="right" style="padding:10px 14px;font-size:11px;letter-spacing:1px;color:#C5A55A;font-weight:600;text-transform:uppercase;">Subs</th>
                                        <th align="right" style="padding:10px 14px;font-size:11px;letter-spacing:1px;color:#C5A55A;font-weight:600;text-transform:uppercase;">Revenue</th>
                                    </tr>
                                    @foreach ($report['stats']['new_subscriptions']['by_plan'] as $plan)
                                        <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                                            <td align="left"  style="padding:10px 14px;font-size:13px;color:#e5e5e5;">{{ $plan['plan'] }}</td>
                                            <td align="right" style="padding:10px 14px;font-size:13px;color:#ffffff;font-weight:600;">{{ number_format($plan['count']) }}</td>
                                            <td align="right" style="padding:10px 14px;font-size:13px;color:#C5A55A;font-weight:600;">{{ $plan['revenue_fmt'] }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- ─── Top Movies ─────────────────────────────────────────── --}}
                    @if (!empty($report['stats']['top_movies']))
                        <tr>
                            <td style="padding:20px 32px 8px 32px;">
                                <div style="font-size:11px;letter-spacing:2px;color:#C5A55A;font-weight:600;text-transform:uppercase;margin-bottom:10px;">Top 5 Movies (by Viewers)</div>
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.06);border-radius:8px;overflow:hidden;">
                                    <tr style="background:rgba(197,165,90,0.08);">
                                        <th align="left"  style="padding:10px 14px;font-size:11px;letter-spacing:1px;color:#C5A55A;font-weight:600;text-transform:uppercase;">#</th>
                                        <th align="left"  style="padding:10px 14px;font-size:11px;letter-spacing:1px;color:#C5A55A;font-weight:600;text-transform:uppercase;">Title</th>
                                        <th align="right" style="padding:10px 14px;font-size:11px;letter-spacing:1px;color:#C5A55A;font-weight:600;text-transform:uppercase;">Viewers</th>
                                        <th align="right" style="padding:10px 14px;font-size:11px;letter-spacing:1px;color:#C5A55A;font-weight:600;text-transform:uppercase;">Avg Progress</th>
                                    </tr>
                                    @foreach ($report['stats']['top_movies'] as $i => $movie)
                                        <tr style="border-top:1px solid rgba(255,255,255,0.04);">
                                            <td align="left"  style="padding:10px 14px;font-size:13px;color:#9ca3af;font-weight:600;">{{ $i + 1 }}</td>
                                            <td align="left"  style="padding:10px 14px;font-size:13px;color:#ffffff;">{{ $movie['title'] }}</td>
                                            <td align="right" style="padding:10px 14px;font-size:13px;color:#e5e5e5;">{{ number_format($movie['views']) }}</td>
                                            <td align="right" style="padding:10px 14px;font-size:13px;color:#C5A55A;font-weight:600;">{{ $movie['avg_progress'] }}%</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </td>
                        </tr>
                    @endif

                    {{-- ─── Engagement (Comments + Ratings) ───────────────────── --}}
                    <tr>
                        <td style="padding:20px 32px 8px 32px;">
                            <div style="font-size:11px;letter-spacing:2px;color:#C5A55A;font-weight:600;text-transform:uppercase;margin-bottom:10px;">Engagement</div>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td width="50%" style="padding:6px;" valign="top">
                                        <div style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:14px 16px;">
                                            <div style="font-size:10px;letter-spacing:1.5px;color:#9ca3af;text-transform:uppercase;font-weight:600;">Comments</div>
                                            <div style="font-size:20px;font-weight:700;color:#ffffff;margin:6px 0 10px;">{{ number_format($report['stats']['comments']['count']) }}</div>
                                            <div style="font-size:12px;color:#9ca3af;line-height:1.7;">
                                                <span style="color:#22c55e;font-weight:600;">{{ $report['stats']['comments']['sentiment']['positive'] }} positive</span> ·
                                                <span style="color:#9ca3af;">{{ $report['stats']['comments']['sentiment']['neutral'] }} neutral</span> ·
                                                <span style="color:#ef4444;font-weight:600;">{{ $report['stats']['comments']['sentiment']['negative'] }} negative</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td width="50%" style="padding:6px;" valign="top">
                                        <div style="background:#1a1a1a;border:1px solid rgba(255,255,255,0.06);border-radius:8px;padding:14px 16px;">
                                            <div style="font-size:10px;letter-spacing:1.5px;color:#9ca3af;text-transform:uppercase;font-weight:600;">Ratings</div>
                                            <div style="font-size:20px;font-weight:700;color:#ffffff;margin:6px 0 10px;">{{ number_format($report['stats']['ratings_count']) }}</div>
                                            <div style="font-size:12px;color:#9ca3af;">
                                                Avg score: <span style="color:#C5A55A;font-weight:600;">{{ $report['stats']['ratings_avg'] ?: '-' }}</span> / 10
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ─── Footer ─────────────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:24px 32px 28px 32px;border-top:1px solid rgba(255,255,255,0.06);margin-top:16px;">
                            <div style="font-size:11px;color:#6b7280;line-height:1.6;text-align:center;">
                                Generated automatically by FLiK · {{ $report['generated_at'] }}<br>
                                You're receiving this because your account has Super Admin role.<br>
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
