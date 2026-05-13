<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see QuizAttempt}.
 *
 * The leaderboard is public (read across users), but the FULL attempt
 * row — including which answers were given — is private to the player.
 */
class QuizAttemptPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, QuizAttempt $attempt): bool
    {
        return (int) $attempt->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Quiz attempts are immutable once submitted. */
    public function update(User $user, QuizAttempt $attempt): bool
    {
        return false;
    }

    public function delete(User $user, QuizAttempt $attempt): bool
    {
        return (int) $attempt->user_id === (int) $user->id;
    }
}
