<x-layout :title="$template->exists ? 'Edit template' : 'Create template'">
    <h1 class="mb-2 text-3xl font-semibold">{{ $template->exists ? 'Edit template' : 'Create template' }}</h1>
    <p class="mb-8 text-slate-500">Changes apply to future assignments. Existing shift checklists keep their original instructions.</p>
    <form data-checklist-editor method="POST" action="{{ $template->exists ? route('admin.templates.update', $template) : route('admin.templates.store') }}" class="max-w-3xl space-y-6">
        @csrf
        @if($template->exists) @method('PUT') @endif
        <div class="space-y-5 rounded-xl border border-slate-200 bg-white p-5 sm:p-6">
            <label class="block">Name<input name="name" value="{{ old('name', $template->name) }}" maxlength="255" required class="mt-2 block w-full rounded border border-slate-300 p-3"></label>
            <label class="block">Description <span class="text-sm text-slate-500">(optional)</span><textarea name="description" rows="3" maxlength="5000" class="mt-2 block w-full rounded border border-slate-300 p-3">{{ old('description', $template->description) }}</textarea></label>
            <input type="hidden" name="is_active" value="0">
            <label class="flex items-center gap-3"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->exists ? $template->is_active : true))> Template active</label>
        </div>
        <div>
            <h2 class="text-xl font-semibold">Checklist items</h2>
            <p class="mt-2 text-sm text-slate-500">Choose an available check for each item. Up to 30 items per template.</p>
        </div>
        <noscript><p class="rounded border border-amber-300 bg-amber-50 p-4">Enable JavaScript to add, remove or reorder items.</p></noscript>
        @php
            $rows = old('items', $template->exists ? $template->items->toArray() : [[]]);
            $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [[]];
        @endphp
        <div data-checklist-items class="space-y-5">
            @foreach($rows ?: [[]] as $index => $item)
                @include('admin.templates._item', ['index' => $index, 'item' => $item])
            @endforeach
        </div>
        <template data-item-template>@include('admin.templates._item', ['index' => '__INDEX__', 'item' => []])</template>
        <button type="button" data-add-item class="rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm disabled:cursor-default disabled:opacity-40">+ Add item</button>
        <p data-item-announcement aria-live="polite" class="sr-only"></p>
        <div class="flex flex-wrap gap-3"><button class="rounded-lg bg-slate-900 px-5 py-3 text-white">Save template</button><a href="{{ route('admin.templates.index') }}" class="p-3 underline">Cancel</a></div>
    </form>
</x-layout>
