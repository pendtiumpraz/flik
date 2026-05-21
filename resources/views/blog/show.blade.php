@php
    /** @var \App\Models\BlogPost $post */
    /** @var \Illuminate\Support\Collection $related */
    $cover = $post->cover_image
        ? (str_starts_with($post->cover_image, 'http') ? $post->cover_image : asset('storage/' . $post->cover_image))
        : null;

    $seoTitle = $post->seo_title ?: $post->title;
    $seoDescription = $post->seo_description ?: ($post->excerpt ?: \Illuminate\Support\Str::limit(strip_tags($post->body_html ?? ''), 160));

    // ── Linked movies (curated pivot) come pre-loaded from BlogController::show ──
    // The user-spec name is `relatedMovies`; the controller loads them via the
    // `movies` relation, so we surface both for back-compat with future call sites.
    $relatedMovies = $post->relationLoaded('movies') ? $post->movies : collect();

    // Related posts collection passed under `$related` by the controller. Keep a
    // safe alias the rest of the view reads from.
    $relatedPosts = $related ?? collect();

    // Canonical absolute URL for share buttons + JSON-LD.
    $shareUrl = $post->url;
    $shareText = $post->title . ' — ' . config('app.name', 'FLiK');
@endphp

<x-layout :title="$seoTitle" :description="$seoDescription" :ogImage="$cover">

    {{-- ━━━ schema.org Article JSON-LD ━━━
         Helps Google show the post in the news/article carousel + author byline.
         Kept inline (rather than a component) because the only consumer is this
         single page. --}}
    <script type="application/ld+json">
    @json([
        '@context'      => 'https://schema.org',
        '@type'         => 'Article',
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id'   => $shareUrl,
        ],
        'headline'      => $post->title,
        'description'   => $seoDescription,
        'image'         => $cover ? [$cover] : [],
        'datePublished' => optional($post->published_at)->toIso8601String(),
        'dateModified'  => optional($post->updated_at)->toIso8601String(),
        'author'        => [
            '@type' => 'Person',
            'name'  => $post->author?->name ?? 'Tim FLiK',
        ],
        'publisher'     => [
            '@type' => 'Organization',
            'name'  => config('app.name', 'FLiK'),
            'logo'  => [
                '@type' => 'ImageObject',
                'url'   => asset('img/flik-logo.png'),
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    </script>

    <article class="bg-black text-white min-h-screen pt-16 pb-16">

        {{-- ── Hero (cover image or gradient fallback) ─────────────── --}}
        <header class="relative w-full overflow-hidden" style="height: 480px">
            @if($cover)
                <img src="{{ $cover }}" alt="{{ $post->title }}"
                     class="absolute inset-0 w-full h-full object-cover">
                <div class="absolute inset-0 bg-gradient-to-t from-black via-black/70 to-black/40"></div>
            @else
                <div class="absolute inset-0 bg-gradient-to-br from-[#141414] via-[#0f0f0f] to-[#0a0a0a]"></div>
            @endif

            <div class="relative h-full container mx-auto px-4 lg:px-8 max-w-4xl flex flex-col justify-end pb-10 md:pb-14">
                <div class="flex flex-wrap items-center gap-2 mb-4">
                    @if($post->category)
                        <a href="{{ route('blog.category', $post->category) }}"
                           class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-[0.2em] hover:scale-105 transition-transform"
                           style="background: {{ $post->category->color }}33; color: {{ $post->category->color }}; border: 1px solid {{ $post->category->color }}66">
                            {{ $post->category->name }}
                        </a>
                    @endif
                    @if(! $post->isPublished())
                        <span class="inline-block px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-[0.2em] bg-yellow-500/20 text-yellow-300 border border-yellow-500/40">
                            Preview · {{ ucfirst($post->status) }}
                        </span>
                    @endif
                </div>
                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white leading-tight max-w-3xl">
                    {{ $post->title }}
                </h1>
                @if($post->excerpt)
                    <p class="mt-3 text-base md:text-lg text-gray-300 max-w-2xl leading-relaxed">{{ $post->excerpt }}</p>
                @endif

                <div class="mt-6 flex items-center gap-3 text-sm">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-black ring-2 ring-[#C5A55A]/40"
                         style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                        {{ strtoupper(substr($post->author?->name ?? 'F', 0, 1)) }}
                    </div>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-gray-300">
                        <span class="font-medium text-white">{{ $post->author?->name ?? 'Tim FLiK' }}</span>
                        <span class="text-gray-600">·</span>
                        <span>{{ optional($post->published_at)->isoFormat('D MMM YYYY') ?? '—' }}</span>
                        <span class="text-gray-600">·</span>
                        <span>{{ $post->reading_minutes }} min read</span>
                    </div>
                </div>
            </div>
        </header>

        {{-- ── Body (rendered HTML; markdown already sanitized by setBodyAttribute) ── --}}
        <div class="container mx-auto px-4 lg:px-8 max-w-3xl">
            <div class="prose prose-invert max-w-none prose-headings:font-heading prose-headings:text-white prose-a:text-[#C5A55A] hover:prose-a:text-[#E8D5A3] prose-strong:text-white prose-blockquote:border-l-[#C5A55A] prose-blockquote:text-gray-300 prose-code:text-[#C5A55A] prose-pre:bg-[#0f0f0f] prose-pre:border prose-pre:border-white/10 mt-10 md:mt-14"
                 style="--tw-prose-bullets: rgba(197,165,90,0.5); --tw-prose-counters: rgba(197,165,90,0.5);">
                {!! $post->body_html !!}
            </div>

            {{-- ── Social share + copy link ──────────────────────── --}}
            <div class="mt-12 pt-8 border-t border-white/10"
                 x-data="{
                    copied: false,
                    copy() {
                        navigator.clipboard.writeText('{{ $shareUrl }}').then(() => {
                            this.copied = true;
                            setTimeout(() => this.copied = false, 1800);
                        });
                    }
                 }">
                <div class="text-[10px] uppercase tracking-[0.25em] text-[#C5A55A] font-semibold mb-3">Bagikan artikel</div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="https://twitter.com/intent/tweet?text={{ urlencode($shareText) }}&url={{ urlencode($shareUrl) }}"
                       target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold bg-[#141414] border border-white/10 text-gray-200 hover:border-[#C5A55A] hover:text-[#C5A55A] transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>
                        <span>Twitter / X</span>
                    </a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($shareUrl) }}"
                       target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold bg-[#141414] border border-white/10 text-gray-200 hover:border-[#C5A55A] hover:text-[#C5A55A] transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M9.198 21.5h4v-8.01h3.604l.396-3.98h-4V7.5a1 1 0 0 1 1-1h3v-4h-3a5 5 0 0 0-5 5v2.01h-2l-.396 3.98h2.396v8.01z"/></svg>
                        <span>Facebook</span>
                    </a>
                    <a href="https://api.whatsapp.com/send?text={{ urlencode($shareText . ' ' . $shareUrl) }}"
                       target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold bg-[#141414] border border-white/10 text-gray-200 hover:border-[#C5A55A] hover:text-[#C5A55A] transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.768.967-.941 1.164-.173.198-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413"/></svg>
                        <span>WhatsApp</span>
                    </a>
                    <button type="button" @click="copy()"
                            class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-xs font-semibold bg-[#141414] border border-white/10 text-gray-200 hover:border-[#C5A55A] hover:text-[#C5A55A] transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        <span x-show="!copied">Copy Link</span>
                        <span x-show="copied" x-cloak class="text-green-400">Tersalin!</span>
                    </button>
                </div>
            </div>

            {{-- ── Related movies (admin-curated pivot) ─────────── --}}
            @if($relatedMovies->isNotEmpty())
                <section class="mt-14">
                    <div class="flex items-baseline justify-between mb-5">
                        <h2 class="font-heading text-xl md:text-2xl font-bold text-white">Film yang dibahas</h2>
                        <span class="text-[10px] uppercase tracking-widest text-[#C5A55A]">{{ $relatedMovies->count() }} film</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        @foreach($relatedMovies->take(6) as $movie)
                            @php
                                $poster = $movie->poster_url ?? null;
                                $movieKey = $movie->slug ?? $movie->id;
                            @endphp
                            <a href="{{ route('movies.show', $movieKey) }}"
                               class="group block rounded-xl overflow-hidden bg-[#141414] border border-white/5 hover:border-[#C5A55A]/60 transition-colors">
                                <div class="aspect-[2/3] bg-gradient-to-br from-[#1a1a1a] to-[#0a0a0a] overflow-hidden">
                                    @if($poster)
                                        <img src="{{ $poster }}" alt="{{ $movie->title }}"
                                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                             loading="lazy">
                                    @else
                                        <div class="w-full h-full flex items-center justify-center text-[#C5A55A]/30 text-3xl font-bold">FLiK</div>
                                    @endif
                                </div>
                                <div class="p-3">
                                    <h3 class="text-sm font-semibold text-white group-hover:text-[#C5A55A] transition-colors line-clamp-2">{{ $movie->title }}</h3>
                                    @if($movie->overview)
                                        <p class="mt-1 text-xs text-gray-500 line-clamp-2">{{ $movie->overview }}</p>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ── Related posts ─────────────────────────────────── --}}
            @if($relatedPosts->isNotEmpty())
                <section class="mt-16">
                    <div class="flex items-baseline justify-between mb-5">
                        <h2 class="font-heading text-xl md:text-2xl font-bold text-white">Baca selanjutnya</h2>
                        <a href="{{ route('blog.index') }}" class="text-xs text-[#C5A55A] hover:text-[#E8D5A3] transition-colors">Lihat semua →</a>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        @foreach($relatedPosts->take(3) as $rp)
                            @include('blog.partials.post-card', ['post' => $rp])
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ── Back link ─────────────────────────────────────── --}}
            <div class="mt-14 text-center">
                <a href="{{ route('blog.index') }}"
                   class="inline-flex items-center gap-2 text-sm text-gray-400 hover:text-[#C5A55A] transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    <span>Kembali ke semua artikel</span>
                </a>
            </div>
        </div>
    </article>
</x-layout>
