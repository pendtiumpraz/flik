<x-admin.layout title="Promo Banner Generator — {{ $movie->title }}">

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="{{ route('admin.movies.edit', $movie) }}" style="font-size:12px;color:#777;text-decoration:none">
                <x-icon name="chevron-left" size="12" /> Back to {{ $movie->title }}
            </a>
            <h2 style="font-size:22px;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:10px">
                <x-icon name="sparkles" size="22" style="color:#C5A55A" />
                Promo Banner Generator
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                AI-powered headline + subheadline + CTA copy untuk banner promosi.
            </p>
        </div>
        <a href="{{ url()->current() }}/../social" data-disabled style="opacity:0;pointer-events:none"></a>
    </div>

    <div style="display:grid;grid-template-columns:380px 1fr;gap:24px" id="banner-app">

        <!-- ============ FORM ============ -->
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;height:fit-content">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:8px">
                <x-icon name="cog" size="16" /> Generation Settings
            </h3>
            <p style="font-size:12px;color:#777;margin-bottom:18px">
                Pilih tone-of-voice. AI akan generate dalam Bahasa Indonesia.
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
                        @if($movie->vote_average)
                            <div style="font-size:11px;color:#C5A55A;margin-top:3px">
                                <x-icon name="star-solid" size="11" /> {{ number_format((float) $movie->vote_average, 1) }}/10
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <form id="banner-form">
                @csrf
                <div class="form-group">
                    <label>Tone of Voice</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        @foreach($tones as $tone)
                            <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#0f0f0f;border:1px solid #222;border-radius:8px;cursor:pointer;font-size:13px;text-transform:capitalize;transition:all 0.15s" class="tone-option">
                                <input type="radio" name="tone" value="{{ $tone }}" {{ $loop->first ? 'checked' : '' }} style="accent-color:#C5A55A">
                                <span>{{ $tone }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.2);border-radius:6px;padding:10px 12px;margin-bottom:18px;font-size:11px;color:#aaa;line-height:1.5">
                    <strong style="color:#C5A55A">Tone Guide:</strong><br>
                    <strong>Cinematic</strong> — sinematik megah, puitis.<br>
                    <strong>Casual</strong> — ngobrol santai, "kamu".<br>
                    <strong>Urgent</strong> — FOMO, "jangan ketinggalan".<br>
                    <strong>Celebratory</strong> — meriah, premiere vibes.
                </div>

                <button type="submit" class="btn btn-gold" id="generate-btn" style="width:100%;justify-content:center">
                    <x-icon name="sparkles" size="14" />
                    <span id="generate-btn-label">Generate Banner Copy</span>
                </button>
            </form>
        </div>

        <!-- ============ PREVIEW ============ -->
        <div>
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:14px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                    <h3 style="font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px">
                        <x-icon name="eye" size="14" /> Live Preview
                    </h3>
                    <span id="preview-tone-badge" class="badge badge-gold" style="display:none"></span>
                </div>

                <!-- Empty state -->
                <div id="preview-empty" style="padding:60px 20px;text-align:center;color:#555">
                    <div style="display:inline-flex;width:56px;height:56px;border-radius:50%;background:#0f0f0f;align-items:center;justify-content:center;color:#C5A55A;margin-bottom:14px">
                        <x-icon name="sparkles" size="28" />
                    </div>
                    <p style="margin-bottom:6px;color:#888">Belum ada copy yang di-generate.</p>
                    <p style="font-size:12px">Pilih tone di kiri, lalu klik <strong>Generate Banner Copy</strong>.</p>
                </div>

                <!-- Loading state -->
                <div id="preview-loading" style="display:none;padding:60px 20px;text-align:center;color:#aaa">
                    <div style="display:inline-block;width:32px;height:32px;border:3px solid #2a2a2a;border-top-color:#C5A55A;border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:14px"></div>
                    <p>AI sedang menulis copy…</p>
                </div>

                <!-- Error state -->
                <div id="preview-error" style="display:none;padding:20px;margin:20px;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;color:#ef4444;font-size:13px"></div>

                <!-- Hero banner preview -->
                <div id="preview-content" style="display:none">
                    <div style="position:relative;height:340px;background-image:linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(0,0,0,0.4) 50%, rgba(15,15,15,1) 100%), url('{{ $movie->backdrop_url }}');background-size:cover;background-position:center">
                        <div style="position:absolute;inset:auto 0 0 0;padding:32px 36px">
                            <div id="preview-headline" style="font-family:'Outfit',sans-serif;font-size:36px;font-weight:800;color:#fff;line-height:1.1;text-shadow:0 2px 16px rgba(0,0,0,0.8);margin-bottom:10px"></div>
                            <div id="preview-subheadline" style="font-size:15px;color:#d5d5d5;max-width:540px;line-height:1.5;text-shadow:0 2px 12px rgba(0,0,0,0.8);margin-bottom:16px"></div>
                            <button id="preview-cta" type="button" style="display:inline-flex;align-items:center;gap:8px;background:#C5A55A;color:#000;border:none;padding:10px 20px;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer">
                                <x-icon name="play-solid" size="14" />
                                <span id="preview-cta-text"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Raw copy + char counts -->
                    <div style="padding:20px;border-top:1px solid #2a2a2a">
                        <h4 style="font-size:12px;text-transform:uppercase;color:#666;letter-spacing:1px;margin-bottom:12px">Raw Copy</h4>

                        <div style="display:grid;gap:12px">
                            <div class="copy-row">
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                    <span style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px">Headline</span>
                                    <span style="font-size:11px;color:#555" id="count-headline">0/60</span>
                                </div>
                                <div style="display:flex;gap:8px">
                                    <code id="raw-headline" style="flex:1;background:#0f0f0f;padding:8px 12px;border-radius:6px;color:#C5A55A;font-size:13px;font-family:'Inter';word-break:break-word"></code>
                                    <button type="button" class="btn btn-ghost btn-sm copy-btn" data-target="raw-headline">Copy</button>
                                </div>
                            </div>

                            <div class="copy-row">
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                    <span style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px">Subheadline</span>
                                    <span style="font-size:11px;color:#555" id="count-subheadline">0/100</span>
                                </div>
                                <div style="display:flex;gap:8px">
                                    <code id="raw-subheadline" style="flex:1;background:#0f0f0f;padding:8px 12px;border-radius:6px;color:#e5e5e5;font-size:13px;font-family:'Inter';word-break:break-word"></code>
                                    <button type="button" class="btn btn-ghost btn-sm copy-btn" data-target="raw-subheadline">Copy</button>
                                </div>
                            </div>

                            <div class="copy-row">
                                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                                    <span style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px">CTA Button</span>
                                    <span style="font-size:11px;color:#555" id="count-cta">0/20</span>
                                </div>
                                <div style="display:flex;gap:8px">
                                    <code id="raw-cta" style="flex:1;background:#0f0f0f;padding:8px 12px;border-radius:6px;color:#22c55e;font-size:13px;font-family:'Inter';word-break:break-word"></code>
                                    <button type="button" class="btn btn-ghost btn-sm copy-btn" data-target="raw-cta">Copy</button>
                                </div>
                            </div>
                        </div>

                        <div style="margin-top:16px;display:flex;gap:8px">
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
    </div>

    <style>
        @keyframes spin { to { transform: rotate(360deg); } }
        .tone-option:has(input:checked) { border-color: #C5A55A !important; background: rgba(197,165,90,0.1) !important; color: #C5A55A; }
        .tone-option:hover { border-color: #3a3a3a; }
    </style>

    <script>
    (function () {
        const form        = document.getElementById('banner-form');
        const btn         = document.getElementById('generate-btn');
        const btnLabel    = document.getElementById('generate-btn-label');
        const elEmpty     = document.getElementById('preview-empty');
        const elLoading   = document.getElementById('preview-loading');
        const elError     = document.getElementById('preview-error');
        const elContent   = document.getElementById('preview-content');
        const elToneBadge = document.getElementById('preview-tone-badge');

        const endpoint = @json(route('admin.movies.marketing-ai.banner.generate', $movie));
        const csrf     = document.querySelector('meta[name="csrf-token"]')?.content
                       || form.querySelector('input[name="_token"]').value;

        async function generate() {
            const tone = form.querySelector('input[name="tone"]:checked')?.value || 'cinematic';

            // UI: loading
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
                    body: JSON.stringify({ tone }),
                });

                const json = await res.json();

                if (!res.ok || !json.ok) {
                    throw new Error(json.error || ('HTTP ' + res.status));
                }

                renderPreview(json.data, tone);
            } catch (err) {
                elLoading.style.display = 'none';
                elError.style.display   = 'block';
                elError.textContent     = err.message || 'Generation failed.';
            } finally {
                btn.disabled         = false;
                btnLabel.textContent = 'Generate Banner Copy';
            }
        }

        function renderPreview(data, tone) {
            document.getElementById('preview-headline').textContent     = data.headline;
            document.getElementById('preview-subheadline').textContent  = data.subheadline;
            document.getElementById('preview-cta-text').textContent     = data.cta_text;

            document.getElementById('raw-headline').textContent     = data.headline;
            document.getElementById('raw-subheadline').textContent  = data.subheadline;
            document.getElementById('raw-cta').textContent          = data.cta_text;

            document.getElementById('count-headline').textContent    = [...data.headline].length    + '/60';
            document.getElementById('count-subheadline').textContent = [...data.subheadline].length + '/100';
            document.getElementById('count-cta').textContent         = [...data.cta_text].length    + '/20';

            elToneBadge.textContent     = tone;
            elToneBadge.style.display   = 'inline-flex';
            elLoading.style.display     = 'none';
            elError.style.display       = 'none';
            elContent.style.display     = 'block';
            window.__lastBannerResult   = data;
        }

        form.addEventListener('submit', (e) => { e.preventDefault(); generate(); });
        document.getElementById('regen-btn')?.addEventListener('click', generate);

        // Copy buttons
        document.body.addEventListener('click', async (e) => {
            const t = e.target.closest('.copy-btn');
            if (!t) return;
            const el = document.getElementById(t.dataset.target);
            if (!el) return;
            try {
                await navigator.clipboard.writeText(el.textContent);
                const orig = t.textContent;
                t.textContent = 'Copied!';
                setTimeout(() => { t.textContent = orig; }, 1200);
            } catch {}
        });

        document.getElementById('copy-all-btn')?.addEventListener('click', async () => {
            if (!window.__lastBannerResult) return;
            const txt = JSON.stringify(window.__lastBannerResult, null, 2);
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
