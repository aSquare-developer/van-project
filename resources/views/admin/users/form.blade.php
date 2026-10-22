<x-layout :title="$user->exists ? 'Edit user' : 'Create user'">
    <h1 class="mb-6 text-2xl font-semibold">{{ $user->exists ? 'Edit user' : 'Create user' }}</h1>
    <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="max-w-xl space-y-5 rounded-xl bg-white p-6">@csrf
        @if($user->exists) @method('PUT') @endif
        <label class="block">Name<input class="mt-2 block w-full rounded border p-3" name="name" value="{{ old('name', $user->name) }}" maxlength="255" autocomplete="name" required></label>
        <label class="block">Email<input class="mt-2 block w-full rounded border p-3" type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="254" autocomplete="email" required></label>
        <label class="block">Role<select class="mt-2 block w-full rounded border p-3" name="role">@foreach($roles as $role)<option value="{{ $role->value }}" @selected(old('role', $user->role?->value ?? 'user') === $role->value)>{{ ucfirst($role->value) }}</option>@endforeach</select></label>
        <input type="hidden" name="is_active" value="0">
        <label class="flex items-center gap-3"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->exists ? $user->is_active : true))> Account active</label>
        <p class="text-sm text-slate-600">Password: 12+ characters with uppercase, lowercase, a number and a symbol (maximum 72 bytes). {{ $user->exists ? 'Leave blank to keep the current password.' : '' }}</p>
        <label class="block">Password<input class="mt-2 block w-full rounded border p-3" type="password" name="password" autocomplete="new-password" minlength="12" @required(! $user->exists)></label>
        <label class="block">Confirm password<input class="mt-2 block w-full rounded border p-3" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" @required(! $user->exists)></label>
        <div class="flex gap-4"><button class="rounded-lg bg-slate-900 px-5 py-3 text-white">Save user</button><a class="p-3 underline" href="{{ route('admin.users.index') }}">Cancel</a></div>
    </form>
</x-layout>
