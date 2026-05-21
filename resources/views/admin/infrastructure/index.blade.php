<x-admin.layout title="Infrastructure Settings">

    <style>
        .infra-card {
            background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%);
            border: 1px solid rgba(197,165,90,0.15);
            border-radius: 14px;
            overflow: hidden;
        }
        .infra-tabs {
            display: flex; overflow-x: auto;
            border-bottom: 1px solid #2a2a2a;
            background: #141414;
            scrollbar-width: thin;
        }
        .infra-tab {
            padding: 14px 22px;
            background: transparent; border: none;
            color: #888; cursor: pointer;
            font-size: 13px; font-weight: 600;
            white-space: nowrap;
            border-bottom: 2px solid transparent;
            transition: all 0.15s ease;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .infra-tab:hover { color: #C5A55A; }
        .infra-tab.is-active {
            color: #C5A55A;
            border-bottom-color: #C5A55A;
            background: rgba(197,165,90,0.05);
        }
        .infra-tab-icon { font-size: 16px; }
        .infra-body { padding: 28px; }
        .infra-row {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 24px;
            padding: 18px 0;
            border-bottom: 1px solid #2a2a2a;
            align-items: start;
        }
        .infra-row:last-child { border-bottom: none; }
        @media (max-width: 720px) {
            .infra-row { grid-template-columns: 1fr; gap: 8px; }
        }
        .infra-label-block { display: flex; flex-direction: column; gap: 4px; }
        .infra-label {
            color: #fff; font-size: 13.5px; font-weight: 600;
        }
        .infra-key {
            color: #555; font-size: 11px;
            font-family: ui-monospace, Menlo, monospace;
        }
        .infra-help {
            color: #888; font-size: 12px; line-height: 1.5; margin-top: 4px;
        }
        .infra-input {
            width: 100%;
            padding: 10px 14px;
            background: #0a0a0a;
            border: 1px solid #2a2a2a;
            color: #fff;
            border-radius: 8px;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            transition: all 0.15s ease;
        }
        .infra-input:focus {
            outline: none;
            border-color: #C5A55A;
            box-shadow: 0 0 0 3px rgba(197,165,90,0.15);
        }
        .infra-select { appearance: none; background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23C5A55A' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
        .infra-toggle {
            display: inline-flex; align-items: center; gap: 10px;
            cursor: pointer; color: #ddd;
        }
        .infra-toggle input { accent-color: #C5A55A; width: 18px; height: 18px; }
        .infra-secret-wrap { position: relative; }
        .infra-secret-toggle {
            position: absolute; right: 8px; top: 50%;
            transform: translateY(-50%);
            background: transparent; border: none;
            color: #888; cursor: pointer; padding: 4px 8px;
            font-size: 11px; font-weight: 600;
        }
        .infra-secret-toggle:hover { color: #C5A55A; }

        .infra-save-bar {
            position: sticky; bottom: 0;
            background: linear-gradient(180deg, transparent, #0a0a0a 30%);
            padding: 16px 0;
            margin-top: 24px;
            display: flex; justify-content: flex-end; gap: 10px;
            z-index: 5;
        }
        .infra-warning {
            background: rgba(234,179,8,0.08);
            border-left: 3px solid #eab308;
            color: #fde68a;
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 0 8px 8px 0;
            font-size: 13px;
        }
    </style>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
        <div>
            <h2 style="font-size:22px;font-weight:700;color:#fff;letter-spacing:-0.3px">⚙️ Infrastructure Settings</h2>
            <p style="font-size:13px;color:#888;margin-top:4px">Switch DRM provider, CDN target, storage disk, payment gateway, dll — tanpa edit .env atau redeploy.</p>
        </div>
    </div>

    @if(session('success'))
        <div style="background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);color:#86efac;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px">
            ✓ {{ session('success') }}
        </div>
    @endif

    <div class="infra-warning">
        ⚠ <strong>Hati-hati:</strong> Beberapa setting butuh queue worker restart untuk effect penuh (DRM provider change, storage disk change). API key salah → fitur terkait akan gagal. Pastikan test di staging dulu.
    </div>

    @php
        // Build the Alpine state object: tab + ALL select-driver values so the
        // form can conditionally show/hide rows based on the current pick.
        // Walk the catalogue + extract every 'select' input that other rows
        // reference via show_when — those are the "drivers" that other rows
        // depend on.
        $drivers = [];
        foreach ($tabs as $items) {
            foreach ($items as $item) {
                if (!empty($item['show_when']) && is_array($item['show_when'])) {
                    foreach (array_keys($item['show_when']) as $depKey) {
                        $drivers[$depKey] = $current[$depKey] ?? '';
                    }
                }
            }
        }
        $alpineState = ['tab' => array_key_first($tabs)];
        foreach ($drivers as $key => $val) {
            // Alpine key: replace dots with underscores to make valid JS identifier
            $alpineState[str_replace('.', '_', $key)] = (string) $val;
        }
    @endphp

    <form method="POST" action="{{ route('admin.infrastructure.update') }}"
          x-data='@json($alpineState)'>
        @csrf

        <div class="infra-card">
            {{-- Tab strip --}}
            <div class="infra-tabs">
                @foreach($tabs as $group => $items)
                    @php
                        $tabIcons = [
                            'drm' => '🔐',
                            'cdn' => '☁️',
                            'storage' => '🗄️',
                            'realtime' => '📡',
                            'payment' => '💳',
                            'email' => '📧',
                            'integrations' => '🔌',
                        ];
                        $tabLabels = [
                            'drm' => 'DRM',
                            'cdn' => 'CDN',
                            'storage' => 'Storage',
                            'realtime' => 'Realtime',
                            'payment' => 'Payment',
                            'email' => 'Email',
                            'integrations' => 'Integrations',
                        ];
                    @endphp
                    <button type="button"
                            @click="tab = '{{ $group }}'"
                            :class="tab === '{{ $group }}' ? 'infra-tab is-active' : 'infra-tab'">
                        <span class="infra-tab-icon">{{ $tabIcons[$group] ?? '⚙️' }}</span>
                        {{ $tabLabels[$group] ?? ucfirst($group) }}
                        <span style="font-size:10px;color:#555">({{ count($items) }})</span>
                    </button>
                @endforeach
            </div>

            {{-- Tab body --}}
            <div class="infra-body">
                @foreach($tabs as $group => $items)
                    <div x-show="tab === '{{ $group }}'" x-cloak>
                        @foreach($items as $item)
                            @php
                                $key = $item['key'];
                                $inputName = str_replace('.', '_', $key);
                                $value = $current[$key] ?? ($item['default'] ?? '');
                                $isSecret = !empty($item['secret']);

                                // Build x-show expression from show_when array.
                                // Example: ['payment.provider' => ['midtrans']]
                                //   → x-show="['midtrans'].includes(payment_provider)"
                                // Multi-condition: ['drm.provider' => ['widevine', 'fairplay']]
                                //   → x-show="['widevine','fairplay'].includes(drm_provider)"
                                $xShow = null;
                                if (!empty($item['show_when']) && is_array($item['show_when'])) {
                                    $conds = [];
                                    foreach ($item['show_when'] as $depKey => $allowed) {
                                        $depVar = str_replace('.', '_', $depKey);
                                        $allowedList = is_array($allowed) ? $allowed : [$allowed];
                                        $jsArr = json_encode($allowedList);
                                        $conds[] = "{$jsArr}.includes({$depVar})";
                                    }
                                    $xShow = implode(' && ', $conds);
                                }
                            @endphp

                            <div class="infra-row" @if($xShow) x-show="{{ $xShow }}" x-transition.opacity x-cloak @endif>
                                <div class="infra-label-block">
                                    <span class="infra-label">{{ $item['label'] }}</span>
                                    <span class="infra-key">{{ $key }}</span>
                                    @if(!empty($item['help']))
                                        <span class="infra-help">{{ $item['help'] }}</span>
                                    @endif
                                </div>
                                <div>
                                    @switch($item['type'])
                                        @case('select')
                                            @php
                                                // If this is a "driver" key (referenced by other rows' show_when),
                                                // bind it to Alpine state so dependent rows re-evaluate on change.
                                                $isDriver = array_key_exists($key, $drivers);
                                            @endphp
                                            <select name="{{ $inputName }}" class="infra-input infra-select"
                                                    @if($isDriver) x-model="{{ $inputName }}" @endif>
                                                @foreach($item['options'] as $optVal => $optLabel)
                                                    <option value="{{ $optVal }}" @selected($value === $optVal)>{{ $optLabel }}</option>
                                                @endforeach
                                            </select>
                                            @break

                                        @case('bool')
                                            <label class="infra-toggle">
                                                <input type="hidden" name="{{ $inputName }}" value="0">
                                                <input type="checkbox" name="{{ $inputName }}" value="1" @checked($value === '1' || $value === 1 || $value === true)>
                                                <span style="font-size:12px;color:#aaa">Enabled</span>
                                            </label>
                                            @break

                                        @case('int')
                                            <input type="number" name="{{ $inputName }}" value="{{ $value }}" class="infra-input" style="max-width:180px">
                                            @break

                                        @case('password')
                                            <div x-data="{ visible: false }" class="infra-secret-wrap">
                                                <input :type="visible ? 'text' : 'password'" name="{{ $inputName }}"
                                                       value="{{ $value }}" class="infra-input"
                                                       placeholder="••••••••" autocomplete="off">
                                                <button type="button" class="infra-secret-toggle" @click="visible = !visible">
                                                    <span x-text="visible ? 'Hide' : 'Show'"></span>
                                                </button>
                                            </div>
                                            @break

                                        @case('email')
                                            <input type="email" name="{{ $inputName }}" value="{{ $value }}" class="infra-input">
                                            @break

                                        @case('url')
                                            <input type="url" name="{{ $inputName }}" value="{{ $value }}" class="infra-input" placeholder="https://...">
                                            @break

                                        @default
                                            <input type="text" name="{{ $inputName }}" value="{{ $value }}" class="infra-input">
                                    @endswitch
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="infra-save-bar">
            <a href="{{ route('admin.dashboard') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-gold">💾 Save All Settings</button>
        </div>
    </form>

</x-admin.layout>
