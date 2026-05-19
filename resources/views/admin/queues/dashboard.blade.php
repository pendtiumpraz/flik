<x-admin.layout title="Queue Dashboard">

    {{-- Inline Chart.js for the 24h sparkline. CDN-loaded; no Vite dependency
         so the dashboard renders even if the bundled assets are stale. --}}
    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    @endpush

    @if(session('error'))
        <div style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.4);color:#fca5a5;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    {{-- ── KPI cards + live updater (Alpine) ─────────────────────────── --}}
    <div
        x-data="queueDashboard({
            liveUrl: @js(route('admin.queue-dashboard.live')),
            pendingTotal: {{ (int) $totalPending }},
            failedTotal: {{ (int) $failedTotal }},
            failuresLastHour: {{ (int) $failuresLastHour }},
            oldestPendingIso: @js(optional($oldestPendingAll)->toIso8601String()),
            depths: @js($depths),
            throughput: @js($throughput),
        })"
        x-init="init()">

        <div class="grid-stats" style="margin-bottom:24px">
            <div class="stat-card">
                <div class="label">Pending (all queues)</div>
                <div class="value" x-text="pendingTotal.toLocaleString()"></div>
            </div>
            <div class="stat-card">
                <div class="label">Failed (total)</div>
                <div class="value" x-text="failedTotal.toLocaleString()" style="color:#fca5a5"></div>
            </div>
            <div class="stat-card">
                <div class="label">Oldest pending</div>
                <div class="value" style="font-size:22px" x-text="oldestPendingAge"></div>
            </div>
            <div class="stat-card">
                <div class="label">Failures last hour</div>
                <div class="value" x-text="failuresLastHour.toLocaleString()"></div>
            </div>
        </div>

        <div style="display:flex;align-items:center;gap:10px;font-size:12px;color:#666;margin-bottom:16px">
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e" x-show="polling"></span>
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#666" x-show="!polling"></span>
            <span x-text="polling ? 'Live — auto-refreshing every 5s' : 'Paused (tab hidden)'"></span>
            <span style="margin-left:auto" x-text="'Last updated: ' + lastUpdated"></span>
        </div>

        {{-- ── Per-queue table ───────────────────────────────────────── --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
            <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;align-items:center;justify-content:space-between">
                <h3 style="font-size:14px;font-weight:600;color:#C5A55A;letter-spacing:1px;text-transform:uppercase">
                    Queues
                </h3>
                <span style="font-size:12px;color:#666">
                    Source: <code style="background:#0f0f0f;padding:2px 6px;border-radius:4px">jobs</code> table —
                    database driver only
                </span>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Queue</th>
                        <th style="text-align:right">Pending</th>
                        <th style="text-align:right">Reserved</th>
                        <th>Oldest pending</th>
                        <th>Health</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in depths" :key="row.queue">
                        <tr>
                            <td><code style="background:#0f0f0f;padding:3px 8px;border-radius:4px;color:#C5A55A" x-text="row.queue"></code></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums" x-text="row.pending.toLocaleString()"></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums" x-text="row.reserved.toLocaleString()"></td>
                            <td x-text="oldestAgeFor(row)"></td>
                            <td>
                                <span class="badge"
                                    :style="healthStyle(row.pending)"
                                    x-text="healthLabel(row.pending)"></span>
                            </td>
                            <td style="text-align:right">
                                <form method="POST" action="{{ route('admin.queue-dashboard.retry-all') }}"
                                    onsubmit="return confirm('Retry every failed job on queue ' + this.queue.value + '?')"
                                    style="display:inline">
                                    @csrf
                                    <input type="hidden" name="queue" :value="row.queue">
                                    <button type="submit" class="btn btn-ghost btn-sm" title="Retry all failed jobs on this queue">
                                        Retry all failed
                                    </button>
                                </form>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="depths.length === 0">
                        <td colspan="6" style="text-align:center;color:#666;padding:24px">No queues detected.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- ── Throughput sparkline ──────────────────────────────────── --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;margin-bottom:24px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                <h3 style="font-size:14px;font-weight:600;color:#C5A55A;letter-spacing:1px;text-transform:uppercase">
                    Failures — last 24h
                </h3>
                <span style="font-size:12px;color:#666">
                    Successful throughput requires a Horizon-style ledger; only failures shown here.
                </span>
            </div>
            <div style="height:200px"><canvas x-ref="throughputCanvas"></canvas></div>
        </div>
    </div>

    {{-- ── Recent failed jobs (last 20) ──────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;align-items:center;justify-content:space-between">
            <h3 style="font-size:14px;font-weight:600;color:#C5A55A;letter-spacing:1px;text-transform:uppercase">
                Recent failed jobs
            </h3>
            <a href="{{ route('admin.queue-dashboard.failed') }}" class="btn btn-ghost btn-sm">
                Show all failed →
            </a>
        </div>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>UUID</th>
                    <th>Queue</th>
                    <th>Job class</th>
                    <th>Failed at</th>
                    <th>Exception</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($failedRecent as $job)
                    @php
                        $payload = json_decode((string) $job->payload, true) ?: [];
                        $jobClass = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'unknown');
                        $exceptionFirstLine = strtok((string) $job->exception, "\n") ?: '';
                    @endphp
                    <tr>
                        <td><code style="font-size:11px;color:#888">{{ \Illuminate\Support\Str::limit($job->uuid, 8, '') }}…</code></td>
                        <td><code style="background:#0f0f0f;padding:2px 8px;border-radius:4px;color:#C5A55A">{{ $job->queue }}</code></td>
                        <td><code style="font-size:12px">{{ $jobClass }}</code></td>
                        <td style="color:#999;font-size:12px">{{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}</td>
                        <td style="color:#fca5a5;font-size:12px;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                            title="{{ $exceptionFirstLine }}">
                            {{ \Illuminate\Support\Str::limit($exceptionFirstLine, 80) }}
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <form method="POST" action="{{ route('admin.queue-dashboard.retry', $job->uuid) }}" style="display:inline">
                                @csrf
                                <button type="submit" class="btn btn-ghost btn-sm">Retry</button>
                            </form>
                            <form method="POST" action="{{ route('admin.queue-dashboard.forget', $job->uuid) }}"
                                onsubmit="return confirm('Permanently delete this failed job?')"
                                style="display:inline">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center;color:#666;padding:24px">
                            No failed jobs. Workers are healthy.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── Workers / advisory note ───────────────────────────────────── --}}
    @if(! empty($workers['note']))
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:16px 20px;margin-bottom:24px;color:#888;font-size:13px">
            <strong style="color:#C5A55A">Worker introspection:</strong>
            {{ $workers['note'] }}
        </div>
    @endif

    {{-- ── Flush-failed danger zone ──────────────────────────────────── --}}
    <div x-data="{ open: false }"
        style="background:rgba(220,38,38,0.05);border:1px solid rgba(220,38,38,0.3);border-radius:12px;padding:20px;margin-bottom:24px">
        <div style="display:flex;align-items:center;justify-content:space-between">
            <div>
                <h3 style="font-size:14px;font-weight:600;color:#dc2626;letter-spacing:1px;text-transform:uppercase;margin-bottom:4px">
                    Danger zone — flush failed
                </h3>
                <p style="font-size:13px;color:#888">
                    Wipes the entire <code style="background:#0f0f0f;padding:2px 6px;border-radius:4px">failed_jobs</code> table.
                    Cannot be undone. Re-prompts for your admin password before executing.
                </p>
            </div>
            <button type="button" @click="open = true" class="btn btn-danger">
                Flush all failed
            </button>
        </div>

        {{-- Confirm modal --}}
        <div x-show="open" x-cloak
            @keydown.escape.window="open = false"
            style="position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:50;display:flex;align-items:center;justify-content:center;padding:16px">
            <div @click.outside="open = false"
                style="background:#1a1a1a;border:1px solid rgba(220,38,38,0.4);border-radius:12px;padding:24px;width:100%;max-width:480px">
                <h3 style="font-size:18px;color:#dc2626;margin-bottom:8px">Confirm flush</h3>
                <p style="color:#aaa;font-size:14px;margin-bottom:20px">
                    This permanently deletes <strong style="color:#fff">{{ $failedTotal }}</strong> failed-job records.
                    Re-enter your admin password to proceed.
                </p>
                <form method="POST" action="{{ route('admin.queue-dashboard.flush') }}">
                    @csrf
                    <div class="form-group">
                        <label>Current password</label>
                        <input type="password" name="confirm_password" required autocomplete="current-password"
                            class="form-input" placeholder="••••••••">
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end">
                        <button type="button" class="btn btn-ghost" @click="open = false">Cancel</button>
                        <button type="submit" class="btn btn-danger">Flush all failed</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ── Alpine data fn + chart bootstrap ──────────────────────────── --}}
    @push('scripts')
        <script>
            function queueDashboard(initial) {
                return {
                    liveUrl: initial.liveUrl,
                    pendingTotal: initial.pendingTotal,
                    failedTotal: initial.failedTotal,
                    failuresLastHour: initial.failuresLastHour,
                    oldestPendingIso: initial.oldestPendingIso,
                    depths: initial.depths.map(d => ({
                        queue: d.queue,
                        pending: d.pending,
                        reserved: d.reserved,
                        oldest_pending_iso: d.oldest_pending ? d.oldest_pending : null,
                    })),
                    throughput: initial.throughput,
                    polling: !document.hidden,
                    intervalId: null,
                    chart: null,
                    lastUpdated: new Date().toLocaleTimeString(),

                    get oldestPendingAge() {
                        return this.humanAge(this.oldestPendingIso) || '—';
                    },

                    init() {
                        // Build the chart once Chart.js has loaded. The library is
                        // loaded with `defer`, so we wait for window load to be safe.
                        const start = () => {
                            if (typeof Chart === 'undefined') {
                                setTimeout(start, 100);
                                return;
                            }
                            this.renderChart();
                        };
                        window.addEventListener('load', start);
                        if (document.readyState === 'complete') start();

                        // Visibility API — pause polling when tab hidden so the
                        // server isn't pinged from 50 stale tabs sitting on
                        // someone's second monitor.
                        document.addEventListener('visibilitychange', () => {
                            if (document.hidden) {
                                this.stopPolling();
                            } else {
                                this.startPolling();
                                this.fetchLive(); // immediate refresh on focus
                            }
                        });

                        if (!document.hidden) this.startPolling();
                    },

                    startPolling() {
                        if (this.intervalId !== null) return;
                        this.polling = true;
                        this.intervalId = setInterval(() => this.fetchLive(), 5000);
                    },

                    stopPolling() {
                        if (this.intervalId !== null) {
                            clearInterval(this.intervalId);
                            this.intervalId = null;
                        }
                        this.polling = false;
                    },

                    async fetchLive() {
                        try {
                            const res = await fetch(this.liveUrl, {
                                headers: { 'Accept': 'application/json' },
                                credentials: 'same-origin',
                            });
                            if (!res.ok) return;
                            const data = await res.json();
                            this.pendingTotal = data.total_pending;
                            this.failedTotal = data.failed_total;
                            this.failuresLastHour = data.failures_last_hour;
                            this.depths = data.depths.map(d => ({
                                queue: d.queue,
                                pending: d.pending,
                                reserved: d.reserved,
                                oldest_pending_iso: d.oldest_pending_iso,
                            }));
                            // The overall oldest pending = min ISO timestamp across queues.
                            const isos = this.depths.map(d => d.oldest_pending_iso).filter(Boolean);
                            this.oldestPendingIso = isos.length ? isos.sort()[0] : null;
                            this.throughput = data.throughput_24h;
                            this.lastUpdated = new Date().toLocaleTimeString();
                            this.refreshChart();
                        } catch (e) {
                            // Network blip — silently skip this tick; the next
                            // poll will reconcile.
                        }
                    },

                    renderChart() {
                        const ctx = this.$refs.throughputCanvas;
                        if (!ctx) return;
                        this.chart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: this.throughput.map(p => p.hour.substring(5, 13)),
                                datasets: [{
                                    label: 'Failures',
                                    data: this.throughput.map(p => p.failed_count),
                                    borderColor: '#fca5a5',
                                    backgroundColor: 'rgba(239,68,68,0.15)',
                                    fill: true,
                                    tension: 0.3,
                                    pointRadius: 2,
                                    pointBackgroundColor: '#dc2626',
                                }],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: {
                                    x: { ticks: { color: '#666', font: { size: 10 } }, grid: { color: '#222' } },
                                    y: { beginAtZero: true, ticks: { color: '#666', precision: 0 }, grid: { color: '#222' } },
                                },
                            },
                        });
                    },

                    refreshChart() {
                        if (!this.chart) return;
                        this.chart.data.labels = this.throughput.map(p => p.hour.substring(5, 13));
                        this.chart.data.datasets[0].data = this.throughput.map(p => p.failed_count);
                        this.chart.update('none');
                    },

                    healthLabel(pending) {
                        if (pending > 500) return 'CRITICAL';
                        if (pending > 100) return 'WARNING';
                        return 'HEALTHY';
                    },

                    healthStyle(pending) {
                        if (pending > 500) return 'background:rgba(220,38,38,0.2);color:#fca5a5';
                        if (pending > 100) return 'background:rgba(234,179,8,0.2);color:#facc15';
                        return 'background:rgba(34,197,94,0.2);color:#22c55e';
                    },

                    oldestAgeFor(row) {
                        return this.humanAge(row.oldest_pending_iso) || '—';
                    },

                    humanAge(iso) {
                        if (!iso) return null;
                        const then = new Date(iso).getTime();
                        const now = Date.now();
                        const sec = Math.max(0, Math.floor((now - then) / 1000));
                        if (sec < 60) return sec + 's ago';
                        if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
                        if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
                        return Math.floor(sec / 86400) + 'd ago';
                    },
                };
            }
        </script>
    @endpush
</x-admin.layout>
