<x-admin.layout title="Content Gap Analysis">

    @php
        $gold       = '#C5A55A';
        $catalog    = $report['catalog_stats'] ?? [];
        $demand     = $report['demand_stats'] ?? [];
        $signals    = $report['gap_signals'] ?? [];
        $recs       = $report['recommendations'] ?? [];
        $aiError    = $report['ai_error'] ?? null;
        $window     = $report['window_days'] ?? 90;

        $totalMovies        = (int) ($catalog['total_movies'] ?? 0);
        $totalRatings       = (int) ($demand['total_ratings'] ?? 0);
        $totalViews         = (int) ($demand['total_views'] ?? 0);
        $genreCount         = count($catalog['by_genre'] ?? []);
        $decadeCount        = count($catalog['by_decade'] ?? []);

        $priorityMeta = [
            'high'   => ['#ef4444', 'rgba(239,68,68,0.12)', 'Tinggi'],
            'medium' => ['#eab308', 'rgba(234,179,8,0.12)', 'Sedang'],
            'low'    => ['#22c55e', 'rgba(34,197,94,0.12)', 'Rendah'],
        ];

        $maxGenreCount = max([1, ...array_column($catalog['by_genre'] ?? [], 'count')]);
        $maxDecadeCount = max([1, ...array_column($catalog['by_decade'] ?? [], 'count')]);
        $maxDemandViews = max([1, ...array_column($demand['most_watched_genres'] ?? [], 'views')]);
    @endphp

    {{-- ── Header ───────────────────────────────────────────── --}}
    <div style="display:flex;justify-content:space-between;align-items:start;gap:16px;flex-wrap:wrap;margin-bottom:24px">
        <div>
            <h2 style="font-size:22px;font-weight:600;display:flex;align-items:center;gap:8px">
                <x-icon name="sparkles" size="22" style="color:{{ $gold }}" />
                Content Gap Analysis
            </h2>
            <p style="color:#777;font-size:13px;margin-top:6px;max-width:680px">
                Membandingkan komposisi katalog dengan permintaan pengguna dalam {{ $window }} hari terakhir.
                AI mengidentifikasi kesenjangan paling kritis dan memberi rekomendasi akuisisi/restorasi.
            </p>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            @if($cachedAt)
                <div style="background:#1a1a1a;border:1px solid #2a2a2a;padding:8px 14px;border-radius:8px;font-size:12px;color:#aaa">
                    <x-icon name="clock" size="13" />
                    Cache: <strong style="color:#fff">{{ \Carbon\Carbon::parse($cachedAt)->diffForHumans() }}</strong>
                </div>
            @endif
            <a href="{{ url()->current() }}?refresh=1"
               class="btn btn-ghost btn-sm"
               onclick="this.innerText='Memproses...'; this.style.opacity='0.6'">
                <x-icon name="sparkles" size="14" /> Refresh
            </a>
        </div>
    </div>

    @if($aiError)
        <div style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.3);color:#fca5a5;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-size:13px">
            <strong>AI tidak tersedia:</strong> {{ $aiError }} — statistik tetap bisa dibaca di bawah.
        </div>
    @endif

    {{-- ── KPI cards ────────────────────────────────────────── --}}
    <div class="grid-stats" style="margin-bottom:24px">
        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Total Film</div>
                    <div class="value">{{ number_format($totalMovies) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">{{ $genreCount }} genre · {{ $decadeCount }} dekade</div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:{{ $gold }}">
                    <x-icon name="film" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Sesi Tonton ({{ $window }}d)</div>
                    <div class="value">{{ number_format($totalViews) }}</div>
                </div>
                <div class="icon" style="background:rgba(59,130,246,0.15);color:#3b82f6">
                    <x-icon name="eye" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Rating Baru ({{ $window }}d)</div>
                    <div class="value">{{ number_format($totalRatings) }}</div>
                </div>
                <div class="icon" style="background:rgba(234,179,8,0.15);color:#eab308">
                    <x-icon name="star" size="20" />
                </div>
            </div>
        </div>

        <div class="stat-card">
            <div style="display:flex;justify-content:space-between;align-items:start">
                <div>
                    <div class="label">Rekomendasi AI</div>
                    <div class="value">{{ count($recs) }}</div>
                    <div style="font-size:11px;color:#666;margin-top:4px">
                        @php
                            $high = collect($recs)->where('priority','high')->count();
                            $med  = collect($recs)->where('priority','medium')->count();
                            $low  = collect($recs)->where('priority','low')->count();
                        @endphp
                        <span style="color:#ef4444">{{ $high }}H</span> ·
                        <span style="color:#eab308">{{ $med }}M</span> ·
                        <span style="color:#22c55e">{{ $low }}L</span>
                    </div>
                </div>
                <div class="icon" style="background:rgba(197,165,90,0.15);color:{{ $gold }}">
                    <x-icon name="sparkles" size="20" />
                </div>
            </div>
        </div>
    </div>

    {{-- ── AI Recommendations ───────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
            <h3 style="font-size:15px;font-weight:600;display:flex;align-items:center;gap:8px">
                <x-icon name="sparkles" size="16" style="color:{{ $gold }}" />
                Rekomendasi AI
            </h3>
            <span style="font-size:11px;color:#666">Diurutkan berdasarkan prioritas</span>
        </div>

        @if(empty($recs))
            <div style="padding:40px 20px;text-align:center;color:#666;font-size:13px">
                Tidak ada rekomendasi yang dihasilkan. Coba refresh atau periksa konfigurasi AI provider.
            </div>
        @else
            <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
                @foreach($recs as $rec)
                    @php
                        [$pColor, $pBg, $pLabel] = $priorityMeta[$rec['priority']] ?? $priorityMeta['medium'];
                    @endphp
                    <div style="background:#0f0f0f;border:1px solid #2a2a2a;border-left:3px solid {{ $pColor }};border-radius:10px;padding:18px;display:flex;flex-direction:column;gap:10px">
                        <div style="display:flex;justify-content:space-between;align-items:start;gap:8px">
                            <h4 style="font-size:14px;font-weight:600;color:#fff;line-height:1.4">{{ $rec['gap_description'] }}</h4>
                            <span style="background:{{ $pBg }};color:{{ $pColor }};padding:3px 10px;border-radius:20px;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;flex-shrink:0">
                                {{ $pLabel }}
                            </span>
                        </div>

                        @if(!empty($rec['evidence']))
                            <div>
                                <div style="font-size:10px;color:#666;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Bukti</div>
                                <p style="font-size:13px;color:#bbb;line-height:1.5">{{ $rec['evidence'] }}</p>
                            </div>
                        @endif

                        @if(!empty($rec['recommendation']))
                            <div style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.18);border-radius:8px;padding:12px;margin-top:4px">
                                <div style="font-size:10px;color:{{ $gold }};text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;font-weight:600">Rekomendasi</div>
                                <p style="font-size:13px;color:#e5e5e5;line-height:1.5">{{ $rec['recommendation'] }}</p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── Gap signals ──────────────────────────────────────── --}}
    @if(!empty($signals))
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
            <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
                <h3 style="font-size:15px;font-weight:600">Sinyal Kesenjangan (Permintaan vs Pasokan)</h3>
                <p style="font-size:12px;color:#666;margin-top:4px">
                    Delta positif = pengguna menonton genre ini lebih banyak dari yang katalog tawarkan.
                </p>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Genre</th>
                        <th style="text-align:right">Film</th>
                        <th style="text-align:right">Sesi Tonton</th>
                        <th style="text-align:right">% Pasokan</th>
                        <th style="text-align:right">% Permintaan</th>
                        <th style="text-align:right">Δ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($signals as $s)
                        @php
                            $delta = (float) $s['delta'];
                            $deltaColor = $delta >= 5 ? '#ef4444' : ($delta >= 1 ? '#eab308' : ($delta <= -5 ? '#3b82f6' : '#888'));
                        @endphp
                        <tr>
                            <td>
                                <div style="font-weight:500;color:#fff">{{ $s['label'] }}</div>
                                <div style="font-size:11px;color:#666">{{ $s['slug'] }}</div>
                            </td>
                            <td style="text-align:right;color:#aaa">{{ number_format($s['supply_count']) }}</td>
                            <td style="text-align:right;color:#aaa">{{ number_format($s['demand_views']) }}</td>
                            <td style="text-align:right">{{ number_format($s['supply_share'], 1) }}%</td>
                            <td style="text-align:right">{{ number_format($s['demand_share'], 1) }}%</td>
                            <td style="text-align:right;font-weight:600;color:{{ $deltaColor }}">
                                {{ $delta > 0 ? '+' : '' }}{{ number_format($delta, 1) }}pp
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- ── Catalog vs Demand bars (genre) ───────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:16px;margin-bottom:24px">

        {{-- Catalog by genre --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
            <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
                <h3 style="font-size:15px;font-weight:600">Pasokan: Film per Genre</h3>
            </div>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:8px;max-height:420px;overflow-y:auto">
                @foreach(($catalog['by_genre'] ?? []) as $g)
                    @php $w = ($g['count'] / $maxGenreCount) * 100; @endphp
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                            <span style="color:#e5e5e5">{{ $g['name'] }}</span>
                            <span style="color:#888">{{ number_format($g['count']) }}</span>
                        </div>
                        <div style="background:#0f0f0f;border-radius:4px;height:6px;overflow:hidden">
                            <div style="height:100%;background:{{ $gold }};width:{{ $w }}%;transition:width .3s"></div>
                        </div>
                    </div>
                @endforeach
                @if(empty($catalog['by_genre']))
                    <div style="text-align:center;color:#555;padding:24px;font-size:13px">Belum ada genre.</div>
                @endif
            </div>
        </div>

        {{-- Demand: most watched genres --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
            <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
                <h3 style="font-size:15px;font-weight:600">Permintaan: Genre Paling Ditonton ({{ $window }}d)</h3>
            </div>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:8px;max-height:420px;overflow-y:auto">
                @foreach(($demand['most_watched_genres'] ?? []) as $g)
                    @php $w = ($g['views'] / $maxDemandViews) * 100; @endphp
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                            <span style="color:#e5e5e5">{{ $g['name'] }}</span>
                            <span style="color:#888">{{ number_format($g['views']) }}</span>
                        </div>
                        <div style="background:#0f0f0f;border-radius:4px;height:6px;overflow:hidden">
                            <div style="height:100%;background:#3b82f6;width:{{ $w }}%;transition:width .3s"></div>
                        </div>
                    </div>
                @endforeach
                @if(empty($demand['most_watched_genres']))
                    <div style="text-align:center;color:#555;padding:24px;font-size:13px">Belum ada aktivitas tonton dalam jendela ini.</div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Decade & language coverage ───────────────────────── --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:16px">

        {{-- By decade --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
            <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
                <h3 style="font-size:15px;font-weight:600">Distribusi Dekade Katalog</h3>
            </div>
            <div style="padding:20px;display:flex;align-items:flex-end;gap:8px;height:200px">
                @foreach(($catalog['by_decade'] ?? []) as $d)
                    @php $h = max(2, ($d['count'] / $maxDecadeCount) * 100); @endphp
                    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;min-width:0">
                        <div style="flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center">
                            <div title="{{ $d['decade'] }}: {{ $d['count'] }} film"
                                 style="width:100%;max-width:36px;height:{{ $h }}%;background:linear-gradient(180deg,{{ $gold }} 0%,rgba(197,165,90,0.4) 100%);border-radius:4px 4px 0 0"></div>
                        </div>
                        <div style="font-size:10px;color:#666;white-space:nowrap">{{ $d['decade'] }}</div>
                    </div>
                @endforeach
                @if(empty($catalog['by_decade']))
                    <div style="margin:auto;color:#555;font-size:13px">Belum ada release_date pada film.</div>
                @endif
            </div>
        </div>

        {{-- By language (subtitle proxy) --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
            <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
                <h3 style="font-size:15px;font-weight:600">Cakupan Bahasa (subtitle aktif)</h3>
            </div>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:6px;max-height:420px;overflow-y:auto">
                @php
                    $byLang = $catalog['by_language'] ?? [];
                    $maxLang = max([1, ...array_column($byLang, 'count')]);
                @endphp
                @forelse($byLang as $l)
                    @php $w = ($l['count'] / $maxLang) * 100; @endphp
                    <div>
                        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                            <span style="color:#e5e5e5;font-family:'JetBrains Mono',monospace;text-transform:uppercase">{{ $l['code'] }}</span>
                            <span style="color:#888">{{ number_format($l['count']) }} film</span>
                        </div>
                        <div style="background:#0f0f0f;border-radius:4px;height:6px;overflow:hidden">
                            <div style="height:100%;background:#22c55e;width:{{ $w }}%"></div>
                        </div>
                    </div>
                @empty
                    <div style="text-align:center;color:#555;padding:24px;font-size:13px">Belum ada subtitle aktif (atau tabel movie_subtitles tidak tersedia).</div>
                @endforelse
            </div>
        </div>
    </div>

    <div style="margin-top:24px;font-size:11px;color:#555;text-align:center">
        Dibuat: {{ $report['generated_at'] ?? '—' }} · Cache 24 jam · Tambahkan <code style="color:{{ $gold }}">?refresh=1</code> untuk paksa hitung ulang.
    </div>

</x-admin.layout>
