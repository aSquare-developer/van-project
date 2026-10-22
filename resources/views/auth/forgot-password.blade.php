<x-layout title="Reset password">
    <section class="mx-auto max-w-md rounded-2xl bg-white p-8 shadow-sm">
        <h1 class="mb-4 text-2xl font-semibold">Reset password</h1>
        <p class="mb-6 text-slate-600">Enter your email to request a reset link.</p>
        <form method="POST" action="{{ route('password.email') }}" class="space-y-5">@csrf
            <label class="block">Email<input class="mt-2 block w-full rounded border p-3" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required></label>
            <button class="rounded-lg bg-slate-900 p-3 text-white">Send reset link</button>
        </form>
        <a class="mt-5 inline-block underline" href="{{ route('login') }}">Back to sign in</a>
    </section>
</x-layout>
