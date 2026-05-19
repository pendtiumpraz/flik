<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Persistent cache of AI translations.
 *
 * One row per (target_locale, source_hash) pair — see the unique index in
 * database/migrations/2026_05_10_150002_create_translation_cache_table.php.
 *
 * Written exclusively by App\Services\Ai\Tasks\TextTranslator. The admin
 * dashboard (App\Http\Controllers\Admin\TranslationDashboardController)
 * reads aggregate stats from this table to show cache hit rate + size.
 *
 * NOTE: `created_at` / `last_used_at` are managed by the service (we never
 * use Eloquent's automatic timestamps because the column name `last_used_at`
 * doesn't match the framework convention). Setting `$timestamps = false`
 * keeps Eloquent out of our way.
 */
class TranslationCache extends Model
{
    protected $table = 'translation_cache';

    public $timestamps = false;

    protected $fillable = [
        'source_locale',
        'target_locale',
        'source_text',
        'source_hash',
        'translation',
        'provider',
        'created_at',
        'last_used_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    /**
     * Canonicalize source text → sha256 hex digest used as the lookup key.
     *
     * Canonicalisation keeps "  Hello  " and "Hello" pointing at the same
     * cache row. Lowercasing is deliberately skipped (case matters in many
     * languages and we want exact-tone reuse).
     */
    public static function hashSource(string $text): string
    {
        return hash('sha256', trim($text));
    }
}
