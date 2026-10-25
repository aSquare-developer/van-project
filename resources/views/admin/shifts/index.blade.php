<x-layout title="Shifts">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div><h1 class="text-3xl font-semibold tracking-tight">Shifts</h1><p class="mt-2 text-slate-500">Each worker receives a separate checklist for their assigned shift.</p></div>
        <a href="{{ route('admin.shifts.create') }}" class="rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white">Assign shift</a>
    </div>
    <form method="GET" action="{{ route('admin.shifts.index') }}" class="mb-6 flex flex-wrap items-end gap-3">
        <label class="block text-sm font-medium">Status<select name="status" class="mt-2 block rounded-lg border border-slate-300 bg-white p-3"><option value="">All statuses</option>@foreach(App\Enums\ShiftStatus::cases() as $status)<option value="{{ $status->value }}" @selected(request('status') === $status->value)>{{ $status->label() }}</option>@endforeach</select></label>
        <button class="rounded-lg border border-slate-300 bg-white px-4 py-3 text-sm">Filter</button>
        <a href="{{ route('admin.shifts.index', ['status' => 'awaiting_review']) }}" class="px-2 py-3 text-sm underline">Awaiting review ({{ $awaitingCount }})</a>
    </form>
    <div class="space-y-3">
        @forelse($shifts as $shift)
            <article class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5">
                <div class="min-w-0"><h2 class="break-words font-semibold"><a href="{{ route('admin.shifts.show', $shift) }}" class="underline decoration-slate-300 underline-offset-4">{{ $shift->title }}</a></h2><p class="mt-2 break-words text-sm text-slate-500">{{ $shift->shift_date->format('d M Y') }} · {{ $shift->worker->name }} · {{ $shift->van_name }}</p><p class="mt-1 text-sm text-slate-500">{{ $shift->checks_count }} items</p></div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium">{{ ucfirst(str_replace('_', ' ', $shift->status->value)) }}</span>
                <x-delete-resource :action="route('admin.shifts.destroy', $shift)" label="shift" :name="$shift->title" message="The shift will disappear for both administrators and its worker. Pending analysis will be cancelled. Photos, results and decisions are retained." />
            </article>
        @empty
            <p class="rounded-xl border border-dashed border-slate-300 p-8 text-slate-500">No shifts assigned yet.</p>
        @endforelse
    </div>
    <div class="mt-6">{{ $shifts->links() }}</div>
</x-layout>
