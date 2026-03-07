<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
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
                    'Akses film terbatas',
                    'Kualitas 480p',
                    '1 perangkat',
                    'Dengan iklan',
                ]),
                'sort_order' => 1,
            ],
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'price' => 29000,
                'billing_cycle' => 'monthly',
                'max_screens' => 1,
                'video_quality' => '720p',
                'ads_free' => true,
                'download_enabled' => false,
                'features' => json_encode([
                    'Akses semua film',
                    'Kualitas HD 720p',
                    '1 perangkat',
                    'Tanpa iklan',
                ]),
                'sort_order' => 2,
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'price' => 59000,
                'billing_cycle' => 'monthly',
                'max_screens' => 3,
                'video_quality' => '1080p',
                'ads_free' => true,
                'download_enabled' => true,
                'features' => json_encode([
                    'Akses semua film',
                    'Kualitas Full HD 1080p',
                    '3 perangkat sekaligus',
                    'Tanpa iklan',
                    'Download offline',
                    'Early access film baru',
                ]),
                'sort_order' => 3,
            ],
            [
                'name' => 'Ultra',
                'slug' => 'ultra',
                'price' => 99000,
                'billing_cycle' => 'monthly',
                'max_screens' => 5,
                'video_quality' => '4K',
                'ads_free' => true,
                'download_enabled' => true,
                'features' => json_encode([
                    'Akses semua film + eksklusif',
                    'Kualitas 4K Ultra HD + HDR',
                    '5 perangkat sekaligus',
                    'Tanpa iklan',
                    'Download offline tanpa batas',
                    'Early access film baru',
                    'Behind the scenes content',
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
    }
}
