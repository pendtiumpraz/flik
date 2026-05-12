<x-admin.layout title="AI Reviews — {{ $movie->title }}">

    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            <x-icon name="info" size="16" /> {{ session('error') }}
        </div>
    @endif

    {{-- ── Header ────────────────────────────────────────────── --}}
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="{{ route('admin.movies.edit', $movie) }}" style="font-size:12px;color:#777;text-decoration:none">
                <x-icon name="chevron-left" size="12" /> Back to {{ $movie->title }}
            </a>
            <h2 style="font-size:22px;font-weight:600;margin-top:4px;display:flex;align-items:center;gap:10px">
                <x-icon name="sparkles" size="22" style="color:#C5A55A" />
                AI Movie Reviewer
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                {{ $movie->title }} · 4 perspectives ·
                {{ collect($reviews)->filter()->count() }}/{{ count($perspectives) }} generated
            </p>
        </div>

        {{-- Bulk generate (all 4 at once) --}}
        <form method="POST" action="{{ route('admin.movies.ai-reviews.generate', $movie) }}">
            @csrf
            @foreach($perspectives as $p)
                <input type="hidden" name="perspectives[]" value="{{ $p }}">
            @endforeach
            <button type="submit" class="btn btn-gold" onclick="return confirm('Generate all 4 perspectives? Ini akan memanggil AI provider 4x dan menimpa review yang sudah ada.')">
                <x-icon name="sparkles" size="14" /> Generate All 4
            </button>
        </form>
    </div>

    {{-- ── Perspective Overview Cards ────────────────────────── --}}
    <div class="grid-stats" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:24px">
        @php
            $perspectiveMeta = [
                'critic'   => ['icon' => 'star',     'tone' => 'Analytical · 200 words',  'desc' => 'Kritikus publikasi besar — bahas penyutradaraan, akting, naskah, tema.'],
                'casual'   => ['icon' => 'fire',     'tone' => 'Friendly · 150 words',     'desc' => 'Movie blogger santai — fun, relatable, langsung ke point.'],
                'family'   => ['icon' => 'heart',    'tone' => 'Family-safe · 120 words',  'desc' => 'Orang tua review kecocokan usia, kekerasan, bahasa, tema.'],
                'academic' => ['icon' => 'gem',      'tone' => 'Theoretical · 250 words',  'desc' => 'Akademisi film studies — auteur theory, genre conventions, dll.'],
            ];
        @endphp

        @foreach($perspectives as $p)
            @php $r = $reviews[$p] ?? null; @endphp
            <button
                type="button"
                onclick="velflixShowReview('{{ $p }}')"
                data-tab-button="{{ $p }}"
                class="ai-tab-card {{ $loop->first ? 'is-active' : '' }}"
                style="text-align:left;background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:14px 16px;cursor:pointer;transition:all 0.2s;font-family:inherit;color:inherit;display:flex;flex-direction:column;gap:6px"
            >
                <div style="display:flex;align-items:center;justify-content:space-between">
                    <div style="display:flex;align-items:center;gap:8px;color:#C5A55A">
                        <x-icon name="{{ $perspectiveMeta[$p]['icon'] }}" size="16" />
                        <span style="font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:1px">{{ $labels[$p] }}</span>
                    </div>
                    @if($r)
                        <span class="badge badge-green">Ready</span>
                    @else
                        <span class="badge" style="background:#2a2a2a;color:#777">Empty</span>
                    @endif
                </div>
                <div style="font-size:11px;color:#777">{{ $perspectiveMeta[$p]['tone'] }}</div>
                <div style="font-size:11px;color:#555;line-height:1.5">{{ $perspectiveMeta[$p]['desc'] }}</div>
                @if($r && $r->rating !== null)
                    <div style="margin-top:4px;font-family:'Outfit';font-size:18px;font-weight:700;color:#C5A55A">
                        {{ number_format((float) $r->rating, 1) }}<span style="font-size:11px;color:#666;font-weight:400">/10</span>
                    </div>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ── Tab Panels ───────────────────────────────────────── --}}
    @foreach($perspectives as $p)
        @php $r = $reviews[$p] ?? null; @endphp
        <section
            data-tab-panel="{{ $p }}"
            style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:24px;{{ $loop->first ? '' : 'display:none' }}"
        >
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #2a2a2a">
                <div>
                    <div style="display:flex;align-items:center;gap:8px;color:#C5A55A;font-size:11px;text-transform:uppercase;letter-spacing:2px;font-weight:600">
                        <x-icon name="{{ $perspectiveMeta[$p]['icon'] }}" size="14" />
                        {{ $labels[$p] }} · {{ $perspectiveMeta[$p]['tone'] }}
                    </div>
                    @if($r)
                        <h3 style="font-size:20px;font-weight:600;margin-top:8px;color:#fff">{{ $r->title }}</h3>
                    @else
                        <h3 style="font-size:18px;font-weight:500;margin-top:8px;color:#666">Belum ada review untuk perspective ini.</h3>
                    @endif
                </div>

                <form method="POST" action="{{ route('admin.movies.ai-reviews.generate', $movie) }}">
                    @csrf
                    <input type="hidden" name="perspective" value="{{ $p }}">
                    <button type="submit" class="btn {{ $r ? 'btn-ghost' : 'btn-gold' }}" {{ $r ? "onclick=\"return confirm('Re-generate review {$labels[$p]}? Review lama akan ditimpa.')\"" : '' }}>
                        <x-icon name="sparkles" size="14" />
                        {{ $r ? 'Re-generate' : 'Generate' }}
                    </button>
                </form>
            </div>

            @if($r)
                <div style="display:flex;flex-wrap:wrap;gap:18px;font-size:11px;color:#666;margin-bottom:18px">
                    @if($r->rating !== null)
                        <span><x-icon name="star-solid" size="12" style="color:#C5A55A" /> <strong style="color:#C5A55A">{{ number_format((float) $r->rating, 1) }}/10</strong></span>
                    @endif
                    <span><x-icon name="cog" size="12" /> {{ $r->provider_used }}</span>
                    <span><x-icon name="clock" size="12" /> {{ $r->generated_at?->diffForHumans() ?? '—' }}</span>
                </div>
                <div style="color:#d5d5d5;font-size:14.5px;line-height:1.85;white-space:pre-wrap;font-family:'Inter'">{{ $r->body }}</div>
            @else
                <div style="padding:36px 20px;text-align:center;color:#555;border:1px dashed #2a2a2a;border-radius:10px">
                    <x-icon name="sparkles" size="32" style="color:#333;margin-bottom:8px" />
                    <p style="margin-top:8px;font-size:13px">Klik tombol <strong style="color:#C5A55A">Generate</strong> di atas untuk membuat review pertama dari sudut pandang ini.</p>
                </div>
            @endif
        </section>
    @endforeach

    {{-- ── Info box ─────────────────────────────────────────── --}}
    <div style="margin-top:24px;background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:16px 20px">
        <div style="color:#C5A55A;font-weight:600;font-size:13px;margin-bottom:6px;display:flex;align-items:center;gap:6px">
            <x-icon name="info" size="14" /> About AI Movie Reviewer
        </div>
        <div style="color:#aaa;font-size:12px;line-height:1.7">
            Setiap film bisa punya <strong>4 review independen</strong>, satu per perspective. AI dipanggil via provider yang di-set default di
            <a href="{{ route('admin.ai.index') }}" style="color:#C5A55A;text-decoration:none">/admin/ai-settings</a>.
            Re-generate akan <strong>menimpa</strong> review lama (unique by movie + perspective).
            Hasil ditulis dalam Bahasa Indonesia; tone & target panjang berbeda per perspective.
        </div>
    </div>

    <style>
        .ai-tab-card:hover { border-color: #C5A55A !important; }
        .ai-tab-card.is-active { border-color: #C5A55A !important; background: rgba(197,165,90,0.08) !important; box-shadow: 0 0 0 1px rgba(197,165,90,0.4) inset; }
    </style>

    <script>
        function velflixShowReview(perspective) {
            document.querySelectorAll('[data-tab-panel]').forEach(el => {
                el.style.display = el.dataset.tabPanel === perspective ? 'block' : 'none';
            });
            document.querySelectorAll('[data-tab-button]').forEach(el => {
                el.classList.toggle('is-active', el.dataset.tabButton === perspective);
            });
        }
    </script>

</x-admin.layout>
