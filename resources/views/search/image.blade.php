<x-layout title="Image Search — FLiK">
    <main class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-7xl">

            {{-- ── Header ─────────────────────────────────────────── --}}
            <div class="text-center mb-10 md:mb-12">
                <div class="inline-flex items-center justify-center gap-2 mb-4">
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                    <span class="text-[11px] md:text-xs font-bold uppercase tracking-[0.25em]" style="color: #C5A55A">
                        Image Search
                    </span>
                    <x-icon name="sparkles" :size="18" class="text-[#C5A55A]" />
                </div>

                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white leading-tight">
                    Filmnya <span style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); -webkit-background-clip: text; background-clip: text; color: transparent;">apa</span> ya?
                </h1>
                <p class="text-gray-400 mt-3 text-sm md:text-base max-w-xl mx-auto">
                    Upload poster, screenshot, atau still scene — AI akan tebak filmnya dan cocokkan ke katalog.
                </p>
            </div>

            {{-- ── Form ───────────────────────────────────────────── --}}
            <form method="POST"
                  action="{{ url('/search/image') }}"
                  enctype="multipart/form-data"
                  class="max-w-2xl mx-auto mb-12 md:mb-16">
                @csrf

                <label for="image-input"
                       class="block rounded-xl p-6 md:p-10 text-center cursor-pointer transition-all hover:bg-[rgba(197,165,90,0.05)]"
                       style="background: rgba(20,18,16,0.7); border: 2px dashed rgba(197,165,90,0.35);">
                    <div class="inline-flex items-center justify-center w-14 h-14 rounded-full mb-4"
                         style="background: rgba(197,165,90,0.12); border: 1px solid rgba(197,165,90,0.3)">
                        <x-icon name="film" :size="22" class="text-[#C5A55A]" />
                    </div>
                    <p class="font-heading font-semibold text-white text-base md:text-lg">
                        Klik untuk upload gambar
                    </p>
                    <p class="text-gray-400 text-xs mt-1.5">
                        JPG, PNG, atau WebP — maks 8 MB
                    </p>
                    <input id="image-input" type="file" name="image" accept="image/jpeg,image/jpg,image/png,image/webp"
                           required class="sr-only"
                           onchange="this.form.submit()">
                </label>

                @error('image')
                    <p class="mt-2 text-xs text-red-400 text-center">{{ $message }}</p>
                @enderror

                @if(!empty($error))
                    <p class="mt-2 text-xs text-red-400 text-center">{{ $error }}</p>
                @endif
            </form>

            {{-- ── Results ────────────────────────────────────────── --}}
            @if($submitted)
                @if($imagePreview)
                    <div class="max-w-md mx-auto mb-10">
                        <p class="text-[10px] md:text-xs uppercase tracking-[0.25em] font-bold text-center mb-3" style="color: #C5A55A">
                            Gambar yang kamu upload
                        </p>
                        <div class="rounded-xl overflow-hidden" style="border: 1px solid rgba(197,165,90,0.25)">
                            <img src="{{ $imagePreview }}" alt="Uploaded image"
                                 class="w-full h-auto max-h-72 object-contain bg-black" />
                        </div>
                    </div>
                @endif

                @if($movies->count() > 0)
                    <div class="mb-6 flex items-center gap-3">
                        <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                        <div class="text-center px-4">
                            <p class="text-[11px] md:text-xs uppercase tracking-[0.25em] font-bold" style="color: #C5A55A">
                                {{ $movies->count() }} kemungkinan film
                            </p>
                        </div>
                        <div class="flex-1 h-px" style="background: linear-gradient(90deg, transparent, rgba(197,165,90,0.4), transparent)"></div>
                    </div>

                    {{-- Confidence chips above the grid --}}
                    <div class="max-w-3xl mx-auto mb-8 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                        @foreach($movies as $movie)
                            @php
                                $confidence = $movie['_ai_confidence'] ?? null;
                                $aiTitle = $movie['_ai_guess_title'] ?? null;
                                $pct = $confidence !== null ? round($confidence * 100) : null;
                            @endphp
                            <a href="{{ route('movies.show', $movie['slug']) }}"
                               class="block rounded-lg p-3 transition-all hover:translate-y-[-1px]"
                               style="background: rgba(20,18,16,0.6); border: 1px solid rgba(197,165,90,0.2)">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-semibold text-white line-clamp-1">{{ $movie['title'] }}</p>
                                    @if($pct !== null)
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full"
                                              style="background: rgba(197,165,90,0.15); color: #C5A55A; border: 1px solid rgba(197,165,90,0.3)">
                                            {{ $pct }}%
                                        </span>
                                    @endif
                                </div>
                                @if($aiTitle && mb_strtolower($aiTitle) !== mb_strtolower($movie['title']))
                                    <p class="text-[11px] text-gray-500 mt-1 italic">AI guess: {{ $aiTitle }}</p>
                                @endif
                            </a>
                        @endforeach
                    </div>

                    <x-movies :movies="$movies" :genres="$genres" density="large">
                        <x-slot:category>
                            <x-icon name="film" :size="16" class="text-[#C5A55A]" />
                            <span>Match terbaik dari katalog</span>
                        </x-slot:category>
                    </x-movies>
                @else
                    <div class="text-center py-16 rounded-xl max-w-xl mx-auto"
                         style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.15)">
                        <x-icon name="film" :size="40" class="mx-auto text-gray-700 mb-3" />
                        <p class="text-gray-300 font-semibold">Tidak ada match yang ditemukan</p>
                        <p class="text-gray-500 text-xs mt-1.5 max-w-sm mx-auto">
                            AI tidak bisa mengidentifikasi film dari gambar ini, atau filmnya belum ada di katalog FLiK. Coba upload gambar lain.
                        </p>
                    </div>
                @endif
            @else
                {{-- Pre-search hint --}}
                <div class="max-w-3xl mx-auto grid grid-cols-1 md:grid-cols-3 gap-4 mt-8">
                    @foreach ([
                        ['icon' => 'download', 'title' => '1. Upload gambar',     'desc' => 'Poster, screenshot, atau still scene.'],
                        ['icon' => 'sparkles', 'title' => '2. AI identifikasi',  'desc' => 'Gemini Vision tebak filmnya.'],
                        ['icon' => 'film',     'title' => '3. Cocokkan katalog', 'desc' => 'Tampilkan match dari koleksi FLiK.'],
                    ] as $step)
                        <div class="rounded-xl p-5 text-center"
                             style="background: rgba(20,18,16,0.5); border: 1px solid rgba(197,165,90,0.12)">
                            <div class="inline-flex items-center justify-center w-10 h-10 rounded-full mb-3"
                                 style="background: rgba(197,165,90,0.12); border: 1px solid rgba(197,165,90,0.3)">
                                <x-icon :name="$step['icon']" :size="18" class="text-[#C5A55A]" />
                            </div>
                            <p class="font-heading font-semibold text-white text-sm">{{ $step['title'] }}</p>
                            <p class="text-gray-400 text-xs mt-1.5 leading-relaxed">{{ $step['desc'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </main>
</x-layout>
