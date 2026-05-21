<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds 3 starter blog categories (News / Reviews / Lists) plus 2 sample
 * posts so the public /blog landing page renders something meaningful the
 * moment migrations finish.
 *
 * Idempotent: every row uses firstOrCreate keyed on slug — re-running the
 * seeder never duplicates content and never overwrites an editor's
 * post-seed edits.
 *
 * Mirrors HelpSeeder's shape for consistency across the editorial surfaces.
 */
class BlogSeeder extends Seeder
{
    public function run(): void
    {
        // Author defaults to the seeded admin so the byline makes sense
        // on first install; falls back to the lowest-id user if the admin
        // hasn't been seeded yet (defensive — DatabaseSeeder runs admin first).
        $authorId = User::query()->where('email', 'admin@gmail.com')->value('id')
            ?? User::query()->orderBy('id')->value('id');

        // ── Categories ────────────────────────────────────────────────
        $categories = [
            [
                'slug'       => 'news',
                'name'       => 'News',
                'color'      => '#C5A55A',
                'sort_order' => 10,
            ],
            [
                'slug'       => 'reviews',
                'name'       => 'Reviews',
                'color'      => '#E8D5A3',
                'sort_order' => 20,
            ],
            [
                'slug'       => 'lists',
                'name'       => 'Lists',
                'color'      => '#8b7239',
                'sort_order' => 30,
            ],
        ];

        $catIdBySlug = [];
        foreach ($categories as $row) {
            $cat = BlogCategory::firstOrCreate(['slug' => $row['slug']], $row);
            $catIdBySlug[$row['slug']] = $cat->id;
        }

        // ── Sample posts ──────────────────────────────────────────────
        // Author IDs are required — skip seeding posts when no user exists
        // (e.g. on a fully blank DB) rather than tripping the FK constraint.
        if (! $authorId) {
            return;
        }

        $posts = [
            [
                'slug'       => 'selamat-datang-di-blog-flik',
                'title'      => 'Selamat Datang di Blog FLiK — Rumah Cerita Sinema Indonesia',
                'category'   => 'news',
                'excerpt'    => 'Mulai hari ini, FLiK punya rumah baru untuk catatan, review, dan kurasi tontonan dari tim editorial kami.',
                'is_featured'=> true,
                'body'       => <<<MD
Halo, sinefil! Mulai hari ini blog FLiK resmi mengudara sebagai rumah baru untuk catatan, review, dan kurasi tontonan dari tim editorial kami.

## Apa yang akan kamu temukan di sini

- **News** — berita rilis film terbaru, festival, dan kabar dari industri sinema Indonesia maupun dunia.
- **Reviews** — ulasan jujur dengan sudut pandang sinematografi, naratif, dan emosi.
- **Lists** — kurasi tematik: film weekend, drama keluarga, sci-fi yang membuat berpikir, dan banyak lagi.

## Mengapa blog?

Banyak film di FLiK lebih kaya saat dilihat dengan konteks — pengaruh sutradara, momen produksi, atau dialog dengan film-film sejenis. Blog ini hadir untuk menjembatani tontonan dengan cerita di baliknya.

Selamat membaca, dan selamat menonton. **Tim FLiK Editorial**
MD,
            ],
            [
                'slug'       => 'lima-film-indonesia-wajib-tonton-akhir-pekan-ini',
                'title'      => 'Lima Film Indonesia yang Wajib Kamu Tonton Akhir Pekan Ini',
                'category'   => 'lists',
                'excerpt'    => 'Kurasi singkat: lima judul dari katalog FLiK yang bisa jadi pelarian sinematik akhir pekanmu — drama, komedi, hingga horor.',
                'is_featured'=> false,
                'body'       => <<<MD
Akhir pekan tanpa rencana? Berikut lima judul dari katalog FLiK yang siap menemani:

1. **Pilihan dramatis** untuk yang ingin menangis sebentar dan lega setelahnya.
2. **Komedi keluarga** yang aman ditonton bareng siapa saja — termasuk tante yang baru tiba dari kampung.
3. **Horor lokal** yang membuktikan bahwa rumah tua dan suara pintu tetap rumus yang manjur.
4. **Drama remaja** dengan soundtrack yang akan menempel di kepala sampai Senin pagi.
5. **Film festival** untuk yang ingin merasa pintar sambil menyeruput kopi.

## Cara menonton

Buka halaman film, tekan **Play**, dan biarkan FLiK menyesuaikan kualitas dengan koneksimu otomatis. Tidak yakin mau yang mana? Coba fitur **Discover by Mood** di menu navigasi atas — kami akan memilihkan satu sesuai suasana hatimu sekarang.

Selamat menonton!
MD,
            ],
        ];

        foreach ($posts as $i => $art) {
            $catId = $catIdBySlug[$art['category']] ?? null;

            BlogPost::firstOrCreate(
                ['slug' => $art['slug']],
                [
                    'title'          => $art['title'],
                    'excerpt'        => $art['excerpt'],
                    // body mutator (BlogPost::setBodyAttribute) automatically renders
                    // body_html and computes reading_minutes — don't set those by hand.
                    'body'           => $art['body'],
                    'category_id'    => $catId,
                    'author_user_id' => $authorId,
                    'status'         => BlogPost::STATUS_PUBLISHED,
                    // Stagger published_at so the listing has a stable order on
                    // a freshly-seeded site (newest first = index 0).
                    'published_at'   => now()->subDays($i + 1),
                    'is_featured'    => (bool) ($art['is_featured'] ?? false),
                ]
            );
        }
    }
}
