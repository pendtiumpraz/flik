<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see Comment}.
 *
 * Ownership rule: comments belong to the user who posted them via `user_id`.
 * Admins (`isSuperAdmin()` / `is_admin`) get a blanket bypass through the
 * {@see AuthServiceProvider::boot()} `Gate::before()` hook — no need to add
 * `|| $user->is_admin` checks in every method here.
 *
 * `view` is intentionally permissive: comment threads are public on the
 * movie detail page, so any signed-in user may see them.
 */
class CommentPolicy
{
    use HandlesAuthorization;

    /**
     * Only staff/admin should browse the global comments table (e.g.
     * moderation queue). Regular users browse their own via the profile.
     */
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Reading a single comment row. Public surface — anyone signed in
     * may load a comment (the moderation status filter is applied in the
     * query layer, not here).
     */
    public function view(User $user, Comment $comment): bool
    {
        return true;
    }

    /**
     * Any authenticated user can create a comment. Rate limiting and
     * spoiler/sentiment moderation happen elsewhere.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Editing a comment is restricted to its author. Admin override is
     * delivered via Gate::before — see AuthServiceProvider.
     */
    public function update(User $user, Comment $comment): bool
    {
        return (int) $comment->user_id === (int) $user->id;
    }

    /**
     * Deleting a comment: author-owned. Admin bypass handled by Gate::before.
     */
    public function delete(User $user, Comment $comment): bool
    {
        return (int) $comment->user_id === (int) $user->id;
    }

    /**
     * Restoring a soft-deleted comment is a moderator action.
     */
    public function restore(User $user, Comment $comment): bool
    {
        return false;
    }

    /**
     * Hard-delete: admin only (Gate::before grants).
     */
    public function forceDelete(User $user, Comment $comment): bool
    {
        return false;
    }
}
