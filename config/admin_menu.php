<?php

/**
 * Admin Menu Manifest — single source of truth for the sidebar.
 *
 * CONTRACT:
 *   - Every entry must declare: label, icon, permission (or null), route (or null).
 *   - `permission` is matched 1:1 against the Permission catalogue created by
 *     ROLE swarm peer #1 (see database/migrations/*_create_permissions_table.php).
 *   - `route` is a named Laravel route. When the route does not exist yet
 *     (feature in flight, partial rollout, env-gated controller absent), the
 *     sidebar component must call `Route::has(...)` and silently skip — do NOT
 *     blow up the layout because a feature shipped without its route name.
 *   - `permission = null` means "anyone in the admin panel can see this"
 *     (Dashboard, Pitch Deck). The outer `auth + can:admin` route group still
 *     applies, so null does not mean "public".
 *   - The Menu Matrix audit page (/admin/menu-matrix) ALSO consumes this file
 *     so the visual audit table mirrors exactly what users see in the sidebar.
 *     Add a new sidebar item ONLY by appending to this file — never by hardcoding
 *     `<a>` in layout.blade.php.
 *
 * NEW ITEM CHECKLIST:
 *   1. Append to the right section (or create a new section).
 *   2. Pick the closest existing permission name from the 30-perm taxonomy.
 *      If none fits, coordinate with the ROLE swarm before inventing one.
 *   3. The `category` key on each section is what the Menu Matrix uses for its
 *      filter dropdown — pick from: content, intelligence, marketing,
 *      analytics, security, system, distribution, overview.
 */

return [
    'sections' => [

        // ── Overview ────────────────────────────────────────────────
        'overview' => [
            'label' => 'Menu',
            'category' => 'overview',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'route' => 'admin.dashboard',
                    'permission' => null, // anyone with admin gate sees this
                    'icon' => 'home',
                ],
                [
                    // Owned by NOTIF #1 (this swarm). The bell-only widget
                    // entry is delegated to NOTIF #2 — they may add a
                    // second item or replace this one. The route resolves
                    // to the full inbox; sidebar component degrades silently
                    // if it's not yet registered.
                    'label' => 'Notifications',
                    'route' => 'admin.notifications.index',
                    'permission' => null, // bell must reach every staff role
                    'icon' => 'sparkles',
                ],
            ],
        ],

        // ── Content (catalog CRUD) ─────────────────────────────────
        'content' => [
            'label' => 'Content',
            'category' => 'content',
            'items' => [
                [
                    'label' => 'Movies',
                    'route' => 'admin.movies.index',
                    'permission' => 'movies.view',
                    'icon' => 'film',
                ],
                [
                    'label' => 'Genres',
                    'route' => 'admin.genres.index',
                    'permission' => 'movies.update', // loose: same as catalog edit
                    'icon' => 'bookmark',
                ],
                [
                    'label' => 'Cast',
                    'route' => 'admin.casts.index',
                    'permission' => 'movies.update',
                    'icon' => 'user',
                ],
                [
                    'label' => 'Banners',
                    'route' => 'admin.banners.index',
                    'permission' => 'movies.update',
                    'icon' => 'sparkles',
                ],
                [
                    'label' => 'Help Articles',
                    'route' => 'admin.help.articles.index',
                    'permission' => 'help.manage',
                    'icon' => 'info',
                ],
                [
                    'label' => 'Help Categories',
                    'route' => 'admin.help.categories.index',
                    'permission' => 'help.manage',
                    'icon' => 'bookmark',
                ],
                [
                    // TMDB Import Wizard — admin pastes an id (or searches by
                    // title) to auto-fill a Movie row + cast + posters. Sits
                    // under Content because the operator using it is the
                    // catalogue editor, not a sysadmin.
                    'label' => 'TMDB Import',
                    'route' => 'admin.tmdb.index',
                    'permission' => 'movies.create',
                    'icon' => 'sparkles',
                ],
            ],
        ],

        // ── System (users + identity + integrations) ───────────────
        'system' => [
            'label' => 'System',
            'category' => 'system',
            'items' => [
                [
                    'label' => 'Architecture Docs',
                    'route' => 'admin.docs.index',
                    'permission' => null, // any admin
                    'icon' => 'book',
                ],
                [
                    'label' => 'Users',
                    'route' => 'admin.users.index',
                    'permission' => 'users.view',
                    'icon' => 'user',
                ],
                [
                    'label' => 'Roles & Permissions',
                    'route' => 'admin.roles.index',
                    'permission' => 'roles.manage',
                    'icon' => 'shield',
                ],
                [
                    'label' => 'Menu Matrix',
                    'route' => 'admin.menu-matrix.index',
                    'permission' => 'roles.manage',
                    'icon' => 'check',
                ],
                [
                    'label' => 'API Keys',
                    'route' => 'admin.api-keys.index',
                    'permission' => 'security.api_keys',
                    'icon' => 'gem',
                ],
                [
                    'label' => 'Queues',
                    'route' => 'admin.queue-dashboard.index',
                    'permission' => 'system.queues',
                    'icon' => 'server',
                ],
                [
                    // Operational health dashboard (mirrors `php artisan flik:doctor`).
                    // Permission `system.health` is granted to both `admin` and
                    // `super_admin` so day-to-day operators can triage without
                    // shelling into the box.
                    'label' => 'Health',
                    'route' => 'admin.health.index',
                    'permission' => 'system.health',
                    'icon' => 'shield',
                ],
                [
                    // App-level maintenance kill switch — gated tighter than
                    // Health because flipping it takes the site down for
                    // everyone outside the allow list. Seeded ONLY to
                    // super_admin; the legacy `is_admin` Gate fallback in
                    // AuthServiceProvider still grants access before the seed
                    // is re-run, so the entry never silently disappears.
                    'label' => 'Maintenance',
                    'route' => 'admin.maintenance.index',
                    'permission' => 'system.maintenance',
                    'icon' => 'cog',
                ],
                [
                    'label' => 'Pitch Deck',
                    'route' => 'admin.pitch-deck',
                    'permission' => null, // informational
                    'icon' => 'medal',
                ],
                [
                    // i18n dashboard — UI string coverage per locale + AI
                    // translation cache stats. No dedicated permission yet;
                    // any admin with the `admin` gate can read it (it's
                    // diagnostic, not mutating). Switch the permission once
                    // an `i18n.manage` ability is added to the catalogue.
                    'label' => 'Translations',
                    'route' => 'admin.translations.index',
                    'permission' => null,
                    'icon' => 'sparkles',
                ],
                [
                    // Runtime feature toggles — gradual rollouts (role /
                    // percentage / user list / authed / guest). Backed by
                    // App\Models\FeatureFlag + App\Services\Features\FeatureManager.
                    'label' => 'Feature Flags',
                    'route' => 'admin.feature-flags.index',
                    'permission' => 'system.feature_flags',
                    'icon' => 'lightning',
                ],
                [
                    // Runtime-editable application settings (branding,
                    // social handles, limits, AI knobs). Backed by
                    // App\Models\Setting + setting()/Setting::get helpers.
                    'label' => 'Settings',
                    'route' => 'admin.settings.index',
                    'permission' => 'system.settings',
                    'icon' => 'cog',
                ],
            ],
        ],

        // ── Intelligence (AI providers + ops) ──────────────────────
        'intelligence' => [
            'label' => 'Intelligence',
            'category' => 'intelligence',
            'items' => [
                [
                    'label' => 'AI Providers',
                    'route' => 'admin.ai.index',
                    'permission' => 'ai.providers.configure',
                    'icon' => 'cog',
                ],
                [
                    'label' => 'AI Usage',
                    'route' => 'admin.ai.usage',
                    'permission' => 'ai.usage.view',
                    'icon' => 'lightning',
                ],
                [
                    'label' => 'Audit Logs',
                    'route' => 'admin.audit-logs.index',
                    'permission' => 'security.audit_logs',
                    'icon' => 'info',
                ],
                [
                    'label' => 'Sentiment Dashboard',
                    'route' => 'admin.sentiment.index',
                    'permission' => 'comments.moderate',
                    'icon' => 'star',
                ],
                [
                    'label' => 'Comment Queue',
                    'route' => 'admin.comments.queue',
                    'permission' => 'comments.moderate',
                    'icon' => 'chat',
                ],
                [
                    'label' => 'Director Analyses',
                    'route' => 'admin.director-analyses.index',
                    'permission' => 'ai.tasks.run',
                    'icon' => 'user-circle',
                ],
            ],
        ],

        // ── Marketing (AI-assisted campaign ops) ───────────────────
        'marketing' => [
            'label' => 'Marketing',
            'category' => 'marketing',
            'items' => [
                [
                    'label' => 'Content Gap Analysis',
                    'route' => 'admin.insights.content-gap',
                    'permission' => 'analytics.insights',
                    'icon' => 'sparkles',
                ],
                [
                    'label' => 'Pricing Optimization',
                    'route' => 'admin.insights.pricing',
                    'permission' => 'analytics.insights',
                    'icon' => 'cog',
                ],
                [
                    'label' => 'Email A/B Subjects',
                    'route' => 'admin.marketing-ops.email-subjects',
                    'permission' => 'marketing.email_ab',
                    'icon' => 'lightning',
                ],
                [
                    // Bulk email campaign builder — segmentation, AI copy
                    // draft, tracked sends. Shares the `marketing.email_ab`
                    // permission with the A/B subject helper above.
                    'label' => 'Email Campaigns',
                    'route' => 'admin.email-campaigns.index',
                    'permission' => 'marketing.email_ab',
                    'icon' => 'sparkles',
                ],
                [
                    // Push Broadcasts — owned by peer DEV #5. The sidebar
                    // component calls Route::has() and silently skips this
                    // item until that swarm registers the route.
                    'label' => 'Push Broadcasts',
                    'route' => 'admin.push-broadcasts.index',
                    'permission' => 'marketing.email_ab',
                    'icon' => 'lightning',
                ],
                [
                    'label' => 'CS Reply Drafter',
                    'route' => 'admin.marketing-ops.cs-reply',
                    'permission' => 'marketing.cs_reply',
                    'icon' => 'chat',
                ],
                [
                    // Web Push broadcasts — composer + send history. Gated on the
                    // dedicated `push.send` permission so it can be scoped tighter
                    // than the broader marketing.email_ab bundle.
                    'label' => 'Push Notifications',
                    'route' => 'admin.push.index',
                    'permission' => 'push.send',
                    'icon' => 'sparkles',
                ],
                [
                    // Subscription discount codes (manual + bulk-generated).
                    // Sits in Marketing because the day-to-day operators are
                    // marketing/growth, not finance — finance only sees the
                    // result via the Revenue Dashboard.
                    'label' => 'Promo Codes',
                    'route' => 'admin.promo-codes.index',
                    'permission' => 'promo.manage',
                    'icon' => 'gift',
                ],
            ],
        ],

        // ── Analytics (revenue + ops dashboards) ───────────────────
        'analytics' => [
            'label' => 'Analytics',
            'category' => 'analytics',
            'items' => [
                [
                    'label' => 'Revenue Dashboard',
                    'route' => 'admin.revenue.dashboard',
                    'permission' => 'analytics.revenue',
                    'icon' => 'coin',
                ],
                [
                    'label' => 'Geo Distribution',
                    'route' => 'admin.geo.distribution',
                    'permission' => 'analytics.geo',
                    'icon' => 'eye',
                ],
                [
                    'label' => 'Cohort Analysis',
                    'route' => 'admin.cohorts.index',
                    'permission' => 'analytics.cohort',
                    'icon' => 'user-circle',
                ],
                [
                    'label' => 'Funnel',
                    'route' => 'admin.funnel.index',
                    'permission' => 'analytics.funnel',
                    'icon' => 'chevron-down',
                ],
                [
                    'label' => 'A/B Tests',
                    'route' => 'admin.ab-tests.index',
                    'permission' => 'analytics.funnel', // close enough; reuse funnel perm
                    'icon' => 'sparkles',
                ],
                [
                    'label' => 'Churn Risk',
                    'route' => 'admin.churn.dashboard',
                    'permission' => 'analytics.churn',
                    'icon' => 'fire',
                ],
                [
                    'label' => 'Performance',
                    'route' => 'admin.performance.index',
                    'permission' => 'analytics.performance',
                    'icon' => 'server',
                ],
            ],
        ],

        // ── Security (operational hardening) ───────────────────────
        'security' => [
            'label' => 'Security',
            'category' => 'security',
            'items' => [
                [
                    'label' => 'WAF Banned IPs',
                    'route' => 'admin.security.waf.banned-ips',
                    'permission' => 'security.waf',
                    'icon' => 'shield',
                ],
            ],
        ],

        // ── Distribution (encoding + DRM ops) ──────────────────────
        'distribution' => [
            'label' => 'Distribution',
            'category' => 'distribution',
            'items' => [
                [
                    'label' => 'Encoding Jobs',
                    'route' => 'admin.movies.index', // proxied; encoding columns live on movies
                    'permission' => 'movies.update',
                    'icon' => 'server',
                ],
            ],
        ],
    ],
];
