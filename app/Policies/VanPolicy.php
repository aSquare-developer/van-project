<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Models\Van;

class VanPolicy
{
    public function delete(User $user, Van $record): bool
    {
        return $user->is_active && $user->role === Role::Admin;
    }

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === Role::Admin;
    }

    public function view(User $user, Van $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Van $record): bool
    {
        return $this->viewAny($user);
    }
}
