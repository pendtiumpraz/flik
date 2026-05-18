<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role aggregates a set of {@see Permission} grants and can be assigned to
 * one or more {@see User}. Roles flagged `is_system=true` are seeded by
 * {@see \Database\Seeders\RolePermissionSeeder} and must not be deleted —
 * the controller layer is responsible for enforcing that guard.
 *
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string|null $description
 * @property bool $is_system
 * @property int $priority
 */
class Role extends Model
{
    use HasFactory;

    /**
     * SECURITY: `$guarded = []` lets the seeder + admin tooling write any
     * field, but `is_system` is forcibly stripped from end-user payloads
     * at the controller layer (and re-asserted only by the seeder via
     * forceFill). Never accept `is_system` from a request directly.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system',
        'priority',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'priority' => 'integer',
    ];

    // ── Relations ─────────────────────────────────────────────
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user')
            ->withPivot(['assigned_by_user_id', 'assigned_at'])
            ->withTimestamps();
    }

    // ── Scopes ────────────────────────────────────────────────
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    // ── Permission helpers ────────────────────────────────────
    /**
     * Check whether this role grants the named permission. Loads the
     * `permissions` relation on demand and short-circuits via the pivot
     * collection so subsequent calls are O(1).
     */
    public function hasPermission(string $name): bool
    {
        return $this->permissions
            ->contains(fn (Permission $p): bool => $p->name === $name);
    }

    /**
     * Grant a permission to this role. Accepts either a Permission model
     * or its slug. Silently no-ops when the slug doesn't resolve so the
     * caller can't accidentally crash on a typo (audit the slug list in
     * RolePermissionSeeder for the source of truth).
     */
    public function givePermission(Permission|string $perm): void
    {
        $permission = $this->resolvePermission($perm);

        if ($permission === null) {
            return;
        }

        // syncWithoutDetaching keeps existing grants and adds the new one
        // idempotently — safe to call repeatedly.
        $this->permissions()->syncWithoutDetaching([$permission->id]);

        // Bust the in-memory relation cache so hasPermission() sees the
        // grant immediately within the same request.
        $this->unsetRelation('permissions');
    }

    public function revokePermission(Permission|string $perm): void
    {
        $permission = $this->resolvePermission($perm);

        if ($permission === null) {
            return;
        }

        $this->permissions()->detach($permission->id);
        $this->unsetRelation('permissions');
    }

    /**
     * Replace the role's permission set with exactly the supplied list.
     * Accepts an array of slugs or Permission models. Unknown slugs are
     * dropped silently (same rationale as givePermission()).
     *
     * @param  array<int, Permission|string>  $perms
     */
    public function syncPermissions(array $perms): void
    {
        $ids = collect($perms)
            ->map(fn ($p) => $this->resolvePermission($p)?->id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->permissions()->sync($ids);
        $this->unsetRelation('permissions');
    }

    private function resolvePermission(Permission|string $perm): ?Permission
    {
        if ($perm instanceof Permission) {
            return $perm;
        }

        return Permission::query()->where('name', $perm)->first();
    }
}
