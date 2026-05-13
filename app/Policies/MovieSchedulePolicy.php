<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MovieSchedule;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see MovieSchedule}.
 *
 * Schedules are per-user calendar entries (the "Save for Friday Night"
 * feature). They are strictly private — including their .ics export.
 */
class MovieSchedulePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MovieSchedule $schedule): bool
    {
        return (int) $schedule->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MovieSchedule $schedule): bool
    {
        return (int) $schedule->user_id === (int) $user->id;
    }

    public function delete(User $user, MovieSchedule $schedule): bool
    {
        return (int) $schedule->user_id === (int) $user->id;
    }
}
