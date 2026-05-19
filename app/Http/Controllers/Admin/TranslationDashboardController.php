<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TranslationCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * /admin/translations — coverage + AI translation cache stats.
 *
 * Two halves:
 *   1. STATIC coverage — for every locale in config('locales.available') we
 *      diff lang/<code>.json against the union of every locale's keys. The
 *      view renders a coverage bar + a list of missing keys per locale so
 *      translators can see exactly what's still untranslated.
 *
 *   2. AI CACHE — aggregate counts from translation_cache (size, hits in
 *      the last 24h, breakdown by provider + by (source → target) pair).
 *      The "hit" count is approximated as rows where last_used_at > created_at
 *      within the last 24h — exact counts would need a separate access-log
 *      table which is overkill for this surface.
 */
class TranslationDashboardController extends Controller
{
    /**
     * Render the dashboard.
     */
    public function index(): View
    {
        return view('admin.translations.index', [
            'coverage' => $this->coverage(),
            'cacheStats' => $this->cacheStats(),
            'byPair' => $this->byLocalePair(),
            'byProvider' => $this->byProvider(),
        ]);
    }

    /**
     * Per-locale key coverage relative to the union of every locale's keys.
     *
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     present: int,
     *     total: int,
     *     missing: int,
     *     percent: float,
     *     missing_keys: array<int, string>,
     * }>
     */
    protected function coverage(): array
    {
        $available = (array) config('locales.available', []);
        if (empty($available)) {
            return [];
        }

        // Load every locale's JSON dictionary once. lang_path() resolves to
        // resources/lang/ in this project (Laravel's default for this layout)
        // — using the helper insulates us from a future move to lang/.
        $byLocale = [];
        $unionKeys = [];
        foreach (array_keys($available) as $code) {
            $path = app()->langPath($code.'.json');
            $data = $this->loadDictionary($path);
            $byLocale[$code] = $data;
            // Union of all keys across all locales — this becomes the
            // "complete set" we measure each locale against.
            foreach (array_keys($data) as $k) {
                $unionKeys[$k] = true;
            }
        }

        $total = count($unionKeys);
        $out = [];

        foreach ($available as $code => $meta) {
            $dict = $byLocale[$code] ?? [];
            $present = 0;
            $missing = [];
            foreach (array_keys($unionKeys) as $key) {
                // A key counts as "present" only when the value is a
                // non-empty string — empty translations are placeholders.
                $value = $dict[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $present++;
                } else {
                    $missing[] = $key;
                }
            }

            $out[] = [
                'code' => $code,
                'name' => (string) ($meta['name'] ?? $code),
                'flag' => (string) ($meta['flag'] ?? ''),
                'rtl' => (bool) ($meta['rtl'] ?? false),
                'present' => $present,
                'total' => $total,
                'missing' => count($missing),
                'percent' => $total > 0 ? round(($present / $total) * 100, 1) : 100.0,
                'missing_keys' => array_slice($missing, 0, 50), // cap to keep the view readable
            ];
        }

        return $out;
    }

    /**
     * Aggregate cache stats. Pure counts — no provider call.
     *
     * @return array{
     *     total: int,
     *     fresh_24h: int,
     *     hits_24h: int,
     *     hit_rate_pct: float,
     *     oldest: \Illuminate\Support\Carbon|null,
     *     newest: \Illuminate\Support\Carbon|null,
     * }
     */
    protected function cacheStats(): array
    {
        // The table may not be migrated yet in a fresh checkout — guard so
        // the dashboard renders even without `translation_cache`.
        if (! \Illuminate\Support\Facades\Schema::hasTable('translation_cache')) {
            return [
                'total' => 0,
                'fresh_24h' => 0,
                'hits_24h' => 0,
                'hit_rate_pct' => 0.0,
                'oldest' => null,
                'newest' => null,
            ];
        }

        $since = Carbon::now()->subDay();

        $total = (int) TranslationCache::query()->count();

        // "Fresh" = a brand new translation written in the last 24h (cache miss).
        $fresh24h = (int) TranslationCache::query()
            ->where('created_at', '>=', $since)
            ->count();

        // "Hits" = rows whose last_used_at is in the last 24h but created
        // BEFORE that window — those are reads against pre-existing cache rows.
        $hits24h = (int) TranslationCache::query()
            ->where('last_used_at', '>=', $since)
            ->where('created_at', '<', $since)
            ->count();

        $totalReads = $fresh24h + $hits24h;
        $hitRate = $totalReads > 0 ? round(($hits24h / $totalReads) * 100, 1) : 0.0;

        $oldest = TranslationCache::query()->min('created_at');
        $newest = TranslationCache::query()->max('created_at');

        return [
            'total' => $total,
            'fresh_24h' => $fresh24h,
            'hits_24h' => $hits24h,
            'hit_rate_pct' => $hitRate,
            'oldest' => $oldest ? Carbon::parse($oldest) : null,
            'newest' => $newest ? Carbon::parse($newest) : null,
        ];
    }

    /**
     * Cache rows grouped by (source_locale → target_locale) pair.
     *
     * @return array<int, array{source: string, target: string, count: int}>
     */
    protected function byLocalePair(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('translation_cache')) {
            return [];
        }

        return TranslationCache::query()
            ->selectRaw('source_locale, target_locale, COUNT(*) as count')
            ->groupBy('source_locale', 'target_locale')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => [
                'source' => (string) $r->source_locale,
                'target' => (string) $r->target_locale,
                'count' => (int) $r->count,
            ])
            ->all();
    }

    /**
     * Cache rows grouped by provider.
     *
     * @return array<int, array{provider: string, count: int}>
     */
    protected function byProvider(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('translation_cache')) {
            return [];
        }

        return TranslationCache::query()
            ->selectRaw("COALESCE(provider, 'unknown') as provider, COUNT(*) as count")
            ->groupBy('provider')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($r) => [
                'provider' => (string) $r->provider,
                'count' => (int) $r->count,
            ])
            ->all();
    }

    /**
     * Load a lang/<code>.json file as an associative array.
     *
     * @return array<string, string>
     */
    protected function loadDictionary(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }
        $raw = File::get($path);
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
