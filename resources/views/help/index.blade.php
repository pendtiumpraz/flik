<x-layout :title="'Pusat Bantuan — ' . config('app.name', 'FLiK')"
          description="Pusat Bantuan FLiK — temukan jawaban cepat seputar akun, langganan, pemutaran, dan privasi.">
    <div class="bg-black min-h-screen pt-24 pb-20">
        <div class="container mx-auto px-4 lg:px-8 max-w-6xl">

            {{-- Hero --}}
            <div class="text-center mb-12 md:mb-16">
                <p class="text-[#C5A55A] text-xs font-semibold tracking-[0.3em] uppercase mb-3">FLiK Support</p>
                <h1 class="text-4xl md:text-6xl font-bold text-white font-heading">Bagaimana kami bisa membantu?</h1>
                <p class="text-gray-400 mt-4 max-w-2xl mx-auto text-base md:text-lg">
                    Cari panduan, jawaban cepat, dan solusi masalah pemutaran di Pusat Bantuan FLiK.
                </p>

                <div class="mt-8 md:mt-10">
                    @include('help.partials.search-bar', ['size' => 'hero'])
                </div>
            </div>

            {{-- Category grid --}}
            @if($categories->isEmpty())
                <div class="text-center text-gray-500 py-20">
                    <p>Belum ada artikel bantuan tersedia.</p>
                </div>
            @else
                <h2 class="text-xs font-semibold tracking-[0.3em] uppercase text-[#C5A55A] mb-6">Telusuri berdasarkan kategori</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-{{ min(4, max(2, $categories->count())) }} gap-5 mb-16">
                    @foreach($categories as $cat)
                        <a href="{{ route('help.category', $cat) }}"
                           class="group block bg-[#141414] hover:bg-[#1a1a1a] border border-[#2a2a2a] hover:border-[#C5A55A]/50 rounded-2xl p-6 transition-all">
                            <div class="flex items-start justify-between mb-4">
                                <div class="w-12 h-12 rounded-xl bg-[#C5A55A]/10 flex items-center justify-center text-[#C5A55A] group-hover:bg-[#C5A55A]/20 transition-colors">
                                    @if($cat->icon)
                                        <x-icon :name="$cat->icon" :size="24" />
                                    @else
                                        <x-icon name="info" :size="24" />
                                    @endif
                                </div>
                                <span class="text-xs text-gray-500">{{ $cat->articles_count }} artikel</span>
                            </div>
                            <h3 class="text-lg font-semibold text-white group-hover:text-[#C5A55A] transition-colors">{{ $cat->name }}</h3>
                            @if($cat->description)
                                <p class="text-sm text-gray-400 mt-2 line-clamp-2">{{ $cat->description }}</p>
                            @endif

                            @php $preview = $previewArticles->get($cat->id, collect()); @endphp
                            @if($preview->isNotEmpty())
                                <ul class="mt-4 pt-4 border-t border-[#2a2a2a] space-y-2">
                                    @foreach($preview as $art)
                                        <li>
                                            <a href="{{ route('help.show', $art->slug) }}"
                                               class="text-sm text-gray-300 hover:text-[#C5A55A] flex items-start gap-2"
                                               @click.stop>
                                                <span class="text-[#C5A55A] mt-0.5">&rarr;</span>
                                                <span class="line-clamp-1">{{ $art->title }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Popular --}}
            @if($popular->isNotEmpty())
                <h2 class="text-xs font-semibold tracking-[0.3em] uppercase text-[#C5A55A] mb-6">Artikel populer</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-16">
                    @foreach($popular as $art)
                        <a href="{{ route('help.show', $art->slug) }}"
                           class="block bg-[#141414] hover:bg-[#1a1a1a] border border-[#2a2a2a] hover:border-[#C5A55A]/40 rounded-xl p-5 transition-all">
                            <div class="flex items-start gap-4">
                                <div class="text-[#C5A55A] flex-shrink-0 mt-1">
                                    <x-icon :name="$art->category?->icon ?: 'info'" :size="20" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-base font-semibold text-white line-clamp-2">{{ $art->title }}</h3>
                                    @if($art->category)
                                        <p class="text-xs text-gray-500 mt-1">{{ $art->category->name }}</p>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Contact CTA --}}
            <div class="mt-12 bg-gradient-to-br from-[#1a1a1a] to-[#0f0f0f] border border-[#C5A55A]/30 rounded-2xl p-8 md:p-10 text-center">
                <h2 class="text-2xl md:text-3xl font-bold text-white mb-3">Masih butuh bantuan?</h2>
                <p class="text-gray-400 max-w-xl mx-auto mb-6">Tim dukungan kami siap merespons via email dalam 24 jam.</p>
                <a href="mailto:support@flik.id" class="inline-block bg-[#C5A55A] hover:bg-[#d4b76a] text-black font-semibold px-6 py-3 rounded-lg transition-colors">
                    Hubungi Dukungan
                </a>
            </div>

        </div>
    </div>
</x-layout>
