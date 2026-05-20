<x-layout :title="'Cari: ' . $q . ' — Pusat Bantuan ' . config('app.name', 'FLiK')"
          description="Hasil pencarian Pusat Bantuan FLiK.">
    <div class="bg-black min-h-screen pt-24 pb-20">
        <div class="container mx-auto px-4 lg:px-8 max-w-4xl">

            {{-- Breadcrumb --}}
            <nav class="text-sm text-gray-500 mb-6">
                <a href="{{ route('help.index') }}" class="hover:text-[#C5A55A]">Pusat Bantuan</a>
                <span class="mx-2 text-gray-700">&rsaquo;</span>
                <span class="text-gray-300">Hasil pencarian</span>
            </nav>

            <h1 class="text-2xl md:text-3xl font-bold text-white mb-6 font-heading">
                Hasil pencarian
            </h1>

            <div class="mb-8">
                @include('help.partials.search-bar', ['size' => 'compact', 'initial' => $q])
            </div>

            {{-- Category filter chips --}}
            @if($q !== '')
                <div class="flex flex-wrap gap-2 mb-6">
                    <a href="{{ route('help.search', ['q' => $q]) }}"
                       class="text-xs px-3 py-1.5 rounded-full border {{ $currentCategoryId === null ? 'border-[#C5A55A] bg-[#C5A55A]/15 text-[#C5A55A]' : 'border-[#2a2a2a] text-gray-400 hover:text-white' }}">
                        Semua
                    </a>
                    @foreach($categories as $cat)
                        <a href="{{ route('help.search', ['q' => $q, 'category_id' => $cat->id]) }}"
                           class="text-xs px-3 py-1.5 rounded-full border {{ $currentCategoryId === $cat->id ? 'border-[#C5A55A] bg-[#C5A55A]/15 text-[#C5A55A]' : 'border-[#2a2a2a] text-gray-400 hover:text-white' }}">
                            {{ $cat->name }}
                        </a>
                    @endforeach
                </div>
            @endif

            @if($q === '')
                <p class="text-gray-500 py-10">Ketik kueri di kotak pencarian untuk memulai.</p>
            @elseif($results->isEmpty())
                <div class="bg-[#141414] border border-[#2a2a2a] rounded-2xl p-8 text-center">
                    <p class="text-gray-300 mb-3">Tidak ada hasil untuk "<span class="text-[#C5A55A]">{{ $q }}</span>".</p>
                    <p class="text-sm text-gray-500">Coba kata kunci yang lebih umum, atau <a href="{{ route('help.index') }}" class="text-[#C5A55A] hover:underline">jelajahi kategori</a>.</p>
                </div>
            @else
                <p class="text-sm text-gray-500 mb-6">{{ $results->count() }} hasil ditemukan untuk "<span class="text-[#C5A55A]">{{ $q }}</span>"</p>

                <div class="space-y-3">
                    @foreach($results as $art)
                        @php
                            // Build a snippet that surrounds the first match in the body.
                            $plain = strip_tags($art->body_html ?? \App\Models\HelpArticle::renderMarkdown((string) $art->body));
                            $plain = trim(preg_replace('/\s+/', ' ', $plain) ?? '');
                            $pos = stripos($plain, $q);
                            if ($pos !== false) {
                                $start = max(0, $pos - 60);
                                $snippet = ($start > 0 ? '… ' : '') . mb_substr($plain, $start, 220) . (mb_strlen($plain) > $start + 220 ? ' …' : '');
                            } else {
                                $snippet = $art->excerpt ?: \Illuminate\Support\Str::words($plain, 30);
                            }
                            // Highlight matches.
                            $highlighted = preg_replace(
                                '/(' . preg_quote((string) $q, '/') . ')/i',
                                '<mark style="background:rgba(197,165,90,0.25);color:#E8D5A3;padding:1px 3px;border-radius:3px">$1</mark>',
                                e($snippet)
                            );
                        @endphp
                        <a href="{{ route('help.show', $art->slug) }}"
                           class="block bg-[#141414] hover:bg-[#1a1a1a] border border-[#2a2a2a] hover:border-[#C5A55A]/40 rounded-xl p-5 transition-all">
                            @if($art->category)
                                <p class="text-xs text-[#C5A55A] uppercase tracking-wider mb-2">{{ $art->category->name }}</p>
                            @endif
                            <h2 class="text-base md:text-lg font-semibold text-white mb-2">{!! preg_replace('/(' . preg_quote((string) $q, '/') . ')/i', '<mark style="background:rgba(197,165,90,0.25);color:#E8D5A3;padding:1px 3px;border-radius:3px">$1</mark>', e($art->title)) !!}</h2>
                            <p class="text-sm text-gray-400 line-clamp-3">{!! $highlighted !!}</p>
                        </a>
                    @endforeach
                </div>
            @endif

        </div>
    </div>
</x-layout>
