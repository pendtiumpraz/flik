{{--
    Integration setup guide — in-app render of docs/integration-setup.md.

    Left column: pick a service. Right column: its step-by-step tutorial.
    Source of truth is the Markdown file; IntegrationGuideController sections it.
--}}
<x-admin.layout title="Panduan Koneksi">

    @push('styles')
    <style>
        .guide-wrap { display: grid; grid-template-columns: 260px 1fr; gap: 22px; align-items: start; }
        @media (max-width: 900px) { .guide-wrap { grid-template-columns: 1fr; } }

        /* Service picker (left) */
        .guide-nav {
            background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px;
            padding: 8px; position: sticky; top: 84px; max-height: calc(100vh - 110px);
            overflow-y: auto;
        }
        @media (max-width: 900px) { .guide-nav { position: static; max-height: none; } }
        .guide-nav-item {
            display: block; width: 100%; text-align: left; background: transparent;
            border: none; cursor: pointer; color: #aaa; font-size: 13px; font-weight: 500;
            padding: 9px 12px; border-radius: 8px; transition: all .15s; line-height: 1.35;
        }
        .guide-nav-item:hover { background: #252525; color: #e5e5e5; }
        .guide-nav-item.is-active { background: rgba(197,165,90,0.14); color: #C5A55A; }

        /* Content panel (right) */
        .guide-panel {
            background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 12px; padding: 28px 32px;
        }

        /* Rendered markdown */
        .md-content { font-size: 14px; color: #cfcfcf; line-height: 1.7; }
        .md-content h2 { font-size: 20px; color: #fff; margin: 0 0 16px; }
        .md-content h3 { font-size: 16px; color: #C5A55A; margin: 26px 0 10px; }
        .md-content h4 { font-size: 14px; color: #e5e5e5; margin: 20px 0 8px; }
        .md-content p { margin: 0 0 12px; }
        .md-content ul, .md-content ol { margin: 0 0 14px; padding-left: 22px; }
        .md-content li { margin-bottom: 6px; }
        .md-content a { color: #C5A55A; text-decoration: underline; }
        .md-content strong { color: #fff; }
        .md-content code {
            background: #0f0f0f; color: #E8D5A3; padding: 2px 6px; border-radius: 5px;
            font-family: 'JetBrains Mono', Menlo, monospace; font-size: 12.5px;
        }
        .md-content pre {
            background: #0f0f0f; border: 1px solid #2a2a2a; border-radius: 8px;
            padding: 14px 16px; overflow-x: auto; margin: 0 0 14px;
        }
        .md-content pre code { background: transparent; padding: 0; color: #d6d6d6; }
        .md-content blockquote {
            border-left: 3px solid #C5A55A; background: rgba(197,165,90,0.06);
            padding: 10px 16px; margin: 0 0 14px; color: #bbb; border-radius: 0 8px 8px 0;
        }
        .md-content table { width: 100%; border-collapse: collapse; margin: 0 0 16px; font-size: 13px; }
        .md-content th, .md-content td { border: 1px solid #2a2a2a; padding: 8px 12px; text-align: left; vertical-align: top; }
        .md-content th { background: #202020; color: #fff; font-weight: 600; }
        .md-content hr { border: none; border-top: 1px solid #2a2a2a; margin: 22px 0; }
    </style>
    @endpush

    {{-- Top callout: where to actually enter the keys --}}
    <div style="background:rgba(197,165,90,0.08);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
        <div style="font-size:13px;color:#bbb;line-height:1.55">
            <strong style="color:#C5A55A">Pilih layanan</strong> di kiri untuk lihat langkahnya.
            Setelah dapat key-nya, masukkan di halaman <strong>Infrastructure</strong>.
        </div>
        <a href="{{ route('admin.infrastructure.index') }}" class="btn btn-gold">🔧 Buka Infrastructure</a>
    </div>

    @if($missing || empty($sections))
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:60px 20px;text-align:center">
            <div style="font-family:'Outfit';font-size:16px;font-weight:600;color:#888;margin-bottom:6px">Panduan tidak ditemukan</div>
            <p style="font-size:13px;color:#666">File <code style="background:#0f0f0f;padding:2px 7px;border-radius:5px;color:#C5A55A">docs/integration-setup.md</code> belum ada di project.</p>
        </div>
    @else
        <div class="guide-wrap" x-data="{ sel: @js($sections[0]['id']) }">

            {{-- ─── Service picker ─────────────────────────────── --}}
            <nav class="guide-nav">
                @if($intro)
                    <button type="button" class="guide-nav-item"
                            :class="sel === 'intro' ? 'is-active' : ''"
                            @click="sel = 'intro'">ℹ️ Cara kerja & ringkasan</button>
                @endif
                @foreach($sections as $s)
                    <button type="button" class="guide-nav-item"
                            :class="sel === @js($s['id']) ? 'is-active' : ''"
                            @click="sel = @js($s['id'])">{{ $s['title'] }}</button>
                @endforeach
            </nav>

            {{-- ─── Content panel ──────────────────────────────── --}}
            <div>
                @if($intro)
                    <div class="guide-panel md-content" x-show="sel === 'intro'" x-cloak>
                        {!! $intro !!}
                    </div>
                @endif
                @foreach($sections as $s)
                    <div class="guide-panel md-content" x-show="sel === @js($s['id'])" x-cloak>
                        {!! $s['html'] !!}
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</x-admin.layout>
