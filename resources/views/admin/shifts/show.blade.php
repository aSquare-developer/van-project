<x-layout title="Shift details">
    <a href="{{ route('admin.shifts.index') }}" class="text-sm underline">← All shifts</a>
    <h1 class="mb-2 mt-5 break-words text-3xl font-semibold">{{ $shift->title }}</h1>
    <p class="mb-8 text-slate-500">{{ ucfirst(str_replace('_', ' ', $shift->status->value)) }} · {{ $shift->shift_date->format('d M Y') }}</p>
    <dl class="mb-8 grid gap-5 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-2">
        <div><dt class="text-sm text-slate-500">Worker</dt><dd class="mt-1 break-words font-medium">{{ $shift->worker->name }}</dd></div>
        <div><dt class="text-sm text-slate-500">Van at assignment</dt><dd class="mt-1 break-words font-medium">{{ $shift->van_name }}{{ $shift->van_registration ? ' — '.$shift->van_registration : '' }}</dd></div>
        <div><dt class="text-sm text-slate-500">Template at assignment</dt><dd class="mt-1 break-words font-medium">{{ $shift->template_name }}</dd></div>
        <div><dt class="text-sm text-slate-500">Assigned by</dt><dd class="mt-1 break-words font-medium">{{ $shift->assignedBy->name }}</dd></div>
    </dl>
    <h2 class="mb-4 text-xl font-semibold">Assigned checklist</h2>
    <ol class="space-y-4">
        @foreach($shift->checks as $check)
            <li class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3"><h3 class="break-words font-semibold">{{ $check->position }}. {{ $check->title }}</h3><span class="rounded-full bg-slate-100 px-3 py-1 text-xs">{{ ucfirst(str_replace('_', ' ', $check->status->value)) }}</span></div>
                <p class="mt-3 whitespace-pre-line break-words text-sm text-slate-600">{{ $check->instructions }}</p>
                <p class="mt-3 text-xs font-medium text-slate-500">{{ $check->is_required ? 'Required' : 'Optional' }}</p>
            </li>
        @endforeach
    </ol>
</x-layout>
