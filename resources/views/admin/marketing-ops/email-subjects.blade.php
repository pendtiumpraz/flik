<x-admin.layout title="Email Subject A/B Tester">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600;display:flex;align-items:center;gap:10px">
                <x-icon name="sparkles" size="22" style="color:#C5A55A" />
                Email Subject A/B Tester
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                Generate beberapa variasi subject line Bahasa Indonesia dengan tone berbeda untuk A/B testing.
            </p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:380px 1fr;gap:24px" id="subjects-app">

        <!-- ============ FORM ============ -->
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;height:fit-content">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:8px">
                <x-icon name="cog" size="16" /> Campaign Settings
            </h3>
            <p style="font-size:12px;color:#777;margin-bottom:18px">
                Pilih intent campaign, jumlah variasi, dan konteks personalisasi.
            </p>

            <form id="subjects-form">
                @csrf

                <div class="form-group">
                    <label>Email Intent</label>
                    <select name="intent" id="intent-select" class="form-input">
                        @foreach($intents as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                        <option value="__custom__">— Custom (ketik manual) —</option>
                    </select>
                </div>

                <div class="form-group" id="custom-intent-wrap" style="display:none">
                    <label>Custom Intent</label>
                    <input type="text" name="custom_intent" class="form-input" placeholder="contoh: family_plan_upgrade">
                </div>

                <div class="form-group">
                    <label>Jumlah Variasi</label>
                    <input type="number" name="variants" value="4" min="1" max="10" class="form-input" style="text-align:center;font-size:18px;font-weight:600">
                </div>

                <div class="form-group">
                    <label>Konteks (opsional)</label>
                    <p style="font-size:11px;color:#666;margin-top:-4px;margin-bottom:6px">
                        Key-value untuk personalisasi. Satu per baris, format <code style="color:#C5A55A">key: value</code>.
                    </p>
                    <textarea name="context" class="form-input" rows="5" placeholder="user_name: Andi&#10;movie_title: Pengabdi Setan&#10;plan_name: Premium&#10;discount_pct: 25"></textarea>
                </div>

                <div style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.2);border-radius:6px;padding:10px 12px;margin-bottom:18px;font-size:11px;color:#aaa;line-height:1.5">
                    <strong style="color:#C5A55A">Tone variants:</strong>
                    {{ implode(', ', $tones) }}.<br>
                    AI mendistribusikan tone secara variatif antar subject lines.
                </div>

                <button type="submit" class="btn btn-gold" id="generate-btn" style="width:100%;justify-content:center">
                    <x-icon name="sparkles" size="14" />
                    <span id="generate-btn-label">Generate Subject Lines</span>
                </button>
            </form>
        </div>

        <!-- ============ PREVIEW ============ -->
        <div>
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:14px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                    <h3 style="font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px">
                        <x-icon name="eye" size="14" /> Generated Variants
                    </h3>
                    <span id="variant-badge" class="badge badge-gold" style="display:none"></span>
                </div>

                <div id="preview-empty" style="padding:60px 20px;text-align:center;color:#555">
                    <div style="display:inline-flex;width:56px;height:56px;border-radius:50%;background:#0f0f0f;align-items:center;justify-content:center;color:#C5A55A;margin-bottom:14px">
                        <x-icon name="bell" size="28" />
                    </div>
                    <p style="margin-bottom:6px;color:#888">Belum ada subject yang di-generate.</p>
                </div>

                <div id="preview-loading" style="display:none;padding:60px 20px;text-align:center;color:#aaa">
                    <div style="display:inline-block;width:32px;height:32px;border:3px solid #2a2a2a;border-top-color:#C5A55A;border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:14px"></div>
                    <p>AI sedang menulis subject lines…</p>
                </div>

                <div id="preview-error" style="display:none;padding:20px;margin:20px;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;color:#ef4444;font-size:13px"></div>

                <div id="preview-content" style="display:none;padding:20px">
                    <div id="variant-list" style="display:grid;gap:12px"></div>

                    <div style="margin-top:20px;display:flex;gap:8px">
                        <button type="button" class="btn btn-ghost btn-sm" id="copy-all-btn">
                            <x-icon name="download" size="12" /> Copy All as JSON
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
        .variant-card {
            background:#0f0f0f; border:1px solid #222; border-radius:10px; padding:14px 16px;
            display:grid; grid-template-columns: 1fr auto auto; gap:12px; align-items:center;
            transition: border-color 0.2s;
        }
        .variant-card:hover { border-color:#3a3a3a; }
        .variant-card .subject-main { font-size:14px; font-weight:600; color:#fff; line-height:1.4; word-break:break-word; }
        .variant-card .subject-meta { font-size:11px; color:#666; margin-top:4px; display:flex; gap:8px; align-items:center; }
        .tone-pill { padding:2px 8px; border-radius:10px; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }
        .tone-curious  { background:rgba(59,130,246,0.18); color:#60a5fa; }
        .tone-urgent   { background:rgba(220,38,38,0.18);  color:#f87171; }
        .tone-personal { background:rgba(34,197,94,0.18);  color:#4ade80; }
        .tone-playful  { background:rgba(168,85,247,0.18); color:#c084fc; }
        .open-rate { font-family:'Outfit'; font-weight:700; color:#C5A55A; font-size:14px; min-width:50px; text-align:right; }
    </style>

    <script>
    (function () {
        const form      = document.getElementById('subjects-form');
        const btn       = document.getElementById('generate-btn');
        const btnLabel  = document.getElementById('generate-btn-label');
        const elEmpty   = document.getElementById('preview-empty');
        const elLoading = document.getElementById('preview-loading');
        const elError   = document.getElementById('preview-error');
        const elContent = document.getElementById('preview-content');
        const elList    = document.getElementById('variant-list');
        const elBadge   = document.getElementById('variant-badge');
        const intentSelect    = document.getElementById('intent-select');
        const customWrap      = document.getElementById('custom-intent-wrap');

        const endpoint = '{{ url('/admin/marketing-ops/email-subjects') }}';
        const csrf     = document.querySelector('meta[name="csrf-token"]')?.content
                       || form.querySelector('input[name="_token"]').value;

        intentSelect.addEventListener('change', () => {
            customWrap.style.display = intentSelect.value === '__custom__' ? 'block' : 'none';
        });

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            })[c]);
        }

        function parseContextLines(raw) {
            const ctx = {};
            (raw || '').split(/\n/).forEach(line => {
                const m = line.match(/^\s*([A-Za-z0-9_\-\.]+)\s*[:=]\s*(.+?)\s*$/);
                if (m) ctx[m[1]] = m[2];
            });
            return ctx;
        }

        async function generate() {
            let intent = form.querySelector('select[name="intent"]').value;
            if (intent === '__custom__') {
                intent = form.querySelector('input[name="custom_intent"]').value.trim() || 'general';
            }
            const variants = parseInt(form.querySelector('input[name="variants"]').value, 10) || 4;
            const context  = parseContextLines(form.querySelector('textarea[name="context"]').value);

            elEmpty.style.display   = 'none';
            elContent.style.display = 'none';
            elError.style.display   = 'none';
            elLoading.style.display = 'block';
            btn.disabled            = true;
            btnLabel.textContent    = 'Generating…';

            try {
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ intent, variants, context }),
                });

                const json = await res.json();
                if (!res.ok || !json.ok) {
                    throw new Error(json.error || ('HTTP ' + res.status));
                }

                renderVariants(json.data || []);
            } catch (err) {
                elLoading.style.display = 'none';
                elError.style.display   = 'block';
                elError.textContent     = err.message || 'Generation failed.';
            } finally {
                btn.disabled         = false;
                btnLabel.textContent = 'Generate Subject Lines';
            }
        }

        function renderVariants(items) {
            elList.innerHTML = '';

            if (!items.length) {
                elList.innerHTML = '<div style="padding:24px;text-align:center;color:#666;font-size:13px">AI tidak menghasilkan variasi.</div>';
            } else {
                items.forEach((item, idx) => {
                    const tone = (item.tone || 'curious').toLowerCase();
                    const len  = [...(item.subject || '')].length;
                    const ratePct = ((item.predicted_open_rate || 0) * 100).toFixed(1);

                    const card = document.createElement('div');
                    card.className = 'variant-card';
                    card.innerHTML = `
                        <div style="min-width:0">
                            <div class="subject-main">${escapeHtml(item.subject || '')}</div>
                            <div class="subject-meta">
                                <span class="tone-pill tone-${tone}">${escapeHtml(tone)}</span>
                                <span>${len}/60 chars</span>
                                <span>Variant ${String.fromCharCode(65 + idx)}</span>
                            </div>
                        </div>
                        <div class="open-rate" title="Predicted open rate (AI-estimated)">${ratePct}%</div>
                        <button type="button" class="btn btn-ghost btn-sm copy-subject-btn" data-subject="${escapeHtml(item.subject || '')}">
                            Copy
                        </button>
                    `;
                    elList.appendChild(card);
                });
            }

            elBadge.textContent     = items.length + ' variants';
            elBadge.style.display   = 'inline-flex';
            elLoading.style.display = 'none';
            elError.style.display   = 'none';
            elContent.style.display = 'block';
            window.__lastSubjects   = items;
        }

        form.addEventListener('submit', (e) => { e.preventDefault(); generate(); });
        document.getElementById('regen-btn')?.addEventListener('click', generate);

        document.body.addEventListener('click', async (e) => {
            const t = e.target.closest('.copy-subject-btn');
            if (!t) return;
            try {
                await navigator.clipboard.writeText(t.dataset.subject || '');
                const orig = t.textContent;
                t.textContent = 'Copied!';
                setTimeout(() => { t.textContent = orig; }, 1200);
            } catch {}
        });

        document.getElementById('copy-all-btn')?.addEventListener('click', async () => {
            if (!window.__lastSubjects) return;
            const txt = JSON.stringify(window.__lastSubjects, null, 2);
            try {
                await navigator.clipboard.writeText(txt);
                const btn = document.getElementById('copy-all-btn');
                const orig = btn.innerHTML;
                btn.innerHTML = '✓ Copied!';
                setTimeout(() => { btn.innerHTML = orig; }, 1500);
            } catch {}
        });
    })();
    </script>

</x-admin.layout>
