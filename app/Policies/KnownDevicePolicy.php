<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\KnownDevice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for {@see KnownDevice}.
 *
 * Trusted-device registry per user. Marking someone else's device as
 * trusted would skip their new-device login alert — strict ownership.
 */
class KnownDevicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, KnownDevice $device): bool
    {
        return (int) $device->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        // Created automatically by LoginAlertService, not by users.
        return false;
    }

    public function update(User $user, KnownDevice $device): bool
    {
        return (int) $device->user_id === (int) $user->id;
    }

    public function delete(User $user, KnownDevice $device): bool
    {
        return (int) $device->user_id === (int) $user->id;
    }
}
