<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === Role::Admin;
    }

    public function view(User $user, User $subject): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $subject): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, User $subject): bool
    {
        return $this->viewAny($user) && $user->id !== $subject->id;
    }
}
