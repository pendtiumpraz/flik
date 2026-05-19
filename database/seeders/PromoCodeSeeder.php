<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PromoCode;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds a small set of canonical promo codes so /admin/promo-codes
 * is not empty on a fresh install. Idempotent via updateOrCreate
 * keyed on the code — re-seeding tweaks the parameters but never
 * duplicates the row.
 *
 * - WELCOME10  : evergreen 10% off, first-1000 redemptions.
 * - LAUNCH2026 : 50% off first month, capped to 2026 launch window.
 * - STUDENT    : flat Rp 20.000 off, no expiry — works on any plan.
 */
class PromoCodeSeeder extends Seeder
{
    public function run(): void
    {
        $codes = [
            [
                'code'                    => 'WELCOME10',
                'name'                    => 'Welcome 10% off',
                'description'             => 'Diskon 10% untuk pelanggan baru.',
                'discount_type'           => PromoCode::TYPE_PERCENTAGE,
                'discount_value'          => 10,
                'applies_to_plans'        => null, // all plans
                'max_uses'                => 1000,
                'max_uses_per_user'       => 1,
                'min_subscription_months' => 1,
                'starts_at'               => null,
                'expires_at'              => null,
                'is_active'               => true,
                'is_first_time_only'      => true,
            ],
            [
                'code'                    => 'LAUNCH2026',
                'name'                    => 'Launch promo — 50% off first month',
                'description'             => 'Promo peluncuran FLiK 2026. Berlaku hingga akhir 2026.',
                'discount_type'           => PromoCode::TYPE_PERCENTAGE,
                'discount_value'          => 50,
                'applies_to_plans'        => null,
                'max_uses'                => null, // unlimited within window
                'max_uses_per_user'       => 1,
                'min_subscription_months' => 1,
                'starts_at'               => null,
                'expires_at'              => Carbon::create(2026, 12, 31, 23, 59, 59),
                'is_active'               => true,
                'is_first_time_only'      => true,
            ],
            [
                'code'                    => 'STUDENT',
                'name'                    => 'Student Discount',
                'description'             => 'Diskon flat Rp 20.000 untuk pelajar/mahasiswa.',
                'discount_type'           => PromoCode::TYPE_FIXED,
                'discount_value'          => 20000,
                'applies_to_plans'        => null,
                'max_uses'                => null,
                'max_uses_per_user'       => 1,
                'min_subscription_months' => 1,
                'starts_at'               => null,
                'expires_at'              => null,
                'is_active'               => true,
                'is_first_time_only'      => false,
            ],
        ];

        foreach ($codes as $data) {
            PromoCode::updateOrCreate(
                ['code' => $data['code']],
                $data
            );
        }
    }
}
