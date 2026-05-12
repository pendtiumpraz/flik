<x-admin.layout title="{{ $analysis->director_name }} — Auteur Analysis">

    @php
        $data = $analysis->data ?? [];
        $signatureStyle      = $data['signature_style']        ?? '';
        $recurringThemes     = $data['recurring_themes']       ?? [];
        $collaborators       = $data['frequent_collaborators'] ?? [];
        $influence           = $data['influence']              ?? '';
        $essentialFilms      = $data['essential_films']        ?? [];
        $trivia              = $data['trivia']                 ?? [];
        $sourceUrls          = $analysis->source_urls          ?? [];
    @endphp

    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    {{-- ── Header ────────────────────────────────────────────── --}}
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="{{ route('admin.director-analyses.index') }}" style="font-size:12px;color:#777;text-decoration:none">
                ← Back to all directors
            </a>
            <h2 style="font-size:26px;font-weight:700;margin-top:6px;color:#fff;display:flex;align-items:center;gap:10px">
                <span style="color:#C5A55A">◎</span>
                {{ $analysis->director_name }}
            </h2>
            <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:11.5px;color:#666;margin-top:8px">
                <span>Slug: <code style="color:#aaa">{{ $analysis->slug }}</code></span>
                <span>· Generated {{ $analysis->generated_at?->diffForHumans() ?? '—' }}</span>
                <span>· {{ count($essentialFilms) }} essential films</span>
                <span>· {{ count($recurringThemes) }} themes</span>
            </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="POST" action="{{ route('admin.director-analyses.refresh', $analysis->slug) }}" style="margin:0">
                @csrf
                <button type="submit" class="btn btn-ghost" onclick="return confirm('Re-generate analysis untuk {{ $analysis->director_name }}? Akan menimpa data lama.')">
                    ↻ Re-generate
                </button>
            </form>
            <form method="POST" action="{{ route('admin.director-analyses.destroy', $analysis->slug) }}" style="margin:0" onsubmit="return confirm('Hapus analisis ini?')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        </div>
    </div>

    {{-- ── Signature style + Influence (top hero) ────────────── --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px">

        <section style="background:linear-gradient(135deg,rgba(197,165,90,0.12),rgba(197,165,90,0.03));border:1px solid rgba(197,165,90,0.3);border-radius:12px;padding:22px 24px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:2px;color:#C5A55A;font-weight:600;margin-bottom:10px">
                Signature Style
            </div>
            @if($signatureStyle !== '')
                <div style="color:#e5e5e5;font-size:14.5px;line-height:1.8">{{ $signatureStyle }}</div>
            @else
                <div style="color:#555;font-size:13px;font-style:italic">Belum tersedia.</div>
            @endif
        </section>

        <section style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:22px 24px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:2px;color:#C5A55A;font-weight:600;margin-bottom:10px">
                Influence
            </div>
            @if($influence !== '')
                <div style="color:#d5d5d5;font-size:14px;line-height:1.8">{{ $influence }}</div>
            @else
                <div style="color:#555;font-size:13px;font-style:italic">Belum tersedia.</div>
            @endif
        </section>
    </div>

    {{-- ── Themes + Collaborators ─────────────────────────────── --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px">

        <section style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:2px;color:#C5A55A;font-weight:600;margin-bottom:14px">
                Recurring Themes ({{ count($recurringThemes) }})
            </div>
            @if(!empty($recurringThemes))
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    @foreach($recurringThemes as $theme)
                        <span class="badge badge-gold" style="padding:6px 12px;font-size:12px">{{ $theme }}</span>
                    @endforeach
                </div>
            @else
                <div style="color:#555;font-size:13px;font-style:italic">Belum tersedia.</div>
            @endif
        </section>

        <section style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px 24px">
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:2px;color:#C5A55A;font-weight:600;margin-bottom:14px">
                Frequent Collaborators ({{ count($collaborators) }})
            </div>
            @if(!empty($collaborators))
                <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px">
                    @foreach($collaborators as $collaborator)
                        <li style="display:flex;align-items:center;gap:10px;font-size:13.5px;color:#d5d5d5">
                            <span style="color:#C5A55A">▸</span>
                            <span>{{ $collaborator }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <div style="color:#555;font-size:13px;font-style:italic">Belum tersedia.</div>
            @endif
        </section>
    </div>

    {{-- ── Essential Films ───────────────────────────────────── --}}
    <section style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:18px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:2px;color:#C5A55A;font-weight:600;margin-bottom:16px">
            Essential Films ({{ count($essentialFilms) }})
        </div>

        @if(!empty($essentialFilms))
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
                @foreach($essentialFilms as $film)
                    @php
                        $title = $film['title'] ?? '';
                        $year  = $film['year']  ?? null;
                        $why   = $film['why_essential'] ?? '';
                    @endphp
                    <div style="background:#111;border:1px solid #222;border-radius:10px;padding:16px 18px;display:flex;flex-direction:column;gap:8px">
                        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px">
                            <div style="font-family:'Outfit';font-size:16px;font-weight:600;color:#fff;line-height:1.3">{{ $title }}</div>
                            @if($year)
                                <div style="font-family:'Outfit';font-size:13px;color:#C5A55A;font-weight:600">{{ $year }}</div>
                            @endif
                        </div>
                        @if($why !== '')
                            <div style="color:#999;font-size:12.5px;line-height:1.6">{{ $why }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div style="color:#555;font-size:13px;font-style:italic">Belum ada film esensial yang tercatat.</div>
        @endif
    </section>

    {{-- ── Trivia ────────────────────────────────────────────── --}}
    <section style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;margin-bottom:18px">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:2px;color:#C5A55A;font-weight:600;margin-bottom:16px">
            Trivia ({{ count($trivia) }})
        </div>

        @if(!empty($trivia))
            <ol style="margin:0;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:12px;counter-reset:trivia-counter">
                @foreach($trivia as $fact)
                    <li style="counter-increment:trivia-counter;display:flex;gap:14px;align-items:flex-start;background:#111;border:1px solid #222;border-radius:8px;padding:14px 16px">
                        <span style="display:flex;align-items:center;justify-content:center;min-width:26px;height:26px;border-radius:50%;background:rgba(197,165,90,0.18);color:#C5A55A;font-weight:700;font-size:12px;font-family:'Outfit'">
                            {{ $loop->iteration }}
                        </span>
                        <span style="color:#d5d5d5;font-size:13.5px;line-height:1.7">{{ $fact }}</span>
                    </li>
                @endforeach
            </ol>
        @else
            <div style="color:#555;font-size:13px;font-style:italic">Belum ada trivia.</div>
        @endif
    </section>

    {{-- ── Source URLs ───────────────────────────────────────── --}}
    @if(!empty($sourceUrls))
        <section style="background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:16px 20px">
            <div style="color:#C5A55A;font-weight:600;font-size:12px;margin-bottom:10px">
                Sources ({{ count($sourceUrls) }})
            </div>
            <ul style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:6px">
                @foreach($sourceUrls as $url)
                    <li>
                        <a href="{{ $url }}" target="_blank" rel="noopener" style="color:#C5A55A;font-size:12px;text-decoration:none;word-break:break-all">
                            {{ $url }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

</x-admin.layout>
