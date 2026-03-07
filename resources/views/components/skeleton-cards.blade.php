{{-- Skeleton movie card --}}
@props(['count' => 6])

<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-{{ min($count, 6) }} gap-4">
    @for($i = 0; $i < $count; $i++)
    <div class="animate-pulse">
        <div class="aspect-[2/3] rounded-lg" style="background:linear-gradient(90deg,#1a1a1a 25%,#252525 50%,#1a1a1a 75%);background-size:400% 100%;animation:shimmer 1.5s infinite"></div>
        <div class="mt-2 h-3 rounded" style="background:#1a1a1a;width:75%"></div>
        <div class="mt-1 h-2.5 rounded" style="background:#1a1a1a;width:40%"></div>
    </div>
    @endfor
</div>

<style>
@keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
}
</style>
