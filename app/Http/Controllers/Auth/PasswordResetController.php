<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PasswordRules;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => strtolower($data['email']), 'is_active' => true]);

        return back()->with('status', 'If an active account exists, a password reset link has been sent.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRules::rule()],
        ]);
        $data['email'] = strtolower($data['email']);
        $data['is_active'] = true;
        $status = Password::reset($data, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $locked = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_unless($locked->is_active, 403);
                $locked->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'session_version' => $locked->session_version + 1,
                ])->save();
                DB::table('sessions')->where('user_id', $locked->id)->delete();
                event(new PasswordReset($locked));
            });
        });

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)])->withInput($request->only('email'));
    }
}
