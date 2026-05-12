<x-admin.layout title="Director Auteur Analyses">

    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            {{ session('error') }}
        </div>
    @endif

    {{-- ── Header ────────────────────────────────────────────── --}}
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h2 style="font-size:22px;font-weight:600;display:flex;align-items:center;gap:10px">
                <span style="color:#C5A55A">◎</span>
                Director Auteur Analysis
            </h2>
            <p style="color:#777;font-size:13px;margin-top:4px">
                {{ $analyses->total() }} sutradara sudah dianalisis · cache 7 hari per nama
            </p>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:340px 1fr;gap:24px;align-items:flex-start">

        {{-- ── Add / Analyze form ────────────────────────────── --}}
        <aside>
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px">
                <h3 style="font-size:15px;font-weight:600;margin-bottom:6px;color:#fff">Analyze a director</h3>
                <p style="color:#666;font-size:12px;margin-bottom:16px;line-height:1.6">
                    Masukkan nama lengkap sutradara. Web search (Wikipedia + DDG) akan dipakai sebagai grounding sebelum AI menulis breakdown auteur dalam Bahasa Indonesia.
                </p>

                <form method="POST" action="{{ route('admin.director-analyses.analyze') }}">
                    @csrf
                    <div class="form-group">
                        <label for="director_name">Nama Sutradara</label>
                        <input
                            id="director_name"
                            type="text"
                            name="director_name"
                            class="form-input"
                            placeholder="e.g. Christopher Nolan"
                            required
                            value="{{ old('director_name') }}"
                            maxlength="200"
                        >
                        @error('director_name')
                            <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label for="count">Jumlah Essential Films (3–10)</label>
                        <input
                            id="count"
                            type="number"
                            name="count"
                            class="form-input"
                            min="3"
                            max="10"
                            value="{{ old('count', 5) }}"
                        >
                    </div>

                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#aaa;margin-bottom:16px;cursor:pointer">
                        <input type="checkbox" name="force" value="1">
                        <span>Force refresh (skip 7-day cache)</span>
                    </label>

                    <button type="submit" class="btn btn-gold" style="width:100%;justify-content:center">
                        ✨ Analyze new director
                    </button>
                </form>
            </div>

            <div style="margin-top:16px;background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:14px 16px">
                <div style="color:#C5A55A;font-weight:600;font-size:12px;margin-bottom:6px">About</div>
                <div style="color:#aaa;font-size:11.5px;line-height:1.7">
                    Tiap analisis tersimpan di tabel <code style="color:#C5A55A">director_analyses</code> dan
                    juga di-cache 7 hari (key per nama, lowercase). Klik "Force refresh" untuk membuat ulang segera.
                </div>
            </div>
        </aside>

        {{-- ── List of analysed directors ────────────────────── --}}
        <div>
            <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden">
                <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center">
                    <h3 style="font-size:15px;font-weight:600">Analyzed Directors ({{ $analyses->total() }})</h3>
                </div>

                @if($analyses->isEmpty())
                    <div style="padding:48px 20px;text-align:center;color:#555">
                        <div style="font-size:36px;color:#2a2a2a;margin-bottom:8px">◎</div>
                        <p style="font-size:13px">Belum ada sutradara yang dianalisis.</p>
                        <p style="font-size:12px;margin-top:6px">Pakai form di kiri untuk mulai.</p>
                    </div>
                @else
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Director</th>
                                <th>Themes</th>
                                <th>Essential</th>
                                <th>Generated</th>
                                <th style="width:140px">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($analyses as $row)
                                @php
                                    $data = $row->data ?? [];
                                    $themes = $data['recurring_themes'] ?? [];
                                    $essential = $data['essential_films'] ?? [];
                                @endphp
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.director-analyses.show', $row->slug) }}" style="color:#fff;font-weight:600;text-decoration:none">
                                            {{ $row->director_name }}
                                        </a>
                                        <div style="font-size:11px;color:#555;margin-top:2px">{{ $row->slug }}</div>
                                    </td>
                                    <td>
                                        @if(!empty($themes))
                                            <div style="display:flex;flex-wrap:wrap;gap:4px;max-width:280px">
                                                @foreach(array_slice($themes, 0, 3) as $theme)
                                                    <span class="badge badge-gold" style="font-size:10px">{{ $theme }}</span>
                                                @endforeach
                                                @if(count($themes) > 3)
                                                    <span style="font-size:10px;color:#666">+{{ count($themes) - 3 }}</span>
                                                @endif
                                            </div>
                                        @else
                                            <span style="color:#444;font-size:11px">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge badge-blue">{{ count($essential) }} films</span>
                                    </td>
                                    <td style="color:#666;font-size:12px">
                                        {{ $row->generated_at?->diffForHumans() ?? '—' }}
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px">
                                            <a href="{{ route('admin.director-analyses.show', $row->slug) }}" class="btn btn-ghost btn-sm">View</a>
                                            <form method="POST" action="{{ route('admin.director-analyses.destroy', $row->slug) }}" onsubmit="return confirm('Hapus analisis untuk {{ $row->director_name }}?')" style="margin:0">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-danger btn-sm">Del</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if($analyses->hasPages())
                <div style="margin-top:16px">
                    {{ $analyses->withQueryString()->links() }}
                </div>
            @endif
        </div>
    </div>

</x-admin.layout>
