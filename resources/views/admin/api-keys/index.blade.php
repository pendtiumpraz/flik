<x-admin.layout title="API Keys">

    {{-- ── New-key modal: shown ONCE right after creation ─────────────────── --}}
    @if(!empty($newPlaintext))
        <div x-data="{ open: true, copied: false }"
             x-show="open"
             x-cloak
             style="position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:50">
            <div @click.outside="open = false"
                 style="background:#1a1a1a;border:1px solid #C5A55A;border-radius:14px;max-width:600px;width:90%;padding:28px">
                <h2 style="font-size:18px;font-weight:700;color:#C5A55A;margin-bottom:6px">API key created</h2>
                <p style="font-size:13px;color:#aaa;margin-bottom:18px">
                    <strong style="color:#fff">{{ $newName }}</strong> — copy the key now.
                    It will not be shown again. Store it in your service's secret manager.
                </p>

                <div style="background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px;padding:14px;font-family:monospace;font-size:13px;color:#C5A55A;word-break:break-all;margin-bottom:14px">{{ $newPlaintext }}</div>

                <div style="display:flex;gap:10px;justify-content:flex-end">
                    <button type="button"
                            class="btn btn-ghost btn-sm"
                            @click="navigator.clipboard.writeText(@js($newPlaintext)); copied = true; setTimeout(() => copied = false, 1800)">
                        <span x-show="!copied">Copy</span>
                        <span x-show="copied" x-cloak>Copied</span>
                    </button>
                    <button type="button" class="btn btn-gold btn-sm" @click="open = false">I've stored it</button>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Create form ─────────────────────────────────────────────────────── --}}
    <form method="POST" action="{{ route('admin.api-keys.store') }}"
          style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;margin-bottom:24px">
        @csrf
        <h3 style="font-size:14px;font-weight:600;color:#C5A55A;letter-spacing:1px;text-transform:uppercase;margin-bottom:14px">
            Issue new key
        </h3>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:14px">
            <div class="form-group" style="margin:0">
                <label>Name <span style="color:#dc2626">*</span></label>
                <input type="text" name="name" class="form-input" required maxlength="80"
                       placeholder="e.g. Subtitle Worker (prod)" value="{{ old('name') }}">
                @error('name')<div style="color:#dc2626;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
            </div>

            <div class="form-group" style="margin:0">
                <label>Abilities (CSV)</label>
                <input type="text" name="abilities" class="form-input" maxlength="500"
                       placeholder="* or movies.read,movies.write" value="{{ old('abilities', '*') }}">
                @error('abilities')<div style="color:#dc2626;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
            </div>

            <div class="form-group" style="margin:0">
                <label>Expires at (optional)</label>
                <input type="datetime-local" name="expires_at" class="form-input" value="{{ old('expires_at') }}">
                @error('expires_at')<div style="color:#dc2626;font-size:12px;margin-top:4px">{{ $message }}</div>@enderror
            </div>
        </div>

        <button type="submit" class="btn btn-gold">
            Generate key
        </button>
    </form>

    {{-- ── Existing keys table ─────────────────────────────────────────────── --}}
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:15px;font-weight:600">
                Existing keys
                <span style="font-size:12px;color:#777;font-weight:400;margin-left:6px">
                    ({{ number_format($keys->total()) }} total)
                </span>
            </h3>
        </div>

        <div style="overflow-x:auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Prefix</th>
                        <th>Abilities</th>
                        <th>Created by</th>
                        <th>Created</th>
                        <th>Last used</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($keys as $key)
                        <tr>
                            <td style="color:#fff;font-weight:500">{{ $key->name }}</td>
                            <td style="font-family:monospace;color:#C5A55A">{{ $key->key_prefix }}…</td>
                            <td>
                                @foreach((array) ($key->abilities ?? ['*']) as $ability)
                                    <span class="badge badge-blue" style="margin-right:4px">{{ $ability }}</span>
                                @endforeach
                            </td>
                            <td style="color:#aaa">{{ $key->creator?->name ?? '—' }}</td>
                            <td style="color:#777;font-size:12px">
                                {{ optional($key->created_at)->diffForHumans() ?? '—' }}
                            </td>
                            <td style="color:#777;font-size:12px">
                                @if($key->last_used_at)
                                    {{ $key->last_used_at->diffForHumans() }}
                                    <div style="font-size:11px;color:#555;margin-top:2px">{{ $key->last_used_ip }}</div>
                                @else
                                    <span style="color:#555">never</span>
                                @endif
                            </td>
                            <td>
                                @if($key->isRevoked())
                                    <span class="badge" style="background:rgba(220,38,38,0.2);color:#dc2626">revoked</span>
                                @elseif($key->isExpired())
                                    <span class="badge" style="background:rgba(245,158,11,0.2);color:#f59e0b">expired</span>
                                @else
                                    <span class="badge badge-green">active</span>
                                    @if($key->expires_at)
                                        <div style="font-size:11px;color:#555;margin-top:2px">
                                            until {{ $key->expires_at->format('Y-m-d H:i') }}
                                        </div>
                                    @endif
                                @endif
                            </td>
                            <td style="text-align:right">
                                @unless($key->isRevoked())
                                    <form method="POST" action="{{ route('admin.api-keys.destroy', $key->id) }}"
                                          onsubmit="return confirm('Revoke {{ $key->name }}? This cannot be undone.')"
                                          style="display:inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align:center;color:#666;padding:32px">
                                No API keys yet. Issue one above to grant service-to-service access.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($keys->hasPages())
            <div style="padding:16px 20px;border-top:1px solid #2a2a2a">
                {{ $keys->links() }}
            </div>
        @endif
    </div>

</x-admin.layout>
