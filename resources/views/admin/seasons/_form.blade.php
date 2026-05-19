{{--
    Shared season form fields — included from create.blade.php / edit.blade.php.
    Variables expected:
      $season      ?Season   nullable (null = create mode)
      $nextNumber  int       default season_number suggestion for create mode
--}}

<div class="form-group">
    <label for="season_number">Season Number <span style="color:#ef4444">*</span></label>
    <input type="number" name="season_number" id="season_number" min="1" max="99" required
           value="{{ old('season_number', $season?->season_number ?? $nextNumber) }}"
           class="form-input" style="width:180px">
    @error('season_number') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label for="title">Title (optional)</label>
    <input type="text" name="title" id="title" maxlength="200"
           value="{{ old('title', $season?->title) }}"
           placeholder="e.g. The Crown War"
           class="form-input">
    @error('title') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label for="overview">Overview (optional)</label>
    <textarea name="overview" id="overview" maxlength="5000" rows="4"
              class="form-input">{{ old('overview', $season?->overview) }}</textarea>
    @error('overview') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label for="poster_path">Poster Path / URL (optional)</label>
    <input type="text" name="poster_path" id="poster_path" maxlength="500"
           value="{{ old('poster_path', $season?->poster_path) }}"
           placeholder="https://… or storage path"
           class="form-input">
    @error('poster_path') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
</div>

<div class="form-group">
    <label for="air_date">Air Date (optional)</label>
    <input type="date" name="air_date" id="air_date"
           value="{{ old('air_date', $season?->air_date?->format('Y-m-d')) }}"
           class="form-input" style="width:200px">
    @error('air_date') <div style="color:#ef4444;font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
</div>
