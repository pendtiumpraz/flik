<x-layout>
    @php
        $windowMeta = [
            '1h'  => ['label' => '1 Jam',   'sub' => 'Trending Sekarang'],
            '24h' => ['label' => '24 Jam',  'sub' => 'Trending Hari Ini'],
            '7d'  => ['label' => '7 Hari',  'sub' => 'Trending Minggu Ini'],
            '30d' => ['label' => '30 Hari', 'sub' => 'Trending Bulan Ini'],
        ];
        $defaultWindow = '24h';
    @endphp

    <div class="bg-black min-h-screen pt-16">
        <div class="container mx-auto px-4 md:px-6 lg:px-8 max-w-[1400px] py-6 md:py-10"
             x-data="{ active: '{{ $defaultWindow }}' }"
             x-init="
                // Honour #window-XX deep links so the homepage shelf's
                // 'Lihat semua' link lands on the right tab.
                const m = (window.location.hash || '').match(/window-(1h|24h|7d|30d)/);
                if (m) active = m[1];
             ">

            <!-- Page header -->
            <header class="mb-6 md:mb-10">
                <div class="flex items-center gap-3 mb-2">
                    <span class="text-2xl">🔥</span>
                    <h1 class="font-heading text-2xl md:text-4xl font-black text-white tracking-tight">
                        Trending
                    </h1>
                </div>
                <p class="text-sm text-gray-400 max-w-2xl">
                    Apa yang sedang ditonton orang di FLiK sekarang. Disusun berdasarkan jumlah view,
                    penonton unik, dan momentum baru-baru ini. Diperbarui otomatis tiap 10 menit.
                </p>
            </header>

            <!-- Window tabs -->
            <div class="flex flex-wrap gap-2 mb-6 md:mb-8">
                @foreach($windows as $w)
                    <button
                        @click="active = '{{ $w }}'; window.location.hash = 'window-{{ $w }}'"
                        :class="active === '{{ $w }}' ? 'trending-tab-active' : 'trending-tab-inactive'"
                        class="trending-tab">
                        <span class="font-bold">{{ $windowMeta[$w]['label'] }}</span>
                        <span class="ml-2 opacity-70 text-[11px]">{{ $windowMeta[$w]['sub'] }}</span>
                    </button>
                @endforeach
            </div>

            <!-- Window panels -->
            @foreach($windows as $w)
                @php $rows = $rowsByWindow[$w]; @endphp
                <section x-show="active === '{{ $w }}'" x-cloak id="window-{{ $w }}">
                    @if($rows->isEmpty())
                        <div class="text-center py-20 rounded-xl"
                             style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                            <div class="text-5xl mb-3 opacity-50">📊</div>
                            <p class="text-sm text-gray-400 mb-1">Belum ada data trending untuk periode ini.</p>
                            <p class="text-xs text-gray-600">Data muncul setelah pengguna mulai menonton film.</p>
                        </div>
                    @else
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4 md:gap-5">
                            @foreach($rows as $row)
                                @php
                                    $movie = $row->movie;
                                    $slugOrId = $movie->slug ?? $movie->id;
                                    $poster = $movie->effective_poster_url ?? $movie->poster_url ?? '/images/no-poster.png';
                                @endphp
                                <a href="{{ route('movies.show', $slugOrId) }}"
                                   class="trending-page-card group block"
                                   title="{{ $movie->title }}">
                                    <div class="relative">
                                        <span class="trending-page-rank" aria-hidden="true">{{ $row->rank }}</span>
                                        <div class="trending-page-thumb">
                                            <img src="{{ $poster }}"
                                                 alt="{{ $movie->title }}"
                                                 loading="lazy"
                                                 onerror="this.onerror=null; this.src='/images/no-poster.png';">
                                        </div>
                                    </div>
                                    <div class="mt-2">
                                        <div class="text-sm font-semibold text-white truncate group-hover:text-[#C5A55A] transition-colors">
                                            {{ $movie->title }}
                                        </div>
                                        <div class="flex items-center gap-1.5 mt-0.5 text-[10px] text-gray-500">
                                            <span>{{ number_format($row->view_count) }} views</span>
                                            <span class="text-gray-700">·</span>
                                            <span>{{ number_format($row->unique_viewer_count) }} unik</span>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    </div>

    <x-footer />

    <style>
        .trending-tab {
            display: inline-flex;
            align-items: center;
            padding: 8px 14px;
            font-size: 13px;
            border-radius: 8px;
            transition: all 200ms;
            cursor: pointer;
            border: 1px solid rgba(197,165,90,0.18);
        }
        .trending-tab-inactive {
            color: #aaa;
            background: rgba(20,18,16,0.6);
        }
        .trending-tab-inactive:hover {
            color: #C5A55A;
            border-color: rgba(197,165,90,0.4);
        }
        .trending-tab-active {
            color: #000;
            background: linear-gradient(135deg, #C5A55A, #E8D5A3);
            border-color: #C5A55A;
        }

        .trending-page-card { position: relative; }
        .trending-page-thumb {
            position: relative;
            aspect-ratio: 2/3;
            border-radius: 10px;
            overflow: hidden;
            background: #141210;
            margin-left: 28px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.5);
            transition: transform 200ms ease, box-shadow 200ms ease;
        }
        .trending-page-card:hover .trending-page-thumb {
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(197,165,90,0.25);
        }
        .trending-page-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .trending-page-rank {
            position: absolute;
            left: -8px;
            bottom: -14px;
            font-family: 'Bebas Neue', 'Oswald', 'Inter', sans-serif;
            font-weight: 900;
            font-size: 96px;
            line-height: 1;
            color: #0a0a0a;
            -webkit-text-stroke: 2px #C5A55A;
            z-index: 1;
            user-select: none;
            pointer-events: none;
            letter-spacing: -0.05em;
        }
    </style>
</x-layout>
