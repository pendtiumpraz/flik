{{--
    Shared episode form — included from create/edit. Variables:
      $episode     ?Episode  null = create
      $nextNumber  int       default episode_number suggestion
--}}

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="form-group">
        <label for="episode_number">Episode Number <span style="color:#ef4444">*</span></label>
        <input type="number" name="episode_number" id="episode_number" min="1" max="999" required
               value="{{ old('episode_number', $episode?->episode_number ?? $nextNumber) }}"
               class="form-input" style="width:140px">
        @error('episode_number') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
    </div>

    <div class="form-group">
        <label for="runtime_minutes">Runtime (minutes)</label>
        <input type="number" name="runtime_minutes" id="runtime_minutes" min="1" max="600"
               value="{{ old('runtime_minutes', $episode?->runtime_minutes) }}"
               class="form-input" style="width:140px">
        @error('runtime_minutes') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
    </div>
</div>

<div class="form-group">
    <label for="title">Title <span style="color:#ef4444">*</span></label>
    <input type="text" name="title" id="title" maxlength="200" required
           value="{{ old('title', $episode?->title) }}"
           placeholder="e.g. The Trial"
           class="form-input">
    @error('title') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label for="overview">Overview (optional)</label>
    <textarea name="overview" id="overview" rows="4" maxlength="5000"
              class="form-input">{{ old('overview', $episode?->overview) }}</textarea>
    @error('overview') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div class="form-group">
        <label for="air_date">Air Date</label>
        <input type="date" name="air_date" id="air_date"
               value="{{ old('air_date', $episode?->air_date?->format('Y-m-d')) }}"
               class="form-input">
        @error('air_date') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
    </div>

    <div class="form-group">
        <label for="still_path">Still Frame (URL / storage path)</label>
        <input type="text" name="still_path" id="still_path" maxlength="500"
               value="{{ old('still_path', $episode?->still_path) }}"
               placeholder="https://… or storage path"
               class="form-input">
        @error('still_path') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
    </div>
</div>

<div style="border-top:1px solid #2a2a2a;margin:8px 0 18px"></div>
<div style="font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:#888;margin-bottom:10px;font-weight:600">Playback (optional)</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px">
    <div class="form-group">
        <label for="video_path">Video Path</label>
        <input type="text" name="video_path" id="video_path" maxlength="500"
               value="{{ old('video_path', $episode?->video_path) }}"
               class="form-input">
        @error('video_path') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
    </div>

    <div class="form-group">
        <label for="video_disk">Disk</label>
        <select name="video_disk" id="video_disk" class="form-input">
            <option value="">(default)</option>
            @foreach(['public', 's3', 'azure', 'alibaba', 'bunny'] as $disk)
                <option value="{{ $disk }}" @selected(old('video_disk', $episode?->video_disk) === $disk)>{{ $disk }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="form-group">
    <label for="hls_manifest_path">HLS Manifest Path (optional)</label>
    <input type="text" name="hls_manifest_path" id="hls_manifest_path" maxlength="500"
           value="{{ old('hls_manifest_path', $episode?->hls_manifest_path) }}"
           placeholder="e.g. episodes/123/master.m3u8"
           class="form-input">
    @error('hls_manifest_path') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
</div>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
    <div class="form-group">
        <label for="intro_start_seconds">Intro Start (s)</label>
        <input type="number" name="intro_start_seconds" id="intro_start_seconds" min="0"
               value="{{ old('intro_start_seconds', $episode?->intro_start_seconds) }}"
               class="form-input">
    </div>
    <div class="form-group">
        <label for="intro_end_seconds">Intro End (s)</label>
        <input type="number" name="intro_end_seconds" id="intro_end_seconds" min="0"
               value="{{ old('intro_end_seconds', $episode?->intro_end_seconds) }}"
               class="form-input">
    </div>
    <div class="form-group">
        <label for="outro_start_seconds">Outro Start (s)</label>
        <input type="number" name="outro_start_seconds" id="outro_start_seconds" min="0"
               value="{{ old('outro_start_seconds', $episode?->outro_start_seconds) }}"
               class="form-input">
    </div>
</div>
