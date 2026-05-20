<x-layout :title="$article->title . ' — Pusat Bantuan ' . config('app.name', 'FLiK')"
          :description="$article->excerpt ?: \Illuminate\Support\Str::words(strip_tags($article->body_html ?? ''), 25)">

    {{-- JSON-LD: FAQPage if the body has Q/A pattern, Article otherwise.
         Built in HelpController::buildJsonLd. --}}
    @push('scripts')
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endpush

    <div class="bg-black min-h-screen pt-24 pb-20">
        <div class="container mx-auto px-4 lg:px-8 max-w-3xl">

            {{-- Breadcrumb --}}
            <nav class="text-sm text-gray-500 mb-6">
                <a href="{{ route('help.index') }}" class="hover:text-[#C5A55A]">Pusat Bantuan</a>
                @if($article->category)
                    <span class="mx-2 text-gray-700">&rsaquo;</span>
                    <a href="{{ route('help.category', $article->category) }}" class="hover:text-[#C5A55A]">{{ $article->category->name }}</a>
                @endif
                <span class="mx-2 text-gray-700">&rsaquo;</span>
                <span class="text-gray-300 line-clamp-1 inline-block max-w-xs align-bottom">{{ $article->title }}</span>
            </nav>

            {{-- Article header --}}
            <header class="mb-8 pb-8 border-b border-[#2a2a2a]">
                @if($article->status !== \App\Models\HelpArticle::STATUS_PUBLISHED)
                    <div class="inline-block mb-3 px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-[0.2em] bg-yellow-500/20 text-yellow-400">
                        Pratinjau Draft
                    </div>
                @endif
                <h1 class="text-3xl md:text-4xl font-bold text-white font-heading">{{ $article->title }}</h1>

                <div class="flex flex-wrap items-center gap-3 mt-4 text-xs text-gray-500">
                    @if($article->category)
                        <a href="{{ route('help.category', $article->category) }}"
                           class="inline-flex items-center gap-1.5 text-[#C5A55A] hover:underline">
                            @if($article->category->icon)
                                <x-icon :name="$article->category->icon" :size="14" />
                            @endif
                            {{ $article->category->name }}
                        </a>
                        <span class="text-gray-700">&middot;</span>
                    @endif
                    @if($article->last_reviewed_at)
                        <span title="Terakhir direview oleh tim editorial">
                            Direview {{ $article->last_reviewed_at->translatedFormat('d F Y') }}
                        </span>
                    @elseif($article->updated_at)
                        <span>Diperbarui {{ $article->updated_at->diffForHumans() }}</span>
                    @endif
                    @if($article->helpful_percentage !== null)
                        <span class="text-gray-700">&middot;</span>
                        <span class="text-green-400">{{ $article->helpful_percentage }}% terbantu</span>
                    @endif
                </div>
            </header>

            {{-- Body --}}
            <article class="prose prose-invert prose-lg max-w-none help-article-body">
                {!! $article->body_html !!}
            </article>

            {{-- Feedback widget --}}
            <div x-data="helpFeedback({{ json_encode(['url' => route('help.feedback', $article->slug)]) }})"
                 class="mt-12 bg-[#141414] border border-[#2a2a2a] rounded-2xl p-6 md:p-8">
                <template x-if="!submitted">
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-1">Apakah artikel ini membantu?</h3>
                        <p class="text-sm text-gray-400 mb-4">Suara Anda membantu kami memprioritaskan artikel mana yang perlu diperbaiki.</p>

                        <div class="flex flex-wrap gap-3 mb-4">
                            <button @click="submit(true)"
                                    :disabled="loading"
                                    class="inline-flex items-center gap-2 bg-green-500/15 hover:bg-green-500/25 border border-green-500/40 text-green-400 px-5 py-2.5 rounded-lg text-sm font-medium transition-colors disabled:opacity-50">
                                <span>&#x1F44D;</span>
                                <span>Ya, membantu</span>
                            </button>
                            <button @click="showComment = true"
                                    :disabled="loading"
                                    class="inline-flex items-center gap-2 bg-red-500/15 hover:bg-red-500/25 border border-red-500/40 text-red-400 px-5 py-2.5 rounded-lg text-sm font-medium transition-colors disabled:opacity-50">
                                <span>&#x1F44E;</span>
                                <span>Belum membantu</span>
                            </button>
                        </div>

                        <div x-show="showComment" x-cloak class="mt-4">
                            <label class="block text-xs text-gray-400 mb-2">Apa yang bisa kami perbaiki? (opsional)</label>
                            <textarea x-model="comment"
                                      rows="3"
                                      maxlength="1000"
                                      class="w-full bg-[#0f0f0f] border border-[#2a2a2a] focus:border-[#C5A55A] text-white text-sm rounded-lg p-3 outline-none"></textarea>
                            <button @click="submit(false)"
                                    :disabled="loading"
                                    class="mt-3 bg-[#C5A55A] hover:bg-[#d4b76a] text-black font-semibold px-5 py-2 rounded-lg text-sm transition-colors disabled:opacity-50">
                                Kirim Masukan
                            </button>
                        </div>

                        <p x-show="error" x-cloak x-text="error" class="text-red-400 text-sm mt-3"></p>
                    </div>
                </template>

                <template x-if="submitted">
                    <div class="text-center py-4">
                        <p class="text-2xl mb-2">&#x2728;</p>
                        <p class="text-white font-semibold mb-1" x-text="message"></p>
                        <p class="text-sm text-gray-500">Terima kasih telah membantu kami menjaga kualitas Pusat Bantuan.</p>
                    </div>
                </template>
            </div>

            {{-- Related articles --}}
            @if($related->isNotEmpty())
                <div class="mt-12">
                    <h3 class="text-xs font-semibold tracking-[0.3em] uppercase text-[#C5A55A] mb-5">Artikel terkait</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach($related as $rel)
                            <a href="{{ route('help.show', $rel->slug) }}"
                               class="block bg-[#141414] hover:bg-[#1a1a1a] border border-[#2a2a2a] hover:border-[#C5A55A]/40 rounded-xl p-5 transition-all">
                                @if($rel->category)
                                    <p class="text-xs text-[#C5A55A] uppercase tracking-wider mb-2">{{ $rel->category->name }}</p>
                                @endif
                                <h4 class="text-base font-semibold text-white line-clamp-2">{{ $rel->title }}</h4>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Contact CTA --}}
            <div class="mt-12 bg-gradient-to-br from-[#1a1a1a] to-[#0f0f0f] border border-[#C5A55A]/30 rounded-2xl p-6 md:p-8 text-center">
                <h3 class="text-xl md:text-2xl font-bold text-white mb-2">Masih butuh bantuan?</h3>
                <p class="text-gray-400 mb-5 text-sm">Tim dukungan kami siap merespons via email dalam 24 jam.</p>
                <a href="mailto:support@flik.id"
                   class="inline-block bg-[#C5A55A] hover:bg-[#d4b76a] text-black font-semibold px-6 py-3 rounded-lg transition-colors">
                    Hubungi Kami
                </a>
            </div>

        </div>
    </div>

    {{-- Prose styling tuned for the gold/dark theme. --}}
    @push('styles')
        <style>
            .help-article-body h2 { color:#fff; font-family:'Outfit',sans-serif; font-size:1.6rem; font-weight:700; margin-top:2.2em; margin-bottom:0.8em; }
            .help-article-body h3 { color:#fff; font-family:'Outfit',sans-serif; font-size:1.25rem; font-weight:600; margin-top:1.8em; margin-bottom:0.6em; }
            .help-article-body p  { color:#d4d4d4; line-height:1.8; margin-bottom:1em; }
            .help-article-body ul, .help-article-body ol { color:#d4d4d4; margin:0.5em 0 1.5em 1.5em; }
            .help-article-body ul li { list-style: disc; margin-bottom:0.4em; }
            .help-article-body ol li { list-style: decimal; margin-bottom:0.4em; }
            .help-article-body a { color:#C5A55A; text-decoration:underline; }
            .help-article-body strong { color:#fff; }
            .help-article-body code { background:#1a1a1a; padding:2px 6px; border-radius:4px; color:#E8D5A3; font-size:90%; }
            .help-article-body pre { background:#0a0a0a; padding:14px; border-radius:8px; overflow-x:auto; border:1px solid #2a2a2a; margin:1.5em 0; }
            .help-article-body blockquote { border-left:3px solid #C5A55A; padding-left:14px; color:#bbb; margin:1.5em 0; font-style:italic; }
            .help-article-body img { max-width:100%; border-radius:10px; border:1px solid #2a2a2a; margin:1.5em 0; }
        </style>
    @endpush

    @push('scripts')
        <script>
            function helpFeedback(opts) {
                return {
                    url: opts.url,
                    loading: false,
                    submitted: false,
                    showComment: false,
                    comment: '',
                    message: '',
                    error: '',
                    submit(isHelpful) {
                        if (this.loading) return;
                        this.loading = true;
                        this.error = '';
                        fetch(this.url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            },
                            body: JSON.stringify({
                                is_helpful: isHelpful ? 1 : 0,
                                comment: isHelpful ? null : (this.comment || null),
                            }),
                        })
                            .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                            .then(({ ok, data }) => {
                                this.loading = false;
                                this.submitted = true;
                                this.message = data.message || 'Terima kasih atas masukan Anda!';
                            })
                            .catch(() => {
                                this.loading = false;
                                this.error = 'Gagal mengirim. Coba lagi beberapa saat.';
                            });
                    },
                };
            }
        </script>
    @endpush
</x-layout>
