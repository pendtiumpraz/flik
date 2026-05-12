<x-admin.layout title="CS Reply Drafter">

    @php
        $categories = \App\Services\Ai\Tasks\CustomerSupportReplyDrafter::CATEGORIES;
        $categoryLabels = [
            'billing'       => 'Billing',
            'technical'     => 'Technical',
            'content_issue' => 'Content Issue',
            'account'       => 'Account',
            'refund'        => 'Refund',
            'general'       => 'General',
        ];
    @endphp

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600;display:flex;align-items:center;gap:10px">
                <x-icon name="sparkles" size="22" style="color:#C5A55A" />
                Customer Support Reply Drafter
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                Draft balasan support yang hangat & empatik dalam Bahasa Indonesia. Wajib di-review admin sebelum dikirim.
            </p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:flex-start" id="cs-app">

        <!-- ============ FORM ============ -->
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:8px">
                <x-icon name="cog" size="16" /> Ticket Input
            </h3>

            <form id="cs-form">
                @csrf

                <div class="form-group">
                    <label>Kategori Issue</label>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
                        @foreach($categories as $cat)
                            <label class="cat-option" style="display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 8px;background:#0f0f0f;border:1px solid #222;border-radius:6px;cursor:pointer;font-size:12px;text-align:center;transition:all 0.15s">
                                <input type="radio" name="category" value="{{ $cat }}" {{ $cat === 'general' ? 'checked' : '' }} style="accent-color:#C5A55A">
                                <span>{{ $categoryLabels[$cat] ?? ucfirst($cat) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="form-group">
                    <label>Pesan User</label>
                    <textarea name="query" class="form-input" rows="8" placeholder="Tempel pesan / email customer di sini…" required></textarea>
                </div>

                <div class="form-group">
                    <label>Konteks User (opsional)</label>
                    <p style="font-size:11px;color:#666;margin-top:-4px;margin-bottom:6px">
                        Key-value untuk personalisasi. Satu per baris, format <code style="color:#C5A55A">key: value</code>.
                    </p>
                    <textarea name="context" class="form-input" rows="4" placeholder="name: Andi&#10;plan: Premium&#10;subscription_status: active&#10;account_age_days: 145"></textarea>
                </div>

                <button type="submit" class="btn btn-gold" id="generate-btn" style="width:100%;justify-content:center">
                    <x-icon name="sparkles" size="14" />
                    <span id="generate-btn-label">Draft Reply</span>
                </button>
            </form>
        </div>

        <!-- ============ PREVIEW ============ -->
        <div style="position:sticky;top:80px">
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:14px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                    <h3 style="font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px">
                        <x-icon name="eye" size="14" /> Draft Reply
                    </h3>
                    <span id="meta-badge" style="font-size:11px;color:#666;display:none"></span>
                </div>

                <div id="preview-empty" style="padding:60px 20px;text-align:center;color:#555">
                    <div style="display:inline-flex;width:56px;height:56px;border-radius:50%;background:#0f0f0f;align-items:center;justify-content:center;color:#C5A55A;margin-bottom:14px">
                        <x-icon name="user-circle" size="28" />
                    </div>
                    <p style="margin-bottom:6px;color:#888">Belum ada draft.</p>
                    <p style="font-size:12px">Isi form di kiri, lalu klik <strong>Draft Reply</strong>.</p>
                </div>

                <div id="preview-loading" style="display:none;padding:60px 20px;text-align:center;color:#aaa">
                    <div style="display:inline-block;width:32px;height:32px;border:3px solid #2a2a2a;border-top-color:#C5A55A;border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:14px"></div>
                    <p>AI sedang menulis balasan empatik…</p>
                </div>

                <div id="preview-error" style="display:none;padding:20px;margin:20px;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;color:#ef4444;font-size:13px"></div>

                <div id="preview-content" style="display:none;padding:20px">
                    <div style="background:rgba(220,38,38,0.06);border:1px solid rgba(220,38,38,0.18);border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:11px;color:#fca5a5;line-height:1.5">
                        <strong>⚠️ Review wajib:</strong> AI bisa salah. Selalu baca + edit sebelum kirim ke customer.
                    </div>

                    <textarea id="draft-output" class="form-input" rows="14" style="font-family:'Inter';line-height:1.6;font-size:14px"></textarea>

                    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
                        <button type="button" class="btn btn-gold btn-sm" id="copy-draft-btn">
                            <x-icon name="download" size="12" /> Copy Final Draft
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm" id="regen-btn">
                            <x-icon name="sparkles" size="12" /> Regenerate
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        @keyframes spin { to { transform: rotate(360deg); } }
        .cat-option:has(input:checked) { border-color:#C5A55A !important; background:rgba(197,165,90,0.1) !important; color:#C5A55A; }
        .cat-option:hover { border-color:#3a3a3a; }
    </style>

    <script>
    (function () {
        const form      = document.getElementById('cs-form');
        const btn       = document.getElementById('generate-btn');
        const btnLabel  = document.getElementById('generate-btn-label');
        const elEmpty   = document.getElementById('preview-empty');
        const elLoading = document.getElementById('preview-loading');
        const elError   = document.getElementById('preview-error');
        const elContent = document.getElementById('preview-content');
        const elDraft   = document.getElementById('draft-output');
        const elBadge   = document.getElementById('meta-badge');

        const endpoint = '{{ url('/admin/marketing-ops/cs-reply') }}';
        const csrf     = document.querySelector('meta[name="csrf-token"]')?.content
                       || form.querySelector('input[name="_token"]').value;

        function parseContextLines(raw) {
            const ctx = {};
            (raw || '').split(/\n/).forEach(line => {
                const m = line.match(/^\s*([A-Za-z0-9_\-\.]+)\s*[:=]\s*(.+?)\s*$/);
                if (m) ctx[m[1]] = m[2];
            });
            return ctx;
        }

        async function generate() {
            const query    = form.querySelector('textarea[name="query"]').value.trim();
            const category = form.querySelector('input[name="category"]:checked')?.value || 'general';
            const context  = parseContextLines(form.querySelector('textarea[name="context"]').value);

            if (!query) {
                elError.style.display = 'block';
                elError.textContent   = 'Pesan user wajib diisi.';
                elEmpty.style.display = 'none';
                return;
            }

            elEmpty.style.display   = 'none';
            elContent.style.display = 'none';
            elError.style.display   = 'none';
            elLoading.style.display = 'block';
            btn.disabled            = true;
            btnLabel.textContent    = 'Drafting…';

            try {
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ query, category, context }),
                });

                const json = await res.json();
                if (!res.ok || !json.ok) {
                    throw new Error(json.error || ('HTTP ' + res.status));
                }

                renderDraft(json.data || {}, json.category || category);
            } catch (err) {
                elLoading.style.display = 'none';
                elError.style.display   = 'block';
                elError.textContent     = err.message || 'Drafting failed.';
            } finally {
                btn.disabled         = false;
                btnLabel.textContent = 'Draft Reply';
            }
        }

        function renderDraft(data, category) {
            elDraft.value           = data.draft || '';
            elBadge.textContent     = `${category} · ${data.word_count || 0} words · ${data.char_count || 0} chars`;
            elBadge.style.display   = 'inline-flex';
            elLoading.style.display = 'none';
            elError.style.display   = 'none';
            elContent.style.display = 'block';
        }

        form.addEventListener('submit', (e) => { e.preventDefault(); generate(); });
        document.getElementById('regen-btn')?.addEventListener('click', generate);

        document.getElementById('copy-draft-btn')?.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(elDraft.value);
                const b = document.getElementById('copy-draft-btn');
                const orig = b.innerHTML;
                b.innerHTML = '✓ Copied!';
                setTimeout(() => { b.innerHTML = orig; }, 1500);
            } catch {}
        });
    })();
    </script>

</x-admin.layout>
