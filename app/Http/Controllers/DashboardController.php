<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $stats = null;
        $recentUsers = collect();
        if (Gate::allows('viewAny', User::class)) {
            $stats = [
                'Total users' => User::count(),
                'Active accounts' => User::where('is_active', true)->count(),
                'Blocked accounts' => User::where('is_active', false)->count(),
                'Active admins' => User::where('is_active', true)->where('role', Role::Admin)->count(),
            ];
            $recentUsers = User::latest('id')->limit(5)->get();
        }

        return view('dashboard', compact('stats', 'recentUsers'));
    }
}
