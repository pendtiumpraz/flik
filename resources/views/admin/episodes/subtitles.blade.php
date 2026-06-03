<x-admin.layout title="Subtitles — {{ $episode->title }}">

    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            ❌ {{ session('error') }}
        </div>
    @endif

    <!-- Header -->
    <div style="margin-bottom:24px">
        @if($episode->movie && $episode->season)
            <a href="{{ route('admin.movies.seasons.episodes.edit', [$episode->movie, $episode->season, $episode]) }}" style="font-size:12px;color:#777;text-decoration:none">← Back to episode</a>
        @endif
        <h2 style="font-size:22px;font-weight:600;margin-top:4px">Episode Subtitles</h2>
        <p style="color:#777;font-size:13px;margin-top:4px">
            {{ $episode->movie?->title }} · S{{ $episode->season?->season_number }}E{{ $episode->episode_number }} — {{ $episode->title }}
            · {{ $subtitles->count() }} subtitle
        </p>
    </div>

    <!-- Existing subtitles -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:15px;font-weight:600">Existing Subtitles</h3>
        </div>
        @if($subtitles->isEmpty())
            <div style="padding:48px 20px;text-align:center;color:#555">
                <p style="margin-bottom:8px">Belum ada subtitle untuk episode ini.</p>
                <p style="font-size:12px">Upload file .srt / .vtt di bawah. (Generate/translate AI saat ini hanya untuk movie.)</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Bahasa</th>
                        <th>Cues</th>
                        <th>Status</th>
                        <th>Default</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($subtitles as $sub)
                    <tr>
                        <td>
                            <div style="font-weight:500;color:#fff" {{ $sub->is_rtl ? 'dir=rtl' : '' }}>{{ $sub->label }}</div>
                            <div style="font-size:11px;color:#666;margin-top:2px"><code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:#C5A55A">{{ $sub->language_code }}</code></div>
                        </td>
                        <td><span style="color:#aaa">{{ $sub->cue_count ?? '—' }}</span></td>
                        <td>
                            @if($sub->status === 'ready')
                                <span class="badge badge-green">Ready</span>
                            @else
                                <span class="badge" style="background:#2a2a2a;color:#777">{{ ucfirst($sub->status) }}</span>
                            @endif
                        </td>
                        <td>
                            @if($sub->is_default)
                                <span class="badge badge-gold">Default</span>
                            @else
                                <form method="POST" action="{{ route('admin.episodes.subtitles.default', [$episode, $sub]) }}" style="display:inline">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm">Set Default</button>
                                </form>
                            @endif
                        </td>
                        <td style="text-align:right">
                            @if($sub->status === 'ready')
                                <a href="{{ route('admin.episodes.subtitles.download', [$episode, $sub]) }}" class="btn btn-ghost btn-sm" title="Download WebVTT">.vtt</a>
                                <a href="{{ route('admin.episodes.subtitles.download', [$episode, $sub]) }}?format=srt" class="btn btn-ghost btn-sm" title="Download SubRip">.srt</a>
                            @endif
                            <form method="POST" action="{{ route('admin.episodes.subtitles.destroy', [$episode, $sub]) }}" style="display:inline" onsubmit="return confirm('Hapus subtitle {{ $sub->label }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <!-- Upload -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px">
        <h3 style="font-size:15px;font-weight:600;margin-bottom:6px">⬆️ Upload Subtitle (.srt / .vtt)</h3>
        <p style="font-size:12px;color:#777;margin-bottom:14px">File <code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:#C5A55A">.srt</code> otomatis dikonversi ke WebVTT (timeline tidak diubah).</p>
        <form method="POST" action="{{ route('admin.episodes.subtitles.upload', $episode) }}" enctype="multipart/form-data" style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
            @csrf
            <div class="form-group" style="margin-bottom:0">
                <label>File (.srt / .vtt)</label>
                <input type="file" name="subtitle_file" accept=".srt,.vtt" required class="form-input">
            </div>
            <div class="form-group" style="margin-bottom:0;min-width:240px">
                <label>Bahasa</label>
                <select name="language" class="form-input">
                    @foreach($grouped as $group => $langs)
                        <optgroup label="{{ $groups[$group] ?? $group }}">
                            @foreach($langs as $code => $meta)
                                @if(!isset($meta['variant']))
                                    <option value="{{ $code }}" {{ $code === 'id' ? 'selected' : '' }}>{{ $meta['native'] }} ({{ $meta['name'] }})</option>
                                @endif
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-gold">⬆️ Upload</button>
        </form>
    </div>

</x-admin.layout>
