<x-layout>
    {{--
        Self-service "My Roles & Permissions" page.

        Renders:
          1. Super-admin sentinel banner (when applicable).
          2. "My Roles" — one card per assigned role with priority/since metadata.
          3. "My Permissions" — collapsible Alpine accordion grouped by category.

        Defensive: every collection passed in MAY be empty (pre-migration
        fresh install). The view falls back to an "no roles yet" panel
        rather than rendering broken markup.
    --}}
    <div class="min-h-screen bg-black pt-20 pb-16">
        <div class="container mx-auto px-4 md:px-16 max-w-5xl">

            {{-- Back to profile breadcrumb --}}
            <div class="mb-6 flex items-center gap-3 text-sm">
                <a href="{{ route('profile.show') }}" class="text-gray-400 hover:text-white transition-colors">
                    &larr; Back to Profile
                </a>
            </div>

            {{-- Header --}}
            <div class="mb-8">
                <h1 class="font-heading text-2xl md:text-3xl font-bold text-white">My Roles &amp; Permissions</h1>
                <p class="text-gray-500 text-sm mt-2">
                    Daftar role dan permission yang melekat ke akun Anda. Hubungi admin jika ada akses yang dirasa keliru.
                </p>
            </div>

            {{-- ━━━ Super-admin sentinel banner ━━━ --}}
            @if($isSuperAdmin)
                <div class="mb-8 rounded-2xl overflow-hidden relative"
                     style="background: linear-gradient(135deg, #2a2520 0%, #3d3018 50%, #2a2520 100%); border: 1px solid #C5A55A;">
                    <div class="p-6 md:p-8 flex flex-col md:flex-row items-center gap-5">
                        <div class="w-16 h-16 rounded-full flex items-center justify-center text-3xl"
                             style="background: linear-gradient(135deg, #C5A55A, #E8D5A3); color: #000;">
                            &#9733;
                        </div>
                        <div class="text-center md:text-left flex-1">
                            <h2 class="font-heading text-xl md:text-2xl font-bold" style="color:#C5A55A">
                                Super Admin &mdash; all permissions granted
                            </h2>
                            <p class="text-gray-300 text-sm mt-1">
                                Anda memiliki akses penuh ke seluruh fitur, termasuk role management,
                                backup, dan API keys. Setiap pengecekan permission akan otomatis
                                lolos via <code class="text-xs px-1 py-0.5 rounded" style="background:#0a0a0a">Gate::before</code>.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ━━━ My Roles ━━━ --}}
            <div class="rounded-xl overflow-hidden mb-8" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-5 flex items-center justify-between" style="border-bottom:1px solid #2a2a2a">
                    <h3 class="font-heading font-semibold text-white text-lg">My Roles</h3>
                    <span class="text-xs px-2 py-1 rounded-full font-semibold"
                          style="background:rgba(197,165,90,0.15);color:#C5A55A">
                        {{ $roles->count() }} {{ \Illuminate\Support\Str::plural('role', $roles->count()) }}
                    </span>
                </div>

                <div class="p-5">
                    @if($roles->isEmpty())
                        <p class="text-gray-600 text-sm text-center py-6">
                            Belum ada role yang ter-assign. Anda hanya memiliki akses publik
                            (browse film, watchlist, watch party).
                        </p>
                    @else
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            @foreach($roles as $role)
                                <div class="p-4 rounded-lg"
                                     style="background:#252525;border:1px solid {{ $role->is_system ? '#C5A55A33' : '#2a2a2a' }}">
                                    <div class="flex items-start justify-between gap-3 mb-2">
                                        <div>
                                            <div class="font-heading font-semibold text-white text-base">
                                                {{ $role->display_name ?? \Illuminate\Support\Str::headline($role->name) }}
                                            </div>
                                            <code class="text-xs text-gray-500">{{ $role->name }}</code>
                                        </div>
                                        @if($role->is_system)
                                            <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold uppercase tracking-wide whitespace-nowrap"
                                                  style="background:rgba(197,165,90,0.15);color:#C5A55A">
                                                System
                                            </span>
                                        @else
                                            <span class="text-[10px] px-2 py-0.5 rounded-full font-semibold uppercase tracking-wide whitespace-nowrap"
                                                  style="background:rgba(59,130,246,0.15);color:#3b82f6">
                                                Custom
                                            </span>
                                        @endif
                                    </div>

                                    @if($role->description)
                                        <p class="text-xs text-gray-400 leading-relaxed">{{ $role->description }}</p>
                                    @endif

                                    @if(!empty($role->pivot?->assigned_at))
                                        <div class="text-[11px] text-gray-600 mt-3 pt-3" style="border-top:1px solid #2a2a2a">
                                            Since
                                            <span class="text-gray-400">
                                                {{ \Illuminate\Support\Carbon::parse($role->pivot->assigned_at)->diffForHumans() }}
                                            </span>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- ━━━ My Permissions ━━━ --}}
            <div class="rounded-xl overflow-hidden" style="background:#1a1a1a;border:1px solid #2a2a2a">
                <div class="p-5 flex items-center justify-between" style="border-bottom:1px solid #2a2a2a">
                    <h3 class="font-heading font-semibold text-white text-lg">My Permissions</h3>
                    <span class="text-xs px-2 py-1 rounded-full font-semibold"
                          style="background:rgba(197,165,90,0.15);color:#C5A55A">
                        {{ $totalPermissionsCount }}
                        {{ \Illuminate\Support\Str::plural('permission', $totalPermissionsCount) }}
                    </span>
                </div>

                <div class="p-5">
                    @if($isSuperAdmin)
                        <p class="text-gray-400 text-sm text-center py-6">
                            Super Admin bypass aktif &mdash; setiap permission check otomatis lolos.
                            Daftar di bawah ini hanya menampilkan permission yang ter-attach ke role Anda.
                        </p>
                    @endif

                    @if($groupedPermissions->isEmpty())
                        <p class="text-gray-600 text-sm text-center py-6">
                            Belum ada permission yang ter-grant.
                            @if(!$isSuperAdmin)
                                Hubungi admin untuk request akses.
                            @endif
                        </p>
                    @else
                        <div class="space-y-3" x-data="{ open: '{{ $groupedPermissions->keys()->first() }}' }">
                            @foreach($groupedPermissions as $category => $perms)
                                <div class="rounded-lg overflow-hidden" style="background:#252525;border:1px solid #2a2a2a">
                                    {{-- Collapsible header --}}
                                    <button type="button"
                                            x-on:click="open === '{{ $category }}' ? open = null : open = '{{ $category }}'"
                                            class="w-full p-4 flex items-center justify-between gap-3 text-left transition-colors hover:bg-black/20">
                                        <div class="flex items-center gap-3">
                                            <span class="font-semibold text-white capitalize">
                                                {{ str_replace('_', ' ', $category) }}
                                            </span>
                                            <span class="text-xs px-2 py-0.5 rounded-full font-semibold"
                                                  style="background:rgba(197,165,90,0.15);color:#C5A55A">
                                                {{ $perms->count() }}
                                            </span>
                                        </div>
                                        <svg class="w-4 h-4 text-gray-400 transition-transform"
                                             :class="{ 'rotate-180': open === '{{ $category }}' }"
                                             fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                  d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>

                                    {{-- Collapsible body --}}
                                    <div x-show="open === '{{ $category }}'"
                                         x-cloak
                                         x-transition:enter="transition ease-out duration-150"
                                         x-transition:enter-start="opacity-0 -translate-y-1"
                                         x-transition:enter-end="opacity-100 translate-y-0"
                                         class="px-4 pb-4">
                                        <div class="space-y-2 pt-1">
                                            @foreach($perms as $perm)
                                                <div class="flex items-start gap-3 p-3 rounded"
                                                     style="background:#1a1a1a">
                                                    <span class="mt-0.5 inline-flex w-5 h-5 rounded-full items-center justify-center flex-shrink-0"
                                                          style="background:rgba(34,197,94,0.15);color:#22c55e">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                                        </svg>
                                                    </span>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="font-semibold text-white text-sm">
                                                            {{ $perm->display_name ?? \Illuminate\Support\Str::headline($perm->name) }}
                                                        </div>
                                                        <code class="text-[11px] text-gray-500 block mt-0.5">{{ $perm->name }}</code>
                                                        @if(!empty($perm->description))
                                                            <p class="text-xs text-gray-400 mt-1 leading-relaxed">
                                                                {{ $perm->description }}
                                                            </p>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Footer link --}}
            <div class="mt-8 text-center">
                <a href="{{ route('profile.show') }}"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-black"
                   style="background:#C5A55A">
                    &larr; Back to Profile
                </a>
            </div>
        </div>
    </div>
</x-layout>
