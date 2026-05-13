<x-layout title="Laporkan Kerentanan — FLiK"
          description="Form pelaporan kerentanan keamanan FLiK. Laporanmu akan diteruskan ke tim keamanan dengan SLA triage 48 jam.">
    <div class="min-h-screen bg-black pt-24 pb-20">
        <div class="container mx-auto px-4 md:px-8 lg:px-16 max-w-2xl">

            <!-- Heading -->
            <div class="text-center mb-10">
                <p class="text-xs uppercase tracking-[0.3em] mb-3" style="color:#C5A55A">Security</p>
                <h1 class="font-heading text-3xl md:text-5xl font-bold text-white">Laporkan Kerentanan</h1>
                <p class="text-gray-400 mt-3 text-base">Triage dalam 48 jam. Baca <a href="{{ route('security.policy') }}" class="underline hover:text-white" style="color:#C5A55A">kebijakan disclosure</a> sebelum mengirim.</p>
            </div>

            <!-- Inline success / error -->
            @if (session('success'))
                <div class="mb-6 rounded-lg px-5 py-4 text-sm" style="background:rgba(197,165,90,0.12);border:1px solid rgba(197,165,90,0.4);color:#E8D5A3">
                    {{ session('success') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-6 rounded-lg px-5 py-4 text-sm text-red-200" style="background:rgba(220,38,38,0.12);border:1px solid rgba(220,38,38,0.4)">
                    <p class="font-semibold mb-1">Periksa kembali field berikut:</p>
                    <ul class="list-disc list-inside space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Form card -->
            <form method="POST" action="{{ route('security.report.submit') }}"
                  class="rounded-2xl p-7 md:p-9 space-y-5"
                  style="background: rgba(255,255,255,0.03); border: 1px solid rgba(197,165,90,0.18)">
                @csrf

                <!-- Reporter name (optional) -->
                <div>
                    <label for="reporter_name" class="block text-sm font-semibold text-gray-200 mb-2">
                        Nama <span class="text-gray-500 font-normal">(opsional)</span>
                    </label>
                    <input type="text" name="reporter_name" id="reporter_name"
                           value="{{ old('reporter_name') }}"
                           maxlength="120"
                           class="w-full rounded-lg px-4 py-2.5 text-white placeholder-gray-500 focus:outline-none transition"
                           style="background:rgba(0,0,0,0.4);border:1px solid rgba(197,165,90,0.25)"
                           onfocus="this.style.borderColor='#C5A55A'"
                           onblur="this.style.borderColor='rgba(197,165,90,0.25)'"
                           placeholder="Boleh anonim — untuk hall of fame jika laporan diterima">
                </div>

                <!-- Reporter email -->
                <div>
                    <label for="reporter_email" class="block text-sm font-semibold text-gray-200 mb-2">
                        Email <span style="color:#C5A55A">*</span>
                    </label>
                    <input type="email" name="reporter_email" id="reporter_email"
                           value="{{ old('reporter_email') }}"
                           required maxlength="190"
                           class="w-full rounded-lg px-4 py-2.5 text-white placeholder-gray-500 focus:outline-none transition"
                           style="background:rgba(0,0,0,0.4);border:1px solid rgba(197,165,90,0.25)"
                           onfocus="this.style.borderColor='#C5A55A'"
                           onblur="this.style.borderColor='rgba(197,165,90,0.25)'"
                           placeholder="kamu@email.com">
                    <p class="text-xs text-gray-500 mt-1.5">Untuk komunikasi follow-up. Tidak akan dipublikasikan.</p>
                </div>

                <!-- Severity -->
                <div>
                    <label for="severity" class="block text-sm font-semibold text-gray-200 mb-2">
                        Tingkat keparahan <span style="color:#C5A55A">*</span>
                    </label>
                    <select name="severity" id="severity" required
                            class="w-full rounded-lg px-4 py-2.5 text-white focus:outline-none transition"
                            style="background:rgba(0,0,0,0.4);border:1px solid rgba(197,165,90,0.25)"
                            onfocus="this.style.borderColor='#C5A55A'"
                            onblur="this.style.borderColor='rgba(197,165,90,0.25)'">
                        <option value="" disabled {{ old('severity') ? '' : 'selected' }}>Pilih tingkat keparahan</option>
                        <option value="low" {{ old('severity') === 'low' ? 'selected' : '' }}>Low — info disclosure ringan</option>
                        <option value="medium" {{ old('severity') === 'medium' ? 'selected' : '' }}>Medium — IDOR / XSS terbatas</option>
                        <option value="high" {{ old('severity') === 'high' ? 'selected' : '' }}>High — SQLi / auth bypass</option>
                        <option value="critical" {{ old('severity') === 'critical' ? 'selected' : '' }}>Critical — RCE / DRM bypass</option>
                    </select>
                </div>

                <!-- Title -->
                <div>
                    <label for="title" class="block text-sm font-semibold text-gray-200 mb-2">
                        Judul singkat <span style="color:#C5A55A">*</span>
                    </label>
                    <input type="text" name="title" id="title"
                           value="{{ old('title') }}"
                           required maxlength="200"
                           class="w-full rounded-lg px-4 py-2.5 text-white placeholder-gray-500 focus:outline-none transition"
                           style="background:rgba(0,0,0,0.4);border:1px solid rgba(197,165,90,0.25)"
                           onfocus="this.style.borderColor='#C5A55A'"
                           onblur="this.style.borderColor='rgba(197,165,90,0.25)'"
                           placeholder="Contoh: Stored XSS pada komentar movie">
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-sm font-semibold text-gray-200 mb-2">
                        Detail kerentanan <span style="color:#C5A55A">*</span>
                    </label>
                    <textarea name="description" id="description"
                              required maxlength="10000" rows="10"
                              class="w-full rounded-lg px-4 py-3 text-white placeholder-gray-500 focus:outline-none transition font-mono text-sm"
                              style="background:rgba(0,0,0,0.4);border:1px solid rgba(197,165,90,0.25)"
                              onfocus="this.style.borderColor='#C5A55A'"
                              onblur="this.style.borderColor='rgba(197,165,90,0.25)'"
                              placeholder="- Langkah reproduksi&#10;- Endpoint / komponen yang terdampak&#10;- Dampak yang mungkin&#10;- Proof of concept (URL, payload, screenshot link)">{{ old('description') }}</textarea>
                    <p class="text-xs text-gray-500 mt-1.5">Maks 10.000 karakter. Sertakan PoC bila ada — link ke gist/video lebih disukai daripada attachment.</p>
                </div>

                <!-- Submit -->
                <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 pt-2">
                    <a href="{{ route('security.policy') }}" class="text-sm text-gray-400 hover:text-white transition">
                        &larr; Kembali ke kebijakan
                    </a>
                    <button type="submit"
                            class="px-6 py-3 rounded-lg font-semibold text-black transition-transform hover:scale-[1.02]"
                            style="background:linear-gradient(135deg,#C5A55A,#E8D5A3)">
                        Kirim Laporan
                    </button>
                </div>
            </form>

            <!-- Reassurance footer -->
            <p class="text-center text-xs text-gray-500 mt-6">
                Laporan dienkripsi saat transit (TLS) dan diteruskan ke tim keamanan FLiK. Kami tidak akan menempuh jalur hukum terhadap riset iktikad baik — lihat
                <a href="{{ route('security.policy') }}" class="underline hover:text-gray-300">Safe Harbor</a>.
            </p>
        </div>
    </div>
</x-layout>
