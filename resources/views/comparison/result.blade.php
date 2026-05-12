@php
    $rows = [
        ['key' => 'plot',         'label' => 'Plot',          'icon' => 'film'],
        ['key' => 'themes',       'label' => 'Tema',          'icon' => 'sparkles'],
        ['key' => 'style',        'label' => 'Gaya Visual',   'icon' => 'eye'],
        ['key' => 'performances', 'label' => 'Akting',        'icon' => 'star'],
        ['key' => 'verdict',      'label' => 'Vonis',         'icon' => 'trophy'],
    ];
    $comparison = $result['comparison'] ?? [];
@endphp

<x-layout title="{{ $movieA->title }} vs {{ $movieB->title }} — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-6xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-8 md:mb-10">
                <div class="inline-flex items-center justify-center gap-2 mb-3">
                    <x-icon name="sparkles" :size="16" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        AI Movie Comparison
                    </span>
                </div>
                <h1 class="font-heading text-2xl md:text-4xl font-bold text-white leading-tight">
                    {{ $movieA->title }}
                    <span class="mx-2" style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">vs</span>
                    {{ $movieB->title }}
                </h1>
                <a href="{{ url('/compare') }}"
                   class="inline-flex items-center gap-1.5 mt-4 text-xs text-gray-400 hover:text-[#C5A55A] transition-colors">
                    <x-icon name="chevron-left" :size="14" />
                    Bandingkan film lain
                </a>
            </div>

            {{-- ── Side-by-side Movie Cards ───────────────────────── --}}
            <div class="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr] gap-4 md:gap-6 mb-10 md:mb-12 items-stretch">
                @foreach ([['m' => $movieA, 'tag' => 'A'], null, ['m' => $movieB, 'tag' => 'B']] as $i => $cell)
                    @if ($cell === null)
                        <div class="hidden md:flex items-center justify-center">
                            <span class="font-heading text-3xl font-black uppercase tracking-widest"
                                  style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">VS</span>
                        </div>
                    @else
                        @php $m = $cell['m']; $tag = $cell['tag']; @endphp
                        <article class="rounded-2xl overflow-hidden flex flex-col"
                                 style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.3); box-shadow: 0 12px 40px -12px rgba(197,165,90,0.18)">
                            <div class="relative">
                                <img src="{{ $m->effective_poster_url }}"
                                     alt="{{ $m->title }}"
                                     loading="lazy"
                                     width="500"
                                     height="750"
                                     class="w-full aspect-[2/3] object-cover">
                                <span class="absolute top-3 left-3 px-2.5 py-1 rounded-md text-[11px] font-black uppercase tracking-widest text-black"
                                      style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">Film {{ $tag }}</span>
                            </div>
                            <div class="p-4 md:p-5 flex-1 flex flex-col gap-2">
                                <h2 class="font-heading text-lg md:text-xl font-bold text-white leading-snug">
                                    {{ $m->title }}
                                </h2>
                                <div class="flex items-center gap-3 text-xs text-gray-400">
                                    @if($m->release_date)
                                        <span class="inline-flex items-center gap-1">
                                            <x-icon name="calendar" :size="13" />
                                            {{ $m->release_date->format('Y') }}
                                        </span>
                                    @endif
                                    @if($m->vote_average)
                                        <span class="inline-flex items-center gap-1" style="color: #E8D5A3">
                                            <x-icon name="star-solid" :size="13" />
                                            {{ number_format((float) $m->vote_average, 1) }}/10
                                        </span>
                                    @endif
                                </div>
                                @if($m->genres->count())
                                    <div class="flex flex-wrap gap-1.5 mt-1">
                                        @foreach($m->genres->take(3) as $g)
                                            <span class="text-[10px] px-2 py-0.5 rounded-full"
                                                  style="background: rgba(197,165,90,0.1); border: 1px solid rgba(197,165,90,0.25); color: #E8D5A3">
                                                {{ $g->name }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endif
                @endforeach

                {{-- Mobile VS divider --}}
                <div class="md:hidden flex items-center justify-center -my-2">
                    <span class="font-heading text-2xl font-black uppercase tracking-widest"
                          style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">VS</span>
                </div>
            </div>

            {{-- ── Comparison Table ───────────────────────────────── --}}
            <section class="rounded-2xl overflow-hidden mb-10"
                     style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.3); box-shadow: 0 12px 40px -12px rgba(197,165,90,0.18)">
                <header class="px-5 md:px-8 py-4 border-b" style="border-color: rgba(197,165,90,0.2)">
                    <h2 class="font-heading text-lg md:text-xl font-bold text-white inline-flex items-center gap-2">
                        <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                        Analisis Side-by-Side
                    </h2>
                </header>

                <div>
                    @foreach ($rows as $row)
                        <div class="px-5 md:px-8 py-5 md:py-6 grid grid-cols-1 md:grid-cols-[180px_1fr] gap-3 md:gap-6"
                             style="border-bottom: {{ $loop->last ? '0' : '1px solid rgba(197,165,90,0.12)' }}">
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg"
                                      style="background: rgba(197,165,90,0.12); color: #C5A55A">
                                    <x-icon :name="$row['icon']" :size="16" />
                                </span>
                                <div>
                                    <div class="text-[10px] uppercase tracking-[0.18em]" style="color: #C5A55A">
                                        Bidang
                                    </div>
                                    <div class="font-heading text-base font-bold text-white">
                                        {{ $row['label'] }}
                                    </div>
                                </div>
                            </div>
                            <div class="text-sm md:text-[15px] text-gray-200 leading-relaxed whitespace-pre-line">
                                {{ $comparison[$row['key']] ?? '—' }}
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- ── Watch Recommendation ───────────────────────────── --}}
            @if(!empty($result['watch_recommendation']))
                <section class="rounded-2xl p-6 md:p-8 mb-8"
                         style="background: linear-gradient(135deg, rgba(197,165,90,0.12), rgba(232,213,163,0.04)); border: 1px solid rgba(197,165,90,0.4); box-shadow: 0 12px 40px -12px rgba(197,165,90,0.25)">
                    <div class="flex items-start gap-4">
                        <span class="inline-flex items-center justify-center w-12 h-12 rounded-xl flex-shrink-0"
                              style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); color: #0a0a0a">
                            <x-icon name="play-solid" :size="22" />
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="text-[11px] font-bold uppercase tracking-[0.25em] mb-1.5" style="color: #C5A55A">
                                Rekomendasi Menonton
                            </div>
                            <h3 class="font-heading text-lg md:text-xl font-bold text-white mb-2">
                                Mana yang cocok untukmu?
                            </h3>
                            <p class="text-sm md:text-[15px] text-gray-100 leading-relaxed whitespace-pre-line">
                                {{ $result['watch_recommendation'] }}
                            </p>
                        </div>
                    </div>
                </section>
            @endif

            {{-- ── Action Bar ─────────────────────────────────────── --}}
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <a href="{{ route('movies.show', $movieA->slug) }}"
                   class="w-full sm:w-auto px-5 py-2.5 rounded-lg text-sm font-semibold text-center inline-flex items-center justify-center gap-2 transition-all hover:scale-[1.02]"
                   style="background: rgba(197,165,90,0.1); border: 1px solid rgba(197,165,90,0.4); color: #E8D5A3">
                    <x-icon name="info" :size="14" />
                    Detail {{ \Illuminate\Support\Str::limit($movieA->title, 24) }}
                </a>
                <a href="{{ route('movies.show', $movieB->slug) }}"
                   class="w-full sm:w-auto px-5 py-2.5 rounded-lg text-sm font-semibold text-center inline-flex items-center justify-center gap-2 transition-all hover:scale-[1.02]"
                   style="background: rgba(197,165,90,0.1); border: 1px solid rgba(197,165,90,0.4); color: #E8D5A3">
                    <x-icon name="info" :size="14" />
                    Detail {{ \Illuminate\Support\Str::limit($movieB->title, 24) }}
                </a>
                <a href="{{ url('/compare') }}"
                   class="w-full sm:w-auto px-5 py-2.5 rounded-lg text-sm font-bold text-black text-center inline-flex items-center justify-center gap-2 transition-all hover:scale-[1.02]"
                   style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                    <x-icon name="sparkles" :size="14" />
                    Bandingkan Lagi
                </a>
            </div>

            @if(!empty($result['provider']))
                <p class="text-center text-[10px] text-gray-600 mt-6">
                    Powered by {{ $result['provider'] }}
                </p>
            @endif
        </div>
    </main>
</x-layout>
