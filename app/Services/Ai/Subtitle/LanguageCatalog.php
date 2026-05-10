<?php

namespace App\Services\Ai\Subtitle;

/**
 * World language catalog for subtitle generation/translation.
 *
 * Format: BCP-47 code → metadata
 * Special variants tracked for languages with multiple writing conventions:
 *   - Arabic: with/without harakat (tashkeel diacritics)
 *   - Chinese: Simplified vs Traditional
 *   - Norwegian: Bokmål vs Nynorsk
 */
class LanguageCatalog
{
    /**
     * Comprehensive language list. Grouped logically for UI dropdowns.
     */
    public const LANGUAGES = [
        // ── Indonesia & SEA (priority for FLiK) ─────────────────
        'id'    => ['name' => 'Bahasa Indonesia',      'native' => 'Indonesia',         'rtl' => false, 'group' => 'sea',      'priority' => 1],
        'jv'    => ['name' => 'Javanese',              'native' => 'ꦧꦱꦗꦮ / Basa Jawa', 'rtl' => false, 'group' => 'sea',      'priority' => 2],
        'su'    => ['name' => 'Sundanese',             'native' => 'Basa Sunda',        'rtl' => false, 'group' => 'sea',      'priority' => 2],
        'min'   => ['name' => 'Minangkabau',           'native' => 'Baso Minang',       'rtl' => false, 'group' => 'sea',      'priority' => 3],
        'bug'   => ['name' => 'Buginese',              'native' => 'ᨅᨔ ᨕᨘᨁᨗ',          'rtl' => false, 'group' => 'sea',      'priority' => 3],
        'bjn'   => ['name' => 'Banjar',                'native' => 'Bahasa Banjar',     'rtl' => false, 'group' => 'sea',      'priority' => 3],
        'ms'    => ['name' => 'Malay',                 'native' => 'Bahasa Melayu',     'rtl' => false, 'group' => 'sea',      'priority' => 2],
        'ms-MY' => ['name' => 'Malay (Malaysia)',      'native' => 'Bahasa Malaysia',   'rtl' => false, 'group' => 'sea',      'priority' => 3],
        'tl'    => ['name' => 'Tagalog',               'native' => 'Tagalog',           'rtl' => false, 'group' => 'sea',      'priority' => 3],
        'th'    => ['name' => 'Thai',                  'native' => 'ภาษาไทย',           'rtl' => false, 'group' => 'sea',      'priority' => 3],
        'vi'    => ['name' => 'Vietnamese',            'native' => 'Tiếng Việt',        'rtl' => false, 'group' => 'sea',      'priority' => 3],
        'km'    => ['name' => 'Khmer',                 'native' => 'ខ្មែរ',             'rtl' => false, 'group' => 'sea',      'priority' => 4],
        'my'    => ['name' => 'Burmese',               'native' => 'မြန်မာ',            'rtl' => false, 'group' => 'sea',      'priority' => 4],
        'lo'    => ['name' => 'Lao',                   'native' => 'ລາວ',               'rtl' => false, 'group' => 'sea',      'priority' => 4],

        // ── East Asia ───────────────────────────────────────────
        'zh-Hans' => ['name' => 'Chinese (Simplified)', 'native' => '简体中文',         'rtl' => false, 'group' => 'east-asia', 'priority' => 2],
        'zh-Hant' => ['name' => 'Chinese (Traditional)','native' => '繁體中文',         'rtl' => false, 'group' => 'east-asia', 'priority' => 2],
        'ja'      => ['name' => 'Japanese',             'native' => '日本語',           'rtl' => false, 'group' => 'east-asia', 'priority' => 2],
        'ko'      => ['name' => 'Korean',               'native' => '한국어',           'rtl' => false, 'group' => 'east-asia', 'priority' => 2],
        'mn'      => ['name' => 'Mongolian',            'native' => 'Монгол',           'rtl' => false, 'group' => 'east-asia', 'priority' => 4],

        // ── Middle East / Arab world ────────────────────────────
        'ar'             => ['name' => 'Arabic',                       'native' => 'العربية',                'rtl' => true,  'group' => 'middle-east', 'priority' => 2, 'variant' => 'harakat-off'],
        'ar-x-harakat'   => ['name' => 'Arabic (with Harakat/Tashkeel)','native' => 'العَرَبِيَّة (مع التشكيل)', 'rtl' => true,  'group' => 'middle-east', 'priority' => 3, 'variant' => 'harakat-on'],
        'ar-x-classical' => ['name' => 'Arabic (Classical/Quranic)',   'native' => 'العربية الفصحى',         'rtl' => true,  'group' => 'middle-east', 'priority' => 4, 'variant' => 'classical'],
        'ar-EG'          => ['name' => 'Arabic (Egyptian)',            'native' => 'مصري',                  'rtl' => true,  'group' => 'middle-east', 'priority' => 4],
        'fa'             => ['name' => 'Persian (Farsi)',              'native' => 'فارسی',                 'rtl' => true,  'group' => 'middle-east', 'priority' => 3],
        'he'             => ['name' => 'Hebrew',                       'native' => 'עברית',                 'rtl' => true,  'group' => 'middle-east', 'priority' => 3],
        'tr'             => ['name' => 'Turkish',                      'native' => 'Türkçe',                'rtl' => false, 'group' => 'middle-east', 'priority' => 3],
        'ur'             => ['name' => 'Urdu',                         'native' => 'اردو',                  'rtl' => true,  'group' => 'middle-east', 'priority' => 3],
        'ku'             => ['name' => 'Kurdish',                      'native' => 'Kurdî',                 'rtl' => false, 'group' => 'middle-east', 'priority' => 4],

        // ── South Asia ──────────────────────────────────────────
        'hi' => ['name' => 'Hindi',     'native' => 'हिन्दी',  'rtl' => false, 'group' => 'south-asia', 'priority' => 2],
        'bn' => ['name' => 'Bengali',   'native' => 'বাংলা',   'rtl' => false, 'group' => 'south-asia', 'priority' => 3],
        'ta' => ['name' => 'Tamil',     'native' => 'தமிழ்',   'rtl' => false, 'group' => 'south-asia', 'priority' => 3],
        'te' => ['name' => 'Telugu',    'native' => 'తెలుగు',  'rtl' => false, 'group' => 'south-asia', 'priority' => 4],
        'mr' => ['name' => 'Marathi',   'native' => 'मराठी',   'rtl' => false, 'group' => 'south-asia', 'priority' => 4],
        'gu' => ['name' => 'Gujarati',  'native' => 'ગુજરાતી',  'rtl' => false, 'group' => 'south-asia', 'priority' => 4],
        'pa' => ['name' => 'Punjabi',   'native' => 'ਪੰਜਾਬੀ',  'rtl' => false, 'group' => 'south-asia', 'priority' => 4],
        'ne' => ['name' => 'Nepali',    'native' => 'नेपाली',  'rtl' => false, 'group' => 'south-asia', 'priority' => 4],
        'si' => ['name' => 'Sinhala',   'native' => 'සිංහල',   'rtl' => false, 'group' => 'south-asia', 'priority' => 4],

        // ── Europe — Western ────────────────────────────────────
        'en'    => ['name' => 'English',           'native' => 'English',         'rtl' => false, 'group' => 'europe-west', 'priority' => 1],
        'en-US' => ['name' => 'English (US)',      'native' => 'English (US)',    'rtl' => false, 'group' => 'europe-west', 'priority' => 2],
        'en-GB' => ['name' => 'English (UK)',      'native' => 'English (UK)',    'rtl' => false, 'group' => 'europe-west', 'priority' => 3],
        'es'    => ['name' => 'Spanish',           'native' => 'Español',         'rtl' => false, 'group' => 'europe-west', 'priority' => 2],
        'es-MX' => ['name' => 'Spanish (Mexican)', 'native' => 'Español (México)','rtl' => false, 'group' => 'europe-west', 'priority' => 3],
        'fr'    => ['name' => 'French',            'native' => 'Français',        'rtl' => false, 'group' => 'europe-west', 'priority' => 2],
        'de'    => ['name' => 'German',            'native' => 'Deutsch',         'rtl' => false, 'group' => 'europe-west', 'priority' => 2],
        'it'    => ['name' => 'Italian',           'native' => 'Italiano',        'rtl' => false, 'group' => 'europe-west', 'priority' => 3],
        'pt'    => ['name' => 'Portuguese',        'native' => 'Português',       'rtl' => false, 'group' => 'europe-west', 'priority' => 3],
        'pt-BR' => ['name' => 'Portuguese (BR)',   'native' => 'Português (BR)',  'rtl' => false, 'group' => 'europe-west', 'priority' => 3],
        'nl'    => ['name' => 'Dutch',             'native' => 'Nederlands',      'rtl' => false, 'group' => 'europe-west', 'priority' => 3],

        // ── Europe — Nordic ─────────────────────────────────────
        'sv'    => ['name' => 'Swedish',           'native' => 'Svenska',         'rtl' => false, 'group' => 'europe-nordic', 'priority' => 4],
        'no-NB' => ['name' => 'Norwegian Bokmål',  'native' => 'Norsk Bokmål',    'rtl' => false, 'group' => 'europe-nordic', 'priority' => 4],
        'no-NN' => ['name' => 'Norwegian Nynorsk', 'native' => 'Norsk Nynorsk',   'rtl' => false, 'group' => 'europe-nordic', 'priority' => 5],
        'da'    => ['name' => 'Danish',            'native' => 'Dansk',           'rtl' => false, 'group' => 'europe-nordic', 'priority' => 4],
        'fi'    => ['name' => 'Finnish',           'native' => 'Suomi',           'rtl' => false, 'group' => 'europe-nordic', 'priority' => 4],
        'is'    => ['name' => 'Icelandic',         'native' => 'Íslenska',        'rtl' => false, 'group' => 'europe-nordic', 'priority' => 5],

        // ── Europe — Eastern ────────────────────────────────────
        'pl' => ['name' => 'Polish',     'native' => 'Polski',     'rtl' => false, 'group' => 'europe-east', 'priority' => 4],
        'ru' => ['name' => 'Russian',    'native' => 'Русский',    'rtl' => false, 'group' => 'europe-east', 'priority' => 3],
        'uk' => ['name' => 'Ukrainian',  'native' => 'Українська', 'rtl' => false, 'group' => 'europe-east', 'priority' => 4],
        'cs' => ['name' => 'Czech',      'native' => 'Čeština',    'rtl' => false, 'group' => 'europe-east', 'priority' => 4],
        'sk' => ['name' => 'Slovak',     'native' => 'Slovenčina', 'rtl' => false, 'group' => 'europe-east', 'priority' => 5],
        'hu' => ['name' => 'Hungarian',  'native' => 'Magyar',     'rtl' => false, 'group' => 'europe-east', 'priority' => 4],
        'ro' => ['name' => 'Romanian',   'native' => 'Română',     'rtl' => false, 'group' => 'europe-east', 'priority' => 4],
        'bg' => ['name' => 'Bulgarian',  'native' => 'Български',  'rtl' => false, 'group' => 'europe-east', 'priority' => 5],
        'el' => ['name' => 'Greek',      'native' => 'Ελληνικά',   'rtl' => false, 'group' => 'europe-east', 'priority' => 4],

        // ── Africa ──────────────────────────────────────────────
        'sw'  => ['name' => 'Swahili',   'native' => 'Kiswahili', 'rtl' => false, 'group' => 'africa',     'priority' => 4],
        'am'  => ['name' => 'Amharic',   'native' => 'አማርኛ',     'rtl' => false, 'group' => 'africa',     'priority' => 5],
        'ha'  => ['name' => 'Hausa',     'native' => 'Hausa',     'rtl' => false, 'group' => 'africa',     'priority' => 5],
        'yo'  => ['name' => 'Yoruba',    'native' => 'Yorùbá',    'rtl' => false, 'group' => 'africa',     'priority' => 5],
        'zu'  => ['name' => 'Zulu',      'native' => 'isiZulu',   'rtl' => false, 'group' => 'africa',     'priority' => 5],
        'af'  => ['name' => 'Afrikaans', 'native' => 'Afrikaans', 'rtl' => false, 'group' => 'africa',     'priority' => 5],
    ];

    public const GROUPS = [
        'sea'           => '🌏 Asia Tenggara (Indonesia & sekitarnya)',
        'east-asia'     => '🇨🇳 Asia Timur (Mandarin, Jepang, Korea)',
        'middle-east'   => '🕌 Timur Tengah (Arab, Persia, Turki)',
        'south-asia'    => '🇮🇳 Asia Selatan (Hindi, Tamil, dll)',
        'europe-west'   => '🇪🇺 Eropa Barat (Inggris, Spanyol, Prancis, dll)',
        'europe-nordic' => '🇸🇪 Nordic (Swedia, Norwegia, dll)',
        'europe-east'   => '🇷🇺 Eropa Timur (Rusia, Polandia, dll)',
        'africa'        => '🌍 Afrika (Swahili, Amharik, dll)',
    ];

    public static function all(): array
    {
        return self::LANGUAGES;
    }

    public static function get(string $code): ?array
    {
        return self::LANGUAGES[$code] ?? null;
    }

    public static function exists(string $code): bool
    {
        return isset(self::LANGUAGES[$code]);
    }

    public static function name(string $code): string
    {
        return self::LANGUAGES[$code]['name'] ?? $code;
    }

    public static function nativeName(string $code): string
    {
        return self::LANGUAGES[$code]['native'] ?? $code;
    }

    public static function isRtl(string $code): bool
    {
        return self::LANGUAGES[$code]['rtl'] ?? false;
    }

    /**
     * Get languages grouped by region for UI dropdown rendering.
     * @return array<string, array<string, array>>  group => [code => meta]
     */
    public static function grouped(): array
    {
        $grouped = array_fill_keys(array_keys(self::GROUPS), []);
        foreach (self::LANGUAGES as $code => $meta) {
            $group = $meta['group'] ?? 'other';
            $grouped[$group][$code] = $meta;
        }
        // Sort each group by priority then native name
        foreach ($grouped as $g => $items) {
            uasort($grouped[$g], fn ($a, $b) =>
                ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99) ?: strcmp($a['native'], $b['native'])
            );
        }
        return $grouped;
    }

    /**
     * Get only Arabic variants (with/without harakat etc).
     */
    public static function arabicVariants(): array
    {
        return array_filter(self::LANGUAGES, fn ($k) => str_starts_with($k, 'ar'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Whisper-supported language code mapping.
     * Whisper uses ISO 639-1 (2-letter) codes. We strip variants for Whisper.
     */
    public static function toWhisperCode(string $code): string
    {
        $base = explode('-', $code)[0];
        // Whisper uses 'zh' for both Hans/Hant
        return $base;
    }
}
