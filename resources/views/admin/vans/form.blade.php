<x-layout :title="$van->exists ? 'Edit van' : 'Add van'">
    <h1 class="mb-2 text-3xl font-semibold">{{ $van->exists ? 'Edit van' : 'Add van' }}</h1>
    <p class="mb-8 text-slate-500">Inactive vans remain in history and cannot receive new shifts.</p>
    <form method="POST" action="{{ $van->exists ? route('admin.vans.update', $van) : route('admin.vans.store') }}" class="max-w-xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">
        @csrf
        @if($van->exists) @method('PUT') @endif
        <label class="block">Name<input name="name" value="{{ old('name', $van->name) }}" maxlength="255" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
        <label class="block">Registration <span class="text-sm text-slate-500">(optional)</span><input name="registration" value="{{ old('registration', $van->registration) }}" maxlength="32" class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
        <input type="hidden" name="is_active" value="0">
        <label class="flex items-center gap-3"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $van->exists ? $van->is_active : true))> Van active</label>
        <div class="flex flex-wrap gap-3"><button class="rounded-lg bg-slate-900 px-5 py-3 text-white">Save van</button><a href="{{ route('admin.vans.index') }}" class="p-3 underline">Cancel</a></div>
    </form>
</x-layout>
