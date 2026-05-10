<x-admin.layout title="Subtitles — {{ $movie->title }}">

    @if(session('error'))
        <div style="background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);color:#ef4444;padding:12px 20px;border-radius:8px;margin-bottom:20px;font-size:14px">
            ❌ {{ session('error') }}
        </div>
    @endif

    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <a href="{{ route('admin.movies.edit', $movie) }}" style="font-size:12px;color:#777;text-decoration:none">← Back to {{ $movie->title }}</a>
            <h2 style="font-size:22px;font-weight:600;margin-top:4px">Subtitle Manager</h2>
            <p style="color:#777;font-size:13px;margin-top:4px">{{ $movie->title }} · {{ $subtitles->count() }} subtitle aktif</p>
        </div>
    </div>

    <!-- Existing Subtitles -->
    <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;overflow:hidden;margin-bottom:24px">
        <div style="padding:16px 20px;border-bottom:1px solid #2a2a2a">
            <h3 style="font-size:15px;font-weight:600">Existing Subtitles</h3>
        </div>
        @if($subtitles->isEmpty())
            <div style="padding:48px 20px;text-align:center;color:#555">
                <p style="margin-bottom:8px">Belum ada subtitle untuk film ini.</p>
                <p style="font-size:12px">Generate subtitle dasar (Indonesia) dulu dari audio film, baru bisa di-translate ke bahasa lain.</p>
            </div>
        @else
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Bahasa</th>
                        <th>Source</th>
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
                            <div style="font-weight:500;color:#fff" {{ $sub->is_rtl ? 'dir="rtl"' : '' }}>{{ $sub->label }}</div>
                            <div style="font-size:11px;color:#666;margin-top:2px"><code style="background:#0f0f0f;padding:1px 6px;border-radius:3px;color:#C5A55A">{{ $sub->language_code }}</code></div>
                        </td>
                        <td>
                            @if($sub->is_auto_generated)
                                <span class="badge badge-blue">Auto-generated</span>
                            @elseif($sub->is_translated)
                                <span class="badge badge-gold">Translated from {{ $sub->source_language }}</span>
                            @else
                                <span class="badge" style="background:#2a2a2a;color:#777">Manual</span>
                            @endif
                            @if($sub->generator_model)
                                <div style="font-size:10px;color:#555;margin-top:2px">{{ $sub->generator_model }}</div>
                            @endif
                        </td>
                        <td><span style="color:#aaa">{{ $sub->cue_count ?? '—' }}</span></td>
                        <td>
                            @if($sub->status === 'ready')
                                <span class="badge badge-green">Ready</span>
                            @elseif($sub->status === 'processing')
                                <span class="badge" style="background:rgba(234,179,8,0.15);color:#eab308">Processing</span>
                            @elseif($sub->status === 'failed')
                                <span class="badge" style="background:rgba(220,38,38,0.15);color:#ef4444">Failed</span>
                            @else
                                <span class="badge" style="background:#2a2a2a;color:#777">Pending</span>
                            @endif
                        </td>
                        <td>
                            @if($sub->is_default)
                                <span class="badge badge-gold">Default</span>
                            @else
                                <form method="POST" action="{{ route('admin.movies.subtitles.default', [$movie, $sub]) }}" style="display:inline">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm">Set Default</button>
                                </form>
                            @endif
                        </td>
                        <td style="text-align:right">
                            <form method="POST" action="{{ route('admin.movies.subtitles.destroy', [$movie, $sub]) }}" style="display:inline" onsubmit="return confirm('Hapus subtitle {{ $sub->label }}?')">
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

    <div class="grid-stats" style="grid-template-columns:1fr 1fr;gap:24px">

        <!-- Generate Subtitle from Audio -->
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:6px">🎤 Generate Subtitle from Audio</h3>
            <p style="font-size:12px;color:#777;margin-bottom:14px">Extract audio dari film + transcribe via OpenAI gpt-4o-mini-transcribe. Cost ~$0.27 per 90-min film.</p>

            @if(empty($movie->video_path))
                <div style="padding:12px;background:rgba(234,179,8,0.1);border:1px solid rgba(234,179,8,0.3);border-radius:6px;font-size:12px;color:#eab308;margin-bottom:12px">
                    ⚠️ Film belum punya video file (`video_path` kosong). Upload video dulu di edit movie.
                </div>
            @endif

            <form method="POST" action="{{ route('admin.movies.subtitles.generate', $movie) }}">
                @csrf
                <div class="form-group">
                    <label>Source Language</label>
                    <select name="language" class="form-input">
                        @foreach($grouped as $group => $langs)
                            <optgroup label="{{ $groups[$group] ?? $group }}">
                                @foreach($langs as $code => $meta)
                                    @if(!isset($meta['variant']))
                                        <option value="{{ $code }}" {{ $code === 'id' ? 'selected' : '' }}>
                                            {{ $meta['native'] }} ({{ $meta['name'] }})
                                        </option>
                                    @endif
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <button type="submit" class="btn btn-gold" {{ empty($movie->video_path) ? 'disabled' : '' }} style="{{ empty($movie->video_path) ? 'opacity:0.5;cursor:not-allowed' : '' }}">
                    🎤 Generate Now
                </button>
            </form>
        </div>

        <!-- Translate Existing Subtitle -->
        <div style="background:#1a1a1a;border:1px solid #2a2a2a;border-radius:12px;padding:20px">
            <h3 style="font-size:15px;font-weight:600;margin-bottom:6px">🌍 Translate to Another Language</h3>
            <p style="font-size:12px;color:#777;margin-bottom:14px">Translate existing subtitle ke salah satu dari {{ count(\App\Services\Ai\Subtitle\LanguageCatalog::all()) }}+ bahasa dunia. Pakai DeepSeek V4 Flash (~$0.50 per film/bahasa).</p>

            @if($subtitles->where('status', 'ready')->isEmpty())
                <div style="padding:12px;background:rgba(234,179,8,0.1);border:1px solid rgba(234,179,8,0.3);border-radius:6px;font-size:12px;color:#eab308">
                    ⚠️ Generate dulu subtitle dasar di kolom kiri sebelum bisa translate.
                </div>
            @else
                <form method="POST" action="{{ route('admin.movies.subtitles.translate', $movie) }}">
                    @csrf
                    <div class="form-group">
                        <label>Source Subtitle</label>
                        <select name="source_subtitle_id" class="form-input" required>
                            @foreach($subtitles->where('status', 'ready') as $sub)
                                <option value="{{ $sub->id }}">{{ $sub->label }} ({{ $sub->cue_count }} cues)</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Target Language</label>
                        <select name="target_language" class="form-input" required>
                            <option value="">— Pilih bahasa target —</option>
                            @foreach($grouped as $group => $langs)
                                <optgroup label="{{ $groups[$group] ?? $group }}">
                                    @foreach($langs as $code => $meta)
                                        @if(in_array($code, $existingCodes)) @continue @endif
                                        <option value="{{ $code }}">
                                            {{ $meta['native'] }} ({{ $meta['name'] }})
                                            @if(isset($meta['variant']) && $meta['variant'] === 'harakat-on') — auto-add tashkeel @endif
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="btn btn-gold">🌍 Translate Now</button>
                </form>
            @endif
        </div>

    </div>

    <!-- Info Box -->
    <div style="margin-top:24px;background:rgba(197,165,90,0.06);border:1px solid rgba(197,165,90,0.25);border-radius:10px;padding:16px 20px">
        <div style="color:#C5A55A;font-weight:600;font-size:13px;margin-bottom:6px">💡 About Multi-language Subtitle</div>
        <div style="color:#aaa;font-size:12px;line-height:1.6">
            <strong>{{ count(\App\Services\Ai\Subtitle\LanguageCatalog::all()) }} bahasa dunia</strong> tersedia, dikelompokkan per region (SEA, East Asia, Middle East, South Asia, Europe, Africa).<br>
            <strong>Arabic varieties</strong>: Standard (tanpa harakat), dengan Harakat/Tashkeel (untuk learner & content religius), Classical (Quran style), Egyptian dialect.<br>
            <strong>Chinese</strong>: Simplified (Mainland) + Traditional (Taiwan/Hong Kong).<br>
            <strong>Norwegian</strong>: Bokmål + Nynorsk variants.<br>
            <strong>English</strong>: US + UK variants.<br>
            Semua subtitle WebVTT-format (`.vtt`), kompatibel dengan Video.js/Shaka Player out-of-the-box.
        </div>
    </div>

</x-admin.layout>
