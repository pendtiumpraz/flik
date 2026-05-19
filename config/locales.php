<?php

declare(strict_types=1);

/**
 * Available UI locales for FLiK.
 *
 * Source of truth for:
 *   - <x-lang-switcher /> dropdown (which flags + labels render)
 *   - App\Http\Middleware\SetLocale (which codes are accepted)
 *   - resources/views/components/layout.blade.php (which codes go RTL)
 *   - App\Http\Controllers\Admin\TranslationDashboardController (coverage matrix)
 *
 * To add a new locale:
 *   1. Add an entry here (5-char max code, e.g. 'ms', 'ja', 'zh-CN').
 *   2. Create lang/<code>.json (start with a copy of lang/en.json).
 *   3. RTL languages must set 'rtl' => true so the layout flips dir="rtl".
 *
 * The 'default' value is a hard fallback for cases where APP_LOCALE is unset
 * AND the SetLocale middleware can't resolve any of session/user/header.
 */

return [
    'available' => [
        'id' => ['name' => 'Bahasa Indonesia', 'flag' => "\u{1F1EE}\u{1F1E9}", 'rtl' => false],
        'en' => ['name' => 'English',           'flag' => "\u{1F1EC}\u{1F1E7}", 'rtl' => false],
        'ar' => ['name' => 'العربية',           'flag' => "\u{1F1F8}\u{1F1E6}", 'rtl' => true],
    ],

    'default' => 'id',
];
