<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the runtime-editable settings registry with a comprehensive
 * starter set covering branding, social, limits, feature toggles,
 * email, legal, and AI tuning.
 *
 * Idempotent: matched on the unique `key` column via updateOrCreate.
 * On re-run, descriptive fields (`description`, `group`, `is_public`,
 * `is_secret`, `validation_rules`, `type`) ARE overwritten so the
 * canonical metadata stays current, but `value` is preserved when the
 * row already exists — we never clobber an admin's edit.
 *
 * The SettingsController also exposes a "Restore defaults" button that
 * does the opposite (forcibly resets values to these seeds).
 */
class SettingSeeder extends Seeder
{
    /**
     * Seed payload. Each row:
     *   key                 → dotted slug used by code & admin
     *   value               → default (typed; mutator handles serialization)
     *   type                → string|int|float|bool|json|array
     *   group               → admin tab bucket
     *   description         → operator-facing help text in the admin form
     *   is_public           → safe to expose in window.SITE_CONFIG etc.
     *   is_secret           → mask in admin UI (still stored plaintext)
     *   validation_rules    → Laravel rule string applied on bulk update
     *
     * @var array<int, array{key:string, value:mixed, type:string, group:string, description:string, is_public:bool, is_secret:bool, validation_rules:?string}>
     */
    private const SETTINGS = [
        // ── Branding ─────────────────────────────────────────────
        [
            'key' => 'site.name', 'value' => 'FLiK', 'type' => 'string', 'group' => 'branding',
            'description' => 'Site name shown in the header, page title, and emails.',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'required|string|max:60',
        ],
        [
            'key' => 'site.tagline', 'value' => 'Rumah Sinema Indonesia', 'type' => 'string', 'group' => 'branding',
            'description' => 'Short tagline displayed under the logo on the landing page.',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'nullable|string|max:160',
        ],
        [
            'key' => 'site.logo_url', 'value' => '/img/flik-logo.png', 'type' => 'string', 'group' => 'branding',
            'description' => 'Path or absolute URL to the brand logo (PNG/SVG).',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'nullable|string|max:255',
        ],
        [
            'key' => 'site.favicon', 'value' => '/favicon.ico', 'type' => 'string', 'group' => 'branding',
            'description' => 'Path or absolute URL to the favicon.',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'nullable|string|max:255',
        ],

        // ── Social ───────────────────────────────────────────────
        [
            'key' => 'social.twitter', 'value' => 'https://twitter.com/flik_id', 'type' => 'string', 'group' => 'social',
            'description' => 'Public Twitter/X profile URL — linked from footer & SEO meta.',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'nullable|url|max:255',
        ],
        [
            'key' => 'social.instagram', 'value' => 'https://instagram.com/flik_id', 'type' => 'string', 'group' => 'social',
            'description' => 'Public Instagram profile URL.',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'nullable|url|max:255',
        ],
        [
            'key' => 'social.tiktok', 'value' => 'https://tiktok.com/@flik_id', 'type' => 'string', 'group' => 'social',
            'description' => 'Public TikTok profile URL.',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'nullable|url|max:255',
        ],

        // ── Limits / quotas ──────────────────────────────────────
        [
            'key' => 'limits.comment_per_hour', 'value' => 30, 'type' => 'int', 'group' => 'limits',
            'description' => 'Maximum comments a single user can post per hour. Enforced by RateLimit middleware.',
            'is_public' => false, 'is_secret' => false,
            'validation_rules' => 'required|integer|min:1|max:1000',
        ],
        [
            'key' => 'limits.recommendation_count', 'value' => 20, 'type' => 'int', 'group' => 'limits',
            'description' => 'Number of personalized recommendations to surface per user per page.',
            'is_public' => false, 'is_secret' => false,
            'validation_rules' => 'required|integer|min:1|max:200',
        ],

        // ── Feature toggles (lightweight booleans — graduate to feature_flags
        //    only when you need user-segment targeting) ──────────────
        [
            'key' => 'features.show_trending_shelf', 'value' => true, 'type' => 'bool', 'group' => 'features',
            'description' => 'Show the "Trending Now" shelf on the homepage.',
            'is_public' => false, 'is_secret' => false,
            'validation_rules' => 'nullable|boolean',
        ],
        [
            'key' => 'features.show_streak_widget', 'value' => true, 'type' => 'bool', 'group' => 'features',
            'description' => 'Show the daily-streak widget on the user dashboard / header.',
            'is_public' => false, 'is_secret' => false,
            'validation_rules' => 'nullable|boolean',
        ],

        // ── Email ────────────────────────────────────────────────
        [
            'key' => 'email.from_name', 'value' => 'FLiK', 'type' => 'string', 'group' => 'email',
            'description' => 'Default "From" name used in transactional emails (welcome, reset, receipts).',
            'is_public' => false, 'is_secret' => false,
            'validation_rules' => 'required|string|max:60',
        ],
        [
            'key' => 'email.from_address', 'value' => 'noreply@flik.id', 'type' => 'string', 'group' => 'email',
            'description' => 'Default "From" address used in transactional emails. Must be allowed by your mail provider.',
            'is_public' => false, 'is_secret' => false,
            'validation_rules' => 'required|email|max:120',
        ],
        [
            'key' => 'email.support_address', 'value' => 'support@flik.id', 'type' => 'string', 'group' => 'email',
            'description' => 'Public-facing support inbox surfaced in footer + auto-replies.',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'required|email|max:120',
        ],

        // ── Legal ────────────────────────────────────────────────
        [
            'key' => 'legal.company_name', 'value' => 'PT FLiK Sinema Indonesia', 'type' => 'string', 'group' => 'legal',
            'description' => 'Legal company name shown on receipts, T&Cs, and invoices.',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'required|string|max:160',
        ],
        [
            'key' => 'legal.contact_email', 'value' => 'legal@flik.id', 'type' => 'string', 'group' => 'legal',
            'description' => 'Legal/DPO contact email — surfaced on the Privacy Policy page.',
            'is_public' => true, 'is_secret' => false,
            'validation_rules' => 'required|email|max:120',
        ],

        // ── AI tuning ────────────────────────────────────────────
        [
            'key' => 'ai.default_model_temperature', 'value' => 0.7, 'type' => 'float', 'group' => 'ai',
            'description' => 'Default sampling temperature passed to LLM tasks (0.0 = deterministic, 2.0 = wild).',
            'is_public' => false, 'is_secret' => false,
            'validation_rules' => 'required|numeric|min:0|max:2',
        ],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::SETTINGS as $row) {
            $existing = Setting::query()->where('key', $row['key'])->first();

            // First-run path: insert with seeded default value.
            if ($existing === null) {
                $model = new Setting();
                $model->key = $row['key'];
                $model->type = $row['type'];
                $model->group = $row['group'];
                $model->description = $row['description'];
                $model->is_public = $row['is_public'];
                $model->is_secret = $row['is_secret'];
                $model->validation_rules = $row['validation_rules'];
                // Set value AFTER `type` so the mutator picks the right encoder.
                $model->value = $row['value'];
                $model->save();

                continue;
            }

            // Re-run path: refresh metadata but DO NOT touch the value.
            // Operators who tweaked a setting in production must keep their edit.
            $existing->fill([
                'type' => $row['type'],
                'group' => $row['group'],
                'description' => $row['description'],
                'is_public' => $row['is_public'],
                'is_secret' => $row['is_secret'],
                'validation_rules' => $row['validation_rules'],
            ])->save();
        }
    }

    /**
     * "Restore defaults" entry point invoked by SettingsController::seed().
     * Unlike run(), this RESETS every seeded value back to the canonical
     * default — destructive on purpose. Custom (non-seeded) settings the
     * admin has added through other means are left untouched.
     */
    public static function restoreDefaults(): int
    {
        if (! Schema::hasTable('settings')) {
            return 0;
        }

        $count = 0;
        foreach (self::SETTINGS as $row) {
            $model = Setting::query()->where('key', $row['key'])->first()
                ?? new Setting(['key' => $row['key']]);

            $model->key = $row['key'];
            $model->type = $row['type'];
            $model->group = $row['group'];
            $model->description = $row['description'];
            $model->is_public = $row['is_public'];
            $model->is_secret = $row['is_secret'];
            $model->validation_rules = $row['validation_rules'];
            // Reset value last so the mutator sees the canonical `type`.
            $model->value = $row['value'];
            $model->save();
            $count++;
        }

        return $count;
    }
}
