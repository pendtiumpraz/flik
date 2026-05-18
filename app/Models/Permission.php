<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Permission is an atomic capability slug (e.g. `movies.create`) that can be
 * attached to one or more {@see Role}. The canonical permission list lives
 * in {@see \Database\Seeders\RolePermissionSeeder}; the admin UI may add
 * additional custom permissions on top.
 *
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string $category
 * @property string|null $description
 */
class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'category',
        'description',
    ];

    // ── Relations ─────────────────────────────────────────────
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')
            ->withTimestamps();
    }

    // ── Helpers ───────────────────────────────────────────────
    /**
     * Group every permission by category for the admin role-editor UI.
     * Returns a Collection keyed by category slug, where each value is
     * itself a Collection of Permission models.
     *
     * @return Collection<string, Collection<int, self>>
     */
    public static function groupedByCategory(): Collection
    {
        return static::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category');
    }
}
