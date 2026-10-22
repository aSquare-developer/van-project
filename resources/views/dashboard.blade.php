<x-layout title="Dashboard">
    <h1 class="text-2xl font-semibold">Dashboard</h1>
    <p class="my-6">Welcome, {{ auth()->user()->name }}.</p>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="rounded bg-slate-900 px-4 py-2 text-white">Sign out</button></form>
</x-layout>
