<x-admin.layout title="Social Media Generator — {{ $movie->title }}">

    @php
        $platformMeta = [
            'instagram' => ['label' => 'Instagram', 'max' => 2200, 'icon' => '📸', 'accent' => '#E1306C'],
            'twitter'   => ['label' => 'Twitter / X', 'max' => 280, 'icon' => '𝕏',  'accent' => '#1DA1F2'],
            'tiktok'    => ['label' => 'TikTok',    'max' => 2200, 'icon' => '🎵', 'accent' => '#FE2C55'],
            'facebook'  => ['label' => 'Facebook',  'max' => 2000, 'icon' => 'f',  'accent' => '#1877F2'],
        ];
    @endphp

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="{{ route('admin.movies.edit', $movie) }}" style="font-size:12px;color:#777;text-decoration:none">
                <x-icon name="chevron-left" size="12" /> Back to {{ $movie->title }}
            </a>
            <h2 style="font-size:22px;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:10px">
                <x-icon name="sparkles" size="22" style="color:#C5A55A" />
                Social Media Post Generator
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                AI-powered caption + hashtags optimized per platform.
            </p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:380px 1fr;gap:24px" id="social-app">

        <!-- ============ FORM ============ -->
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px;height:fit-content">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:8px">
                <x-icon name="cog" size="16" /> Platform Settings
            </h3>
            <p style="font-size:12px;color:#777;margin-bottom:18px">
                Tiap platform punya style & character limit yang berbeda — AI akan adapt otomatis.
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

            <form id="social-form">
                @csrf
                <div class="form-group">
                    <label>Platform</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        @foreach($platforms as $platform)
                            @php $meta = $platformMeta[$platform] ?? null; @endphp
                            <label class="platform-option" style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#0f0f0f;border:1px solid #222;border-radius:8px;cursor:pointer;font-size:13px;transition:all 0.15s">
                                <input type="radio" name="platform" value="{{ $platform }}" {{ $loop->first ? 'checked' : '' }} style="accent-color:#C5A55A">
                                <span style="display:inline-flex;align-items:center;gap:6px">
                                    <span style="font-size:14px">{{ $meta['icon'] ?? '' }}</span>
                                    <span>{{ $meta['label'] ?? ucfirst($platform) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div id="platform-info" style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.2);border-radius:6px;padding:10px 12px;margin-bottom:18px;font-size:11px;color:#aaa;line-height:1.55">
                    <!-- filled by JS -->
                </div>

                <button type="submit" class="btn btn-gold" id="generate-btn" style="width:100%;justify-content:center">
                    <x-icon name="sparkles" size="14" />
                    <span id="generate-btn-label">Generate Social Post</span>
                </button>
            </form>
        </div>

        <!-- ============ PREVIEW ============ -->
        <div>
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:14px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                    <h3 style="font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px">
                        <x-icon name="eye" size="14" /> Platform Preview
                    </h3>
                    <span id="preview-platform-badge" class="badge badge-gold" style="display:none"></span>
                </div>

                <!-- Empty -->
                <div id="preview-empty" style="padding:60px 20px;text-align:center;color:#555">
                    <div style="display:inline-flex;width:56px;height:56px;border-radius:50%;background:#0f0f0f;align-items:center;justify-content:center;color:#C5A55A;margin-bottom:14px">
                        <x-icon name="sparkles" size="28" />
                    </div>
                    <p style="margin-bottom:6px;color:#888">Belum ada post yang di-generate.</p>
                    <p style="font-size:12px">Pilih platform di kiri, lalu klik <strong>Generate Social Post</strong>.</p>
                </div>

                <!-- Loading -->
                <div id="preview-loading" style="display:none;padding:60px 20px;text-align:center;color:#aaa">
                    <div style="display:inline-block;width:32px;height:32px;border:3px solid #2a2a2a;border-top-color:#C5A55A;border-radius:50%;animation:spin 0.8s linear infinite;margin-bottom:14px"></div>
                    <p>AI sedang menulis caption…</p>
                </div>

                <!-- Error -->
                <div id="preview-error" style="display:none;padding:20px;margin:20px;background:rgba(220,38,38,0.1);border:1px solid rgba(220,38,38,0.3);border-radius:8px;color:#ef4444;font-size:13px"></div>

                <!-- Content -->
                <div id="preview-content" style="display:none;padding:24px">
                    <!-- Platform mock card -->
                    <div id="mock-card" style="background:#fff;color:#0f0f0f;border-radius:12px;overflow:hidden;max-width:520px;margin:0 auto;box-shadow:0 4px 24px rgba(0,0,0,0.4)">
                        <!-- Header (FLiK as poster) -->
                        <div style="padding:12px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid #eee">
                            <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#C5A55A,#E8D5A3);display:flex;align-items:center;justify-content:center;font-weight:800;color:#000;font-size:13px">F</div>
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:600;font-size:13px;color:#0f0f0f">FLiK Indonesia</div>
                                <div style="font-size:11px;color:#777" id="mock-meta">@flik.id · Sponsored</div>
                            </div>
                            <span style="color:#777;font-size:18px">⋯</span>
                        </div>

                        <!-- Caption above image (varies by platform) -->
                        <div id="mock-caption-top" style="padding:12px 14px;font-size:14px;line-height:1.45;color:#0f0f0f;white-space:pre-wrap;word-break:break-word"></div>

                        <!-- Image -->
                        <div style="background:#000;aspect-ratio:1/1;background-image:url('{{ $movie->backdrop_url }}');background-size:cover;background-position:center;display:flex;align-items:flex-end">
                            <div style="background:linear-gradient(0deg,rgba(0,0,0,0.7),transparent);width:100%;padding:24px 16px 14px;color:#fff">
                                <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:20px">{{ $movie->title }}</div>
                                @if($movie->release_date)
                                    <div style="font-size:12px;opacity:0.85">{{ $movie->release_date->format('Y') }}</div>
                                @endif
                            </div>
                        </div>

                        <!-- Actions strip -->
                        <div id="mock-actions" style="padding:10px 14px;display:flex;align-items:center;gap:14px;color:#0f0f0f;border-bottom:1px solid #f3f3f3">
                            <span style="font-size:18px">♡</span>
                            <span style="font-size:18px">💬</span>
                            <span style="font-size:18px">↗</span>
                            <span style="margin-left:auto;font-size:18px">🔖</span>
                        </div>

                        <!-- Caption below image (Instagram style) -->
                        <div id="mock-caption-bottom" style="padding:12px 14px 16px;font-size:13px;line-height:1.5;color:#0f0f0f;white-space:pre-wrap;word-break:break-word"></div>
                    </div>

                    <!-- Raw data + counts -->
                    <div style="margin-top:24px;background:#0f0f0f;border:1px solid #222;border-radius:10px;padding:16px">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:10px;flex-wrap:wrap">
                            <h4 style="font-size:12px;text-transform:uppercase;color:#888;letter-spacing:1px">Raw Output</h4>
                            <div style="display:flex;align-items:center;gap:10px;font-size:11px;color:#888">
                                <span>Length: <strong id="char-count-text" style="color:#fff">0</strong> / <span id="char-count-max">2200</span></span>
                                <div style="width:80px;height:4px;background:#2a2a2a;border-radius:2px;overflow:hidden">
                                    <div id="char-count-bar" style="height:100%;background:#22c55e;width:0%;transition:width 0.3s,background 0.3s"></div>
                                </div>
                            </div>
                        </div>

                        <div style="margin-bottom:14px">
                            <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Caption</div>
                            <div style="display:flex;gap:8px;align-items:flex-start">
                                <pre id="raw-caption" style="flex:1;background:#1a1a1a;padding:10px 12px;border-radius:6px;color:#e5e5e5;font-size:13px;font-family:'Inter';white-space:pre-wrap;word-break:break-word;margin:0;max-height:240px;overflow-y:auto"></pre>
                                <button type="button" class="btn btn-ghost btn-sm copy-btn" data-target="raw-caption">Copy</button>
                            </div>
                        </div>

                        <div>
                            <div style="font-size:11px;color:#888;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">
                                Hashtags <span id="hashtag-count" style="color:#555">(0)</span>
                            </div>
                            <div id="raw-hashtags" style="display:flex;flex-wrap:wrap;gap:6px"></div>
                            <button type="button" class="btn btn-ghost btn-sm copy-btn" data-target="raw-hashtags-string" style="margin-top:10px">Copy All Hashtags</button>
                            <span id="raw-hashtags-string" style="display:none"></span>
                        </div>

                        <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
                            <button type="button" class="btn btn-ghost btn-sm" id="copy-all-btn">
                                <x-icon name="download" size="12" /> Copy Caption + Hashtags
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
        .platform-option:has(input:checked) { border-color:#C5A55A !important; background:rgba(197,165,90,0.1) !important; color:#C5A55A; }
        .platform-option:hover { border-color: #3a3a3a; }
        .hashtag-pill { background:#1a1a1a;border:1px solid #2a2a2a;color:#C5A55A;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:500; }
    </style>

    <script>
    (function () {
        const platformMeta = @json($platformMeta);

        const form        = document.getElementById('social-form');
        const btn         = document.getElementById('generate-btn');
        const btnLabel    = document.getElementById('generate-btn-label');
        const platformInfo = document.getElementById('platform-info');
        const elEmpty     = document.getElementById('preview-empty');
        const elLoading   = document.getElementById('preview-loading');
        const elError     = document.getElementById('preview-error');
        const elContent   = document.getElementById('preview-content');
        const elPlatBadge = document.getElementById('preview-platform-badge');

        const endpoint = @json(route('admin.movies.marketing-ai.social.generate', $movie));
        const csrf     = form.querySelector('input[name="_token"]').value;

        const platformGuide = {
            instagram: '<strong>Instagram</strong> — caption panjang OK (max 2200), storytelling + emoji. Hashtag block di akhir (8-12 buah).',
            twitter:   '<strong>Twitter/X</strong> — HARD limit 280 chars termasuk hashtag. Tone witty + punchline. 2-4 hashtags inline.',
            tiktok:    '<strong>TikTok</strong> — Gen-Z casual, hook depan, slang gaul. WAJIB ada #fyp / #foryoupage.',
            facebook:  '<strong>Facebook</strong> — conversational, ajak diskusi. Hashtag minim (2-3). Tidak hashtag-heavy.',
        };

        function updatePlatformInfo() {
            const p = form.querySelector('input[name="platform"]:checked')?.value || 'instagram';
            platformInfo.innerHTML = platformGuide[p] || '';
        }
        form.querySelectorAll('input[name="platform"]').forEach(r => r.addEventListener('change', updatePlatformInfo));
        updatePlatformInfo();

        async function generate() {
            const platform = form.querySelector('input[name="platform"]:checked')?.value || 'instagram';

            elEmpty.style.display    = 'none';
            elContent.style.display  = 'none';
            elError.style.display    = 'none';
            elLoading.style.display  = 'block';
            btn.disabled             = true;
            btnLabel.textContent     = 'Generating…';

            try {
                const res = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept':       'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ platform }),
                });
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || ('HTTP ' + res.status));
                renderPreview(json.data, platform);
            } catch (err) {
                elLoading.style.display = 'none';
                elError.style.display   = 'block';
                elError.textContent     = err.message || 'Generation failed.';
            } finally {
                btn.disabled         = false;
                btnLabel.textContent = 'Generate Social Post';
            }
        }

        function renderPreview(data, platform) {
            const meta = platformMeta[platform] || { label: platform, max: 2200 };
            const hashtagStr = (data.hashtags || []).join(' ');

            // Layout differs per platform: Twitter/Facebook put caption ABOVE image; Instagram/TikTok BELOW.
            const captionAbove = (platform === 'twitter' || platform === 'facebook');
            const captionTop    = document.getElementById('mock-caption-top');
            const captionBottom = document.getElementById('mock-caption-bottom');
            const mockMeta      = document.getElementById('mock-meta');

            mockMeta.textContent = {
                instagram: '@flik.id · Sponsored',
                twitter:   '@flik_id · 1h',
                tiktok:    '@flik.id · Original sound',
                facebook:  'FLiK Indonesia · Sponsored',
            }[platform] || '@flik.id';

            if (captionAbove) {
                // Twitter: include hashtags inline at the end
                const txt = platform === 'twitter'
                    ? data.caption + (hashtagStr ? ' ' + hashtagStr : '')
                    : data.caption;
                captionTop.textContent    = txt;
                captionTop.style.display  = 'block';
                captionBottom.style.display = 'none';
            } else {
                captionTop.style.display    = 'none';
                captionBottom.style.display = 'block';
                captionBottom.innerHTML     = escapeHtml(data.caption)
                    + (hashtagStr ? '\n\n<span style="color:#385898">' + escapeHtml(hashtagStr) + '</span>' : '');
            }

            // Raw caption box
            document.getElementById('raw-caption').textContent = data.caption;

            // Hashtags
            const hashWrap = document.getElementById('raw-hashtags');
            hashWrap.innerHTML = '';
            (data.hashtags || []).forEach(h => {
                const pill = document.createElement('span');
                pill.className = 'hashtag-pill';
                pill.textContent = h;
                hashWrap.appendChild(pill);
            });
            document.getElementById('hashtag-count').textContent = '(' + (data.hashtags || []).length + ')';
            document.getElementById('raw-hashtags-string').textContent = hashtagStr;

            // Char counter
            const count = data.character_count ?? [...data.caption].length;
            const max   = meta.max;
            document.getElementById('char-count-text').textContent = count;
            document.getElementById('char-count-max').textContent  = max;
            const pct = Math.min(100, Math.round((count / max) * 100));
            const bar = document.getElementById('char-count-bar');
            bar.style.width = pct + '%';
            bar.style.background = count > max ? '#ef4444' : (pct > 80 ? '#eab308' : '#22c55e');

            elPlatBadge.textContent    = meta.label;
            elPlatBadge.style.display  = 'inline-flex';
            elLoading.style.display    = 'none';
            elError.style.display      = 'none';
            elContent.style.display    = 'block';

            window.__lastSocialResult = { ...data, platform };
        }

        function escapeHtml(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
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
            if (!window.__lastSocialResult) return;
            const r = window.__lastSocialResult;
            const full = r.caption + (r.hashtags?.length ? '\n\n' + r.hashtags.join(' ') : '');
            try {
                await navigator.clipboard.writeText(full);
                const btn = document.getElementById('copy-all-btn');
                const orig = btn.innerHTML;
                btn.innerHTML = '✓ Copied!';
                setTimeout(() => { btn.innerHTML = orig; }, 1500);
            } catch {}
        });
    })();
    </script>

</x-admin.layout>
