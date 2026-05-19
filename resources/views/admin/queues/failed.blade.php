<x-admin.layout title="Failed Jobs">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
        <a href="{{ route('admin.queue-dashboard.index') }}" class="btn btn-ghost btn-sm">
            ← Back to dashboard
        </a>
        <span style="font-size:12px;color:#666">
            {{ $failed->total() }} failed job(s){{ $queue ? ' on queue '.$queue : '' }}{{ $search ? ' matching "'.$search.'"' : '' }}
        </span>
    </div>

    @if(session('success'))
        <div class="flash-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.4);color:#fca5a5;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    {{-- ── Filters ─────────────────────────────────────────────── --}}
    <form method="GET" action="{{ url()->current() }}"
        style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:16px 20px;margin-bottom:20px">
        <div style="display:grid;grid-template-columns:1fr 2fr auto auto;gap:12px;align-items:end">
            <div class="form-group" style="margin:0">
                <label>Queue</label>
                <select name="queue" class="form-input">
                    <option value="">— Any queue —</option>
                    @foreach($queueOptions as $q)
                        <option value="{{ $q }}" @selected($queue === $q)>{{ $q }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label>Search (matches exception or payload)</label>
                <input type="text" name="search" class="form-input"
                    value="{{ $search }}"
                    placeholder="e.g. TimeoutException, App\Jobs\TranscodeMovie">
            </div>
            <button type="submit" class="btn btn-gold btn-sm">Apply</button>
            <a href="{{ url()->current() }}" class="btn btn-ghost btn-sm">Reset</a>
        </div>
    </form>

    {{-- ── Bulk-action form (wraps the table so checkboxes round-trip) ── --}}
    <form method="POST" id="bulk-form" x-data="{ selected: [] }"
        style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">

        @csrf
        {{-- Method spoofing happens per-button (retry-all is POST, bulk
             delete is DELETE) — handled by switching the form action +
             _method input at submit time. --}}

        <div style="padding:12px 20px;border-bottom:1px solid #2a2a2a;display:flex;align-items:center;gap:12px">
            <span style="font-size:12px;color:#666" x-text="selected.length + ' selected'"></span>
            <div style="margin-left:auto;display:flex;gap:8px">
                <button type="button" class="btn btn-ghost btn-sm"
                    :disabled="selected.length === 0"
                    @click="bulkRetry($refs)">
                    Retry selected
                </button>
                <button type="button" class="btn btn-danger btn-sm"
                    :disabled="selected.length === 0"
                    @click="bulkDelete($refs)">
                    Delete selected
                </button>
            </div>
        </div>

        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:32px">
                        <input type="checkbox"
                            @change="selected = $event.target.checked ? Array.from(document.querySelectorAll('input.row-check')).map(c => c.value) : []"
                            style="accent-color:#C5A55A">
                    </th>
                    <th>UUID</th>
                    <th>Queue</th>
                    <th>Job class</th>
                    <th>Failed at</th>
                    <th>Exception</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($failed as $job)
                    @php
                        $payload = json_decode((string) $job->payload, true) ?: [];
                        $jobClass = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'unknown');
                        $exceptionFirstLine = strtok((string) $job->exception, "\n") ?: '';
                    @endphp
                    <tr>
                        <td>
                            <input type="checkbox" class="row-check"
                                value="{{ $job->uuid }}"
                                @change="selected = $event.target.checked
                                    ? [...selected, '{{ $job->uuid }}']
                                    : selected.filter(v => v !== '{{ $job->uuid }}')"
                                style="accent-color:#C5A55A">
                        </td>
                        <td><code style="font-size:11px;color:#888">{{ \Illuminate\Support\Str::limit($job->uuid, 12, '') }}…</code></td>
                        <td><code style="background:#0f0f0f;padding:2px 8px;border-radius:4px;color:#C5A55A">{{ $job->queue }}</code></td>
                        <td><code style="font-size:12px">{{ $jobClass }}</code></td>
                        <td style="color:#999;font-size:12px" title="{{ $job->failed_at }}">
                            {{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}
                        </td>
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
                        <td colspan="7" style="text-align:center;color:#666;padding:32px">
                            No failed jobs match these filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </form>

    {{-- Pagination — withQueryString so filters survive page nav. --}}
    <div style="margin-top:16px">
        {{ $failed->withQueryString()->links() }}
    </div>

    {{-- ── Bulk-action helpers (Alpine-driven) ───────────────────────── --}}
    @push('scripts')
        <script>
            // CSRF token grab — Laravel publishes it in the meta tag from the layout.
            const __csrf = document.querySelector('meta[name="csrf-token"]')?.content;

            // Bulk actions submit one POST per UUID. Doing this client-side
            // (rather than adding a /bulk-retry endpoint) keeps each action
            // visible in the audit log with its own row, which matters for
            // forensics — the operator sees "user X retried 12 jobs in
            // quick succession" rather than one opaque bulk row.
            window.bulkRetry = async function ({}) {
                const boxes = Array.from(document.querySelectorAll('input.row-check:checked'));
                if (boxes.length === 0) return;
                if (!confirm('Retry ' + boxes.length + ' job(s)?')) return;
                for (const cb of boxes) {
                    await fetch('{{ url('/admin/queues/retry') }}/' + cb.value, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': __csrf, 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });
                }
                location.reload();
            };

            window.bulkDelete = async function ({}) {
                const boxes = Array.from(document.querySelectorAll('input.row-check:checked'));
                if (boxes.length === 0) return;
                if (!confirm('Permanently delete ' + boxes.length + ' failed job(s)?')) return;
                for (const cb of boxes) {
                    await fetch('{{ url('/admin/queues/forget') }}/' + cb.value, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': __csrf,
                            'Accept': 'application/json',
                            'X-HTTP-Method-Override': 'DELETE',
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: '_method=DELETE',
                        credentials: 'same-origin',
                    });
                }
                location.reload();
            };
        </script>
    @endpush

</x-admin.layout>
