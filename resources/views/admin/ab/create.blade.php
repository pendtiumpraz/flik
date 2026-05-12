<x-admin.layout title="Create A/B Test">
    <div class="max-w-2xl space-y-6">
        <header>
            <h1 class="text-3xl font-bold" style="color:#C5A55A">New Experiment</h1>
            <p class="text-sm text-gray-400 mt-1">Define variants with weights — sticky assignment kicks in once you Start.</p>
        </header>

        @if ($errors->any())
            <div class="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-red-300 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.ab-tests.store') }}" class="space-y-4"
              x-data="{ variants: [{key:'control', weight:50}, {key:'variant_a', weight:50}] }">
            @csrf

            <label class="block">
                <span class="text-gray-300 text-sm">Name</span>
                <input name="name" required value="{{ old('name') }}"
                       class="w-full mt-1 px-3 py-2 bg-zinc-900 border border-white/10 rounded text-white" />
            </label>

            <label class="block">
                <span class="text-gray-300 text-sm">Slug <span class="text-gray-500">(optional)</span></span>
                <input name="slug" value="{{ old('slug') }}"
                       class="w-full mt-1 px-3 py-2 bg-zinc-900 border border-white/10 rounded text-white" />
            </label>

            <label class="block">
                <span class="text-gray-300 text-sm">Hypothesis</span>
                <textarea name="hypothesis" rows="3"
                          class="w-full mt-1 px-3 py-2 bg-zinc-900 border border-white/10 rounded text-white">{{ old('hypothesis') }}</textarea>
            </label>

            <div class="space-y-2">
                <span class="text-gray-300 text-sm">Variants</span>
                <template x-for="(v, idx) in variants" :key="idx">
                    <div class="flex gap-2 items-center">
                        <input :name="`variant_keys[]`" x-model="v.key" required
                               placeholder="key" class="flex-1 px-3 py-2 bg-zinc-900 border border-white/10 rounded text-white" />
                        <input :name="`variant_weights[]`" x-model.number="v.weight" type="number" min="0" required
                               placeholder="weight" class="w-24 px-3 py-2 bg-zinc-900 border border-white/10 rounded text-white" />
                        <button type="button" @click="variants.splice(idx, 1)"
                                x-show="variants.length > 2"
                                class="px-3 py-2 text-red-400 hover:text-red-300">×</button>
                    </div>
                </template>
                <button type="button" @click="variants.push({key:'', weight:50})"
                        class="text-sm text-[#C5A55A] hover:underline">+ Add variant</button>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="start_now" value="1" class="accent-[#C5A55A]" />
                Start immediately after creation
            </label>

            <div class="flex gap-2">
                <button type="submit"
                        class="px-4 py-2 rounded bg-[#C5A55A] text-black font-semibold hover:bg-[#d4b76a]">Create</button>
                <a href="{{ route('admin.ab-tests.index') }}"
                   class="px-4 py-2 rounded border border-white/15 text-gray-300 hover:border-white/30">Cancel</a>
            </div>
        </form>
    </div>
</x-admin.layout>
