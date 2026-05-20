<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime-editable key/value setting.
 *
 * The DB stores every value as text; the {@see $type} column tells the
 * model how to decode it on read and encode it on write. This means a
 * single table serves booleans, integers, floats, JSON blobs, and plain
 * strings without per-type sub-tables.
 *
 * Reads are cached for 1 hour per key (see {@see get()}). The cache is
 * invalidated automatically when the row is saved or deleted (see {@see booted()}).
 *
 * @property int $id
 * @property string $key
 * @property mixed $value      typed on read via getValueAttribute()
 * @property string $type
 * @property string $group
 * @property string|null $description
 * @property bool $is_public
 * @property bool $is_secret
 * @property string|null $validation_rules
 */
class Setting extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_public' => 'boolean',
        'is_secret' => 'boolean',
    ];

    public const TYPES = ['string', 'int', 'float', 'bool', 'json', 'array'];

    private const CACHE_TTL_SECONDS = 3600; // 1 hour

    /**
     * Bust the read cache when a setting is saved or deleted so callers
     * never see a stale value after an admin edit. The cache key is
     * keyed off `key` (the dotted slug), not the model id, so we can
     * forget it without round-tripping the DB on update.
     */
    protected static function booted(): void
    {
        $forget = function (self $setting): void {
            Cache::forget('setting.value.' . $setting->key);
            // The grouped helper bucket cache (Setting::group()) is also
            // invalidated since one entry might have moved groups.
            Cache::forget('setting.group.' . $setting->group);
            // Original group, if the model was moved between groups in
            // the same save (UPDATE that changed `group`). Forget that
            // bucket too so the old tab stops showing the stale row.
            if ($setting->isDirty('group')) {
                Cache::forget('setting.group.' . (string) $setting->getOriginal('group'));
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * Typed value accessor. Reads the raw text column + the type column
     * and hands back a PHP-native value. Always lossless on the boolean
     * (0/1/true/false/"true"/"false") and integer cases.
     */
    public function getValueAttribute(?string $raw): mixed
    {
        return self::castOut($raw, (string) ($this->attributes['type'] ?? 'string'));
    }

    /**
     * Typed value mutator. Accepts a PHP-native value and serialises
     * for storage according to the row's type column.
     */
    public function setValueAttribute(mixed $value): void
    {
        $this->attributes['value'] = self::castIn($value, (string) ($this->attributes['type'] ?? 'string'));
    }

    // ── Static helpers ────────────────────────────────────────────

    /**
     * Get a setting's typed value. Returns $default when the key is
     * missing OR the DB layer isn't available yet (fresh install).
     *
     * Cached for 1h; cache is busted on save/delete.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Tolerate a missing table — callers (helper, blade directive)
        // can be hit very early in boot before migrations run.
        if (! self::tableExists()) {
            return $default;
        }

        $cached = Cache::remember(
            'setting.value.' . $key,
            self::CACHE_TTL_SECONDS,
            static function () use ($key): array {
                $row = static::query()
                    ->where('key', $key)
                    ->first(['value', 'type']);

                if ($row === null) {
                    // Marker meaning "no row" — distinct from a row whose
                    // value is literally null.
                    return ['__missing' => true];
                }

                return [
                    'value' => $row->getAttributes()['value'] ?? null,
                    'type' => $row->getAttributes()['type'] ?? 'string',
                ];
            }
        );

        if (isset($cached['__missing'])) {
            return $default;
        }

        $value = self::castOut($cached['value'] ?? null, (string) ($cached['type'] ?? 'string'));

        return $value ?? $default;
    }

    /**
     * Upsert a setting value. Creates the row if missing (defaulting
     * type from the inferred PHP type). Returns the saved model.
     */
    public static function set(string $key, mixed $value): self
    {
        $setting = static::query()->where('key', $key)->first()
            ?? new self(['key' => $key, 'type' => self::inferType($value), 'group' => 'general']);

        if (! $setting->exists) {
            // Ensure the (key, type, group) defaults are stamped before
            // the mutator runs — setValueAttribute needs $this->attributes['type'].
            $setting->key = $key;
            $setting->type = self::inferType($value);
            $setting->group = $setting->group ?: 'general';
        }

        $setting->value = $value; // routed through setValueAttribute
        $setting->save();

        return $setting;
    }

    /**
     * All settings in the given group, keyed by their `key` column.
     * Cached for 1h per group; useful for rendering the admin tabs.
     *
     * @return Collection<string, self>
     */
    public static function group(string $group): Collection
    {
        if (! self::tableExists()) {
            return collect();
        }

        return Cache::remember(
            'setting.group.' . $group,
            self::CACHE_TTL_SECONDS,
            static function () use ($group): Collection {
                return static::query()
                    ->where('group', $group)
                    ->orderBy('key')
                    ->get()
                    ->keyBy('key');
            }
        );
    }

    /**
     * All distinct group slugs (used by the admin UI to build tabs).
     *
     * @return array<int, string>
     */
    public static function groupKeys(): array
    {
        if (! self::tableExists()) {
            return [];
        }

        return static::query()
            ->select('group')
            ->distinct()
            ->orderBy('group')
            ->pluck('group')
            ->all();
    }

    /**
     * Public-flagged settings ready to ship to the frontend. Keys are
     * the dotted slugs, values are the typed PHP values.
     *
     * @return array<string, mixed>
     */
    public static function publicMap(): array
    {
        if (! self::tableExists()) {
            return [];
        }

        return Cache::remember('setting.public-map', self::CACHE_TTL_SECONDS, static function (): array {
            $rows = static::query()->where('is_public', true)->get(['key', 'value', 'type']);
            $map = [];
            foreach ($rows as $row) {
                $map[$row->key] = self::castOut(
                    $row->getAttributes()['value'] ?? null,
                    (string) ($row->getAttributes()['type'] ?? 'string'),
                );
            }

            return $map;
        });
    }

    // ── Type coercion ─────────────────────────────────────────────

    /**
     * Decode a raw text column into a PHP value of the declared type.
     * Wrong-shaped JSON / bad ints degrade silently to the raw string
     * (or null) so a malformed row never crashes a page render.
     */
    public static function castOut(?string $raw, string $type): mixed
    {
        if ($raw === null) {
            return null;
        }

        return match ($type) {
            'int' => is_numeric($raw) ? (int) $raw : null,
            'float' => is_numeric($raw) ? (float) $raw : null,
            'bool' => self::toBool($raw),
            'json', 'array' => self::decodeJson($raw),
            default => $raw, // 'string' or unknown ⇒ raw
        };
    }

    /**
     * Encode a PHP value into the text representation we persist.
     * Booleans always serialise as "1"/"0" (so `true == "1"` reads
     * roundtrip cleanly).
     */
    public static function castIn(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (string) (int) $value,
            'float' => (string) (float) $value,
            'bool' => self::toBool($value) ? '1' : '0',
            'json', 'array' => is_string($value)
                // Allow admins to paste raw JSON. Validate by attempting
                // to decode; fall through to re-encode if the string is
                // already well-formed JSON we want to preserve.
                ? (self::isValidJson($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));

            return in_array($v, ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private static function decodeJson(string $raw): mixed
    {
        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    private static function isValidJson(string $raw): bool
    {
        json_decode($raw);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Guess the most natural `type` slug for a PHP value when set()
     * is called without an existing row to anchor the type to.
     */
    public static function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'array',
            default => 'string',
        };
    }

    private static function tableExists(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (\Throwable) {
            // Catches the "no database configured" boot-time exception
            // — helper/Blade directive can run before the connection
            // is even wired up in tests.
            return false;
        }
    }
}
