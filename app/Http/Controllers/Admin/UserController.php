<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\UserManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = User::query()->latest('id');
        if (! empty($filters['q'])) {
            $query->where(function ($query) use ($filters): void {
                $query->where('name', 'like', '%'.$filters['q'].'%')->orWhere('email', 'like', '%'.$filters['q'].'%');
            });
        }

        return view('admin.users.index', ['users' => $query->paginate(20)->withQueryString()]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('admin.users.form', ['user' => new User, 'roles' => Role::cases()]);
    }

    public function store(StoreUserRequest $request, UserManager $manager): RedirectResponse
    {
        $manager->create($request->user(), $request->validated());

        return redirect()->route('admin.users.index')->with('status', 'User created.');
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('admin.users.form', ['user' => $user, 'roles' => Role::cases()]);
    }

    public function update(UpdateUserRequest $request, User $user, UserManager $manager): RedirectResponse
    {
        $manager->update($request->user(), $user->id, $request->validated());

        return redirect()->route('admin.users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user, UserManager $manager): RedirectResponse
    {
        Gate::authorize('delete', $user);
        $manager->delete($request->user(), $user->id);

        return redirect()->route('admin.users.index')->with('status', 'User deleted.');
    }
}
