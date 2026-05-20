{{--
    Maintenance Mode admin dashboard.

    Layout: <x-admin.layout> (gold theme + sidebar + Alpine).

    The big status card is the most important affordance — operators glancing
    at this page need to know in <1s whether the site is in maintenance.
    Red = enabled, Green = live. The bypass form below is collapsible so the
    "everything is fine, leave it alone" state is a single screen.
--}}
<x-admin.layout :title="$title">
    <div style="max-width: 1100px; margin: 0 auto;">

        {{-- ─── BIG STATUS BANNER ────────────────────────────── --}}
        @if($state->isEnabled())
            <div style="background: linear-gradient(135deg, #2a0f0f, #3a1212); border: 1px solid #dc2626; border-radius: 14px; padding: 24px 28px; margin-bottom: 24px; display: flex; align-items: center; gap: 20px;">
                <div style="width: 60px; height: 60px; border-radius: 50%; background: #dc2626; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="32" height="32" fill="none" stroke="#fff" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div style="flex: 1;">
                    <div style="font-family: 'Outfit'; font-size: 22px; font-weight: 700; color: #fca5a5;">Maintenance mode is ACTIVE</div>
                    <div style="font-size: 13px; color: #f4cccc; margin-top: 4px;">
                        Site is hidden from public.
                        @if($state->enabled_at)
                            Enabled {{ $state->enabled_at->diffForHumans() }}
                            @if($state->enabledBy) by <strong>{{ $state->enabledBy->name }}</strong>@endif.
                        @endif
                        @if($state->scheduled_until)
                            Scheduled until <strong>{{ $state->scheduled_until->format('Y-m-d H:i') }}</strong> ({{ $state->scheduled_until->diffForHumans() }}).
                        @endif
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.maintenance.disable') }}"
                      onsubmit="return confirm('Disable maintenance mode and bring the site back online for everyone?');">
                    @csrf
                    <button type="submit" class="btn btn-gold" style="font-size: 14px; padding: 10px 20px;">
                        Disable Now
                    </button>
                </form>
            </div>
        @else
            <div style="background: linear-gradient(135deg, #0d2a17, #103a1f); border: 1px solid #22c55e; border-radius: 14px; padding: 24px 28px; margin-bottom: 24px; display: flex; align-items: center; gap: 20px;">
                <div style="width: 60px; height: 60px; border-radius: 50%; background: #22c55e; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <svg width="32" height="32" fill="none" stroke="#000" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div style="flex: 1;">
                    <div style="font-family: 'Outfit'; font-size: 22px; font-weight: 700; color: #86efac;">Site is LIVE</div>
                    <div style="font-size: 13px; color: #ccf0d6; margin-top: 4px;">All visitors can access the site normally. Use the form below to schedule or trigger a maintenance window.</div>
                </div>
            </div>
        @endif

        {{-- Validation errors --}}
        @if($errors->any())
            <div style="background: rgba(220,38,38,0.15); border: 1px solid rgba(220,38,38,0.3); color: #fca5a5; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                <strong>Could not save:</strong>
                <ul style="margin-top: 6px; padding-left: 20px;">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div style="display: grid; grid-template-columns: 1fr 360px; gap: 24px; align-items: start;">

            {{-- ─── CONFIGURATION FORM ────────────────────────── --}}
            <div style="background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px; padding: 24px;">
                <h2 style="font-size: 18px; font-weight: 600; margin-bottom: 4px;">Configure maintenance window</h2>
                <p style="font-size: 12px; color: #777; margin-bottom: 20px;">
                    "Save Settings" updates the message and bypass rules without flipping the switch — useful for pre-staging an outage notice.
                </p>

                <form id="maintenance-form"
                      method="POST"
                      action="{{ $state->isEnabled() ? route('admin.maintenance.update') : route('admin.maintenance.enable') }}">
                    @csrf

                    <div class="form-group">
                        <label for="message">Message to visitors (plain text — shown on the 503 page)</label>
                        <textarea name="message" id="message" class="form-input" rows="4" placeholder="Kami sedang melakukan pemeliharaan terjadwal. Mohon coba lagi dalam beberapa menit. Terima kasih atas kesabaran Anda.">{{ old('message', $state->message) }}</textarea>
                        <div style="font-size: 11px; color: #555; margin-top: 4px;">Max 1000 characters. Indonesian and English both render correctly.</div>
                    </div>

                    <div class="form-group">
                        <label for="scheduled_until">Scheduled until (optional — drives the countdown on the 503 page)</label>
                        <input type="datetime-local"
                               name="scheduled_until"
                               id="scheduled_until"
                               class="form-input"
                               value="{{ old('scheduled_until', optional($state->scheduled_until)->format('Y-m-d\TH:i')) }}">
                    </div>

                    <div class="form-group">
                        <label for="allow_ips">IP allow-list (one per line — these IPs bypass the switch)</label>
                        <textarea name="allow_ips" id="allow_ips" class="form-input" rows="4" placeholder="203.0.113.42&#10;2001:db8::1">{{ old('allow_ips', implode("\n", $state->allow_ips ?? [])) }}</textarea>
                        <div style="font-size: 11px; color: #555; margin-top: 4px;">
                            Your current IP is <code style="background: #0f0f0f; padding: 2px 6px; border-radius: 4px;">{{ request()->ip() }}</code>.
                            CIDR ranges are not supported yet — list each IP explicitly.
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Roles that bypass the switch</label>
                        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 6px;">
                            @php
                                $currentRoles = old('allow_roles', $state->allow_roles ?? ['super_admin']);
                            @endphp
                            @foreach($availableRoles as $key => $label)
                                <label style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #0f0f0f; border: 1px solid #2a2a2a; border-radius: 6px; cursor: pointer; font-size: 13px;">
                                    <input type="checkbox"
                                           name="allow_roles[]"
                                           value="{{ $key }}"
                                           {{ in_array($key, $currentRoles, true) ? 'checked' : '' }}
                                           {{ $key === 'super_admin' ? 'disabled checked' : '' }}>
                                    {{ $label }}
                                    @if($key === 'super_admin')
                                        <span style="margin-left: auto; font-size: 10px; color: #C5A55A;">(forced)</span>
                                    @endif
                                </label>
                            @endforeach
                            {{-- Re-include super_admin via hidden input because disabled checkboxes don't POST. --}}
                            <input type="hidden" name="allow_roles[]" value="super_admin">
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 24px;">
                        @if($state->isEnabled())
                            <button type="submit" class="btn btn-gold">Save Settings</button>
                        @else
                            <button type="submit"
                                    class="btn btn-danger"
                                    onclick="return confirm('Enable maintenance mode RIGHT NOW? All visitors who do not match the bypass list will see the 503 page.');">
                                Enable Maintenance Mode
                            </button>
                            <button type="submit"
                                    class="btn btn-ghost"
                                    formaction="{{ route('admin.maintenance.update') }}">
                                Save Settings Only (don't enable)
                            </button>
                        @endif
                    </div>
                </form>
            </div>

            {{-- ─── HISTORY SIDEBAR ──────────────────────────── --}}
            <div style="background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px; padding: 20px;">
                <h3 style="font-size: 14px; font-weight: 600; color: #C5A55A; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px;">Activation History</h3>

                @forelse($history as $h)
                    <div style="padding: 12px 0; border-bottom: 1px solid #222;">
                        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px;">
                            @if($h->action === 'maintenance.enabled')
                                <span class="badge" style="background: rgba(220,38,38,0.2); color: #fca5a5;">ON</span>
                            @elseif($h->action === 'maintenance.disabled')
                                <span class="badge" style="background: rgba(34,197,94,0.2); color: #86efac;">OFF</span>
                            @else
                                <span class="badge badge-blue">EDIT</span>
                            @endif
                            <span style="color: #999;">{{ $h->user?->name ?? 'system' }}</span>
                        </div>
                        <div style="font-size: 11px; color: #666; margin-top: 4px;">
                            {{ $h->created_at?->diffForHumans() }} —
                            <code style="color: #888;">{{ $h->client_ip ?? '?' }}</code>
                        </div>
                    </div>
                @empty
                    <div style="font-size: 12px; color: #555; padding: 12px 0;">No activations recorded yet.</div>
                @endforelse
            </div>
        </div>

        {{-- Footnote about Laravel's native maintenance --}}
        <div style="margin-top: 24px; padding: 16px 20px; background: rgba(197,165,90,0.06); border-left: 3px solid #C5A55A; border-radius: 6px; font-size: 12px; color: #aaa;">
            <strong style="color: #C5A55A;">Note:</strong> this is the App-level switch managed via the DB.
            Laravel's native <code>php artisan down</code> command also still works (it writes a file marker
            at <code>storage/framework/down</code>) and runs in the global middleware stack <em>before</em> this one.
            Use whichever is more convenient — they don't conflict.
        </div>
    </div>
</x-admin.layout>
