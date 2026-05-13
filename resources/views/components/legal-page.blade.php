{{--
    Shared chrome for the three legal pages (privacy / terms / refund).
    Wraps `<x-layout>` so guests can read it without auth and adds:
      - bilingual ID/EN toggle (Alpine, persisted in localStorage)
      - "Last updated" line
      - cross-links to sibling docs + Cookie Settings re-open

    Children should provide ID and EN copy via the slot, each wrapped in
    `x-show="lang === 'id'"` or `x-show="lang === 'en'"`.

    Required props:
      - $title (string)        page title (also used for <h1>)
      - $updatedAt (string)    ISO date for "Last updated"
--}}
@props(['title', 'updatedAt'])

<x-layout :title="$title . ' — FLiK'" :description="$title">
    <div x-data="{
            lang: (localStorage.getItem('flik_legal_lang') || 'id'),
            setLang(v) { this.lang = v; localStorage.setItem('flik_legal_lang', v); }
         }"
         class="min-h-screen pt-24 pb-16 px-4 sm:px-6 lg:px-8"
         style="background: linear-gradient(180deg, #0a0a0a 0%, #111 100%)">

        <article class="mx-auto max-w-3xl">
            <header class="mb-8 pb-6 border-b" style="border-color: rgba(197,165,90,0.18)">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest" style="color:#C5A55A">FLiK Legal</p>
                        <h1 class="font-heading text-3xl sm:text-4xl font-bold text-white mt-1">{{ $title }}</h1>
                        <p class="text-xs text-gray-500 mt-2">
                            <span x-show="lang === 'id'">Terakhir diperbarui:</span>
                            <span x-show="lang === 'en'">Last updated:</span>
                            <time datetime="{{ $updatedAt }}" class="text-gray-400 font-medium">{{ \Carbon\Carbon::parse($updatedAt)->isoFormat('D MMMM Y') }}</time>
                        </p>
                    </div>

                    <div class="inline-flex rounded-lg overflow-hidden border" style="border-color: rgba(197,165,90,0.3)">
                        <button type="button" @click="setLang('id')"
                                :class="lang === 'id' ? 'text-black' : 'text-gray-300 hover:text-white'"
                                :style="lang === 'id' ? 'background: linear-gradient(135deg,#C5A55A,#E8D5A3)' : ''"
                                class="px-3 py-1.5 text-xs font-semibold transition-colors">
                            Bahasa Indonesia
                        </button>
                        <button type="button" @click="setLang('en')"
                                :class="lang === 'en' ? 'text-black' : 'text-gray-300 hover:text-white'"
                                :style="lang === 'en' ? 'background: linear-gradient(135deg,#C5A55A,#E8D5A3)' : ''"
                                class="px-3 py-1.5 text-xs font-semibold transition-colors">
                            English
                        </button>
                    </div>
                </div>
            </header>

            <div class="legal-prose text-gray-300 leading-relaxed">
                {{ $slot }}
            </div>

            <footer class="mt-12 pt-6 border-t flex flex-wrap gap-4 text-xs" style="border-color: rgba(197,165,90,0.15)">
                <a href="{{ route('legal.privacy') }}" class="text-gray-400 hover:text-[#C5A55A] transition-colors">Kebijakan Privasi / Privacy</a>
                <span class="text-gray-700">·</span>
                <a href="{{ route('legal.terms') }}" class="text-gray-400 hover:text-[#C5A55A] transition-colors">Syarat & Ketentuan / Terms</a>
                <span class="text-gray-700">·</span>
                <a href="{{ route('legal.refund') }}" class="text-gray-400 hover:text-[#C5A55A] transition-colors">Kebijakan Refund / Refund</a>
                <span class="text-gray-700">·</span>
                <button type="button"
                        onclick="window.FlikConsent && window.FlikConsent.reopen()"
                        class="text-gray-400 hover:text-[#C5A55A] transition-colors">
                    Cookie Settings
                </button>
            </footer>
        </article>
    </div>

    @push('scripts')
    <style>
        /* Scoped legal-page typography — keeps default body text from
           bleeding into headings while staying inside the dark theme. */
        .legal-prose h2 { font-family: 'Outfit', sans-serif; color: #fff; font-size: 1.5rem; font-weight: 700; margin-top: 2.5rem; margin-bottom: 1rem; }
        .legal-prose h3 { font-family: 'Outfit', sans-serif; color: #C5A55A; font-size: 1.05rem; font-weight: 600; margin-top: 1.75rem; margin-bottom: 0.5rem; }
        .legal-prose p  { margin-bottom: 1rem; }
        .legal-prose ul { list-style: disc; padding-left: 1.5rem; margin-bottom: 1rem; }
        .legal-prose ol { list-style: decimal; padding-left: 1.5rem; margin-bottom: 1rem; }
        .legal-prose li { margin-bottom: 0.4rem; }
        .legal-prose a  { color: #C5A55A; text-decoration: underline; text-underline-offset: 2px; }
        .legal-prose a:hover { color: #E8D5A3; }
        .legal-prose strong { color: #fff; }
        .legal-prose code   { background: rgba(255,255,255,0.05); padding: 0.1rem 0.4rem; border-radius: 4px; font-size: 0.85em; color: #E8D5A3; }
        .legal-prose .lang-block { display: block; }
    </style>
    @endpush
</x-layout>
