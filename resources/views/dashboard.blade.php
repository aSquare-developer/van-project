<x-layout title="Overview">
    <p class="mb-2 text-xs font-semibold uppercase tracking-widest text-teal-700">Your workspace</p>
    <div class="mb-8 flex flex-wrap items-end justify-between gap-4"><div><h1 class="text-3xl font-semibold tracking-tight">Welcome, {{ auth()->user()->name }}.</h1><p class="mt-2 text-slate-500">{{ $stats ? 'Manage your team and keep access under control.' : 'Your account and workspace at a glance.' }}</p></div><span class="text-sm text-slate-500">{{ now()->format('d M Y') }}</span></div>
    @if(auth()->user()->role === App\Enums\Role::User)
        <section class="mb-6 max-w-2xl rounded-2xl border border-teal-200 bg-teal-50 p-6 sm:p-8">
            <h2 class="text-xl font-semibold">Your shift checklists</h2>
            <p class="mt-3 text-sm text-slate-600">See your assigned shifts and open each item for its instructions and result.</p>
            <a class="mt-5 inline-block rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white" href="{{ route('shifts.index') }}">View my shifts →</a>
        </section>
    @endif
    @if($stats)
        <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">@foreach($stats as $label => $value)<section class="rounded-2xl border border-slate-200 bg-white p-6"><h2 class="text-sm font-medium text-slate-500">{{ $label }}</h2><p class="mt-4 text-4xl font-semibold tracking-tight">{{ $value }}</p></section>@endforeach</div>
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 p-6"><div><h2 class="font-semibold">Newest accounts</h2><p class="mt-1 text-sm text-slate-500">The latest additions to your workspace.</p></div><a class="text-sm font-semibold text-teal-700 underline" href="{{ route('admin.users.index') }}">View all users →</a></div><div class="divide-y divide-slate-100">@forelse($recentUsers as $user)<div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4"><div class="min-w-0"><p class="font-medium">{{ $user->name }}</p><p class="break-all text-sm text-slate-500">{{ $user->email }}</p></div><div class="flex gap-3 text-sm"><span class="capitalize text-slate-500">{{ $user->role->value }}</span><span class="{{ $user->is_active ? 'text-teal-700' : 'text-red-700' }}">{{ $user->is_active ? 'Active' : 'Blocked' }}</span></div></div>@empty<p class="p-6 text-slate-500">No accounts yet.</p>@endforelse</div></section>
    @else
        <section class="max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 sm:p-8"><span class="mb-5 inline-block rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-800">Account active</span><h2 class="text-xl font-semibold">You're signed in.</h2><p class="mt-3 text-slate-500">View your account details in your profile. Contact an administrator if your access needs to change.</p><a class="mt-6 inline-block rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white" href="{{ route('profile.show') }}">View my profile →</a></section>
    @endif
</x-layout>
