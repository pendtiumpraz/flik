@php
    // ━━━ SEO meta computation ━━━
    $seoTitle = "{$cast->name} — Filmografi & Profil | FLiK";
    $seoDescription = $cast->bio
        ? \Illuminate\Support\Str::limit(strip_tags($cast->bio), 160, '…')
        : "Filmografi lengkap dan profil {$cast->name} di FLiK — Rumah Sinema Indonesia.";
    $seoOgImage = $cast->profile_image;

    $isDirector = $cast->role === 'director';
    $birth = $cast->birth_date?->isoFormat('D MMMM Y');
    $auteur = $directorAnalysis?->data ?? null;
@endphp

<x-layout :title="$seoTitle" :description="$seoDescription" :ogImage="$seoOgImage">

    {{-- ━━━ JSON-LD Person schema (SEO) ━━━ --}}
    <script type="application/ld+json">
    @json([
        '@context'    => 'https://schema.org',
        '@type'       => 'Person',
        'name'        => $cast->name,
        'image'       => $seoOgImage,
        'description' => $seoDescription,
        'jobTitle'    => $isDirector ? 'Film Director' : 'Actor',
        'birthDate'   => $cast->birth_date?->toDateString(),
        'nationality' => $cast->nationality,
        'sameAs'      => array_values(array_filter([$cast->wikipedia_url])),
        'url'         => route('public.cast.show', ['cast' => $cast->id, 'slug' => $cast->slug]),
    ])
    </script>

    <div class="bg-[#0a0a0a] text-white min-h-screen">

        {{-- ━━━ HERO ━━━ --}}
        <section class="relative overflow-hidden">
            {{-- Soft gradient backdrop with profile image blurred --}}
            <div class="absolute inset-0 opacity-30 blur-3xl scale-110"
                 style="background-image: url('{{ $cast->profile_image }}'); background-size: cover; background-position: center;"></div>
            <div class="absolute inset-0 bg-gradient-to-b from-[#0a0a0a]/40 via-[#0a0a0a]/85 to-[#0a0a0a]"></div>

            <div class="relative max-w-[1400px] mx-auto px-4 md:px-8 lg:px-16 pt-10 pb-14">

                {{-- Breadcrumb --}}
                <nav class="text-xs text-gray-500 mb-6 flex items-center gap-2">
                    <a href="/" class="hover:text-[#C5A55A]">Home</a>
                    <span>›</span>
                    <a href="{{ route('public.cast.index') }}" class="hover:text-[#C5A55A]">Cast</a>
                    <span>›</span>
                    <span class="text-gray-300">{{ $cast->name }}</span>
                </nav>

                <div class="grid grid-cols-1 md:grid-cols-[260px_1fr] gap-8 md:gap-10">

                    {{-- Profile image --}}
                    <div class="flex md:block justify-center">
                        <div class="relative w-48 md:w-full aspect-[3/4] rounded-2xl overflow-hidden border-2 border-[#C5A55A]/30 shadow-2xl">
                            <img src="{{ $cast->profile_image }}"
                                 alt="{{ $cast->name }}"
                                 class="absolute inset-0 w-full h-full object-cover">
                        </div>
                    </div>

                    {{-- Bio block --}}
                    <div>
                        <div class="flex items-center gap-2 mb-2">
                            <span class="px-2.5 py-1 text-[10px] uppercase tracking-wider rounded-full font-semibold
                                         {{ $isDirector ? 'bg-[#C5A55A] text-black' : 'bg-black/60 text-[#C5A55A] border border-[#C5A55A]/40' }}">
                                {{ $isDirector ? 'Sutradara' : 'Aktor' }}
                            </span>
                            @if($cast->bio_generated_at)
                                <span class="text-[10px] text-gray-500 italic" title="Biografi diperkaya AI">
                                    AI-enriched
                                </span>
                            @endif
                        </div>

                        <h1 class="font-heading text-3xl md:text-5xl font-bold tracking-tight mb-3">
                            {{ $cast->name }}
                        </h1>

                        <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-400 mb-5">
                            @if($birth)
                                <div>
                                    <span class="text-gray-500">Lahir:</span>
                                    <span class="text-gray-200">{{ $birth }}</span>
                                    @if($cast->age) <span class="text-gray-500">({{ $cast->age }} thn)</span> @endif
                                </div>
                            @endif
                            @if($cast->nationality)
                                <div>
                                    <span class="text-gray-500">Asal:</span>
                                    <span class="text-gray-200">{{ $cast->nationality }}</span>
                                </div>
                            @endif
                            @if($activeYears)
                                <div>
                                    <span class="text-gray-500">Aktif:</span>
                                    <span class="text-gray-200">{{ $activeYears }}</span>
                                </div>
                            @endif
                        </div>

                        @if($cast->bio)
                            <p class="text-sm md:text-base text-gray-300 leading-relaxed whitespace-pre-line">
                                {{ $cast->bio }}
                            </p>
                        @else
                            <p class="text-sm text-gray-500 italic">
                                Biografi belum tersedia untuk {{ $cast->name }}.
                                @auth
                                    @can('admin')
                                        Gunakan tombol di bawah untuk memperkaya dengan AI.
                                    @endcan
                                @endauth
                            </p>
                        @endif

                        {{-- Quick links + admin enrich button --}}
                        <div class="mt-5 flex flex-wrap gap-2">
                            @if($cast->wikipedia_url)
                                <a href="{{ $cast->wikipedia_url }}"
                                   target="_blank" rel="noopener nofollow"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 transition">
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M14.97 2.16h6.86v1.27l-2.62.27c-.21.04-.36.12-.43.27-.07.14-.11.36-.11.65v15.7c0 .14-.04.21-.04.36l-.21.21h-1.27L7.21 4.62v11.7c0 .43.04.79.21.97.18.27.61.4 1.27.5l1.78.21v1.27H4V18l1.45-.21c.36-.07.65-.21.79-.43.14-.21.21-.5.21-1.05V4.13c0-.18-.04-.36-.07-.5-.04-.14-.18-.21-.36-.27l-2.07-.27V1.8h5.79l10.04 14.04V4.62c0-.43-.07-.79-.21-1.05-.18-.21-.61-.36-1.27-.43l-1.45-.21V2.16z"/></svg>
                                    Wikipedia
                                </a>
                            @endif

                            {{-- Social share --}}
                            <a href="https://twitter.com/intent/tweet?url={{ urlencode(url()->current()) }}&text={{ urlencode($cast->name . ' di FLiK') }}"
                               target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 transition">
                                Bagikan ke X
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode(url()->current()) }}"
                               target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 transition">
                                Bagikan ke Facebook
                            </a>
                            <a href="https://api.whatsapp.com/send?text={{ urlencode($cast->name . ' — ' . url()->current()) }}"
                               target="_blank" rel="noopener"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 transition">
                                WhatsApp
                            </a>

                            @auth
                                @can('admin')
                                    @if(! $cast->bio_generated_at)
                                        <form method="POST"
                                              action="{{ route('admin.cast.enrich-bio', $cast) }}"
                                              class="inline">
                                            @csrf
                                            <button type="submit"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-lg bg-[#C5A55A]/15 hover:bg-[#C5A55A]/25 border border-[#C5A55A]/40 text-[#C5A55A] font-semibold transition">
                                                ✨ AI-enrich bio
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                            @endauth
                        </div>
                    </div>

                </div>
            </div>
        </section>

        {{-- ━━━ STATS STRIP ━━━ --}}
        <section class="border-y border-white/5 bg-[#0c0a08]">
            <div class="max-w-[1400px] mx-auto px-4 md:px-8 lg:px-16 py-6">
                <div class="grid grid-cols-3 md:grid-cols-4 gap-4 text-center">
                    <div>
                        <div class="text-2xl md:text-3xl font-heading font-bold text-[#C5A55A]">{{ $movieCount }}</div>
                        <div class="text-[10px] uppercase tracking-wider text-gray-500 mt-1">Total Film</div>
                    </div>
                    <div>
                        <div class="text-2xl md:text-3xl font-heading font-bold text-[#C5A55A]">
                            {{ $directedMovies->count() }}
                        </div>
                        <div class="text-[10px] uppercase tracking-wider text-gray-500 mt-1">Disutradarai</div>
                    </div>
                    <div>
                        <div class="text-2xl md:text-3xl font-heading font-bold text-[#C5A55A]">
                            {{ $actedMovies->count() }}
                        </div>
                        <div class="text-[10px] uppercase tracking-wider text-gray-500 mt-1">Dibintangi</div>
                    </div>
                    <div class="hidden md:block">
                        <div class="text-2xl md:text-3xl font-heading font-bold text-[#C5A55A]">
                            {{ $avgRating !== null ? number_format($avgRating, 1) : '—' }}
                            @if($avgRating !== null) <span class="text-sm text-gray-500">/10</span> @endif
                        </div>
                        <div class="text-[10px] uppercase tracking-wider text-gray-500 mt-1">Rating Rata-rata</div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ━━━ AUTEUR ANALYSIS (directors only) ━━━ --}}
        @if($isDirector && $auteur)
            <section class="max-w-[1400px] mx-auto px-4 md:px-8 lg:px-16 py-10">
                <div class="rounded-2xl border border-[#C5A55A]/25 bg-gradient-to-br from-[#1a1410] via-[#0f0d0a] to-[#0a0a0a] p-6 md:p-8">
                    <div class="flex items-center gap-2 mb-4">
                        <span class="text-[#C5A55A] text-xl">◈</span>
                        <h2 class="font-heading text-xl md:text-2xl font-bold">Analisis Auteur</h2>
                        <span class="text-[10px] uppercase tracking-wider text-gray-500 ml-2">AI-generated</span>
                    </div>

                    @if(! empty($auteur['signature_style']))
                        <div class="mb-5">
                            <h3 class="text-[11px] uppercase tracking-[0.2em] text-[#C5A55A]/80 font-semibold mb-1.5">
                                Signature Style
                            </h3>
                            <p class="text-sm text-gray-300 leading-relaxed">{{ $auteur['signature_style'] }}</p>
                        </div>
                    @endif

                    <div class="grid md:grid-cols-2 gap-6">
                        @if(! empty($auteur['recurring_themes']))
                            <div>
                                <h3 class="text-[11px] uppercase tracking-[0.2em] text-[#C5A55A]/80 font-semibold mb-2">
                                    Tema Berulang
                                </h3>
                                <ul class="space-y-1.5">
                                    @foreach($auteur['recurring_themes'] as $theme)
                                        <li class="text-sm text-gray-300 flex gap-2">
                                            <span class="text-[#C5A55A]">▸</span>
                                            <span>{{ $theme }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if(! empty($auteur['frequent_collaborators']))
                            <div>
                                <h3 class="text-[11px] uppercase tracking-[0.2em] text-[#C5A55A]/80 font-semibold mb-2">
                                    Kolaborator Tetap
                                </h3>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($auteur['frequent_collaborators'] as $name)
                                        <span class="inline-block px-2.5 py-1 text-xs rounded-full bg-white/5 border border-white/10 text-gray-200">
                                            {{ $name }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    @if(! empty($auteur['influence']))
                        <div class="mt-5 pt-5 border-t border-white/5">
                            <h3 class="text-[11px] uppercase tracking-[0.2em] text-[#C5A55A]/80 font-semibold mb-1.5">
                                Pengaruh
                            </h3>
                            <p class="text-sm text-gray-300 leading-relaxed">{{ $auteur['influence'] }}</p>
                        </div>
                    @endif

                    @if(! empty($auteur['trivia']))
                        <div class="mt-5 pt-5 border-t border-white/5">
                            <h3 class="text-[11px] uppercase tracking-[0.2em] text-[#C5A55A]/80 font-semibold mb-2">
                                Trivia
                            </h3>
                            <ul class="space-y-1.5">
                                @foreach($auteur['trivia'] as $fact)
                                    <li class="text-sm text-gray-300 flex gap-2">
                                        <span class="text-[#C5A55A]">★</span>
                                        <span>{{ $fact }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </section>
        @elseif($isDirector && $directedMovies->count() >= 3)
            @auth
                @can('admin')
                    <section class="max-w-[1400px] mx-auto px-4 md:px-8 lg:px-16 py-6">
                        <form method="POST" action="{{ route('admin.director-analyses.analyze') }}">
                            @csrf
                            <input type="hidden" name="director_name" value="{{ $cast->name }}">
                            <button type="submit"
                                    class="w-full md:w-auto px-5 py-3 rounded-xl bg-[#C5A55A]/15 hover:bg-[#C5A55A]/25 border border-[#C5A55A]/40 text-[#C5A55A] font-semibold transition">
                                ✨ Generate Auteur Analysis
                            </button>
                        </form>
                    </section>
                @endcan
            @endauth
        @endif

        {{-- ━━━ FILMOGRAPHY (tabs) ━━━ --}}
        <section class="max-w-[1400px] mx-auto px-4 md:px-8 lg:px-16 py-10"
                 x-data="{ tab: '{{ $isDirector && $directedMovies->count() ? 'directed' : 'acted' }}' }">
            <div class="flex items-center gap-3 text-xs uppercase tracking-[0.25em] text-[#C5A55A]/80 mb-3">
                <span class="inline-block w-8 h-px bg-[#C5A55A]"></span>
                <span>Filmografi</span>
            </div>

            {{-- Tab triggers --}}
            <div class="flex flex-wrap gap-2 mb-6 border-b border-white/5">
                @if($actedMovies->isNotEmpty())
                    <button @click="tab = 'acted'"
                            :class="tab === 'acted' ? 'text-[#C5A55A] border-[#C5A55A]' : 'text-gray-400 border-transparent hover:text-gray-200'"
                            class="px-4 py-2.5 text-sm font-semibold border-b-2 transition">
                        Dibintangi ({{ $actedMovies->count() }})
                    </button>
                @endif
                @if($directedMovies->isNotEmpty())
                    <button @click="tab = 'directed'"
                            :class="tab === 'directed' ? 'text-[#C5A55A] border-[#C5A55A]' : 'text-gray-400 border-transparent hover:text-gray-200'"
                            class="px-4 py-2.5 text-sm font-semibold border-b-2 transition">
                        Disutradarai ({{ $directedMovies->count() }})
                    </button>
                @endif
                <button @click="tab = 'all'"
                        :class="tab === 'all' ? 'text-[#C5A55A] border-[#C5A55A]' : 'text-gray-400 border-transparent hover:text-gray-200'"
                        class="px-4 py-2.5 text-sm font-semibold border-b-2 transition">
                    Semua ({{ $allMovies->count() }})
                </button>
            </div>

            {{-- Tab panels --}}
            @php
                $panels = [
                    'acted'    => $actedMovies,
                    'directed' => $directedMovies,
                    'all'      => $allMovies,
                ];
            @endphp

            @foreach($panels as $key => $list)
                <div x-show="tab === '{{ $key }}'" x-cloak>
                    @if($list->isEmpty())
                        <p class="text-sm text-gray-500 italic py-8 text-center">
                            Belum ada film di kategori ini.
                        </p>
                    @else
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4 md:gap-5">
                            @foreach($list as $movie)
                                <a href="{{ route('movies.show', $movie->slug ?: $movie->id) }}"
                                   class="group block rounded-xl overflow-hidden border border-white/5 bg-[#141210]/60 hover:border-[#C5A55A]/40 transition-all duration-300 hover:-translate-y-0.5">
                                    <div class="relative aspect-[2/3] overflow-hidden bg-black/40">
                                        <img src="{{ $movie->poster_url }}"
                                             alt="{{ $movie->title }}"
                                             loading="lazy"
                                             class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
                                        @if($movie->release_date)
                                            <span class="absolute top-2 left-2 px-2 py-0.5 text-[10px] font-bold rounded bg-black/70 text-[#C5A55A]">
                                                {{ $movie->release_date->format('Y') }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="p-2.5">
                                        <h3 class="text-xs md:text-sm font-semibold text-white line-clamp-2 group-hover:text-[#C5A55A] transition-colors">
                                            {{ $movie->title }}
                                        </h3>
                                        @if(! empty($movie->pivot->character)
                                            && ! str_contains(strtolower($movie->pivot->character), 'director')
                                            && ! str_contains(strtolower($movie->pivot->character), 'sutradara'))
                                            <div class="mt-0.5 text-[10px] text-gray-400 line-clamp-1">
                                                as {{ $movie->pivot->character }}
                                            </div>
                                        @endif
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </section>

    </div>
</x-layout>
