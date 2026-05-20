<x-layout title="Create List — FLiK">
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-8 max-w-3xl">

            <div class="mb-6">
                <a href="{{ route('user-lists.mine') }}"
                   class="inline-flex items-center gap-1 text-sm text-gray-400 hover:text-[#C5A55A] transition-colors">
                    <x-icon name="chevron-left" :size="14" /> Kembali ke My Lists
                </a>
                <h1 class="mt-3 font-heading text-2xl md:text-3xl font-bold" style="color: #C5A55A">
                    Buat List Baru
                </h1>
                <p class="mt-1 text-sm text-gray-400">Kurasi koleksi film favorit kamu dalam satu list yang bisa dibagikan.</p>
            </div>

            <form method="POST" action="{{ route('user-lists.store') }}"
                  class="rounded-xl p-6 md:p-8 space-y-6"
                  style="background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid rgba(197,165,90,0.18);">
                @csrf

                @include('lists.partials.form-fields', [
                    'list' => null,
                    'visibilities' => $visibilities,
                ])

                <div class="flex items-center justify-end gap-3 pt-2 border-t" style="border-color: rgba(197,165,90,0.12);">
                    <a href="{{ route('user-lists.mine') }}"
                       class="px-4 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white">
                        Batal
                    </a>
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold text-black"
                            style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                        <x-icon name="plus" :size="14" /> Buat List
                    </button>
                </div>
            </form>
        </div>
    </div>
    <x-footer />
</x-layout>
