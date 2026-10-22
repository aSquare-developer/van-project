<x-layout title="Sign in">
    <section class="mx-auto max-w-md rounded-2xl bg-white p-8 shadow-sm">
        <h1 class="mb-6 text-2xl font-semibold">Sign in</h1>
        <form method="POST" action="{{ route('login') }}" class="space-y-5">
            @csrf
            <label class="block">Email<input class="mt-2 block w-full rounded border p-3" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
            <label class="block">Password<input class="mt-2 block w-full rounded border p-3" type="password" name="password" autocomplete="current-password" required></label>
            <button class="w-full rounded-lg bg-slate-900 p-3 font-medium text-white">Sign in</button>
        </form>
        <a class="mt-5 inline-block underline" href="{{ route('password.request') }}">Forgot your password?</a>
    </section>
</x-layout>
