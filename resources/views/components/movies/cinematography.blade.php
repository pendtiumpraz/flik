@props(['data' => null])

@php
    /**
     * @var \App\Models\MovieCinematography|null $data
     */
    $palette = is_array($data?->color_palette) ? $data->color_palette : [];
    $moods   = is_array($data?->mood_descriptors) ? $data->mood_descriptors : [];
    $frames  = is_array($data?->sample_keyframes_paths) ? $data->sample_keyframes_paths : [];

    // Only render when there is actually something meaningful to show.
    $hasAnything = $data
        && ($data->hasAnalysis() || !empty($frames));
@endphp

@if($hasAnything)
<section class="mt-10 md:mt-12"
         x-data="{
            copied: null,
            copyHex(hex) {
                try {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(hex);
                    } else {
                        const t = document.createElement('textarea');
                        t.value = hex; document.body.appendChild(t); t.select();
                        document.execCommand('copy'); document.body.removeChild(t);
                    }
                    this.copied = hex;
                    setTimeout(() => { if (this.copied === hex) this.copied = null; }, 1400);
                } catch (e) { /* swallow */ }
            }
         }">

    <!-- Header -->
    <div class="flex items-center gap-2 mb-5">
        <div class="w-1 h-6 rounded-full" style="background: #C5A55A"></div>
        <h2 class="font-heading text-xl md:text-2xl font-bold text-white">
            Sinematografi &amp; Warna
        </h2>
        <span class="ml-2 text-[10px] uppercase tracking-widest font-semibold px-2 py-0.5 rounded"
              style="background: rgba(197,165,90,0.15); color: #C5A55A; border: 1px solid rgba(197,165,90,0.3)">
            AI&nbsp;Visual&nbsp;Analysis
        </span>
    </div>

    <div class="rounded-2xl p-5 md:p-7"
         style="background: linear-gradient(180deg, #141210 0%, #0d0c0a 100%); border: 1px solid rgba(197,165,90,0.18)">

        {{-- ── Color Palette ────────────────────────────────────── --}}
        @if(!empty($palette))
            <div class="mb-6">
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-3"
                     style="color: #C5A55A">Palet Warna Dominan</div>

                <div class="flex flex-wrap gap-2">
                    @foreach($palette as $swatch)
                        @php
                            $hex    = $swatch['hex']    ?? null;
                            $weight = isset($swatch['weight']) ? (float) $swatch['weight'] : 0.0;
                        @endphp
                        @if($hex)
                            <button type="button"
                                    @click="copyHex('{{ $hex }}')"
                                    class="group relative flex items-center gap-2 pl-1.5 pr-3 py-1.5 rounded-lg transition-all hover:scale-[1.03] hover:shadow-lg"
                                    style="background: rgba(255,255,255,0.04); border: 1px solid rgba(197,165,90,0.18)"
                                    :title="copied === '{{ $hex }}' ? 'Copied!' : 'Klik untuk salin {{ $hex }}'">
                                <span class="w-7 h-7 rounded-md flex-shrink-0"
                                      style="background: {{ $hex }}; border: 1px solid rgba(255,255,255,0.12)"></span>
                                <span class="flex flex-col text-left leading-tight">
                                    <span class="text-[11px] font-mono text-white tracking-tight">{{ $hex }}</span>
                                    <span class="text-[9px] text-gray-500 uppercase tracking-wider">
                                        {{ number_format($weight * 100, 0) }}%
                                    </span>
                                </span>
                                <span x-show="copied === '{{ $hex }}'" x-cloak
                                      class="absolute -top-2 -right-2 text-[9px] font-bold px-1.5 py-0.5 rounded-full"
                                      style="background: #C5A55A; color: #000">
                                    Copied
                                </span>
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Style descriptor chips ─────────────────────────── --}}
        @if($data->lighting_style || $data->composition_style || !empty($moods))
            <div class="mb-6">
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-3"
                     style="color: #C5A55A">Karakter Visual</div>

                <div class="flex flex-wrap gap-1.5">
                    @if($data->lighting_style)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs rounded-md"
                              style="background: rgba(197,165,90,0.1); color: #fff; border: 1px solid rgba(197,165,90,0.3)">
                            <svg class="w-3 h-3 text-[#C5A55A]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                            <span class="uppercase tracking-wider text-[10px] font-semibold text-[#C5A55A]">Lighting</span>
                            <span>{{ ucwords(str_replace(['-', '_'], ' ', $data->lighting_style)) }}</span>
                        </span>
                    @endif

                    @if($data->composition_style)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs rounded-md"
                              style="background: rgba(197,165,90,0.1); color: #fff; border: 1px solid rgba(197,165,90,0.3)">
                            <svg class="w-3 h-3 text-[#C5A55A]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                            <span class="uppercase tracking-wider text-[10px] font-semibold text-[#C5A55A]">Komposisi</span>
                            <span>{{ ucwords(str_replace(['-', '_'], ' ', $data->composition_style)) }}</span>
                        </span>
                    @endif

                    @foreach($moods as $mood)
                        <span class="inline-flex items-center px-2.5 py-1 text-xs rounded-md"
                              style="background: rgba(255,255,255,0.04); color: #d1d5db; border: 1px solid rgba(197,165,90,0.18)">
                            {{ ucwords(str_replace(['-', '_'], ' ', $mood)) }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Keyframe mini-gallery ─────────────────────────── --}}
        @if(!empty($frames))
            <div class="mb-6">
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-3"
                     style="color: #C5A55A">Sampel Adegan</div>

                <div class="grid grid-cols-3 md:grid-cols-6 gap-2">
                    @foreach($frames as $framePath)
                        @php
                            $url = str_starts_with($framePath, 'http')
                                ? $framePath
                                : asset('storage/' . ltrim($framePath, '/'));
                        @endphp
                        <div class="aspect-video overflow-hidden rounded-md bg-black"
                             style="border: 1px solid rgba(197,165,90,0.18)">
                            <img src="{{ $url }}" alt="Keyframe"
                                 loading="lazy"
                                 class="w-full h-full object-cover hover:scale-105 transition-transform duration-300"
                                 onerror="this.style.display='none'">
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Narrative summary ─────────────────────────────── --}}
        @if(!empty($data->narrative_summary))
            <div>
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-3"
                     style="color: #C5A55A">Analisis Naratif</div>
                <p class="text-sm md:text-base text-gray-300 leading-relaxed whitespace-pre-line">
                    {{ $data->narrative_summary }}
                </p>
            </div>
        @elseif(empty($palette) && empty($moods) && !$data->lighting_style && !$data->composition_style)
            <p class="text-sm text-gray-500 italic">
                Analisis sinematografi belum tersedia untuk film ini.
            </p>
        @endif
    </div>
</section>
@endif
