<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserList;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see UserList} CRUD + item-management actions.
 *
 * `view` uses the visibility-aware helper on the model so guests can view
 * public lists. Mutating actions require ownership (or super-admin, which is
 * handled by the global Gate::before in {@see \App\Providers\AuthServiceProvider}).
 *
 * Note: the controller normally calls `$this->authorize('update', $list)`
 * even though super-admin bypass would suffice — keeping the explicit call
 * means the policy method runs and the audit trail (when wired) sees the
 * action name.
 */
class UserListPolicy
{
    use HandlesAuthorization;

    public function viewAny(?User $user): bool
    {
        // The /lists browse page is intentionally open to guests.
        return true;
    }

    /**
     * Visibility is the source of truth — public/unlisted are viewable by
     * anyone with the URL, private is owner-only. The model centralises the
     * rule so other call sites (e.g. shelf rendering on the home page)
     * cannot drift.
     */
    public function view(?User $user, UserList $list): bool
    {
        return $list->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        // Any authenticated user can create lists.
        return true;
    }

    public function update(User $user, UserList $list): bool
    {
        return $this->isOwnerOrSuperAdmin($user, $list);
    }

    public function delete(User $user, UserList $list): bool
    {
        return $this->isOwnerOrSuperAdmin($user, $list);
    }

    /** Adding a movie is an edit to the list — same ownership gate. */
    public function addMovie(User $user, UserList $list): bool
    {
        return $this->isOwnerOrSuperAdmin($user, $list);
    }

    public function removeMovie(User $user, UserList $list): bool
    {
        return $this->isOwnerOrSuperAdmin($user, $list);
    }

    public function reorder(User $user, UserList $list): bool
    {
        return $this->isOwnerOrSuperAdmin($user, $list);
    }

    /**
     * Following is a viewer action — the visibility check (`view`) gates
     * whether the list is reachable in the first place; once it is, anyone
     * but the owner can follow it.
     */
    public function follow(User $user, UserList $list): bool
    {
        return ! $list->isOwnedBy($user) && $list->isVisibleTo($user);
    }

    private function isOwnerOrSuperAdmin(User $user, UserList $list): bool
    {
        if ($list->isOwnedBy($user)) {
            return true;
        }

        // Defence-in-depth: even though Gate::before in AuthServiceProvider
        // already short-circuits super-admin, we re-check here so a future
        // refactor that drops Gate::before does not silently open access.
        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }
}
