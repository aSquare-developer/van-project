<x-layout title="Vans">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div><h1 class="text-3xl font-semibold tracking-tight">Vans</h1><p class="mt-2 text-slate-500">Manage the vehicles used for shift inspections.</p></div>
        <a href="{{ route('admin.vans.create') }}" class="rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white">Add van</a>
    </div>
    <div class="space-y-3">
        @forelse($vans as $van)
            <article class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5">
                <div><h2 class="font-semibold">{{ $van->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ $van->registration ?? 'No registration entered' }} · {{ $van->is_active ? 'Active' : 'Inactive' }}</p></div>
                <a href="{{ route('admin.vans.edit', $van) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">Edit van</a>
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 p-8 text-slate-500">No vans yet. Add a van before assigning a shift.</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $vans->links() }}</div>
</x-layout>
