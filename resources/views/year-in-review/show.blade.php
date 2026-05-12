@php
    /** @var \App\Models\YearInReview $review */
    /** @var array $stats */
    /** @var array $shareable */
    $userName = auth()->user()?->name ?? 'Sinephile';

    $topGenres   = $stats['top_genres']   ?? [];
    $topActors   = $stats['top_actors']   ?? [];
    $topDecades  = $stats['top_decades']  ?? [];
    $longest     = $stats['longest_session']      ?? null;
    $month       = $stats['most_active_month']    ?? null;
    $weekday     = $stats['most_active_weekday']  ?? null;
    $firstFilm   = $stats['first_watched_movie']  ?? null;
    $rewatched   = $stats['most_rewatched_movie'] ?? null;

    $totalFilms = (int)   ($stats['total_films_watched'] ?? 0);
    $totalHours = (float) ($stats['total_watch_hours']   ?? 0);
    $completed  = (int)   ($stats['completed_count']     ?? 0);

    $topGenre  = $topGenres[0]['name'] ?? null;
    $topActor  = $topActors[0]['name'] ?? null;
    $topDecade = $topDecades[0]['label'] ?? null;
@endphp

<x-layout :title="'FLiK Year In Review ' . $year . ' — ' . $userName">
    <style>
        .yir-page { background: #050505; min-height: 100vh; }
        .yir-card {
            min-height: 90vh;
            padding: 4rem 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        @media (min-width: 768px) {
            .yir-card { padding: 6rem 4rem; }
        }
        .yir-eyebrow {
            font-size: 0.75rem;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            color: rgba(197, 165, 90, 0.85);
            font-weight: 600;
        }
        .yir-headline {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            line-height: 1.05;
            color: #fff;
            font-size: clamp(2.25rem, 8vw, 5.25rem);
            margin: 1rem 0;
        }
        .yir-headline-xl {
            font-family: 'Outfit', sans-serif;
            font-weight: 900;
            line-height: 0.95;
            font-size: clamp(4rem, 18vw, 11rem);
            background: linear-gradient(180deg, #F4E1B0 0%, #C5A55A 60%, #8C6F32 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin: 1rem 0 1.5rem;
            text-shadow: 0 8px 40px rgba(197, 165, 90, 0.25);
        }
        .yir-sub {
            color: #c9c9c9;
            max-width: 38rem;
            margin: 0 auto;
            font-size: clamp(0.95rem, 2vw, 1.125rem);
            line-height: 1.55;
        }
        .yir-bg-cover {
            background:
                radial-gradient(ellipse at top, rgba(197,165,90,0.18) 0%, transparent 55%),
                radial-gradient(ellipse at bottom right, rgba(120,60,180,0.18) 0%, transparent 55%),
                #050505;
        }
        .yir-bg-films {
            background:
                radial-gradient(circle at 30% 20%, rgba(197,165,90,0.22) 0%, transparent 45%),
                radial-gradient(circle at 70% 80%, rgba(80,160,220,0.18) 0%, transparent 45%),
                #050505;
        }
        .yir-bg-hours {
            background:
                radial-gradient(circle at 50% 50%, rgba(197,165,90,0.22) 0%, transparent 60%),
                linear-gradient(180deg, #1a0f04 0%, #050505 100%);
        }
        .yir-bg-genre {
            background:
                radial-gradient(ellipse at top left, rgba(220,80,140,0.20) 0%, transparent 55%),
                radial-gradient(ellipse at bottom right, rgba(197,165,90,0.20) 0%, transparent 55%),
                #050505;
        }
        .yir-bg-actor {
            background:
                radial-gradient(circle at 50% 30%, rgba(80,160,220,0.20) 0%, transparent 55%),
                radial-gradient(circle at 50% 80%, rgba(197,165,90,0.18) 0%, transparent 55%),
                #050505;
        }
        .yir-bg-decade {
            background:
                radial-gradient(ellipse at center, rgba(197,165,90,0.22) 0%, transparent 60%),
                linear-gradient(135deg, #150f06 0%, #050505 100%);
        }
        .yir-bg-when {
            background:
                radial-gradient(circle at 30% 70%, rgba(120,60,180,0.22) 0%, transparent 55%),
                radial-gradient(circle at 70% 30%, rgba(197,165,90,0.18) 0%, transparent 55%),
                #050505;
        }
        .yir-bg-narrative {
            background:
                radial-gradient(ellipse at top, rgba(197,165,90,0.15) 0%, transparent 50%),
                #060403;
        }
        .yir-bg-share {
            background:
                radial-gradient(circle at center, rgba(197,165,90,0.30) 0%, transparent 55%),
                linear-gradient(135deg, #1a1308 0%, #050505 100%);
        }
        .yir-stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 9999px;
            background: rgba(197, 165, 90, 0.10);
            border: 1px solid rgba(197, 165, 90, 0.30);
            color: #C5A55A;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .yir-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin: 2rem auto 0;
            max-width: 22rem;
            text-align: left;
        }
        .yir-list-item {
            display: grid;
            grid-template-columns: 1.75rem 1fr auto;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(197, 165, 90, 0.12);
            color: #fff;
        }
        .yir-list-rank {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.1rem;
            color: #C5A55A;
        }
        .yir-list-count {
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.55);
        }
        .yir-divider {
            width: 80px;
            height: 3px;
            border-radius: 2px;
            background: linear-gradient(90deg, transparent, #C5A55A, transparent);
            margin: 1.5rem auto;
        }
        .yir-narrative {
            max-width: 42rem;
            margin: 1.5rem auto 0;
            color: #d6d6d6;
            font-size: clamp(0.95rem, 1.6vw, 1.1rem);
            line-height: 1.7;
            text-align: left;
            white-space: pre-line;
        }
        .yir-share-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.85rem 1.6rem;
            border-radius: 9999px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(135deg, #E8D5A3, #C5A55A);
            color: #1a1308;
            border: none;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.2s ease;
            box-shadow: 0 8px 24px rgba(197, 165, 90, 0.35);
        }
        .yir-share-btn:hover { transform: translateY(-1px); box-shadow: 0 12px 32px rgba(197, 165, 90, 0.45); }
        .yir-share-btn:active { transform: translateY(0); }
        .yir-share-btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.85rem 1.6rem;
            border-radius: 9999px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            background: transparent;
            color: #C5A55A;
            border: 1px solid rgba(197, 165, 90, 0.4);
            cursor: pointer;
        }
        .yir-share-btn-ghost:hover { background: rgba(197, 165, 90, 0.08); }
        .yir-scroll-hint {
            position: absolute;
            bottom: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(197, 165, 90, 0.55);
            font-size: 0.7rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            animation: yir-bounce 2s infinite;
        }
        @keyframes yir-bounce {
            0%, 100% { transform: translate(-50%, 0); }
            50%      { transform: translate(-50%, 6px); }
        }
        .yir-share-grid {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2rem;
        }
        .yir-stat-counter {
            color: rgba(255,255,255,0.55);
            font-size: 0.85rem;
            margin-top: 1.25rem;
        }
    </style>

    <div class="yir-page" x-data="yirShare({ id: {{ (int) $review->id }}, year: {{ (int) $year }}, sharedCount: {{ (int) ($review->shared_count ?? 0) }} })">

        <!-- ━━━ Card 1: Cover ━━━ -->
        <section class="yir-card yir-bg-cover">
            <div class="yir-eyebrow">FLiK Year In Review</div>
            <div class="yir-headline-xl">{{ $year }}</div>
            <div class="yir-sub">
                Halo <strong style="color:#fff">{{ $userName }}</strong> — ini perjalanan sinematikmu sepanjang tahun {{ $year }}.
                Geser ke bawah untuk mulai menonton ulang.
            </div>
            <div class="yir-scroll-hint">Scroll &darr;</div>
        </section>

        <!-- ━━━ Card 2: Total Films ━━━ -->
        <section class="yir-card yir-bg-films">
            <div class="yir-eyebrow">Total Film</div>
            <div class="yir-headline-xl">{{ number_format($totalFilms) }}</div>
            <div class="yir-sub">
                @if ($totalFilms > 0)
                    Kamu menyalakan {{ number_format($totalFilms) }} judul berbeda di FLiK tahun ini.
                    {{ $completed > 0 ? 'Dari semua itu, ' . number_format($completed) . ' kamu tonton sampai habis.' : '' }}
                @else
                    Tahun {{ $year }} kamu belum sempat menonton apa pun di FLiK. Tahun depan, mari mulai!
                @endif
            </div>
        </section>

        <!-- ━━━ Card 3: Watch Hours ━━━ -->
        <section class="yir-card yir-bg-hours">
            <div class="yir-eyebrow">Total Jam Tonton</div>
            <div class="yir-headline-xl">{{ number_format($totalHours, 1) }}</div>
            <div class="yir-sub">
                Sekitar <strong style="color:#C5A55A">{{ number_format($totalHours, 1) }} jam</strong> kamu habiskan bersama FLiK tahun {{ $year }}
                @if ($totalHours >= 24)
                    — itu lebih dari satu hari penuh tanpa tidur!
                @elseif ($totalHours > 0)
                    — waktu yang berarti untuk cerita yang berarti.
                @else
                    .
                @endif
            </div>
        </section>

        <!-- ━━━ Card 4: Top Genres ━━━ -->
        @if (count($topGenres))
        <section class="yir-card yir-bg-genre">
            <div class="yir-eyebrow">Genre Favorit</div>
            <div class="yir-headline">{{ $topGenre }}</div>
            <div class="yir-sub">
                Genre yang paling sering kamu pilih — selera kamu kelihatan.
            </div>
            <div class="yir-list">
                @foreach (array_slice($topGenres, 0, 5) as $i => $g)
                    <div class="yir-list-item">
                        <span class="yir-list-rank">{{ $i + 1 }}</span>
                        <span>{{ $g['name'] }}</span>
                        <span class="yir-list-count">{{ $g['count'] }} film</span>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        <!-- ━━━ Card 5: Top Actors ━━━ -->
        @if (count($topActors))
        <section class="yir-card yir-bg-actor">
            <div class="yir-eyebrow">Aktor yang Paling Sering Muncul</div>
            <div class="yir-headline">{{ $topActor }}</div>
            <div class="yir-sub">
                Wajah yang paling sering muncul di layar kamu tahun ini.
            </div>
            <div class="yir-list">
                @foreach (array_slice($topActors, 0, 5) as $i => $a)
                    <div class="yir-list-item">
                        <span class="yir-list-rank">{{ $i + 1 }}</span>
                        <span>{{ $a['name'] }}</span>
                        <span class="yir-list-count">{{ $a['count'] }} film</span>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        <!-- ━━━ Card 6: Top Decade ━━━ -->
        @if (count($topDecades))
        <section class="yir-card yir-bg-decade">
            <div class="yir-eyebrow">Era Sinematikmu</div>
            <div class="yir-headline-xl">{{ $topDecade }}</div>
            <div class="yir-sub">
                Dekade {{ $topDecade }} adalah era yang paling sering kamu tonton tahun ini.
                Ada sesuatu di film {{ $topDecade }} yang bicara ke kamu.
            </div>
            @if (count($topDecades) > 1)
                <div class="yir-list">
                    @foreach (array_slice($topDecades, 0, 4) as $i => $d)
                        <div class="yir-list-item">
                            <span class="yir-list-rank">{{ $i + 1 }}</span>
                            <span>{{ $d['label'] }}</span>
                            <span class="yir-list-count">{{ $d['count'] }} film</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
        @endif

        <!-- ━━━ Card 7: When You Watched ━━━ -->
        @if ($month || $weekday || $longest)
        <section class="yir-card yir-bg-when">
            <div class="yir-eyebrow">Kapan & Bagaimana</div>
            <div class="yir-headline">Pola tontonan kamu</div>
            <div class="yir-divider"></div>
            <div style="display:grid; gap:1rem; max-width:32rem; margin:1.5rem auto 0;">
                @if (!empty($month['label']))
                    <div class="yir-list-item">
                        <x-icon name="calendar" :size="20" class="text-[#C5A55A]" />
                        <span>Bulan paling aktif</span>
                        <span class="yir-list-count" style="color:#fff; font-weight:600">{{ $month['label'] }}</span>
                    </div>
                @endif
                @if (!empty($weekday['label']))
                    <div class="yir-list-item">
                        <x-icon name="clock" :size="20" class="text-[#C5A55A]" />
                        <span>Hari paling aktif</span>
                        <span class="yir-list-count" style="color:#fff; font-weight:600">{{ $weekday['label'] }}</span>
                    </div>
                @endif
                @if (!empty($longest['title']))
                    <div class="yir-list-item">
                        <x-icon name="fire" :size="20" class="text-[#C5A55A]" />
                        <span>Sesi terpanjang</span>
                        <span class="yir-list-count" style="color:#fff; font-weight:600">{{ $longest['minutes'] }} mnt · {{ \Illuminate\Support\Str::limit($longest['title'], 22) }}</span>
                    </div>
                @endif
                @if (!empty($firstFilm['title']))
                    <div class="yir-list-item">
                        <x-icon name="play" :size="20" class="text-[#C5A55A]" />
                        <span>Film pertama tahun ini</span>
                        <span class="yir-list-count" style="color:#fff; font-weight:600">{{ \Illuminate\Support\Str::limit($firstFilm['title'], 22) }}</span>
                    </div>
                @endif
                @if (!empty($rewatched['title']))
                    <div class="yir-list-item">
                        <x-icon name="heart" :size="20" class="text-[#C5A55A]" />
                        <span>Paling diulang</span>
                        <span class="yir-list-count" style="color:#fff; font-weight:600">{{ \Illuminate\Support\Str::limit($rewatched['title'], 22) }}</span>
                    </div>
                @endif
            </div>
        </section>
        @endif

        <!-- ━━━ Card 8: AI Narrative ━━━ -->
        @if (trim((string) $narrative) !== '')
        <section class="yir-card yir-bg-narrative">
            <div class="yir-eyebrow">Cerita Tahunmu</div>
            <div class="yir-headline">Tahun {{ $year }} dalam narasi</div>
            <div class="yir-divider"></div>
            <div class="yir-narrative">{{ $narrative }}</div>
        </section>
        @endif

        <!-- ━━━ Card 9: Share ━━━ -->
        <section class="yir-card yir-bg-share">
            <div class="yir-eyebrow">Bagikan Recap-mu</div>
            <div class="yir-headline">Sampai jumpa di {{ $year + 1 }}</div>
            <div class="yir-sub">
                Pamerkan tahun sinematikmu! Salin tautan atau bagikan langsung ke media sosial favorit.
            </div>

            <div class="yir-share-grid">
                <button type="button" class="yir-share-btn" @click="copyLink()">
                    <x-icon name="download" :size="18" />
                    <span x-text="copied ? 'Tautan disalin!' : 'Salin Tautan'"></span>
                </button>

                <a class="yir-share-btn-ghost"
                   :href="twitterUrl"
                   target="_blank" rel="noopener noreferrer"
                   @click="trackShare()">
                    <x-icon name="sparkles" :size="18" />
                    <span>Twitter / X</span>
                </a>

                <a class="yir-share-btn-ghost"
                   :href="whatsappUrl"
                   target="_blank" rel="noopener noreferrer"
                   @click="trackShare()">
                    <x-icon name="chevron-right" :size="18" />
                    <span>WhatsApp</span>
                </a>

                <button type="button" class="yir-share-btn-ghost"
                        x-show="canNativeShare"
                        @click="nativeShare()">
                    <x-icon name="lightning" :size="18" />
                    <span>Bagikan…</span>
                </button>
            </div>

            <div class="yir-stat-counter">
                Sudah dibagikan <span x-text="sharedCount"></span> kali
            </div>

            <div style="margin-top:2.5rem; display:flex; gap:0.75rem; flex-wrap:wrap; justify-content:center;">
                <a href="/movies" class="yir-share-btn-ghost">
                    <x-icon name="film" :size="18" />
                    <span>Kembali ke Katalog</span>
                </a>
            </div>
        </section>
    </div>

    @push('scripts')
    <script>
        function yirShare({ id, year, sharedCount }) {
            return {
                id,
                year,
                sharedCount,
                copied: false,
                shareUrl: window.location.origin + '/year-in-review/' + year,
                shareText: @json('Tahun ' . $year . ' ' . $userName . ' di FLiK — ' . $totalFilms . ' film, ' . number_format($totalHours, 1) . ' jam.'),
                canNativeShare: typeof navigator !== 'undefined' && !!navigator.share,
                get twitterUrl() {
                    return 'https://twitter.com/intent/tweet?text=' +
                        encodeURIComponent(this.shareText) +
                        '&url=' + encodeURIComponent(this.shareUrl);
                },
                get whatsappUrl() {
                    return 'https://wa.me/?text=' +
                        encodeURIComponent(this.shareText + ' ' + this.shareUrl);
                },
                async copyLink() {
                    try {
                        await navigator.clipboard.writeText(this.shareUrl);
                        this.copied = true;
                        setTimeout(() => { this.copied = false; }, 2000);
                        this.trackShare();
                    } catch (e) {
                        // Fallback: select text in a hidden input
                        const ta = document.createElement('textarea');
                        ta.value = this.shareUrl;
                        document.body.appendChild(ta);
                        ta.select();
                        try { document.execCommand('copy'); this.copied = true; } catch (_) {}
                        document.body.removeChild(ta);
                        setTimeout(() => { this.copied = false; }, 2000);
                    }
                },
                async nativeShare() {
                    if (!navigator.share) return;
                    try {
                        await navigator.share({
                            title: 'FLiK Year In Review ' + this.year,
                            text: this.shareText,
                            url: this.shareUrl,
                        });
                        this.trackShare();
                    } catch (_) {}
                },
                trackShare() {
                    fetch('/year-in-review/' + this.id + '/share', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept': 'application/json',
                        },
                    })
                    .then(r => r.ok ? r.json() : null)
                    .then(data => { if (data && typeof data.shared_count === 'number') this.sharedCount = data.shared_count; })
                    .catch(() => {});
                },
            };
        }
    </script>
    @endpush
</x-layout>
