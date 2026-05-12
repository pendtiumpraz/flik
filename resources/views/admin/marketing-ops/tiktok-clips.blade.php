<x-admin.layout title="TikTok Clip Suggester — {{ $movie->title }}">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="{{ route('admin.movies.edit', $movie) }}" style="font-size:12px;color:#777;text-decoration:none">
                <x-icon name="chevron-left" size="12" /> Back to {{ $movie->title }}
            </a>
            <h2 style="font-size:22px;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:10px">
                <x-icon name="sparkles" size="22" style="color:#C5A55A" />
                TikTok Clip Suggester
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                AI menemukan window high-energy + menulis caption Gen-Z untuk dipotong jadi TikTok.
            </p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:340px 1fr;gap:24px" id="tiktok-app">

        <!-- ============ FORM ============ -->
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;height:fit-content">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:8px">
                <x-icon name="cog" size="16" /> Generation Settings
            </h3>
            <p style="font-size:12px;color:#777;margin-bottom:18px">
                Pipeline: trailer-window detection (subtitle / audio loudness) → AI caption per klip.
            </p>

            <!-- Movie summary -->
            <div style="background:#0f0f0f;border:1px solid #222;border-radius:8px;padding:12px;margin-bottom:18px">
                <div style="display:flex;gap:12px;align-items:flex-start">
                    <img src="{{ $movie->poster_url }}" alt="" style="width:54px;height:80px;object-fit:cover;border-radius:4px;flex-shrink:0">
                    <div style="min-width:0;flex:1">
                        <div style="font-weight:600;font-size:13px;color:#fff;line-height:1.3">{{ $movie->title }}</div>
                        <div style="font-size:11px;color:#777;margin-top:3px">
                            @if($movie->release_date){{ $movie->release_date->format('Y') }} · @endif
                            {{ $movie->genres->pluck('name')->take(3)->join(', ') ?: 'No genre' }}
                        </div>
                    </div>
                </div>
            </div>

            <form id="tiktok-form">
                @csrf
                <div class="form-group">
                    <label>Jumlah klip yang di-suggest</label>
                    <input type="number" name="count" value="3" min="1" max="10" class="form-input" style="text-align:center;font-size:18px;font-weight:600">
                </div>

                <div style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.2);border-radius:6px;padding:10px 12px;margin-bottom:18px;font-size:11px;color:#aaa;line-height:1.5">
                    <strong style="color:#C5A55A">Tip:</strong> proses ini memakai TrailerSuggester untuk menemukan
                    window berenergi tinggi (membutuhkan subtitle aktif atau FFmpeg). Jika belum ada, hasil bisa kosong.
                </div>

                <button type="submit" class="btn btn-gold" id="generate-btn" style="width:100%;justify-content:center">
                    <x-icon name="sparkles" size="14" />
                    <span id="generate-btn-label">Generate TikTok Clips</span>
                </button>
            </form>
        </div>

        <!-- ============ PREVIEW ============ -->
        <div>
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:14px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                    <h3 style="font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px">
                        <x-icon name="eye" size="14" /> Suggested Clips
                    </h3>
                    <span id="clip-count-badge" class="badge badge-gold" style="display:none"></span>
                </div>

                <div id="preview-empty" style="padding:60px 20px;text-align:center;color:#555">
                    <div style="display:inline-flex;width:56px;height:56px;border-radius:50%;background:#0f0f0f;align-items:center;justify-content:center;color:#C5A55A;margin-bottom:14px">
                        <x-icon name="lightning" size="28" />
                    </div>
                    <p style="margin-bottom:6px;color:#888">Belum ada klip yang di-suggest.</p>
                    <p style="font-size:12px">Klik <strong>Generate TikTok Clips</strong> untuk mulai.</p>
                </div>

                <div id="preview-loading" style="display:none;padding:60px 20px;text-align:center;color:#aaa">
                    <div style="display:inline-block;width:32px;height:32px;border:3px solid #2a2a2a;border-top-color:#C5A55A;border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:14px"></div>
                    <p>AI sedang menganalisis film & menulis caption…</p>
                    <p style="font-size:11px;color:#666;margin-top:8px">Ini bisa makan waktu 20-60 detik.</p>
                </div>

                <div id="preview-error" style="display:none;padding:20px;margin:20px;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;color:#ef4444;font-size:13px"></div>

                <div id="preview-content" style="display:none;padding:20px">
                    <div id="clip-list" style="display:grid;gap:16px"></div>

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
        .clip-card { background:#0f0f0f; border:1px solid #222; border-radius:10px; padding:16px; transition:border-color 0.2s; }
        .clip-card:hover { border-color:#3a3a3a; }
        .clip-card .timecode { font-family:'Outfit',monospace; font-size:18px; font-weight:700; color:#C5A55A; }
        .clip-card .hook { font-size:14px; color:#fff; font-weight:600; margin-top:6px; line-height:1.3; }
        .clip-card .caption { background:#1a1a1a; padding:10px 12px; border-radius:6px; font-size:13px; color:#e5e5e5; line-height:1.5; margin-top:8px; word-break:break-word; }
        .clip-card .hashtags { display:flex; flex-wrap:wrap; gap:4px; margin-top:8px; }
        .clip-card .hashtag { font-size:11px; padding:2px 8px; background:rgba(197,165,90,0.15); color:#C5A55A; border-radius:12px; font-weight:500; }
        .clip-card .actions { margin-top:10px; display:flex; gap:6px; }
    </style>

    <script>
    (function () {
        const form      = document.getElementById('tiktok-form');
        const btn       = document.getElementById('generate-btn');
        const btnLabel  = document.getElementById('generate-btn-label');
        const elEmpty   = document.getElementById('preview-empty');
        const elLoading = document.getElementById('preview-loading');
        const elError   = document.getElementById('preview-error');
        const elContent = document.getElementById('preview-content');
        const elList    = document.getElementById('clip-list');
        const elBadge   = document.getElementById('clip-count-badge');

        const endpoint = '{{ url('/admin/movies/' . $movie->slug . '/marketing-ops/tiktok-clips') }}';
        const csrf     = document.querySelector('meta[name="csrf-token"]')?.content
                       || form.querySelector('input[name="_token"]').value;

        function fmtTime(sec) {
            sec = Math.floor(sec);
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            return `${m}:${String(s).padStart(2,'0')}`;
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, c => ({
                '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
            })[c]);
        }

        async function generate() {
            const count = parseInt(form.querySelector('input[name="count"]').value, 10) || 3;

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

                renderClips(json.data || []);
            } catch (err) {
                elLoading.style.display = 'none';
                elError.style.display   = 'block';
                elError.textContent     = err.message || 'Generation failed.';
            } finally {
                btn.disabled         = false;
                btnLabel.textContent = 'Generate TikTok Clips';
            }
        }

        function renderClips(clips) {
            elList.innerHTML = '';

            if (!clips.length) {
                elList.innerHTML = '<div style="padding:24px;text-align:center;color:#666;font-size:13px">' +
                    'Tidak ada klip yang ditemukan. Pastikan film punya subtitle aktif atau file video yang accessible untuk audio analysis.' +
                    '</div>';
            } else {
                clips.forEach((clip, idx) => {
                    const tags = (clip.hashtags || []).map(t =>
                        `<span class="hashtag">${escapeHtml(t)}</span>`
                    ).join('');

                    const card = document.createElement('div');
                    card.className = 'clip-card';
                    card.innerHTML = `
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
                            <div class="timecode">
                                #${idx + 1} · ${fmtTime(clip.start_seconds)} – ${fmtTime(clip.end_seconds)}
                                <span style="font-size:11px;color:#666;font-weight:400;letter-spacing:0">
                                    (${(clip.end_seconds - clip.start_seconds).toFixed(1)}s)
                                </span>
                            </div>
                        </div>
                        <div class="hook">${escapeHtml(clip.hook_text || '')}</div>
                        <div class="caption" data-copy>${escapeHtml(clip.caption || '')}</div>
                        <div class="hashtags">${tags}</div>
                        <div class="actions">
                            <button type="button" class="btn btn-ghost btn-sm copy-clip-btn" data-clip-idx="${idx}">
                                <x-icon name="download" size="12" /> Copy Caption + Hashtags
                            </button>
                        </div>
                    `;
                    elList.appendChild(card);
                });
            }

            elBadge.textContent     = clips.length + ' clips';
            elBadge.style.display   = 'inline-flex';
            elLoading.style.display = 'none';
            elError.style.display   = 'none';
            elContent.style.display = 'block';
            window.__lastClips      = clips;
        }

        form.addEventListener('submit', (e) => { e.preventDefault(); generate(); });
        document.getElementById('regen-btn')?.addEventListener('click', generate);

        // Per-clip copy
        document.body.addEventListener('click', async (e) => {
            const t = e.target.closest('.copy-clip-btn');
            if (!t) return;
            const clip = (window.__lastClips || [])[parseInt(t.dataset.clipIdx, 10)];
            if (!clip) return;
            const text = (clip.caption || '') + '\n\n' + (clip.hashtags || []).join(' ');
            try {
                await navigator.clipboard.writeText(text);
                const orig = t.innerHTML;
                t.innerHTML = '✓ Copied!';
                setTimeout(() => { t.innerHTML = orig; }, 1200);
            } catch {}
        });

        document.getElementById('copy-all-btn')?.addEventListener('click', async () => {
            if (!window.__lastClips) return;
            const txt = JSON.stringify(window.__lastClips, null, 2);
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
