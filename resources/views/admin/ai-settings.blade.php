<x-admin.layout title="AI Providers">

    @if($errors->any())
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            @foreach($errors->all() as $error)
                <div>• {{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600;margin-bottom:4px">AI Providers</h2>
            <p style="color:#777;font-size:13px">Kelola provider & API key untuk fitur AI di FLiK. Key disimpan terenkripsi di database.</p>
        </div>
        <button onclick="document.getElementById('createModal').style.display='flex'" class="btn btn-gold">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add AI Provider
        </button>
    </div>

    <!-- Stats -->
    <div class="grid-stats" style="margin-bottom:32px">
        <div class="stat-card">
            <div class="label">Total Providers</div>
            <div class="value">{{ $providers->count() }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Active</div>
            <div class="value" style="color:#22c55e">{{ $providers->where('is_active', true)->count() }}</div>
        </div>
        <div class="stat-card">
            <div class="label">Default</div>
            <div class="value" style="color:#C5A55A;font-size:18px;line-height:1.5">
                {{ $providers->where('is_default', true)->first()->provider_label ?? 'Not set' }}
            </div>
        </div>
        <div class="stat-card">
            <div class="label">Total Spend (USD)</div>
            <div class="value">${{ number_format($providers->sum('total_cost_usd'), 2) }}</div>
        </div>
    </div>

    <!-- Provider list -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:15px;font-weight:600">Configured Providers</h3>
        </div>
        @if($providers->isEmpty())
            <div style="padding:48px 20px;text-align:center;color:#555">
                <p style="margin-bottom:8px">Belum ada AI provider terkonfigurasi.</p>
                <p style="font-size:12px">Klik "Add AI Provider" untuk mulai. Semua API key akan dienkripsi sebelum disimpan.</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Provider</th>
                        <th>Model</th>
                        <th>API Key</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($providers as $provider)
                    <tr x-data="{
                            testing: false,
                            result: null,
                            async runTest() {
                                this.testing = true;
                                this.result = null;
                                try {
                                    const res = await fetch(@js(route('admin.ai.test', $provider)), {
                                        method: 'POST',
                                        headers: {
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '{{ csrf_token() }}',
                                            'Accept': 'application/json',
                                            'X-Requested-With': 'XMLHttpRequest',
                                        },
                                        credentials: 'same-origin',
                                    });
                                    this.result = await res.json().catch(() => ({
                                        success: false,
                                        error: 'Server returned non-JSON response (HTTP ' + res.status + ').',
                                        latency_ms: 0,
                                    }));
                                } catch (e) {
                                    this.result = { success: false, error: 'Network error: ' + (e?.message || e), latency_ms: 0 };
                                } finally {
                                    this.testing = false;
                                }
                            },
                        }">
                        <td>
                            <div style="font-weight:500;color:#fff">{{ $provider->name }}</div>
                            @if($provider->is_default)
                                <span class="badge badge-gold" style="margin-top:4px">Default</span>
                            @endif
                        </td>
                        <td>{{ $provider->provider_label }}</td>
                        <td><code style="background:#0f0f0f;padding:2px 8px;border-radius:4px;font-size:12px;color:#C5A55A">{{ $provider->model }}</code></td>
                        <td><code style="font-size:12px;color:#777">{{ $provider->masked_api_key }}</code></td>
                        <td>{{ $provider->priority }}</td>
                        <td>
                            @if($provider->is_active)
                                <span class="badge badge-green">Active</span>
                            @else
                                <span class="badge" style="background:#2a2a2a;color:#777">Disabled</span>
                            @endif

                            <!-- Inline test result -->
                            <template x-if="result">
                                <div x-cloak style="margin-top:8px;padding:8px 10px;border-radius:6px;font-size:11px;line-height:1.5;max-width:280px"
                                     :style="result.success
                                        ? 'background:rgba(34,197,94,0.10);border:1px solid rgba(34,197,94,0.35);color:#86efac'
                                        : 'background:rgba(220,38,38,0.10);border:1px solid rgba(220,38,38,0.35);color:#fca5a5'">
                                    <div style="display:flex;align-items:center;gap:6px;font-weight:600;margin-bottom:4px">
                                        <span x-text="result.success ? 'Connected' : 'Failed'"></span>
                                        <span style="color:#888;font-weight:400" x-text="'· ' + (result.latency_ms ?? 0) + 'ms'"></span>
                                        <button type="button" @click="result = null" style="margin-left:auto;background:none;border:none;color:#777;cursor:pointer;font-size:14px;line-height:1;padding:0">×</button>
                                    </div>
                                    <div x-show="result.success" style="color:#cbd5e1;word-break:break-word">
                                        <span style="color:#888">Reply:</span> <span x-text="(result.response || '').slice(0, 120) || '(empty)'"></span>
                                        <template x-if="result.usage && (result.usage.total_tokens || result.usage.completion_tokens)">
                                            <div style="color:#888;margin-top:2px" x-text="'Tokens: ' + (result.usage.total_tokens ?? ((result.usage.prompt_tokens||0) + (result.usage.completion_tokens||0)))"></div>
                                        </template>
                                    </div>
                                    <div x-show="!result.success" style="color:#fecaca;word-break:break-word" x-text="result.error || 'Unknown error'"></div>
                                </div>
                            </template>
                        </td>
                        <td style="text-align:right">
                            <div style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
                                <button type="button" @click="runTest()" :disabled="testing" class="btn btn-ghost btn-sm" title="Send a minimal probe to verify API key & model">
                                    <x-icon name="lightning" :size="13" style="color:#C5A55A" />
                                    <span x-show="!testing" style="margin-left:4px">Test</span>
                                    <span x-show="testing" x-cloak style="margin-left:4px">Testing…</span>
                                </button>
                                <form method="POST" action="{{ route('admin.ai.toggle', $provider) }}" style="display:inline">
                                    @csrf @method('PUT')
                                    <button type="submit" class="btn btn-ghost btn-sm" title="Toggle active">
                                        {{ $provider->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                                <button onclick="openEditModal({{ $provider->id }})" class="btn btn-ghost btn-sm">Edit</button>
                                <form method="POST" action="{{ route('admin.ai.destroy', $provider) }}" style="display:inline" onsubmit="return confirm('Hapus provider {{ $provider->name }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </div>

                            <!-- Edit modal data -->
                            <div id="editData{{ $provider->id }}" style="display:none"
                                 data-name="{{ $provider->name }}"
                                 data-provider="{{ $provider->provider }}"
                                 data-model="{{ $provider->model }}"
                                 data-base-url="{{ $provider->base_url }}"
                                 data-priority="{{ $provider->priority }}"
                                 data-active="{{ $provider->is_active ? '1' : '0' }}"
                                 data-default="{{ $provider->is_default ? '1' : '0' }}"
                                 data-action="{{ route('admin.ai.update', $provider) }}"></div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <!-- Security note -->
    <div style="margin-top:24px;background:rgba(197,165,90,0.08);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:16px 20px">
        <div style="display:flex;align-items:start;gap:12px">
            <svg width="20" height="20" fill="none" stroke="#C5A55A" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:2px"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            <div>
                <div style="color:#C5A55A;font-weight:600;font-size:14px;margin-bottom:4px">Security</div>
                <div style="color:#aaa;font-size:13px;line-height:1.6">
                    API keys dienkripsi via Laravel <code style="background:#0f0f0f;padding:1px 6px;border-radius:4px">encrypted</code> cast (AES-256-CBC dengan <code>APP_KEY</code>). Key tidak pernah dikirim ke browser — hanya tampilan masked. Pastikan <code>APP_KEY</code> di-rotate berkala dan tidak masuk ke git.
                </div>
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    <div id="createModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:100;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)">
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto">
            <div style="padding:20px 24px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                <h3 style="font-size:18px;font-weight:600">Add AI Provider</h3>
                <button onclick="document.getElementById('createModal').style.display='none'" style="background:none;border:none;color:#777;cursor:pointer;font-size:20px">×</button>
            </div>
            <form method="POST" action="{{ route('admin.ai.store') }}" style="padding:24px">
                @csrf
                <div class="form-group">
                    <label>Display Name</label>
                    <input type="text" name="name" class="form-input" placeholder="e.g. DeepSeek Production" required>
                </div>
                <div class="form-group">
                    <label>Provider</label>
                    <select name="provider" class="form-input" id="providerSelect" required onchange="updateModelHint()">
                        <option value="">— Select provider —</option>
                        @foreach($catalog as $key => $info)
                            <option value="{{ $key }}" data-base="{{ $info['base_url'] }}" data-models="{{ implode(',', $info['models']) }}">{{ $info['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Model</label>
                    <input type="text" name="model" id="modelInput" class="form-input" placeholder="e.g. deepseek-chat" required>
                    <div id="modelHint" style="font-size:11px;color:#666;margin-top:4px"></div>
                </div>
                <div class="form-group">
                    <label>API Key</label>
                    <input type="password" name="api_key" class="form-input" placeholder="sk-..." required autocomplete="off">
                    <div style="font-size:11px;color:#666;margin-top:4px">Akan dienkripsi sebelum disimpan.</div>
                </div>
                <div class="form-group">
                    <label>Base URL <span style="color:#666;font-weight:400">(optional — auto-fill dari provider)</span></label>
                    <input type="url" name="base_url" id="baseUrlInput" class="form-input" placeholder="https://api.example.com/v1">
                </div>
                <div class="form-group">
                    <label>Priority <span style="color:#666;font-weight:400">(lower = higher priority for fallback)</span></label>
                    <input type="number" name="priority" class="form-input" value="100" min="1" max="999">
                </div>
                <div style="display:flex;gap:24px;margin-bottom:24px">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="is_active" value="1" checked> <span style="font-size:14px">Active</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="is_default" value="1"> <span style="font-size:14px">Set as default</span>
                    </label>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end">
                    <button type="button" onclick="document.getElementById('createModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn btn-gold">Save Provider</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:100;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px)">
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;max-width:560px;width:100%;max-height:90vh;overflow-y:auto">
            <div style="padding:20px 24px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                <h3 style="font-size:18px;font-weight:600">Edit AI Provider</h3>
                <button onclick="document.getElementById('editModal').style.display='none'" style="background:none;border:none;color:#777;cursor:pointer;font-size:20px">×</button>
            </div>
            <form method="POST" id="editForm" style="padding:24px">
                @csrf @method('PUT')
                <div class="form-group">
                    <label>Display Name</label>
                    <input type="text" name="name" id="editName" class="form-input" required>
                </div>
                <div class="form-group">
                    <label>Provider</label>
                    <select name="provider" id="editProvider" class="form-input" required>
                        @foreach($catalog as $key => $info)
                            <option value="{{ $key }}">{{ $info['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Model</label>
                    <input type="text" name="model" id="editModel" class="form-input" required>
                </div>
                <div class="form-group">
                    <label>API Key <span style="color:#666;font-weight:400">(kosongkan kalau tidak diubah)</span></label>
                    <input type="password" name="api_key" class="form-input" placeholder="Leave blank to keep current" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Base URL</label>
                    <input type="url" name="base_url" id="editBaseUrl" class="form-input">
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <input type="number" name="priority" id="editPriority" class="form-input" min="1" max="999">
                </div>
                <div style="display:flex;gap:24px;margin-bottom:24px">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="is_active" id="editActive" value="1"> <span style="font-size:14px">Active</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" name="is_default" id="editDefault" value="1"> <span style="font-size:14px">Set as default</span>
                    </label>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end">
                    <button type="button" onclick="document.getElementById('editModal').style.display='none'" class="btn btn-ghost">Cancel</button>
                    <button type="submit" class="btn btn-gold">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateModelHint() {
            const sel = document.getElementById('providerSelect');
            const opt = sel.options[sel.selectedIndex];
            const hint = document.getElementById('modelHint');
            const baseInput = document.getElementById('baseUrlInput');
            const modelInput = document.getElementById('modelInput');
            if (opt && opt.dataset.models) {
                const models = opt.dataset.models.split(',');
                hint.textContent = 'Suggested: ' + models.join(', ');
                if (!modelInput.value && models[0] && models[0] !== 'custom') modelInput.value = models[0];
            } else {
                hint.textContent = '';
            }
            if (opt && opt.dataset.base && !baseInput.value) baseInput.value = opt.dataset.base;
        }

        function openEditModal(id) {
            const data = document.getElementById('editData' + id);
            const form = document.getElementById('editForm');
            form.action = data.dataset.action;
            document.getElementById('editName').value = data.dataset.name;
            document.getElementById('editProvider').value = data.dataset.provider;
            document.getElementById('editModel').value = data.dataset.model;
            document.getElementById('editBaseUrl').value = data.dataset.baseUrl;
            document.getElementById('editPriority').value = data.dataset.priority;
            document.getElementById('editActive').checked = data.dataset.active === '1';
            document.getElementById('editDefault').checked = data.dataset.default === '1';
            document.getElementById('editModal').style.display = 'flex';
        }
    </script>

</x-admin.layout>
