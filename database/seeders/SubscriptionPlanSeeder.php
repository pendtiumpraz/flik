<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Pricing source: PITCH_DECK.md v2.1 (slide 13 + section 6.7).
     *
     * Strategy: 4 plans only (Free + 3 paid). Annual computed at UI level
     * via billing toggle (price × 12 × 0.80 for 20% discount).
     * No separate annual rows — keeps catalog clean & SaaS-grade.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'price' => 0,
                'billing_cycle' => 'monthly',
                'max_screens' => 1,
                'video_quality' => '480p',
                'ads_free' => false,
                'download_enabled' => false,
                'features' => json_encode([
                    'Akses film terbatas (rotated weekly)',
                    'Kualitas SD 480p',
                    '1 perangkat',
                    'Dengan iklan',
                    'No download',
                ]),
                'sort_order' => 1,
            ],

            [
                'name' => 'Basic',
                'slug' => 'basic',
                'price' => 39000,
                'billing_cycle' => 'monthly',
                'max_screens' => 2,
                'video_quality' => '480p',
                'ads_free' => false,
                'download_enabled' => false,
                'features' => json_encode([
                    'Akses semua 400+ film klasik',
                    'Kualitas SD 480p',
                    '2 perangkat sekaligus',
                    'Dengan iklan terbatas',
                    'No download',
                ]),
                'sort_order' => 2,
            ],

            [
                'name' => 'Premium',
                'slug' => 'premium',
                'price' => 79000,
                'billing_cycle' => 'monthly',
                'max_screens' => 4,
                'video_quality' => '1080p',
                'ads_free' => true,
                'download_enabled' => true,
                'features' => json_encode([
                    'Akses semua film',
                    'Kualitas Full HD 1080p',
                    '4 perangkat sekaligus',
                    'Tanpa iklan',
                    'Download offline',
                    'Early access film baru di-restore',
                    'AI personalized recommendations',
                ]),
                'sort_order' => 3,
            ],

            [
                'name' => 'Family',
                'slug' => 'family',
                'price' => 129000,
                'billing_cycle' => 'monthly',
                'max_screens' => 6,
                'video_quality' => '4K',
                'ads_free' => true,
                'download_enabled' => true,
                'features' => json_encode([
                    'Akses semua film + konten eksklusif',
                    'Kualitas 4K Ultra HD (saat tersedia)',
                    '6 perangkat sekaligus',
                    '4 profile keluarga',
                    'Kids safe mode (filter umur)',
                    'Tanpa iklan',
                    'Download offline tanpa batas',
                    'Behind-the-scenes & restoration documentary',
                    'AI personalized recommendations',
                    'Dolby Atmos audio',
                ]),
                'sort_order' => 4,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }

        // Deactivate any plan no longer in this catalog (legacy ultra, separate annual rows, etc.)
        SubscriptionPlan::whereNotIn('slug', collect($plans)->pluck('slug'))
            ->update(['is_active' => false]);
    }
}
