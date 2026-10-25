@props(['title' => 'Overview'])
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light">
    <title>{{ $title }} · Van Project</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
<a class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:p-3" href="#main-content">Skip to content</a>
@auth
    <aside class="fixed inset-y-0 left-0 hidden w-64 flex-col bg-slate-950 p-6 text-white lg:flex">
        <a href="{{ route('dashboard') }}" class="mb-12 flex items-center gap-3 font-semibold tracking-wide"><span class="grid h-10 w-10 place-items-center rounded-xl bg-teal-400 text-lg font-bold text-slate-950">V</span> VAN<span class="text-slate-500">/</span>ADMIN</a>
        <p class="mb-4 px-3 text-xs font-medium uppercase tracking-widest text-slate-500">Workspace</p>
        <nav aria-label="Main navigation" class="space-y-2">
            <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif class="block rounded-lg px-3 py-3 {{ request()->routeIs('dashboard') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">Overview</a>
            @if(auth()->user()->role === App\Enums\Role::User)
                <a href="{{ route('shifts.index') }}" @if(request()->routeIs('shifts.*')) aria-current="page" @endif class="block rounded-lg px-3 py-3 {{ request()->routeIs('shifts.*') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">My shifts</a>
            @endif
            @can('viewAny', App\Models\User::class)<a href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif class="block rounded-lg px-3 py-3 {{ request()->routeIs('admin.users.*') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">User management</a>@endcan
            @can('viewAny', App\Models\Van::class)
                <a href="{{ route('admin.vans.index') }}" @if(request()->routeIs('admin.vans.*')) aria-current="page" @endif class="block rounded-lg px-3 py-3 {{ request()->routeIs('admin.vans.*') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">Vans</a>
                <a href="{{ route('admin.vision-models.index') }}" @if(request()->routeIs('admin.vision-models.*')) aria-current="page" @endif class="block rounded-lg px-3 py-3 {{ request()->routeIs('admin.vision-models.*') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">Vision models</a>
                <a href="{{ route('admin.templates.index') }}" @if(request()->routeIs('admin.templates.*')) aria-current="page" @endif class="block rounded-lg px-3 py-3 {{ request()->routeIs('admin.templates.*') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">Inspection templates</a>
                <a href="{{ route('admin.shifts.index') }}" @if(request()->routeIs('admin.shifts.*')) aria-current="page" @endif class="block rounded-lg px-3 py-3 {{ request()->routeIs('admin.shifts.*') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">Shifts</a>
            @endcan
            @if(Route::has('admin.activity.index')) @can('viewAny', App\Models\User::class)<a href="{{ route('admin.activity.index') }}" class="block rounded-lg px-3 py-3 {{ request()->routeIs('admin.activity.*') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">Activity log</a>@endcan @endif
            <a href="{{ route('profile.show') }}" @if(request()->routeIs('profile.*')) aria-current="page" @endif class="block rounded-lg px-3 py-3 {{ request()->routeIs('profile.*') ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-900 hover:text-white' }}">My profile</a>
        </nav>
        <div class="mt-auto border-t border-slate-800 pt-6 text-sm"><p class="break-words font-medium">{{ auth()->user()->name }}</p><p class="mt-1 capitalize text-slate-500">{{ auth()->user()->role->value }}</p><form class="mt-4" method="POST" action="{{ route('logout') }}">@csrf<button class="text-slate-400 hover:text-white">Sign out →</button></form></div>
    </aside>
    <div class="lg:pl-64">
        <header class="border-b border-slate-200 bg-white px-4 py-4 sm:px-8">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4"><span class="text-sm font-medium text-slate-500">Workspace <span class="mx-2 text-slate-300">/</span> {{ $title }}</span><span class="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold capitalize text-teal-800">{{ auth()->user()->role->value }}</span></div>
            <details class="mx-auto mt-4 max-w-6xl lg:hidden"><summary class="cursor-pointer rounded border border-slate-200 px-3 py-2 text-sm">Menu</summary><nav aria-label="Mobile navigation" class="mt-3 flex flex-wrap gap-4 text-sm"><a class="underline" href="{{ route('dashboard') }}">Overview</a>@if(auth()->user()->role === App\Enums\Role::User)<a class="underline" href="{{ route('shifts.index') }}">My shifts</a>@endif @can('viewAny', App\Models\Van::class)<a class="underline" href="{{ route('admin.vans.index') }}">Vans</a><a class="underline" href="{{ route('admin.vision-models.index') }}">Vision models</a><a class="underline" href="{{ route('admin.templates.index') }}">Inspection templates</a><a class="underline" href="{{ route('admin.shifts.index') }}">Shifts</a>@endcan @can('viewAny', App\Models\User::class)<a class="underline" href="{{ route('admin.users.index') }}">User management</a>@if(Route::has('admin.activity.index'))<a class="underline" href="{{ route('admin.activity.index') }}">Activity log</a>@endif @endcan<a class="underline" href="{{ route('profile.show') }}">My profile</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="underline">Sign out</button></form></nav></details>
        </header>
@else
    <header class="px-4 pt-10 text-center text-sm font-bold tracking-widest text-slate-500">VAN / ADMIN</header>
    <div>
@endauth
        <main id="main-content" class="mx-auto max-w-6xl px-4 py-8 sm:px-8 sm:py-10">
            @if(session('status'))<p role="status" class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('status') }}</p>@endif
            @if($errors->any())<div role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900"><p class="mb-2 font-semibold">Please check the following:</p><ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            {{ $slot }}
        </main>
    </div>
</body>
</html>
