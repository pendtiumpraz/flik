@php
    use Illuminate\Support\Str;

    /** @var string $type */
    /** @var array<int,array<string,mixed>> $matrix */
    /** @var int $periodCount */
    /** @var string $insight */
    /** @var ?string $cachedAt */
    /** @var string $exportUrl */
    /** @var string $refreshUrl */
    /** @var callable $toggleUrl */

    $cohortKey = $type === 'monthly' ? 'cohort_month_start' : 'cohort_week_start';
    $periodLabelShort = $type === 'monthly' ? 'M' : 'W';
    $periodLabelLong = $type === 'monthly' ? 'month' : 'week';

    $gold = '#C5A55A';
    $bg = '#0f0f0f';

    // Aggregate stats across all cohorts for the header band.
    $totalSignups = 0;
    $weightedActive = [];   // period → numerator (active * 1)
    $weightedDenoms = [];   // period → denominator (signup count of cohorts where this period is observed)
    foreach ($matrix as $row) {
        $totalSignups += (int) ($row['signup_count'] ?? 0);
        foreach (($row['retention'] ?? []) as $point) {
            $p = (int) ($point['period'] ?? -1);
            if ($p < 0 || $point['pct'] === null) continue;
            $signups = (int) ($row['signup_count'] ?? 0);
            $weightedActive[$p] = ($weightedActive[$p] ?? 0) + (int) ($point['active'] ?? 0);
            $weightedDenoms[$p] = ($weightedDenoms[$p] ?? 0) + $signups;
        }
    }
    $avgRetention = [];
    for ($p = 0; $p < $periodCount; $p++) {
        $num = $weightedActive[$p] ?? 0;
        $den = $weightedDenoms[$p] ?? 0;
        $avgRetention[$p] = $den > 0 ? round(($num / $den) * 100, 1) : null;
    }

    // Gold-gradient heatmap colour: 0% → very dark, 100% → bright gold.
    // Returns a CSS background + text colour pair.
    $heat = function (?float $pct): array {
        if ($pct === null) {
            return ['bg' => '#0c0c0c', 'border' => '#1a1a1a', 'fg' => '#3a3a3a'];
        }
        $clamped = max(0.0, min(100.0, $pct));
        // 0..100 → 0..1 ramp, slight gamma to make low values still visible.
        $t = pow($clamped / 100, 0.75);
        // Interpolate from #1a1300 (dark amber-black) → #C5A55A (gold)
        $r = (int) round(0x1a + ($t * (0xC5 - 0x1a)));
        $g = (int) round(0x13 + ($t * (0xA5 - 0x13)));
        $b = (int) round(0x00 + ($t * (0x5A - 0x00)));
        $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
        // White text on darker cells, black on bright gold cells.
        $fg = $t > 0.55 ? '#000' : '#f0d68a';
        return ['bg' => $hex, 'border' => $hex, 'fg' => $fg];
    };
@endphp

<x-admin.layout title="Cohort Retention">

    {{-- Header --------------------------------------------------------- --}}
    <div style="display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap;margin-bottom:24px">
        <div>
            <h2 style="font-size:22px;font-weight:600;display:flex;align-items:center;gap:8px">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $gold }}"></span>
                Cohort Retention
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px;max-width:760px">
                Buckets users by signup {{ $periodLabelLong }} and shows the share that returned to watch
                something in {{ $periodLabelShort }}0, {{ $periodLabelShort }}1, … {{ $periodLabelShort }}{{ $periodCount - 1 }}.
                Engagement signal: at least one row in <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:{{ $gold }}">watch_histories</code>
                during the period. Cached 6 hours — append
                <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:{{ $gold }}">?refresh=1</code> to recompute.
            </p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            @if($cachedAt)
                <div style="background:#1a1a1a;border:1px solid #2a2a2a;padding:8px 14px;border-radius:8px;font-size:12px;color:#aaa">
                    AI insight cached:
                    <strong style="color:#fff">{{ \Illuminate\Support\Carbon::parse($cachedAt)->diffForHumans() }}</strong>
                </div>
            @endif
            <a href="{{ $refreshUrl }}" class="btn btn-ghost btn-sm" title="Force recompute matrix and AI insight">
                Refresh
            </a>
            <a href="{{ $exportUrl }}" class="btn btn-gold btn-sm" title="Download the matrix as CSV">
                Export CSV
            </a>
        </div>
    </div>

    {{-- Granularity toggle + summary -------------------------------- --}}
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:20px">
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:10px;padding:4px;display:inline-flex">
            <a href="{{ $toggleUrl('weekly') }}"
               class="btn btn-sm"
               style="border:none;background:{{ $type === 'weekly' ? $gold : 'transparent' }};color:{{ $type === 'weekly' ? '#000' : '#aaa' }};font-weight:600">
                Weekly
            </a>
            <a href="{{ $toggleUrl('monthly') }}"
               class="btn btn-sm"
               style="border:none;background:{{ $type === 'monthly' ? $gold : 'transparent' }};color:{{ $type === 'monthly' ? '#000' : '#aaa' }};font-weight:600">
                Monthly
            </a>
        </div>

        <div style="flex:1;min-width:280px;display:flex;gap:10px;flex-wrap:wrap">
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:10px;padding:10px 16px;flex:1;min-width:160px">
                <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:1px">Total Signups</div>
                <div style="font-family:'Outfit';font-size:22px;font-weight:700;color:#fff;margin-top:2px">
                    {{ number_format($totalSignups) }}
                </div>
                <div style="font-size:11px;color:#555">across {{ count($matrix) }} {{ Str::plural('cohort', count($matrix)) }}</div>
            </div>

            @php
                // Pick the first three meaningful retention milestones for the header.
                $highlightPoints = [1, $type === 'monthly' ? 3 : 4, $periodCount - 1];
            @endphp
            @foreach($highlightPoints as $p)
                @if($p >= 0 && $p < $periodCount)
                    @php $val = $avgRetention[$p] ?? null; @endphp
                    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:10px;padding:10px 16px;flex:1;min-width:140px">
                        <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:1px">
                            Avg {{ $periodLabelShort }}{{ $p }} retention
                        </div>
                        <div style="font-family:'Outfit';font-size:22px;font-weight:700;color:{{ $val === null ? '#555' : $gold }};margin-top:2px">
                            {{ $val === null ? '—' : $val.'%' }}
                        </div>
                        <div style="font-size:11px;color:#555">weighted by signups</div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- Heatmap -------------------------------------------------------- --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:0;overflow:hidden;margin-bottom:24px">
        <div style="padding:14px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <h3 style="font-size:15px;font-weight:600">
                {{ ucfirst($type) }} cohort heatmap
            </h3>
            <div style="display:flex;align-items:center;gap:8px;font-size:11px;color:#666">
                <span>Low</span>
                <div style="width:120px;height:10px;border-radius:6px;background:linear-gradient(90deg,#1a1300,{{ $gold }})"></div>
                <span>High</span>
            </div>
        </div>

        @if(empty($matrix))
            <div style="padding:48px 20px;text-align:center;color:#666;font-size:14px">
                No cohorts to display yet.
            </div>
        @else
            <div style="overflow-x:auto">
                <table class="admin-table" style="min-width:100%;border-collapse:separate;border-spacing:2px;padding:8px">
                    <thead>
                        <tr>
                            <th style="background:#0f0f0f;position:sticky;left:0;z-index:2;min-width:170px;border-bottom:none">
                                Cohort
                            </th>
                            <th style="background:#0f0f0f;text-align:right;min-width:80px;border-bottom:none">
                                Signups
                            </th>
                            @for($p = 0; $p < $periodCount; $p++)
                                <th style="background:#0f0f0f;text-align:center;min-width:62px;border-bottom:none">
                                    {{ $periodLabelShort }}{{ $p }}
                                </th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($matrix as $row)
                            @php
                                $signups = (int) ($row['signup_count'] ?? 0);
                                $byPeriod = [];
                                foreach (($row['retention'] ?? []) as $pt) {
                                    $byPeriod[(int) $pt['period']] = $pt;
                                }
                            @endphp
                            <tr>
                                <td style="background:#0f0f0f;position:sticky;left:0;z-index:1;font-size:12px">
                                    <div style="font-weight:600;color:#fff">{{ $row['label'] ?? '—' }}</div>
                                    <div style="font-size:10px;color:#666">{{ $row[$cohortKey] ?? '' }}</div>
                                </td>
                                <td style="background:#0f0f0f;text-align:right;font-family:'Outfit';font-weight:600;color:#ddd;font-size:13px">
                                    {{ number_format($signups) }}
                                </td>
                                @for($p = 0; $p < $periodCount; $p++)
                                    @php
                                        $pt = $byPeriod[$p] ?? null;
                                        $pct = $pt['pct'] ?? null;
                                        $active = $pt['active'] ?? 0;
                                        $h = $heat($pct);
                                    @endphp
                                    <td
                                        style="
                                            background:{{ $h['bg'] }};
                                            color:{{ $h['fg'] }};
                                            text-align:center;
                                            border:1px solid {{ $h['border'] }};
                                            border-radius:6px;
                                            font-family:'Outfit';
                                            font-weight:600;
                                            font-size:12px;
                                            padding:8px 6px;
                                            line-height:1.2;
                                        "
                                        title="{{ $row['label'] ?? '' }} — {{ $periodLabelShort }}{{ $p }}: {{ $pct === null ? 'future' : $pct.'% ('.$active.'/'.$signups.')' }}"
                                    >
                                        @if($pct === null)
                                            <span style="opacity:0.5">·</span>
                                        @else
                                            {{ $pct }}%
                                            <div style="font-size:9px;font-weight:400;color:{{ $h['fg'] }};opacity:0.7">
                                                {{ $active }}
                                            </div>
                                        @endif
                                    </td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- AI Insight Panel ----------------------------------------------- --}}
    <div style="background:linear-gradient(135deg, rgba(197,165,90,0.08), rgba(197,165,90,0.02));border:1px solid rgba(197,165,90,0.3);border-radius:12px;padding:20px 24px">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
            <div style="width:32px;height:32px;border-radius:8px;background:rgba(197,165,90,0.15);display:flex;align-items:center;justify-content:center;color:{{ $gold }};font-weight:700;font-family:'Outfit'">
                AI
            </div>
            <div>
                <h3 style="font-size:15px;font-weight:600;color:{{ $gold }}">Narrative Insight</h3>
                <div style="font-size:11px;color:#666">
                    Generated by the active provider in
                    <a href="{{ url('/admin/ai-settings') }}" style="color:{{ $gold }};text-decoration:none">/admin/ai-settings</a>
                    @if($cachedAt)
                        · cached {{ \Illuminate\Support\Carbon::parse($cachedAt)->diffForHumans() }}
                    @endif
                </div>
            </div>
        </div>
        <div style="color:#ddd;font-size:14px;line-height:1.7;white-space:pre-line">{{ $insight }}</div>
    </div>

    {{-- Footnote ------------------------------------------------------- --}}
    <div style="margin-top:24px;background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:14px 18px">
        <div style="color:{{ $gold }};font-weight:600;font-size:12px;margin-bottom:4px">
            How retention is measured
        </div>
        <div style="color:#aaa;font-size:12px;line-height:1.6">
            A user belongs to the cohort that matches their <code style="background:{{ $bg }};padding:1px 6px;border-radius:3px;color:{{ $gold }}">users.created_at</code>
            (bucketed to start of {{ $periodLabelLong }}). They count as "active" in {{ $periodLabelShort }}<em>N</em> if any
            <code style="background:{{ $bg }};padding:1px 6px;border-radius:3px;color:{{ $gold }}">watch_histories</code>
            row has <code style="background:{{ $bg }};padding:1px 6px;border-radius:3px;color:{{ $gold }}">last_watched_at</code>
            inside that {{ $periodLabelLong }}. {{ $periodLabelShort }}0 is the signup {{ $periodLabelLong }} itself — so a user
            who watches anything that {{ $periodLabelLong }} counts as 100% retained at {{ $periodLabelShort }}0. Cells marked
            <span style="color:#3a3a3a">·</span> are future periods that haven't elapsed yet.
        </div>
    </div>

</x-admin.layout>
