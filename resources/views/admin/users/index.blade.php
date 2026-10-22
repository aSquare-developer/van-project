<x-layout title="Users">
    <h1 class="mb-6 text-2xl font-semibold">Users</h1>
    <ul class="space-y-3">@forelse($users as $user)<li class="rounded bg-white p-4">{{ $user->name }} · {{ $user->email }} · {{ ucfirst($user->role->value) }}</li>@empty<li>No users found.</li>@endforelse</ul>
    <div class="mt-6">{{ $users->links() }}</div>
</x-layout>
