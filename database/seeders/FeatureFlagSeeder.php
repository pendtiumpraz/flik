<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FeatureFlag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds a starter set of feature flags so the admin UI is non-empty
 * on a fresh install and developers have working examples of every
 * strategy.
 *
 * Idempotent — matched on the unique `key` column. First insert writes
 * the full canonical row; re-runs ONLY overwrite descriptive metadata
 * (name, description) and leave operator-tweaked behavioural fields
 * (is_enabled, strategy, strategy_config, rollout_started_at) untouched.
 * This means re-seeding never resets a production rollout decision.
 */
class FeatureFlagSeeder extends Seeder
{
    /**
     * @var array<int, array{key:string, name:string, description:string, is_enabled:bool, strategy:string, strategy_config:?array<string,mixed>}>
     */
    private const FLAGS = [
        [
            'key' => 'tv_series_section',
            'name' => 'TV Series Section',
            'description' => 'Show the TV-series rail and /series catalog. Disable to revert to films-only homepage.',
            'is_enabled' => true,
            'strategy' => 'on',
            'strategy_config' => null,
        ],
        [
            'key' => 'new_homepage_layout',
            'name' => 'New Homepage Layout (Beta)',
            'description' => 'Rolls out the redesigned homepage hero + shelf layout to a percentage of users.',
            'is_enabled' => true,
            'strategy' => 'percentage',
            'strategy_config' => ['percentage' => 20],
        ],
        [
            'key' => 'experimental_ai_chat',
            'name' => 'Experimental AI Chat Features',
            'description' => 'Unlocks unfinished AI chatbot tools (image search inside chat, voice replies). Admin-only while in dev.',
            'is_enabled' => true,
            'strategy' => 'role',
            'strategy_config' => ['roles' => ['admin', 'super_admin']],
        ],
        [
            'key' => 'christmas_theme',
            'name' => 'Christmas Theme',
            'description' => 'Festive holiday skin (snow, gold accents, special-edition banner). Flip ON in December.',
            'is_enabled' => false,
            'strategy' => 'off',
            'strategy_config' => null,
        ],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('feature_flags')) {
            return;
        }

        foreach (self::FLAGS as $row) {
            $existing = FeatureFlag::query()->where('key', $row['key'])->first();

            if ($existing === null) {
                FeatureFlag::create([
                    'key' => $row['key'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'is_enabled' => $row['is_enabled'],
                    'strategy' => $row['strategy'],
                    'strategy_config' => $row['strategy_config'],
                    // Stamp rollout time only for strategies that are
                    // actually rolling something out — 'off' flags get null.
                    'rollout_started_at' => $row['is_enabled']
                        && in_array($row['strategy'], ['on', 'percentage', 'role', 'users'], true)
                            ? now()
                            : null,
                ]);

                continue;
            }

            // Existing row → refresh ONLY the descriptive metadata so the
            // human-facing name/description stay in sync with code without
            // clobbering rollout decisions.
            $existing->fill([
                'name' => $row['name'],
                'description' => $row['description'],
            ])->save();
        }
    }
}
