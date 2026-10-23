<x-layout title="Inspection item">
    <a href="{{ route('shifts.show', $shift) }}" class="inline-block py-2 text-sm underline">← Back to checklist</a>
    <p class="mb-3 mt-5 break-words text-sm text-slate-500">{{ $shift->title }} · {{ $shift->shift_date->format('d M Y') }}</p>
    <h1 class="break-words text-3xl font-semibold tracking-tight">{{ $check->position }}. {{ $check->title }}</h1>
    <div class="mb-8 mt-4 flex flex-wrap items-center gap-3"><x-status-badge :status="$check->status" /><span class="text-sm font-medium text-slate-500">{{ $check->is_required ? 'Required check' : 'Optional check' }}</span></div>
    <section class="mb-5 max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 sm:p-7">
        <h2 class="text-lg font-semibold">Instructions</h2>
        <p class="mt-4 whitespace-pre-line break-words leading-relaxed text-slate-700">{{ $check->instructions }}</p>
        <p class="mt-6 break-words text-sm text-slate-500">Van: {{ $shift->van_name }}{{ $shift->van_registration ? ' — '.$shift->van_registration : '' }}</p>
    </section>
    <section class="max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 sm:p-7" aria-label="Inspection result">
        <h2 class="text-lg font-semibold">Result</h2>
        <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ $check->status->description() }}</p>
    </section>
</x-layout>
