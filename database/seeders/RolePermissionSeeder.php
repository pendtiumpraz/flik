<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the canonical permission taxonomy + the six system roles, then
 * backfills role assignments for existing users based on the legacy
 * `users.is_admin` flag (admins → 'admin' role, everyone else → 'user').
 *
 * Idempotent: safe to re-run. Uses updateOrCreate / syncWithoutDetaching
 * end-to-end so re-seeding never duplicates rows or wipes custom grants
 * the admin UI has layered on top.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Canonical permission catalog. Grouped per category for readability —
     * each row is [slug, display_name, optional description].
     *
     * @var array<string, array<int, array{0:string,1:string,2?:string}>>
     */
    private const PERMISSIONS = [
        'content' => [
            ['movies.view',     'View Movies',          'Read access to the movie catalog admin views.'],
            ['movies.create',   'Create Movies',        'Add new movies to the catalog.'],
            ['movies.update',   'Update Movies',        'Edit existing movie metadata, posters, and assets.'],
            ['movies.delete',   'Delete Movies',        'Soft- or hard-delete movies from the catalog.'],
            ['genres.manage',   'Manage Genres',        'Create, edit, and delete genres.'],
            ['casts.manage',    'Manage Casts',         'Create, edit, and delete cast members.'],
            ['banners.manage',  'Manage Banners',       'Manage homepage hero banners and slider artwork.'],
        ],
        'subtitles' => [
            ['subtitles.generate',  'Generate Subtitles',   'Run AI subtitle generation for a movie.'],
            ['subtitles.translate', 'Translate Subtitles',  'Translate subtitles into additional languages.'],
            ['subtitles.delete',    'Delete Subtitles',     'Remove subtitle tracks from a movie.'],
        ],
        'ai' => [
            ['ai.providers.configure', 'Configure AI Providers', 'Add, edit, and test AI provider credentials.'],
            ['ai.usage.view',          'View AI Usage',          'Access the AI usage and cost dashboard.'],
            ['ai.tasks.run',           'Run AI Tasks',           'Manually trigger AI generation tasks (tagging, summaries, etc.).'],
        ],
        'marketing' => [
            ['marketing.banner',    'Generate Marketing Banners',  'Use the AI promo banner generator.'],
            ['marketing.social',    'Generate Social Posts',       'Use the AI social-media post generator.'],
            ['marketing.email_ab',  'Manage Email A/B Tests',      'Create and manage marketing email A/B experiments.'],
            ['marketing.tiktok',    'TikTok Marketing',            'Publish/manage TikTok marketing assets.'],
            ['marketing.cs_reply',  'CS Auto-Reply',               'Manage AI customer-support auto-reply templates.'],
            ['push.send',           'Send Push Notifications',     'Compose and broadcast Web Push notifications to subscribers.'],
            ['promo.manage',        'Manage Promo Codes',          'Create, edit, and bulk-generate subscription discount codes.'],
        ],
        'moderation' => [
            ['comments.moderate', 'Moderate Comments', 'Approve, reject, and re-run AI moderation on comments.'],
            ['sentiment.view',    'View Sentiment',    'Access the comment-sentiment dashboard.'],
        ],
        'analytics' => [
            ['analytics.revenue',     'Revenue Analytics',      'Access the revenue analytics dashboard.'],
            ['analytics.geo',         'Geographic Analytics',   'Access the geographic distribution dashboard.'],
            ['analytics.cohort',      'Cohort Analytics',       'Access the cohort retention dashboard.'],
            ['analytics.funnel',      'Funnel Analytics',       'Access the conversion-funnel dashboard.'],
            ['analytics.performance', 'Performance Analytics',  'Access the streaming-performance dashboard.'],
            ['analytics.churn',       'Churn Analytics',        'Access the churn-prediction dashboard.'],
            ['analytics.insights',    'Insights Dashboard',     'Access the aggregated AI insights dashboard.'],
        ],
        'security' => [
            ['security.audit_logs', 'View Audit Logs',      'Read the audit-log dashboard (read-only).'],
            ['security.sessions',   'Manage Sessions',      'View and revoke active user sessions.'],
            ['security.backup',     'Manage Backups',       'Trigger, download, and restore database backups.'],
            ['security.api_keys',   'Manage API Keys',      'Create, rotate, and revoke API keys.'],
            ['security.waf',        'Manage WAF Rules',     'Configure web-application-firewall rules.'],
        ],
        'users' => [
            ['users.view',         'View Users',         'Browse the user list and individual profiles.'],
            ['users.update',       'Update Users',       'Edit user profiles (excluding role assignment).'],
            ['users.delete',       'Delete Users',       'Delete user accounts.'],
            ['users.assign_roles', 'Assign User Roles',  'Attach and detach roles on user accounts.'],
        ],
        'roles' => [
            ['roles.manage', 'Manage Roles', 'Create custom roles and edit role-permission mappings.'],
        ],
        'billing' => [
            ['subscriptions.view',   'View Subscriptions',   'Browse subscription history and active plans.'],
            ['subscriptions.refund', 'Refund Subscriptions', 'Issue refunds against subscription payments.'],
        ],
        'distribution' => [
            ['movies.upload_master',   'Upload Master Files',     'Upload master video files for transcoding.'],
            ['movies.encoding_status', 'View Encoding Status',    'Inspect the transcoding/encoding pipeline status.'],
        ],
        'system' => [
            ['system.queues', 'Manage Queues', 'View the queue dashboard, retry/delete failed jobs, flush the failed queue.'],
        ],
    ];

    /**
     * Canonical role definitions. The `permissions` list is resolved against
     * the slugs seeded above — '*' is a sentinel meaning "every permission".
     *
     * @var array<int, array{name:string, display_name:string, description:string, is_system:bool, priority:int, permissions: array<int,string>|string}>
     */
    private const ROLES = [
        [
            'name'         => 'super_admin',
            'display_name' => 'Super Admin',
            'description'  => 'Full access to every feature, including role management, backups, and API keys.',
            'is_system'    => true,
            'priority'     => 1,
            'permissions'  => '*',
        ],
        [
            'name'         => 'admin',
            'display_name' => 'Admin',
            'description'  => 'Day-to-day administration. Cannot manage roles, API keys, backups, refunds, or delete users.',
            'is_system'    => true,
            'priority'     => 10,
            'permissions'  => [
                // Everything in `content`
                'movies.view', 'movies.create', 'movies.update', 'movies.delete',
                'genres.manage', 'casts.manage', 'banners.manage',
                // `subtitles`
                'subtitles.generate', 'subtitles.translate', 'subtitles.delete',
                // `ai`
                'ai.providers.configure', 'ai.usage.view', 'ai.tasks.run',
                // `marketing`
                'marketing.banner', 'marketing.social', 'marketing.email_ab',
                'marketing.tiktok', 'marketing.cs_reply', 'push.send',
                'promo.manage',
                // `moderation`
                'comments.moderate', 'sentiment.view',
                // `analytics`
                'analytics.revenue', 'analytics.geo', 'analytics.cohort',
                'analytics.funnel', 'analytics.performance', 'analytics.churn',
                'analytics.insights',
                // `security` — minus api_keys, backup
                'security.audit_logs', 'security.sessions', 'security.waf',
                // `users` — minus delete, assign_roles
                'users.view', 'users.update',
                // `billing` — view only, no refund
                'subscriptions.view',
                // `distribution`
                'movies.upload_master', 'movies.encoding_status',
                // `system` (operational, not destructive at the role-management level)
                'system.queues',
            ],
        ],
        [
            'name'         => 'moderator',
            'display_name' => 'Moderator',
            'description'  => 'Moderates user-generated content and reviews security audit trails (read-only).',
            'is_system'    => true,
            'priority'     => 30,
            'permissions'  => [
                'comments.moderate',
                'sentiment.view',
                'security.audit_logs',
            ],
        ],
        [
            'name'         => 'content_editor',
            'display_name' => 'Content Editor',
            'description'  => 'Manages the movie catalog and subtitle pipeline. No analytics, billing, or security access.',
            'is_system'    => true,
            'priority'     => 40,
            'permissions'  => [
                'movies.view', 'movies.create', 'movies.update', 'movies.delete',
                'genres.manage', 'casts.manage', 'banners.manage',
                'subtitles.generate', 'subtitles.translate', 'subtitles.delete',
            ],
        ],
        [
            'name'         => 'finance',
            'display_name' => 'Finance',
            'description'  => 'Revenue, cohort, and funnel analytics plus subscription management and refunds.',
            'is_system'    => true,
            'priority'     => 50,
            'permissions'  => [
                'analytics.revenue', 'analytics.cohort', 'analytics.funnel',
                'subscriptions.view', 'subscriptions.refund',
            ],
        ],
        [
            'name'         => 'user',
            'display_name' => 'User',
            'description'  => 'Default role assigned to every account. No admin-panel access.',
            'is_system'    => true,
            'priority'     => 100,
            'permissions'  => [],
        ],
    ];

    public function run(): void
    {
        // ── 1. Seed every permission (idempotent) ─────────────
        $allSlugs = [];
        foreach (self::PERMISSIONS as $category => $perms) {
            foreach ($perms as $row) {
                [$slug, $displayName] = $row;
                $description = $row[2] ?? null;

                Permission::updateOrCreate(
                    ['name' => $slug],
                    [
                        'display_name' => $displayName,
                        'category'     => $category,
                        'description'  => $description,
                    ]
                );

                $allSlugs[] = $slug;
            }
        }

        // ── 2. Seed every role and sync its permission set ────
        foreach (self::ROLES as $def) {
            $role = Role::updateOrCreate(
                ['name' => $def['name']],
                [
                    'display_name' => $def['display_name'],
                    'description'  => $def['description'],
                    'is_system'    => $def['is_system'],
                    'priority'     => $def['priority'],
                ]
            );

            $slugs = $def['permissions'] === '*'
                ? $allSlugs
                : $def['permissions'];

            $role->syncPermissions($slugs);
        }

        // ── 3. Backfill role assignments for existing users ───
        $this->backfillUserRoles();
    }

    /**
     * Backfill role assignments based on the legacy `users.is_admin` boolean.
     *
     * Why insertOrIgnore over the relation's attach():
     *   - Pivot has a composite PK (role_id, user_id) — duplicate inserts
     *     would crash, but insertOrIgnore quietly drops them, so this method
     *     is fully idempotent across re-runs.
     *   - Avoids loading every user into memory just to attach() one row.
     *
     * Both legacy columns (`users.is_admin`, `users.role`) are intentionally
     * left in place. They're transitional and will be removed in a follow-up
     * migration once every read site uses the role/permission API.
     */
    private function backfillUserRoles(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('role_user')) {
            return;
        }

        $adminRoleId = (int) Role::query()->where('name', 'admin')->value('id');
        $userRoleId  = (int) Role::query()->where('name', 'user')->value('id');

        if ($adminRoleId === 0 || $userRoleId === 0) {
            return; // defensive — seeder above should have created both
        }

        $now = now();

        // Admins → 'admin' role
        if (Schema::hasColumn('users', 'is_admin')) {
            $adminIds = DB::table('users')
                ->where('is_admin', true)
                ->pluck('id');

            $rows = $adminIds->map(fn ($uid) => [
                'role_id'             => $adminRoleId,
                'user_id'             => (int) $uid,
                'assigned_by_user_id' => null,
                'assigned_at'         => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ])->all();

            if (! empty($rows)) {
                // insertOrIgnore lets us re-seed without crashing on the
                // composite PK constraint.
                DB::table('role_user')->insertOrIgnore($rows);
            }
        }

        // Everyone gets the baseline 'user' role
        $allUserIds = DB::table('users')->pluck('id');
        $rows = $allUserIds->map(fn ($uid) => [
            'role_id'             => $userRoleId,
            'user_id'             => (int) $uid,
            'assigned_by_user_id' => null,
            'assigned_at'         => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ])->all();

        if (! empty($rows)) {
            DB::table('role_user')->insertOrIgnore($rows);
        }
    }
}
