<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    public static function rule(): Password
    {
        return Password::min(12)->mixedCase()->numbers()->symbols();
    }
}
