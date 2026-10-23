<?php

namespace App\Policies;

use App\Models\ShiftCheck;
use App\Models\User;

class ShiftCheckPolicy
{
    public function view(User $user, ShiftCheck $check): bool
    {
        return $user->can('view', $check->shift);
    }
}
