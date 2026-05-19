<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

/**
 * SegmentBuilder — translates the JSON segment DSL stored on EmailCampaign
 * into an Eloquent query builder filtered to the audience subset.
 *
 * The DSL is intentionally tiny so non-technical admins can compose it via
 * the UI picker. Composite segments (and/or) recurse on their `children`.
 *
 * Supported leaf types:
 *   {type:'all'}                                  — every verified user
 *   {type:'role',  name:'subscriber'}             — by role slug
 *   {type:'plan',  plan_id:1}                     — active subscribers on a plan
 *   {type:'inactive_days', days:30}               — no WatchHistory in N days
 *   {type:'new_signups',   days:7}                — registered in last N days
 *   {type:'custom_emails', emails:['a@b.com']}    — explicit list (non-users OK)
 *
 * Composite:
 *   {type:'and', children:[...]}                  — intersection of children
 *   {type:'or',  children:[...]}                  — union of children
 *
 * SECURITY: `custom_emails` is the only path that admits arbitrary email
 * strings — those are NOT joined against a query builder by this class;
 * the dispatcher handles them out-of-band. estimate() and resolveUsers()
 * never blindly trust admin input — every type goes through a switch and
 * unknown shapes return an empty result (whereRaw('0=1')).
 */
class SegmentBuilder
{
    /** Maximum recursion depth for composite segments — sanity cap. */
    public const MAX_DEPTH = 6;

    /**
     * Count users matching the segment WITHOUT materialising them. For
     * `custom_emails` this returns the de-duplicated list size (regardless
     * of whether the addresses correspond to real users).
     *
     * @param  array<string, mixed>  $segment
     */
    public function estimate(array $segment): int
    {
        // Special-case: pure custom_emails segment doesn't hit the users
        // table at all — return the de-duped list size.
        if (($segment['type'] ?? null) === 'custom_emails') {
            $emails = $this->normalizeEmails($segment['emails'] ?? []);
            return count($emails);
        }

        return $this->resolveUsers($segment)->count();
    }

    /**
     * Build an Eloquent query for the User rows matching the segment.
     *
     * For the `custom_emails` segment this returns a query restricted to
     * the email list; addresses that don't correspond to a real user
     * simply won't appear in the result — the dispatcher MUST handle
     * those separately (it does — see CampaignDispatcher::enqueue).
     *
     * @param  array<string, mixed>  $segment
     * @return Builder<User>
     */
    public function resolveUsers(array $segment, int $depth = 0): Builder
    {
        if ($depth > self::MAX_DEPTH) {
            // Defensive: nested-too-deep — collapse to an empty set rather
            // than blow up the stack with crafted JSON.
            return User::query()->whereRaw('0 = 1');
        }

        $type = (string) ($segment['type'] ?? '');

        return match ($type) {
            'all'           => $this->all(),
            'role'          => $this->byRole((string) ($segment['name'] ?? '')),
            'plan'          => $this->byPlan((int) ($segment['plan_id'] ?? 0)),
            'inactive_days' => $this->byInactiveDays((int) ($segment['days'] ?? 30)),
            'new_signups'   => $this->byNewSignups((int) ($segment['days'] ?? 7)),
            'custom_emails' => $this->byCustomEmails($segment['emails'] ?? []),
            'and'           => $this->compositeAnd($segment['children'] ?? [], $depth + 1),
            'or'            => $this->compositeOr($segment['children'] ?? [], $depth + 1),
            default         => User::query()->whereRaw('0 = 1'),
        };
    }

    /**
     * Pull a small sample of emails for the audience preview UI.
     *
     * @param  array<string, mixed>  $segment
     * @return list<string>
     */
    public function sampleEmails(array $segment, int $limit = 10): array
    {
        if (($segment['type'] ?? null) === 'custom_emails') {
            return array_slice($this->normalizeEmails($segment['emails'] ?? []), 0, $limit);
        }

        return $this->resolveUsers($segment)
            ->limit($limit)
            ->pluck('email')
            ->map(fn ($e): string => (string) $e)
            ->all();
    }

    // ── Leaf builders ──────────────────────────────────────────

    /**
     * Baseline: every verified user. Unverified accounts never receive
     * marketing email — they haven't proven they own the inbox yet, so
     * including them would invite spam complaints.
     *
     * @return Builder<User>
     */
    private function all(): Builder
    {
        return User::query()->whereNotNull('email_verified_at');
    }

    /**
     * @return Builder<User>
     */
    private function byRole(string $roleName): Builder
    {
        $roleName = trim($roleName);

        if ($roleName === '') {
            return User::query()->whereRaw('0 = 1');
        }

        $base = $this->all();

        // Defensive: handle environments where the pivot RBAC migration
        // hasn't run yet — fall back to the legacy single-column `role`.
        if (!Schema::hasTable('role_user') || !Schema::hasTable('roles')) {
            if (Schema::hasColumn('users', 'role')) {
                return $base->where('role', $roleName);
            }
            return User::query()->whereRaw('0 = 1');
        }

        return $base->whereExists(function (QueryBuilder $q) use ($roleName): void {
            $q->select('role_user.user_id')
              ->from('role_user')
              ->join('roles', 'roles.id', '=', 'role_user.role_id')
              ->whereColumn('role_user.user_id', 'users.id')
              ->where('roles.name', $roleName);
        });
    }

    /**
     * Users whose currently active subscription is on the given plan.
     *
     * @return Builder<User>
     */
    private function byPlan(int $planId): Builder
    {
        if ($planId <= 0) {
            return User::query()->whereRaw('0 = 1');
        }

        return $this->all()->whereExists(function (QueryBuilder $q) use ($planId): void {
            $q->select('subscriptions.id')
              ->from('subscriptions')
              ->whereColumn('subscriptions.user_id', 'users.id')
              ->where('subscriptions.subscription_plan_id', $planId)
              ->where('subscriptions.status', 'active')
              ->where(function (QueryBuilder $sub): void {
                  $sub->whereNull('subscriptions.ends_at')
                      ->orWhere('subscriptions.ends_at', '>', now());
              });
        });
    }

    /**
     * Users with NO WatchHistory row in the last N days. Newly-registered
     * users who have NEVER watched anything are also included (they have
     * zero rows, which trivially satisfies "no rows in last N days").
     *
     * @return Builder<User>
     */
    private function byInactiveDays(int $days): Builder
    {
        $days = max(1, $days);
        $cutoff = now()->subDays($days);

        return $this->all()->whereNotExists(function (QueryBuilder $q) use ($cutoff): void {
            $q->select('watch_histories.id')
              ->from('watch_histories')
              ->whereColumn('watch_histories.user_id', 'users.id')
              ->where('watch_histories.last_watched_at', '>=', $cutoff);
        });
    }

    /**
     * @return Builder<User>
     */
    private function byNewSignups(int $days): Builder
    {
        $days = max(1, $days);

        return $this->all()->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Restrict to users whose email is in the explicit list. Any address
     * that doesn't correspond to a real user row is silently dropped here
     * (and handled by the dispatcher separately).
     *
     * @param  mixed  $emails
     * @return Builder<User>
     */
    private function byCustomEmails(mixed $emails): Builder
    {
        $list = $this->normalizeEmails($emails);

        if ($list === []) {
            return User::query()->whereRaw('0 = 1');
        }

        // Custom-email blasts deliberately DON'T require email_verified_at
        // — admin chose these addresses explicitly so we trust the choice.
        return User::query()->whereIn('email', $list);
    }

    // ── Composite builders ─────────────────────────────────────

    /**
     * @param  mixed  $children
     * @return Builder<User>
     */
    private function compositeAnd(mixed $children, int $depth): Builder
    {
        $kids = $this->normalizeChildren($children);

        if ($kids === []) {
            return User::query()->whereRaw('0 = 1');
        }

        $query = $this->all();

        foreach ($kids as $child) {
            $childIds = $this->resolveUsers($child, $depth)->select('users.id');
            $query->whereIn('users.id', $childIds);
        }

        return $query;
    }

    /**
     * @param  mixed  $children
     * @return Builder<User>
     */
    private function compositeOr(mixed $children, int $depth): Builder
    {
        $kids = $this->normalizeChildren($children);

        if ($kids === []) {
            return User::query()->whereRaw('0 = 1');
        }

        // UNION of child sub-queries via whereIn on a fresh users query.
        // We can't use ->union() cleanly with Eloquent + later filters,
        // so we accumulate the matched IDs and re-issue a single SELECT.
        $query = User::query()->where(function (Builder $or) use ($kids, $depth): void {
            foreach ($kids as $child) {
                $childIds = $this->resolveUsers($child, $depth)->select('users.id');
                $or->orWhereIn('users.id', $childIds);
            }
        });

        return $query;
    }

    // ── Helpers ────────────────────────────────────────────────

    /**
     * @param  mixed  $emails
     * @return list<string>
     */
    private function normalizeEmails(mixed $emails): array
    {
        if (!is_array($emails)) {
            return [];
        }

        $clean = [];
        foreach ($emails as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $e = strtolower(trim($raw));
            if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $clean[$e] = true; // dedupe via keys
        }

        return array_values(array_keys($clean));
    }

    /**
     * @param  mixed  $children
     * @return list<array<string, mixed>>
     */
    private function normalizeChildren(mixed $children): array
    {
        if (!is_array($children)) {
            return [];
        }

        $valid = [];
        foreach ($children as $child) {
            if (is_array($child) && isset($child['type']) && is_string($child['type'])) {
                $valid[] = $child;
            }
        }

        return $valid;
    }
}
