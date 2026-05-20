<x-layout title="Edit List — FLiK">
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-8 max-w-3xl">

            <div class="mb-6">
                <a href="{{ route('user-lists.show', ['user' => $owner->username ?? $owner->id, 'list' => $list->slug]) }}"
                   class="inline-flex items-center gap-1 text-sm text-gray-400 hover:text-[#C5A55A] transition-colors">
                    <x-icon name="chevron-left" :size="14" /> Kembali ke list
                </a>
                <h1 class="mt-3 font-heading text-2xl md:text-3xl font-bold" style="color: #C5A55A">
                    Edit List
                </h1>
            </div>

            <form method="POST"
                  action="{{ route('user-lists.update', ['user' => $owner->username ?? $owner->id, 'list' => $list->slug]) }}"
                  class="rounded-xl p-6 md:p-8 space-y-6"
                  style="background: linear-gradient(180deg, #1a1a1a 0%, #141414 100%); border: 1px solid rgba(197,165,90,0.18);">
                @csrf
                @method('PUT')

                @include('lists.partials.form-fields', [
                    'list' => $list,
                    'visibilities' => $visibilities,
                ])

                <div class="flex items-center justify-between gap-3 pt-2 border-t" style="border-color: rgba(197,165,90,0.12);">
                    <button type="button"
                            x-data
                            @click="if (confirm('Hapus list ini secara permanen?')) document.getElementById('delete-list-form').submit();"
                            class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm text-red-400 hover:text-red-300 hover:bg-red-500/10 transition-colors">
                        <x-icon name="x" :size="14" /> Hapus List
                    </button>
                    <div class="flex items-center gap-3">
                        <a href="{{ route('user-lists.show', ['user' => $owner->username ?? $owner->id, 'list' => $list->slug]) }}"
                           class="px-4 py-2.5 rounded-lg text-sm text-gray-300 hover:text-white">
                            Batal
                        </a>
                        <button type="submit"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold text-black"
                                style="background: linear-gradient(135deg, #C5A55A, #E8D5A3);">
                            <x-icon name="check" :size="14" /> Simpan
                        </button>
                    </div>
                </div>
            </form>

            {{-- Hidden delete form so the trash button can submit via JS confirm --}}
            <form id="delete-list-form" method="POST" class="hidden"
                  action="{{ route('user-lists.destroy', ['user' => $owner->username ?? $owner->id, 'list' => $list->slug]) }}">
                @csrf
                @method('DELETE')
            </form>
        </div>
    </div>
    <x-footer />
</x-layout>
