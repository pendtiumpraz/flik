@if (session()->has('success'))
<div x-data="{ show: true }"
     x-init="setTimeout(() => show = false, 4000)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-y-4"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 translate-y-4"
     class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[9999] rounded-xl px-6 py-3 text-sm font-semibold text-black shadow-2xl"
     style="background:linear-gradient(135deg,#C5A55A,#E8D5A3);min-width:200px;text-align:center">
    <p>{{ session('success') }}</p>
</div>
@endif

@if (session()->has('error'))
<div x-data="{ show: true }"
     x-init="setTimeout(() => show = false, 5000)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-y-4"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 translate-y-4"
     class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[9999] rounded-xl px-6 py-3 text-sm font-semibold text-white shadow-2xl"
     style="background:rgba(220,38,38,0.95);min-width:200px;text-align:center">
    <p>{{ session('error') }}</p>
</div>
@endif
