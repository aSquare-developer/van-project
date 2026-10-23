<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Models\ShiftCheck;
use App\Models\User;

class ShiftCheckPolicy
{
    public function view(User $user, ShiftCheck $check): bool
    {
        return $user->can('view', $check->shift);
    }

    public function submitPhoto(User $user, ShiftCheck $check): bool
    {
        return $user->is_active && $user->role === Role::User
            && $check->shift->worker_id === $user->id
            && in_array($check->shift->status, [ShiftStatus::Assigned, ShiftStatus::InProgress, ShiftStatus::ReturnedForCheck], true);
    }
}
