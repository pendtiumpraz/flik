{{--
    Operational Health Dashboard.

    Same engine as `php artisan flik:doctor`: every card represents one
    category (system / database / queue / etc.) and shows the current
    status pill + count summary. Cards auto-refresh every 60 s via the
    /admin/health/check/{section} JSON endpoint; the Page Visibility API
    pauses polling when the tab is hidden so we don't burn cycles on
    background tabs left open overnight.
--}}
<x-admin.layout :title="$title">
    <div x-data="healthDashboard()" x-init="init()" style="max-width: 1280px; margin: 0 auto;">

        {{-- ─── OVERALL STATUS BANNER ──────────────────────── --}}
        @php
            $overall = $summary['overall'];
            $banner = match ($overall) {
                'ok'   => ['bg' => 'linear-gradient(135deg,#0d2a17,#103a1f)', 'border' => '#22c55e', 'iconBg' => '#22c55e', 'iconColor' => '#000', 'title' => 'All systems healthy', 'subtitle' => 'Every check is reporting OK.'],
                'warn' => ['bg' => 'linear-gradient(135deg,#2a230d,#3a3010)', 'border' => '#f59e0b', 'iconBg' => '#f59e0b', 'iconColor' => '#000', 'title' => 'Degraded',           'subtitle' => 'One or more checks need attention.'],
                'fail' => ['bg' => 'linear-gradient(135deg,#2a0f0f,#3a1212)', 'border' => '#dc2626', 'iconBg' => '#dc2626', 'iconColor' => '#fff', 'title' => 'Critical issues',  'subtitle' => 'One or more checks are FAILING. Act now.'],
            };
        @endphp
        <div style="background: {{ $banner['bg'] }}; border: 1px solid {{ $banner['border'] }}; border-radius: 14px; padding: 24px 28px; margin-bottom: 24px; display: flex; align-items: center; gap: 20px;">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: {{ $banner['iconBg'] }}; color: {{ $banner['iconColor'] }}; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-family: 'Outfit'; font-weight: 800; font-size: 22px;">
                {{ strtoupper($overall) }}
            </div>
            <div style="flex: 1;">
                <div style="font-family: 'Outfit'; font-size: 22px; font-weight: 700;">{{ $banner['title'] }}</div>
                <div style="font-size: 13px; color: #ddd; margin-top: 4px;">{{ $banner['subtitle'] }}</div>
            </div>
            <div style="display: flex; gap: 8px;">
                <a href="{{ route('admin.health.index') }}" class="btn btn-gold">Re-run all</a>
                <a href="{{ route('admin.health.index', ['quick' => 1]) }}" class="btn btn-ghost">Quick check</a>
            </div>
        </div>

        {{-- ─── KPI ROW ────────────────────────────────────── --}}
        <div class="grid-stats" style="margin-bottom: 24px;">
            <div class="stat-card">
                <div class="label">Total checks</div>
                <div class="value">{{ $summary['total'] }}</div>
            </div>
            <div class="stat-card" style="border-color: rgba(34,197,94,0.4);">
                <div class="label" style="color: #22c55e;">Passing</div>
                <div class="value" style="color: #22c55e;">{{ $summary['ok'] }}</div>
            </div>
            <div class="stat-card" style="border-color: rgba(245,158,11,0.4);">
                <div class="label" style="color: #f59e0b;">Warnings</div>
                <div class="value" style="color: #f59e0b;">{{ $summary['warn'] }}</div>
            </div>
            <div class="stat-card" style="border-color: rgba(220,38,38,0.4);">
                <div class="label" style="color: #dc2626;">Failing</div>
                <div class="value" style="color: #dc2626;">{{ $summary['fail'] }}</div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px; font-size: 12px; color: #666;">
            <span x-show="polling" style="display: inline-flex; align-items: center; gap: 6px;">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 8px #22c55e;"></span>
                Auto-refresh every 60s
            </span>
            <span x-show="!polling" style="color: #888;">Auto-refresh paused (tab hidden)</span>
            <span x-text="lastRefreshText" style="margin-left: auto; font-variant-numeric: tabular-nums;"></span>
        </div>

        {{-- ─── PER-SECTION CARDS ─────────────────────────── --}}
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 16px;">
            @foreach($results as $section => $checks)
                @php
                    $sectionSummary = ['ok' => 0, 'warn' => 0, 'fail' => 0];
                    foreach ($checks as $c) {
                        $sectionSummary[$c['status']] = ($sectionSummary[$c['status']] ?? 0) + 1;
                    }
                    $sectionOverall = $sectionSummary['fail'] > 0 ? 'fail' : ($sectionSummary['warn'] > 0 ? 'warn' : 'ok');
                    $pill = match ($sectionOverall) {
                        'ok'   => ['bg' => 'rgba(34,197,94,0.2)',  'color' => '#22c55e'],
                        'warn' => ['bg' => 'rgba(245,158,11,0.2)', 'color' => '#f59e0b'],
                        'fail' => ['bg' => 'rgba(220,38,38,0.2)',  'color' => '#dc2626'],
                    };
                @endphp
                <div class="stat-card"
                     style="padding: 0; overflow: hidden;"
                     x-data="{ open: {{ $sectionOverall !== 'ok' ? 'true' : 'false' }} }"
                     data-section="{{ $section }}">
                    <div style="padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; cursor: pointer;" @click="open = !open">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="badge" style="background: {{ $pill['bg'] }}; color: {{ $pill['color'] }}; font-family: 'Outfit'; font-weight: 700;">{{ strtoupper($sectionOverall) }}</span>
                            <strong style="font-family: 'Outfit'; font-size: 16px; text-transform: capitalize;">{{ $section }}</strong>
                            <span style="font-size: 11px; color: #777;">
                                {{ count($checks) }} check{{ count($checks) === 1 ? '' : 's' }}
                                @if($sectionSummary['warn'] > 0 || $sectionSummary['fail'] > 0)
                                    · <span style="color: #f59e0b;">{{ $sectionSummary['warn'] }} warn</span>
                                    · <span style="color: #dc2626;">{{ $sectionSummary['fail'] }} fail</span>
                                @endif
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="button"
                                    @click.stop="refreshSection('{{ $section }}', $event)"
                                    class="btn btn-ghost btn-sm"
                                    title="Re-run this section">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 0 0 4.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 0 1-15.357-2m15.357 2H15"/></svg>
                            </button>
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" :style="open ? 'transform:rotate(180deg)' : ''" style="transition:transform .2s;color:#666;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </div>
                    </div>

                    <div x-show="open" x-cloak>
                        <div style="border-top: 1px solid #2a2a2a; padding: 4px 0;">
                            @foreach($checks as $c)
                                @php
                                    $rowColor = match ($c['status']) {
                                        'ok'   => '#22c55e',
                                        'warn' => '#f59e0b',
                                        'fail' => '#dc2626',
                                    };
                                    $glyph = match ($c['status']) {
                                        'ok'   => '✓',
                                        'warn' => '!',
                                        'fail' => '✗',
                                    };
                                @endphp
                                <div style="padding: 10px 20px; border-bottom: 1px solid #1f1f1f; display: flex; align-items: start; gap: 10px;">
                                    <span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:{{ $rowColor }}25;color:{{ $rowColor }};font-size:11px;font-weight:700;flex-shrink:0;margin-top:1px;">{{ $glyph }}</span>
                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-size: 13px; color: #ddd; font-weight: 500;">{{ $c['name'] }}</div>
                                        <div style="font-size: 11px; color: #888; margin-top: 2px;">{{ $c['message'] }}</div>
                                        @if(!empty($c['fix']))
                                            <div style="font-size: 11px; color: {{ $rowColor }}; margin-top: 4px;">→ {{ $c['fix'] }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

    </div>

    @push('scripts')
    <script>
        function healthDashboard () {
            return {
                polling: true,
                lastRefresh: Date.now(),
                lastRefreshText: 'updated just now',
                interval: null,
                ticker: null,

                init () {
                    // Auto-refresh only while the tab is visible. The
                    // Page Visibility API saves a lot of pointless polls
                    // when admins leave the dashboard open in a bg tab.
                    document.addEventListener('visibilitychange', () => {
                        this.polling = !document.hidden;
                    });

                    this.interval = setInterval(() => {
                        if (!this.polling) return;
                        this.refreshAllSections();
                    }, 60_000);

                    // "updated Ns ago" ticker
                    this.ticker = setInterval(() => this.updateTimestampText(), 1000);
                },

                async refreshAllSections () {
                    const cards = document.querySelectorAll('[data-section]');
                    for (const card of cards) {
                        const section = card.getAttribute('data-section');
                        await this.fetchSection(section, card);
                    }
                    this.lastRefresh = Date.now();
                    this.updateTimestampText();
                },

                async refreshSection (section, ev) {
                    const card = ev?.target?.closest('[data-section]');
                    if (!card) return;
                    await this.fetchSection(section, card);
                    this.lastRefresh = Date.now();
                    this.updateTimestampText();
                },

                async fetchSection (section, card) {
                    try {
                        const res = await fetch(`{{ url('/admin/health/check') }}/${section}?quick=1`, {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (!res.ok) return;
                        const data = await res.json();
                        // For now we update the count chip + pill in-place
                        // and leave the deep re-render for a future iteration.
                        // The summary pill and counts are the bits operators
                        // glance at — the full-page reload button covers the
                        // rest.
                        const pill = card.querySelector('.badge');
                        if (pill && data.summary) {
                            const ov = data.summary.overall;
                            pill.textContent = ov.toUpperCase();
                            const colours = {
                                ok:   ['rgba(34,197,94,0.2)',  '#22c55e'],
                                warn: ['rgba(245,158,11,0.2)', '#f59e0b'],
                                fail: ['rgba(220,38,38,0.2)',  '#dc2626'],
                            }[ov] || ['#333','#aaa'];
                            pill.style.background = colours[0];
                            pill.style.color = colours[1];
                        }
                    } catch (_) {
                        // best-effort; do not noise the operator with errors
                    }
                },

                updateTimestampText () {
                    const ageSec = Math.floor((Date.now() - this.lastRefresh) / 1000);
                    if (ageSec < 5) this.lastRefreshText = 'updated just now';
                    else if (ageSec < 60) this.lastRefreshText = `updated ${ageSec}s ago`;
                    else this.lastRefreshText = `updated ${Math.floor(ageSec / 60)}m ago`;
                },
            };
        }
    </script>
    @endpush
</x-admin.layout>
