{{-- Family-night picker form (shared by form.blade.php + result.blade.php). --}}
@php
    $selectedIds  = collect($selectedIds ?? [$currentUserId])->map(fn ($v) => (int) $v)->all();
    $constraints  = $constraints ?? [];
    $minAgeVal    = $constraints['min_age']              ?? '';
    $durationVal  = $constraints['duration_max_minutes'] ?? '';
    $languageVal  = $constraints['language']             ?? '';
    $moodVal      = $constraints['mood']                 ?? '';
@endphp

<form method="POST"
      action="{{ route('family-night.recommend') }}"
      class="max-w-3xl mx-auto"
      x-data="{
          selected: @js($selectedIds),
          toggle(id) {
              id = parseInt(id, 10);
              const i = this.selected.indexOf(id);
              if (i === -1) {
                  if (this.selected.length >= 8) return;
                  this.selected.push(id);
              } else {
                  this.selected.splice(i, 1);
              }
          },
          isOn(id) { return this.selected.indexOf(parseInt(id, 10)) !== -1; }
      }">
    @csrf

    <div class="space-y-6 rounded-xl p-5 md:p-7"
         style="background: rgba(20,18,16,0.7); border: 1px solid rgba(197,165,90,0.3); box-shadow: 0 8px 32px -8px rgba(197,165,90,0.15)">

        {{-- ── Viewers ─────────────────────────────────────────── --}}
        <div>
            <label class="block text-[11px] md:text-xs font-bold uppercase tracking-[0.2em] mb-3" style="color: #C5A55A">
                Siapa yang nonton?
            </label>

            {{-- Hidden inputs synced with Alpine state --}}
            <template x-for="uid in selected" :key="uid">
                <input type="hidden" name="user_ids[]" :value="uid">
            </template>

            <div class="flex flex-wrap gap-2 max-h-56 overflow-y-auto pr-1">
                @foreach($viewers as $viewer)
                    @php $isSelf = $viewer->id === $currentUserId; @endphp
                    <button type="button"
                            x-on:click="toggle({{ $viewer->id }})"
                            x-bind:style="isOn({{ $viewer->id }})
                                ? 'background: linear-gradient(135deg, #C5A55A, #E8D5A3); color: #000; border-color: rgba(197,165,90,0.6);'
                                : 'background: rgba(255,255,255,0.04); color: #E8D5A3; border-color: rgba(197,165,90,0.25);'"
                            class="text-[11px] md:text-xs px-3 py-1.5 rounded-full transition-all hover:scale-[1.02] inline-flex items-center gap-1.5 font-semibold"
                            style="border: 1px solid rgba(197,165,90,0.25)">
                        <x-icon name="plus" :size="11" x-show="!isOn({{ $viewer->id }})" />
                        <span x-show="isOn({{ $viewer->id }})" class="text-[10px]">✓</span>
                        <span>{{ $viewer->name }}</span>
                        @if($isSelf)
                            <span class="text-[9px] opacity-70">(kamu)</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <p class="mt-2 text-[11px] text-gray-500">
                Pilih 1–8 penonton.
                <span x-text="`${selected.length} dipilih`" class="text-[#C5A55A] font-semibold"></span>
            </p>

            @error('user_ids')   <p class="mt-2 text-xs text-red-400">{{ $message }}</p> @enderror
            @error('user_ids.*') <p class="mt-2 text-xs text-red-400">{{ $message }}</p> @enderror
        </div>

        {{-- ── Constraints ─────────────────────────────────────── --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5 pt-2"
             style="border-top: 1px solid rgba(197,165,90,0.15)">

            {{-- Min age --}}
            <div>
                <label class="block text-[11px] md:text-xs font-bold uppercase tracking-[0.2em] mb-2" style="color: #C5A55A">
                    Umur termuda
                </label>
                <div class="relative">
                    <input type="number"
                           name="min_age"
                           value="{{ $minAgeVal }}"
                           min="0" max="99"
                           placeholder="cth. 8"
                           class="w-full bg-transparent text-white placeholder-gray-600 px-3 py-2.5 text-sm rounded-lg focus:outline-none"
                           style="background: rgba(0,0,0,0.4); border: 1px solid rgba(197,165,90,0.25)">
                </div>
                <p class="mt-1 text-[10px] text-gray-500">Kosongkan kalau nggak ada anak-anak.</p>
                @error('min_age') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Duration max --}}
            <div>
                <label class="block text-[11px] md:text-xs font-bold uppercase tracking-[0.2em] mb-2" style="color: #C5A55A">
                    Maks durasi (menit)
                </label>
                <input type="number"
                       name="duration_max_minutes"
                       value="{{ $durationVal }}"
                       min="30" max="360" step="5"
                       placeholder="cth. 150"
                       class="w-full bg-transparent text-white placeholder-gray-600 px-3 py-2.5 text-sm rounded-lg focus:outline-none"
                       style="background: rgba(0,0,0,0.4); border: 1px solid rgba(197,165,90,0.25)">
                <p class="mt-1 text-[10px] text-gray-500">Kosongkan kalau bebas.</p>
                @error('duration_max_minutes') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Language --}}
            <div>
                <label class="block text-[11px] md:text-xs font-bold uppercase tracking-[0.2em] mb-2" style="color: #C5A55A">
                    Bahasa pilihan
                </label>
                <select name="language"
                        class="w-full bg-black text-white px-3 py-2.5 text-sm rounded-lg focus:outline-none"
                        style="background: rgba(0,0,0,0.4); border: 1px solid rgba(197,165,90,0.25)">
                    <option value="">— Bebas —</option>
                    @foreach($languages as $label => $code)
                        <option value="{{ $code }}" @selected($languageVal === $code)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('language') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Mood --}}
            <div>
                <label class="block text-[11px] md:text-xs font-bold uppercase tracking-[0.2em] mb-2" style="color: #C5A55A">
                    Mood malam ini
                </label>
                <select name="mood"
                        class="w-full bg-black text-white px-3 py-2.5 text-sm rounded-lg focus:outline-none"
                        style="background: rgba(0,0,0,0.4); border: 1px solid rgba(197,165,90,0.25)">
                    <option value="">— Bebas —</option>
                    @foreach($moods as $mood)
                        <option value="{{ $mood }}" @selected($moodVal === $mood)>{{ ucfirst(str_replace('-', ' ', $mood)) }}</option>
                    @endforeach
                </select>
                @error('mood') <p class="mt-1 text-xs text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- ── Submit ──────────────────────────────────────────── --}}
        <div class="flex items-center justify-center pt-2">
            <button type="submit"
                    x-bind:disabled="selected.length === 0"
                    x-bind:class="selected.length === 0 ? 'opacity-50 cursor-not-allowed' : 'hover:opacity-95'"
                    class="px-6 py-3 rounded-lg font-bold text-black text-sm inline-flex items-center gap-2 transition-all"
                    style="background: linear-gradient(135deg, #C5A55A, #E8D5A3)">
                <x-icon name="sparkles" :size="14" />
                <span>Cariin film untuk kami</span>
            </button>
        </div>
    </div>
</form>
