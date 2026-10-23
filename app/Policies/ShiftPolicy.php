<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Shift;
use App\Models\User;

class ShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->role, [Role::Admin, Role::User], true);
    }

    public function view(User $user, Shift $shift): bool
    {
        return $this->viewAny($user) && ($user->role === Role::Admin || $shift->worker_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->role === Role::Admin;
    }
}
