{{--
    Feature Flag — edit form.

    Strategy picker drives conditionally-rendered config inputs via Alpine
    (role checkbox grid / percentage slider / users textarea / no-config
    notice for off/on/authed/guests). The picker only changes the form's
    visible inputs; the controller persists ONLY the fields relevant to
    the selected strategy so stale config never leaks into the JSON blob.
--}}
<x-admin.layout title="Edit Flag — {{ $flag->name }}">

    <div style="margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <a href="{{ route('admin.feature-flags.index') }}" style="color:#888;font-size:13px;text-decoration:none">&larr; Back to Feature Flags</a>

        {{-- Last-evaluated stat lives here so it's visible without scrolling --}}
        <div style="font-size:12px;color:#666">
            Last updated {{ optional($flag->updated_at)->diffForHumans() ?? 'never' }}
            @if($flag->rollout_started_at)
                &middot; rollout since <span style="color:#C5A55A">{{ $flag->rollout_started_at->diffForHumans() }}</span>
            @endif
        </div>
    </div>

    @if($errors->any())
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#fca5a5;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            <strong>Could not save:</strong>
            <ul style="margin-top:6px;padding-left:20px">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.feature-flags.update', ['flag' => $flag->id]) }}"
          x-data="{
              strategy: @js(old('strategy', $flag->strategy)),
              percentage: @js((int) old('percentage', $percentage)),
              isEnabled: @js((bool) old('is_enabled', $flag->is_enabled)),
          }"
          style="max-width:880px;margin:0 auto">
        @csrf @method('PUT')

        {{-- ─── Identity (key locked, name + description editable) ─── --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:18px">Identity</h2>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                <div class="form-group" style="margin-bottom:0">
                    <label>Key (machine identifier)</label>
                    <input type="text" class="form-input" value="{{ $flag->key }}" disabled readonly>
                    <div style="font-size:11px;color:#888;margin-top:4px">Locked &mdash; renaming would orphan every <code style="background:#0f0f0f;padding:1px 5px;border-radius:4px;color:#C5A55A">feature()</code> call in code.</div>
                </div>

                <div class="form-group" style="margin-bottom:0">
                    <label for="name">Display Name <span style="color:#ef4444">*</span></label>
                    <input type="text" id="name" name="name" class="form-input"
                           value="{{ old('name', $flag->name) }}" required maxlength="160">
                    @error('name')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>

                <div class="form-group" style="grid-column:1 / -1;margin-bottom:0">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" class="form-input" rows="3" maxlength="1000">{{ old('description', $flag->description) }}</textarea>
                    @error('description')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>

        {{-- ─── Master switch ────────────────────────────────────── --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:16px">
            <div>
                <h2 style="font-size:16px;font-weight:600">Master Switch</h2>
                <p style="font-size:12px;color:#777;margin-top:4px">When OFF, the strategy is ignored and every call returns <code style="background:#0f0f0f;padding:1px 5px;border-radius:4px;color:#888">false</code>. Use this for instant kill-switches.</p>
            </div>
            <label class="toggle" style="flex-shrink:0">
                <input type="hidden" name="is_enabled" :value="isEnabled ? 1 : 0">
                <input type="checkbox" x-model="isEnabled">
                <span class="slider"></span>
            </label>
        </div>

        {{-- ─── Strategy picker + config inputs ──────────────────── --}}
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
            <h2 style="font-size:16px;font-weight:600;margin-bottom:6px">Rollout Strategy</h2>
            <p style="font-size:12px;color:#777;margin-bottom:18px">
                Determines WHO sees the feature when the master switch is on. Switching strategies drops stale config from the saved JSON.
            </p>

            <div class="form-group">
                <label for="strategy">Strategy</label>
                <select id="strategy" name="strategy" class="form-input" x-model="strategy">
                    @foreach($strategies as $s)
                        <option value="{{ $s }}">{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
                @error('strategy')<div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $message }}</div>@enderror
            </div>

            {{-- OFF --}}
            <div x-show="strategy === 'off'" x-cloak style="margin-top:16px;padding:14px;background:rgba(120,120,120,0.08);border:1px dashed #333;border-radius:8px;font-size:13px;color:#999">
                <strong style="color:#aaa">Off</strong> &mdash; flag always evaluates to <code style="background:#0f0f0f;padding:1px 5px;border-radius:4px">false</code>, even when the master switch is on. Useful as a "park" state.
            </div>

            {{-- ON --}}
            <div x-show="strategy === 'on'" x-cloak style="margin-top:16px;padding:14px;background:rgba(34,197,94,0.08);border:1px dashed rgba(34,197,94,0.4);border-radius:8px;font-size:13px;color:#86efac">
                <strong style="color:#22c55e">On</strong> &mdash; flag is on for <em>every</em> user (with master switch enabled). Use only when the rollout is complete.
            </div>

            {{-- AUTHED --}}
            <div x-show="strategy === 'authed'" x-cloak style="margin-top:16px;padding:14px;background:rgba(197,165,90,0.08);border:1px dashed rgba(197,165,90,0.4);border-radius:8px;font-size:13px;color:#d4b76a">
                <strong style="color:#C5A55A">Authed</strong> &mdash; on for any logged-in user; off for anonymous visitors.
            </div>

            {{-- GUESTS --}}
            <div x-show="strategy === 'guests'" x-cloak style="margin-top:16px;padding:14px;background:rgba(20,184,166,0.08);border:1px dashed rgba(20,184,166,0.4);border-radius:8px;font-size:13px;color:#5eead4">
                <strong style="color:#14b8a6">Guests</strong> &mdash; on for anonymous visitors only; off the moment the user logs in.
            </div>

            {{-- ROLE --}}
            <div x-show="strategy === 'role'" x-cloak style="margin-top:16px">
                <label>Roles allowed</label>
                @if($availableRoles->isEmpty())
                    <div style="padding:14px;background:#0f0f0f;border:1px dashed #333;border-radius:8px;font-size:13px;color:#888">
                        No roles defined yet. Seed the <code style="color:#C5A55A">roles</code> table first, then return to configure role gates.
                    </div>
                @else
                    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));gap:10px;margin-top:6px">
                        @foreach($availableRoles as $role)
                            <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#0f0f0f;border:1px solid #2a2a2a;border-radius:8px;cursor:pointer;font-size:13px">
                                <input type="checkbox" name="roles[]" value="{{ $role->name }}"
                                       {{ in_array($role->name, old('roles', $selectedRoles), true) ? 'checked' : '' }}
                                       style="accent-color:#C5A55A">
                                <div>
                                    <div style="color:#fff;font-weight:500">{{ $role->display_name ?? $role->name }}</div>
                                    <div style="font-size:10px;color:#666;font-family:'JetBrains Mono',Menlo,monospace">{{ $role->name }}</div>
                                </div>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- PERCENTAGE --}}
            <div x-show="strategy === 'percentage'" x-cloak style="margin-top:16px">
                <label for="percentage">Rollout percentage</label>
                <div style="display:flex;align-items:center;gap:18px;margin-top:6px">
                    <input type="range" id="percentage" name="percentage" min="0" max="100" step="1"
                           x-model.number="percentage"
                           style="flex:1;accent-color:#C5A55A">
                    <div style="font-family:'Outfit';font-size:28px;font-weight:700;color:#C5A55A;min-width:80px;text-align:right">
                        <span x-text="percentage"></span>%
                    </div>
                </div>
                <div style="margin-top:8px;font-size:11px;color:#666">
                    Hashed deterministically on <code style="background:#0f0f0f;padding:1px 5px;border-radius:4px">flag_key + user_id</code> so a user stays inside their cohort across requests. Different flags don't end up rolling out to the same 25%.
                </div>
            </div>

            {{-- USERS --}}
            <div x-show="strategy === 'users'" x-cloak style="margin-top:16px">
                <label for="user_ids">User IDs (comma- or whitespace-separated)</label>
                <textarea id="user_ids" name="user_ids" class="form-input" rows="3"
                          placeholder="1, 42, 137">{{ old('user_ids', $userIds) }}</textarea>
                <div style="font-size:11px;color:#666;margin-top:4px">Non-numeric entries are silently dropped. Best for internal-team dogfood lists.</div>
            </div>
        </div>

        {{-- ─── Actions ──────────────────────────────────────────── --}}
        <div style="display:flex;gap:10px;justify-content:space-between;align-items:center;flex-wrap:wrap">
            <div>
                {{-- Emergency "roll back to 0%" — instantly disables the flag.
                     Submits a separate one-off form that flips is_enabled to 0
                     and clamps the percentage to 0 (defensive: even if an admin
                     re-enables later, they have to opt back in deliberately). --}}
                @if($flag->is_enabled)
                    <form method="POST" action="{{ route('admin.feature-flags.update', ['flag' => $flag->id]) }}" style="display:inline"
                          onsubmit="return confirm('Emergency kill: disable this flag immediately for ALL users? You can re-enable it any time.');">
                        @csrf @method('PUT')
                        <input type="hidden" name="name" value="{{ $flag->name }}">
                        <input type="hidden" name="description" value="{{ $flag->description }}">
                        <input type="hidden" name="strategy" value="{{ $flag->strategy }}">
                        <input type="hidden" name="percentage" value="0">
                        <input type="hidden" name="is_enabled" value="0">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.7-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
                            Roll back to 0%
                        </button>
                    </form>
                @endif
            </div>
            <div style="display:flex;gap:10px">
                <a href="{{ route('admin.feature-flags.index') }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-gold">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Save Changes
                </button>
            </div>
        </div>
    </form>

</x-admin.layout>
