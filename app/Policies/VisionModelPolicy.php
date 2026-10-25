<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Models\VisionModel;

class VisionModelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === Role::Admin;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, VisionModel $model): bool
    {
        return $this->viewAny($user);
    }
}
