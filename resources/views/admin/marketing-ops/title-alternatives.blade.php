<x-admin.layout title="Title Alternatives — {{ $movie->title }}">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="{{ route('admin.movies.edit', $movie) }}" style="font-size:12px;color:#777;text-decoration:none">
                <x-icon name="chevron-left" size="12" /> Back to {{ $movie->title }}
            </a>
            <h2 style="font-size:22px;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:10px">
                <x-icon name="sparkles" size="22" style="color:#C5A55A" />
                Title Alternative Generator
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                Generate alternatif judul SEO-friendly Bahasa Indonesia untuk A/B testing display title.
            </p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:340px 1fr;gap:24px" id="title-app">

        <!-- ============ FORM ============ -->
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;height:fit-content">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:8px">
                <x-icon name="cog" size="16" /> Generation Settings
            </h3>
            <p style="font-size:12px;color:#777;margin-bottom:18px">
                AI akan mempertimbangkan genre, era, mood, dan tema film.
            </p>

            <!-- Movie summary -->
            <div style="background:#0f0f0f;border:1px solid #222;border-radius:8px;padding:12px;margin-bottom:18px">
                <div style="display:flex;gap:12px;align-items:flex-start">
                    <img src="{{ $movie->poster_url }}" alt="" style="width:54px;height:80px;object-fit:cover;border-radius:4px;flex-shrink:0">
                    <div style="min-width:0;flex:1">
                        <div style="font-weight:600;font-size:13px;color:#fff;line-height:1.3">{{ $movie->title }}</div>
                        @if($movie->original_title && $movie->original_title !== $movie->title)
                            <div style="font-size:11px;color:#999;margin-top:2px;font-style:italic">{{ $movie->original_title }}</div>
                        @endif
                        <div style="font-size:11px;color:#777;margin-top:3px">
                            @if($movie->release_date){{ $movie->release_date->format('Y') }} · @endif
                            {{ $movie->genres->pluck('name')->take(3)->join(', ') ?: 'No genre' }}
                        </div>
                    </div>
                </div>
            </div>

            <form id="title-form">
                @csrf
                <div class="form-group">
                    <label>Jumlah alternatif</label>
                    <input type="number" name="count" value="5" min="1" max="10" class="form-input" style="text-align:center;font-size:18px;font-weight:600">
                </div>

                <div style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.2);border-radius:6px;padding:10px 12px;margin-bottom:18px;font-size:11px;color:#aaa;line-height:1.5">
                    <strong style="color:#C5A55A">Use-cases:</strong><br>
                    • A/B test display title untuk CTR<br>
                    • Eksperimen SEO (judul-judul beda untuk landing page)<br>
                    • Variasi judul untuk thumbnail / social
                </div>

                <button type="submit" class="btn btn-gold" id="generate-btn" style="width:100%;justify-content:center">
                    <x-icon name="sparkles" size="14" />
                    <span id="generate-btn-label">Generate Alternatives</span>
                </button>
            </form>
        </div>

        <!-- ============ PREVIEW ============ -->
        <div>
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:14px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                    <h3 style="font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px">
                        <x-icon name="eye" size="14" /> Suggested Titles
                    </h3>
                    <span id="title-count-badge" class="badge badge-gold" style="display:none"></span>
                </div>

                <div id="preview-empty" style="padding:60px 20px;text-align:center;color:#555">
                    <div style="display:inline-flex;width:56px;height:56px;border-radius:50%;background:#0f0f0f;align-items:center;justify-content:center;color:#C5A55A;margin-bottom:14px">
                        <x-icon name="sparkles" size="28" />
                    </div>
                    <p style="margin-bottom:6px;color:#888">Belum ada alternatif yang di-generate.</p>
                    <p style="font-size:12px">Klik <strong>Generate Alternatives</strong> untuk mulai.</p>
                </div>

                <div id="preview-loading" style="display:none;padding:60px 20px;text-align:center;color:#aaa">
                    <div style="display:inline-block;width:32px;height:32px;border:3px solid #2a2a2a;border-top-color:#C5A55A;border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:14px"></div>
                    <p>AI sedang berpikir kreatif…</p>
                </div>

                <div id="preview-error" style="display:none;padding:20px;margin:20px;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;color:#ef4444;font-size:13px"></div>

                <div id="preview-content" style="display:none;padding:20px">
                    <div id="title-list" style="display:grid;gap:12px"></div>

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
        .title-row {
            background:#0f0f0f; border:1px solid #222; border-radius:10px; padding:14px 16px;
            display:grid; grid-template-columns: 32px 1fr auto; gap:14px; align-items:center;
            transition:border-color 0.2s;
        }
        .title-row:hover { border-color:#3a3a3a; }
        .title-row .idx { font-family:'Outfit'; font-size:20px; font-weight:700; color:#C5A55A; text-align:center; }
        .title-row .title-main { font-size:15px; font-weight:600; color:#fff; line-height:1.3; word-break:break-word; }
        .title-row .title-reasoning { font-size:11px; color:#888; margin-top:4px; line-height:1.4; }
        .title-row .char-count { font-size:10px; color:#555; margin-top:3px; }
    </style>

    <script>
    (function () {
        const form      = document.getElementById('title-form');
        const btn       = document.getElementById('generate-btn');
        const btnLabel  = document.getElementById('generate-btn-label');
        const elEmpty   = document.getElementById('preview-empty');
        const elLoading = document.getElementById('preview-loading');
        const elError   = document.getElementById('preview-error');
        const elContent = document.getElementById('preview-content');
        const elList    = document.getElementById('title-list');
        const elBadge   = document.getElementById('title-count-badge');

        const endpoint = '{{ url('/admin/movies/' . $movie->slug . '/marketing-ops/title-alternatives') }}';
        const csrf     = document.querySelector('meta[name="csrf-token"]')?.content
                       || form.querySelector('input[name="_token"]').value;

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            })[c]);
        }

        async function generate() {
            const count = parseInt(form.querySelector('input[name="count"]').value, 10) || 5;

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
                    body: JSON.stringify({ count }),
                });

                const json = await res.json();
                if (!res.ok || !json.ok) {
                    throw new Error(json.error || ('HTTP ' + res.status));
                }

                renderTitles(json.data || []);
            } catch (err) {
                elLoading.style.display = 'none';
                elError.style.display   = 'block';
                elError.textContent     = err.message || 'Generation failed.';
            } finally {
                btn.disabled         = false;
                btnLabel.textContent = 'Generate Alternatives';
            }
        }

        function renderTitles(items) {
            elList.innerHTML = '';

            if (!items.length) {
                elList.innerHTML = '<div style="padding:24px;text-align:center;color:#666;font-size:13px">AI tidak menghasilkan alternatif.</div>';
            } else {
                items.forEach((item, idx) => {
                    const len = [...(item.title || '')].length;
                    const row = document.createElement('div');
                    row.className = 'title-row';
                    row.innerHTML = `
                        <div class="idx">${idx + 1}</div>
                        <div style="min-width:0">
                            <div class="title-main">${escapeHtml(item.title || '')}</div>
                            <div class="title-reasoning">${escapeHtml(item.reasoning || '')}</div>
                            <div class="char-count">${len}/60 chars</div>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm copy-title-btn" data-title="${escapeHtml(item.title || '')}">
                            Copy
                        </button>
                    `;
                    elList.appendChild(row);
                });
            }

            elBadge.textContent     = items.length + ' titles';
            elBadge.style.display   = 'inline-flex';
            elLoading.style.display = 'none';
            elError.style.display   = 'none';
            elContent.style.display = 'block';
            window.__lastTitles     = items;
        }

        form.addEventListener('submit', (e) => { e.preventDefault(); generate(); });
        document.getElementById('regen-btn')?.addEventListener('click', generate);

        document.body.addEventListener('click', async (e) => {
            const t = e.target.closest('.copy-title-btn');
            if (!t) return;
            try {
                await navigator.clipboard.writeText(t.dataset.title || '');
                const orig = t.textContent;
                t.textContent = 'Copied!';
                setTimeout(() => { t.textContent = orig; }, 1200);
            } catch {}
        });

        document.getElementById('copy-all-btn')?.addEventListener('click', async () => {
            if (!window.__lastTitles) return;
            const txt = JSON.stringify(window.__lastTitles, null, 2);
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
