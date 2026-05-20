<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\HelpArticle;
use App\Models\HelpCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds 4 starter categories + 6 example help articles so the public
 * /help landing page renders something the moment migrations finish.
 *
 * Idempotent: every row uses firstOrCreate keyed on slug — re-running
 * the seeder never duplicates content and never overwrites an admin's
 * post-seed edits.
 */
class HelpSeeder extends Seeder
{
    public function run(): void
    {
        // Author defaults to the seeded admin so the "Authored by"
        // attribution makes sense on first install.
        $authorId = User::query()->where('email', 'admin@gmail.com')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        // ── Categories ────────────────────────────────────────────────
        $categories = [
            [
                'slug'        => 'getting-started',
                'name'        => 'Getting Started',
                'description' => 'Mulai perjalanan menonton Anda di FLiK — pendaftaran, profil, dan tur fitur utama.',
                'icon'        => 'sparkles',
                'sort_order'  => 10,
            ],
            [
                'slug'        => 'subscriptions',
                'name'        => 'Langganan & Pembayaran',
                'description' => 'Paket, pembayaran, kode promo, dan kebijakan pengembalian dana.',
                'icon'        => 'coin',
                'sort_order'  => 20,
            ],
            [
                'slug'        => 'playback',
                'name'        => 'Pemutaran & Streaming',
                'description' => 'Masalah pemutaran, kualitas video, subtitle, dan dukungan perangkat.',
                'icon'        => 'film',
                'sort_order'  => 30,
            ],
            [
                'slug'        => 'account',
                'name'        => 'Akun & Privasi',
                'description' => 'Kata sandi, notifikasi, perangkat aktif, dan pengelolaan data pribadi.',
                'icon'        => 'shield',
                'sort_order'  => 40,
            ],
        ];

        $catIdBySlug = [];
        foreach ($categories as $row) {
            $cat = HelpCategory::firstOrCreate(['slug' => $row['slug']], $row);
            $catIdBySlug[$row['slug']] = $cat->id;
        }

        // ── Articles ──────────────────────────────────────────────────
        $articles = [
            [
                'slug'        => 'cara-mendaftar-akun-flik',
                'title'       => 'Cara Mendaftar Akun FLiK',
                'category'    => 'getting-started',
                'excerpt'     => 'Buat akun FLiK dalam kurang dari satu menit menggunakan email atau akun Google Anda.',
                'tags'        => ['pendaftaran', 'akun', 'google'],
                'body'        => <<<MD
Selamat datang di FLiK! Mendaftar akun gratis hanya butuh waktu kurang dari satu menit.

## Langkah-langkah

1. Buka halaman utama FLiK lalu klik tombol **Daftar** di pojok kanan atas.
2. Masukkan nama, email aktif, dan kata sandi minimal 8 karakter. Atau klik **Lanjutkan dengan Google** untuk pendaftaran kilat.
3. Cek inbox email Anda dan klik tautan verifikasi yang kami kirim.
4. Setelah verifikasi berhasil, Anda akan otomatis masuk dan diarahkan ke kuis selera film singkat.
5. Jawab tiga pertanyaan kuis untuk mendapatkan rekomendasi yang lebih akurat. Anda bisa melewati ini dan mengisinya nanti.

## Catatan

Pastikan email yang Anda pakai aktif — beberapa notifikasi penting seperti tagihan langganan dan reset kata sandi dikirim ke email tersebut.
MD,
            ],
            [
                'slug'        => 'memilih-paket-langganan-terbaik',
                'title'       => 'Memilih Paket Langganan yang Tepat',
                'category'    => 'subscriptions',
                'excerpt'     => 'Bandingkan paket Basic, Standard, dan Premium untuk menemukan yang paling sesuai kebutuhan tontonan Anda.',
                'tags'        => ['paket', 'langganan', 'harga'],
                'body'        => <<<MD
FLiK menawarkan tiga paket langganan yang dirancang untuk pola tonton yang berbeda.

## Langkah-langkah

1. Buka halaman **Paket Langganan** dari menu utama.
2. Bandingkan jumlah perangkat aktif bersamaan, kualitas video maksimum, dan akses konten eksklusif untuk setiap paket.
3. Pilih paket yang paling cocok lalu klik **Pilih Paket**.
4. Pilih metode pembayaran (kartu kredit, e-wallet, atau transfer bank) lalu konfirmasi pembayaran melalui Midtrans.
5. Akses penuh aktif segera setelah pembayaran berhasil — tidak perlu menunggu konfirmasi manual.

## Catatan

Anda dapat berpindah paket kapan saja melalui halaman **Akun → Langganan Saya**. Perubahan ke paket yang lebih tinggi berlaku langsung, sementara penurunan ke paket lebih rendah berlaku di periode penagihan berikutnya.
MD,
            ],
            [
                'slug'        => 'mengaktifkan-kode-promo',
                'title'       => 'Cara Mengaktifkan Kode Promo',
                'category'    => 'subscriptions',
                'excerpt'     => 'Tukarkan kode promo Anda untuk mendapatkan diskon atau bonus periode langganan.',
                'tags'        => ['promo', 'diskon', 'kode'],
                'body'        => <<<MD
Punya kode promo dari kampanye atau referral teman? Berikut cara mengaktifkannya.

## Langkah-langkah

1. Masuk ke akun Anda dan buka **Akun → Promo Codes**.
2. Masukkan kode promo persis seperti yang diberikan (case-sensitive).
3. Klik **Tukar Sekarang**. Diskon akan langsung terlihat pada halaman checkout pada pembayaran berikutnya.
4. Jika kode berlaku untuk perpanjangan otomatis, periode bonus akan ditambahkan di akhir siklus langganan aktif Anda.

## Catatan

Beberapa kode promo memiliki batas waktu atau hanya berlaku untuk pengguna baru. Pesan kesalahan di formulir akan menjelaskan alasan kode tidak bisa dipakai.
MD,
            ],
            [
                'slug'        => 'mengatasi-video-buffer',
                'title'       => 'Bagaimana Mengatasi Video yang Sering Buffer?',
                'category'    => 'playback',
                'excerpt'     => 'Langkah cepat ketika video FLiK Anda berhenti memuat di tengah pemutaran.',
                'tags'        => ['playback', 'buffer', 'jaringan'],
                'body'        => <<<MD
Video yang sering berhenti untuk memuat biasanya disebabkan oleh koneksi internet atau perangkat pemutaran.

## Langkah-langkah

1. Jalankan tes kecepatan internet — FLiK butuh minimal 5 Mbps untuk kualitas HD dan 25 Mbps untuk 4K.
2. Tutup aplikasi lain yang berjalan di latar belakang dan menyedot bandwidth.
3. Pindah lebih dekat ke router atau gunakan koneksi kabel jika memungkinkan.
4. Klik ikon roda gigi di pemutar dan turunkan kualitas video menjadi 720p atau lebih rendah.
5. Refresh halaman pemutar. Jika menggunakan aplikasi seluler, tutup paksa lalu buka kembali.

## Catatan

Pada koneksi seluler yang tidak stabil, aktifkan mode **Auto Quality** agar pemutar otomatis menyesuaikan kualitas berdasarkan bandwidth aktual.
MD,
            ],
            [
                'slug'        => 'mengubah-bahasa-subtitle',
                'title'       => 'Cara Mengubah Bahasa Subtitle',
                'category'    => 'playback',
                'excerpt'     => 'FLiK mendukung 69 bahasa subtitle — atur preferensi default atau pilih per film.',
                'tags'        => ['subtitle', 'bahasa', 'pemutaran'],
                'body'        => <<<MD
Setiap film di FLiK dilengkapi subtitle yang di-generate dan diterjemahkan oleh AI, kemudian diverifikasi tim editorial.

## Langkah-langkah

1. Saat memutar film, klik ikon **CC** atau ikon balon teks di sudut kanan bawah pemutar.
2. Pilih bahasa dari daftar — Indonesia menjadi default jika tersedia, diikuti Inggris.
3. Untuk mengatur preferensi permanen, buka **Akun → Preferensi → Bahasa Subtitle Default**.
4. Bahasa yang Anda pilih akan otomatis aktif di setiap film yang menyediakannya.

## Catatan

Beberapa film klasik atau independen mungkin belum memiliki seluruh 69 bahasa. Jika subtitle bahasa pilihan tidak ada, pemutar akan jatuh kembali ke bahasa default kedua Anda.
MD,
            ],
            [
                'slug'        => 'menghapus-akun-permanen',
                'title'       => 'Cara Menghapus Akun FLiK Secara Permanen',
                'category'    => 'account',
                'excerpt'     => 'Pelajari proses penghapusan akun dan data pribadi sesuai UU Perlindungan Data Pribadi.',
                'tags'        => ['privasi', 'hapus akun', 'pdp'],
                'body'        => <<<MD
Anda berhak meminta penghapusan akun dan data pribadi sesuai UU Perlindungan Data Pribadi (UU PDP).

## Langkah-langkah

1. Pastikan langganan aktif sudah berakhir atau dibatalkan — akun dengan langganan aktif tidak bisa dihapus.
2. Buka **Akun → Privasi → Hapus Akun Saya**.
3. Konfirmasi identitas dengan memasukkan kata sandi.
4. Pilih alasan penghapusan (opsional, membantu kami memperbaiki layanan).
5. Klik **Ajukan Penghapusan**. Permintaan akan diproses dalam maksimal 30 hari kerja.

## Catatan

Beberapa data wajib disimpan untuk kepatuhan pajak dan audit selama 5 tahun, sesuai aturan yang berlaku. Data ini dianonimkan sehingga tidak lagi bisa dikaitkan dengan identitas Anda.
MD,
            ],
        ];

        foreach ($articles as $art) {
            $catId = $catIdBySlug[$art['category']] ?? null;

            HelpArticle::firstOrCreate(
                ['slug' => $art['slug']],
                [
                    'title'            => $art['title'],
                    'excerpt'          => $art['excerpt'],
                    'body'             => $art['body'],
                    'category_id'      => $catId,
                    'tags'             => $art['tags'],
                    'status'           => HelpArticle::STATUS_PUBLISHED,
                    'author_user_id'   => $authorId,
                    'last_reviewed_at' => now(),
                ]
            );
        }
    }
}
