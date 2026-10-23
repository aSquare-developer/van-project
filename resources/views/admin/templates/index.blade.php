<x-layout title="Inspection templates">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div><h1 class="text-3xl font-semibold tracking-tight">Inspection templates</h1><p class="mt-2 text-slate-500">Reusable checklists for the end of a shift.</p></div>
        <a href="{{ route('admin.templates.create') }}" class="rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white">Create template</a>
    </div>
    <div class="space-y-3">
        @forelse($templates as $template)
            <article class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5">
                <div class="min-w-0"><h2 class="break-words font-semibold">{{ $template->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ $template->items_count }} items · {{ $template->is_active ? 'Active' : 'Inactive' }}</p></div>
                <a href="{{ route('admin.templates.edit', $template) }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm">Edit template</a>
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 p-8 text-slate-500">No templates yet. Create a checklist before assigning a shift.</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $templates->links() }}</div>
</x-layout>
