{{--
    Settings registry — tabbed editor grouped by the `group` column.

    Per row the input type is driven by the `type` column (string / int /
    float / bool / json / array). Secret rows render as masked dots with a
    "Show" toggle so the operator can opt-in to reveal before editing.

    Submission is a single bulk POST: every visible setting is sent back,
    the controller diff-skips no-op rows so we only audit-log real changes.
--}}
<x-admin.layout title="Settings">

    @push('styles')
    <style>
        /* Tabbed settings registry — self-contained styling so the page no
           longer depends on the undefined .tab-active / .tab-inactive classes. */
        .settings-tabs {
            display: flex; gap: 4px; flex-wrap: wrap;
            border-bottom: 1px solid #2a2a2a; margin-bottom: 22px;
        }
        .settings-tab {
            background: transparent; border: none; cursor: pointer;
            padding: 10px 18px; font-size: 13px; font-weight: 500;
            color: #888; border-bottom: 2px solid transparent;
            border-radius: 6px 6px 0 0; text-transform: capitalize;
            transition: color .15s, background .15s, border-color .15s;
            white-space: nowrap;
        }
        .settings-tab:hover { color: #ccc; background: rgba(255,255,255,0.03); }
        .settings-tab.is-active {
            color: #C5A55A; border-bottom-color: #C5A55A;
            background: rgba(197,165,90,0.08);
        }
        .settings-tab .cnt { font-size: 11px; color: #555; margin-left: 2px; }

        /* Per-setting row: label column + input column, stacks on narrow screens. */
        .setting-row {
            display: grid; grid-template-columns: 280px 1fr; gap: 24px;
            padding-bottom: 20px; border-bottom: 1px solid #1f1f1f;
        }
        .setting-row:last-child { border-bottom: none; padding-bottom: 0; }
        @media (max-width: 820px) {
            .setting-row { grid-template-columns: 1fr; gap: 10px; }
        }
    </style>
    @endpush

    {{-- ─── Helper-usage callout ───────────────────────────────── --}}
    <div style="background:rgba(197,165,90,0.08);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:14px">
        <div style="width:32px;height:32px;border-radius:8px;background:rgba(197,165,90,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <svg width="18" height="18" fill="none" stroke="#C5A55A" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        </div>
        <div style="flex:1;font-size:13px;color:#bbb;line-height:1.55">
            <strong style="color:#C5A55A">Try in code:</strong>
            read any setting with <code style="background:#0f0f0f;padding:2px 7px;border-radius:5px;color:#C5A55A;font-family:'JetBrains Mono',Menlo,monospace;font-size:12px">setting('site.name', 'FLiK')</code>
            in PHP, or
            <code style="background:#0f0f0f;padding:2px 7px;border-radius:5px;color:#C5A55A;font-family:'JetBrains Mono',Menlo,monospace;font-size:12px">@setting('site.name', 'FLiK')</code>
            in Blade. Reads are cached 1 hour; the cache busts the moment you save a value below.
        </div>
    </div>

    @if($grouped->isEmpty())
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:60px 20px;text-align:center">
            <div style="font-family:'Outfit';font-size:16px;font-weight:600;color:#888;margin-bottom:6px">No settings registered</div>
            <p style="font-size:13px;color:#666;margin-bottom:18px">Run <code style="background:#0f0f0f;padding:2px 7px;border-radius:5px;color:#C5A55A">php artisan db:seed --class=SettingSeeder</code> to populate the canonical defaults.</p>
            <form method="POST" action="{{ route('admin.settings.restore-defaults') }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-gold">Restore Defaults</button>
            </form>
        </div>
    @else
        <div x-data="{ tab: @js($groupKeys[0] ?? 'general') }">

            {{-- ─── Tab bar ─────────────────────────────────────── --}}
            <div class="settings-tabs">
                @foreach($groupKeys as $group)
                    <button type="button"
                            @click="tab = @js($group)"
                            :class="tab === @js($group) ? 'settings-tab is-active' : 'settings-tab'">
                        {{ $group }} <span class="cnt">({{ $grouped[$group]->count() }})</span>
                    </button>
                @endforeach
            </div>

            {{-- ─── Validation feedback ─────────────────────────── --}}
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

            {{-- ─── Per-group tab panels ────────────────────────── --}}
            <form method="POST" action="{{ route('admin.settings.update') }}">
                @csrf

                @foreach($groupKeys as $group)
                    <div x-show="tab === @js($group)" x-cloak>
                        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:20px">
                            <h2 style="font-size:16px;font-weight:600;margin-bottom:6px;text-transform:capitalize">{{ $group }}</h2>
                            <p style="font-size:12px;color:#777;margin-bottom:20px">{{ $grouped[$group]->count() }} setting(s) in this group. Changes save when you click "Save Changes" at the bottom.</p>

                            <div style="display:flex;flex-direction:column;gap:22px">
                                @foreach($grouped[$group] as $setting)
                                    @php
                                        $fieldName = "settings.{$setting->id}";
                                        $fieldId = "setting-{$setting->id}";
                                        $rawValue = $setting->getAttributes()['value'] ?? null;
                                        $typedValue = old($fieldName, $setting->value);
                                        $hasError = $errors->has($fieldName);
                                    @endphp

                                    <div class="setting-row">
                                        {{-- Label column --}}
                                        <div>
                                            <label for="{{ $fieldId }}" style="display:block;font-size:13px;font-weight:500;color:#e5e5e5;margin-bottom:4px">
                                                {{ $setting->key }}
                                                @if($setting->is_secret)
                                                    <span class="badge" style="background:rgba(220,38,38,0.18);color:#fca5a5;margin-left:6px">secret</span>
                                                @endif
                                                @if($setting->is_public)
                                                    <span class="badge" style="background:rgba(34,197,94,0.18);color:#86efac;margin-left:6px">public</span>
                                                @endif
                                            </label>
                                            <div style="font-size:11px;color:#666;line-height:1.5">{{ $setting->description ?? '—' }}</div>
                                            <div style="font-size:10px;color:#444;margin-top:6px;font-family:'JetBrains Mono',Menlo,monospace">type: {{ $setting->type }}</div>
                                        </div>

                                        {{-- Input column --}}
                                        <div>
                                            @switch($setting->type)
                                                @case('bool')
                                                    {{-- Hidden 0 input first so an unchecked box still POSTs --}}
                                                    <input type="hidden" name="{{ $fieldName }}" value="0">
                                                    <label class="toggle" style="display:inline-block;vertical-align:middle">
                                                        <input type="checkbox" name="{{ $fieldName }}" id="{{ $fieldId }}" value="1"
                                                               {{ $typedValue ? 'checked' : '' }}>
                                                        <span class="slider"></span>
                                                    </label>
                                                    @break

                                                @case('int')
                                                    <input type="number" step="1" name="{{ $fieldName }}" id="{{ $fieldId }}"
                                                           class="form-input"
                                                           value="{{ $typedValue }}"
                                                           style="max-width:280px">
                                                    @break

                                                @case('float')
                                                    <input type="number" step="0.01" name="{{ $fieldName }}" id="{{ $fieldId }}"
                                                           class="form-input"
                                                           value="{{ $typedValue }}"
                                                           style="max-width:280px">
                                                    @break

                                                @case('json')
                                                @case('array')
                                                    <textarea name="{{ $fieldName }}" id="{{ $fieldId }}"
                                                              class="form-input"
                                                              rows="5"
                                                              style="font-family:'JetBrains Mono',Menlo,monospace;font-size:12px"
                                                              placeholder='{"key": "value"}'>{{ is_string($typedValue) ? $typedValue : json_encode($typedValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</textarea>
                                                    <div style="font-size:11px;color:#666;margin-top:4px">Must be valid JSON. Pretty-printed on display; stored compact.</div>
                                                    @break

                                                @default
                                                    @if($setting->is_secret)
                                                        {{-- Secret: render under an Alpine reveal toggle. The
                                                             starting state shows masked dots; clicking "Show"
                                                             switches the input to type=text and reveals the
                                                             current value so the operator can edit it. --}}
                                                        <div x-data="{ shown: false }">
                                                            <div style="display:flex;gap:8px;align-items:center">
                                                                <input :type="shown ? 'text' : 'password'"
                                                                       name="{{ $fieldName }}" id="{{ $fieldId }}"
                                                                       class="form-input"
                                                                       value="{{ $typedValue }}"
                                                                       autocomplete="new-password"
                                                                       style="max-width:480px;font-family:'JetBrains Mono',Menlo,monospace">
                                                                <button type="button" @click="shown = !shown"
                                                                        class="btn btn-ghost btn-sm"
                                                                        x-text="shown ? 'Hide' : 'Show'"></button>
                                                            </div>
                                                            <div style="font-size:11px;color:#666;margin-top:4px">Stored plaintext &mdash; redacted as <code style="background:#0f0f0f;padding:1px 5px;border-radius:4px">***</code> in audit logs.</div>
                                                        </div>
                                                    @else
                                                        <input type="text" name="{{ $fieldName }}" id="{{ $fieldId }}"
                                                               class="form-input"
                                                               value="{{ $typedValue }}"
                                                               style="max-width:480px">
                                                    @endif
                                            @endswitch

                                            @if($hasError)
                                                <div style="color:#ef4444;font-size:12px;margin-top:6px">{{ $errors->first($fieldName) }}</div>
                                            @endif

                                            @if($setting->validation_rules)
                                                <div style="font-size:10px;color:#444;margin-top:6px;font-family:'JetBrains Mono',Menlo,monospace">rules: {{ $setting->validation_rules }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Save bar (per-tab so it's always visible) --}}
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;flex-wrap:wrap;gap:12px">
                            <div style="font-size:12px;color:#666">
                                Tab: <span style="color:#C5A55A;text-transform:capitalize">{{ $group }}</span> &middot; Submit posts <em>every</em> tab's values; only changed rows are saved.
                            </div>
                            <button type="submit" class="btn btn-gold">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Save Changes
                            </button>
                        </div>
                    </div>
                @endforeach
            </form>

            {{-- ─── Restore defaults (destructive) ──────────────── --}}
            <div style="background:rgba(220,38,38,0.05);border:1px solid rgba(220,38,38,0.2);border-radius:12px;padding:16px 20px;margin-top:24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
                <div>
                    <h3 style="font-size:14px;font-weight:600;color:#fca5a5">Restore canonical defaults</h3>
                    <p style="font-size:12px;color:#888;margin-top:3px">Resets EVERY seeded setting to its canonical default. Custom (non-seeded) settings are preserved. Destructive.</p>
                </div>
                <form method="POST" action="{{ route('admin.settings.restore-defaults') }}"
                      onsubmit="return confirm('Restore every seeded setting back to its canonical default? Operator-edited values will be overwritten. This cannot be undone.');">
                    @csrf
                    <button type="submit" class="btn btn-danger">Restore Defaults</button>
                </form>
            </div>
        </div>
    @endif

</x-admin.layout>
