<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Rating;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see Rating}.
 *
 * Ratings/reviews are publicly visible on the movie detail page, but
 * only the author may update or delete their own row. We prevent
 * "rating-as-someone-else" by always sourcing user_id from auth().
 */
class RatingPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Rating $rating): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Rating $rating): bool
    {
        return (int) $rating->user_id === (int) $user->id;
    }

    public function delete(User $user, Rating $rating): bool
    {
        return (int) $rating->user_id === (int) $user->id;
    }
}
