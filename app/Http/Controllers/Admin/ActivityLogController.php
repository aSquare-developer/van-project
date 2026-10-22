<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function __invoke(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('admin.activity.index', ['entries' => ActivityLog::latest('id')->paginate(30)]);
    }
}
