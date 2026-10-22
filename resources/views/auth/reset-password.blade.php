<x-layout title="Choose password">
    <section class="mx-auto max-w-md rounded-2xl bg-white p-8 shadow-sm">
        <h1 class="mb-4 text-2xl font-semibold">Choose a new password</h1>
        <p class="mb-6 text-slate-600">At least 12 characters, with uppercase, lowercase, a number and a symbol (maximum 72 bytes).</p>
        <form method="POST" action="{{ route('password.update') }}" class="space-y-5">@csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label class="block">Email<input class="mt-2 block w-full rounded border p-3" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="username" required></label>
            <label class="block">New password<input class="mt-2 block w-full rounded border p-3" type="password" name="password" autocomplete="new-password" minlength="12" required></label>
            <label class="block">Confirm password<input class="mt-2 block w-full rounded border p-3" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
            <button class="rounded-lg bg-slate-900 p-3 text-white">Reset password</button>
        </form>
    </section>
</x-layout>
