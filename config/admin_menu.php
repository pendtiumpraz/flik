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
            ],
        ],

        // ── System (users + identity + integrations) ───────────────
        'system' => [
            'label' => 'System',
            'category' => 'system',
            'items' => [
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
                    'label' => 'Pitch Deck',
                    'route' => 'admin.pitch-deck',
                    'permission' => null, // informational
                    'icon' => 'medal',
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
                    'permission' => 'marketing.email',
                    'icon' => 'lightning',
                ],
                [
                    'label' => 'CS Reply Drafter',
                    'route' => 'admin.marketing-ops.cs-reply',
                    'permission' => 'marketing.cs_reply',
                    'icon' => 'chat',
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
