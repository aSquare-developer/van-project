<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\InspectionTemplate;
use App\Models\User;

class InspectionTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === Role::Admin;
    }

    public function view(User $user, InspectionTemplate $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, InspectionTemplate $record): bool
    {
        return $this->viewAny($user);
    }
}
