<x-layout title="Users">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4"><h1 class="text-2xl font-semibold">Users</h1><a class="rounded-lg bg-slate-900 px-4 py-3 text-white" href="{{ route('admin.users.create') }}">Create user</a></div>
    <form class="mb-6 flex gap-3" method="GET" action="{{ route('admin.users.index') }}"><label class="sr-only" for="search">Search users</label><input id="search" class="min-w-0 flex-1 rounded border bg-white p-3" name="q" value="{{ request('q') }}" placeholder="Search by name or email" maxlength="100"><button class="rounded border px-4">Search</button></form>
    <div class="space-y-3">@forelse($users as $user)
        <article class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-white p-5">
            <div class="min-w-0"><h2 class="font-semibold">{{ $user->name }}</h2><p class="break-all text-sm text-slate-600">{{ $user->email }}</p><p class="mt-2 text-sm">{{ ucfirst($user->role->value) }} · {{ $user->is_active ? 'Active' : 'Blocked' }}</p></div>
            <div class="flex items-center gap-4"><a class="underline" href="{{ route('admin.users.edit', $user) }}">Edit</a>
            @can('delete', $user)<form method="POST" action="{{ route('admin.users.destroy', $user) }}" data-confirm="Delete this user permanently?">@csrf @method('DELETE')<button class="rounded border border-red-200 px-3 py-2 text-red-700">Delete</button></form>@endcan</div>
        </article>
    @empty<p class="rounded-xl bg-white p-8 text-slate-600">No users match your search.</p>@endforelse</div>
    <div class="mt-6">{{ $users->links() }}</div>
</x-layout>
