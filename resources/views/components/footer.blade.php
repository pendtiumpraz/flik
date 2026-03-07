<footer class="mx-auto max-w-screen-lg py-8 px-4 md:px-8 text-sm" style="color: #7c7c7c">
    <div class="my-4 flex space-x-6" style="color: #808080">
        <x-entypo-facebook class="h-6 w-6 md:h-7 md:w-7 hover:text-white transition-colors cursor-pointer" />
        <x-bi-instagram class="h-6 w-6 md:h-7 md:w-7 hover:text-white transition-colors cursor-pointer" />
        <x-bi-twitter class="h-6 w-6 md:h-7 md:w-7 hover:text-white transition-colors cursor-pointer" />
        <x-bi-youtube class="h-6 w-6 md:h-7 md:w-7 hover:text-white transition-colors cursor-pointer" />
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
        <div class="space-y-3">
            <a href="{{ route('velflix.index') }}" class="block hover:text-white transition-colors">Jelajahi Film</a>
            <a href="{{ route('plans.index') }}" class="block hover:text-white transition-colors">Paket Langganan</a>
            <div class="hover:text-white transition-colors cursor-pointer">Pusat Bantuan</div>
            <div class="hover:text-white transition-colors cursor-pointer">Hubungi Kami</div>
        </div>
        <div class="space-y-3">
            <a href="{{ route('rewards.index') }}" class="block hover:text-white transition-colors">Rewards & Coins</a>
            <div class="hover:text-white transition-colors cursor-pointer">FAQ</div>
            <div class="hover:text-white transition-colors cursor-pointer">Kebijakan Privasi</div>
        </div>
        <div class="space-y-3">
            <div class="hover:text-white transition-colors cursor-pointer">Syarat & Ketentuan</div>
            <div class="hover:text-white transition-colors cursor-pointer">Cookie Preferences</div>
            <div class="hover:text-white transition-colors cursor-pointer">Karir</div>
        </div>
        <div class="space-y-3">
            <div class="hover:text-white transition-colors cursor-pointer">Tentang FLiK</div>
            <div class="hover:text-white transition-colors cursor-pointer">Investor Relations</div>
            <div class="hover:text-white transition-colors cursor-pointer">Informasi Perusahaan</div>
        </div>
    </div>

    <div class="mt-8 text-xs text-gray-600">
        &copy; {{ date('Y') }} FLiK — Rumah Sinema Indonesia. All rights reserved.
    </div>
</footer>
