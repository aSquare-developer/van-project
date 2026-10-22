<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    public static function rule(): Password
    {
        return Password::min(12)->mixedCase()->numbers()->symbols()->rules([
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, chr(0)))) {
                    $fail('The password must be at most 72 bytes and must not contain null characters.');
                }
            },
        ]);
    }
}
