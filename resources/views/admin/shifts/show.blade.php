<x-layout title="Shift details">
    <a href="{{ route('admin.shifts.index') }}" class="text-sm underline">← All shifts</a>
    <h1 class="mb-2 mt-5 break-words text-3xl font-semibold">{{ $shift->title }}</h1>
    <div class="mb-5"><x-delete-resource :action="route('admin.shifts.destroy', $shift)" label="shift" :name="$shift->title" message="The shift will disappear for its worker as well. Pending analysis will be cancelled; saved history is retained." /></div>
    <p class="mb-8 text-slate-500">{{ ucfirst(str_replace('_', ' ', $shift->status->value)) }} · {{ $shift->shift_date->format('d M Y') }}</p>
    <dl class="mb-8 grid gap-5 rounded-xl border border-slate-200 bg-white p-6 sm:grid-cols-2">
        <div><dt class="text-sm text-slate-500">Worker</dt><dd class="mt-1 break-words font-medium">{{ $shift->worker->name }}</dd></div>
        <div><dt class="text-sm text-slate-500">Van at assignment</dt><dd class="mt-1 break-words font-medium">{{ $shift->van_name }}{{ $shift->van_registration ? ' — '.$shift->van_registration : '' }}</dd></div>
        <div><dt class="text-sm text-slate-500">Template at assignment</dt><dd class="mt-1 break-words font-medium">{{ $shift->template_name }}</dd></div>
        <div><dt class="text-sm text-slate-500">Assigned by</dt><dd class="mt-1 break-words font-medium">{{ $shift->assignedBy->name }}</dd></div>
    </dl>
    <a href="{{ route('shifts.show', $shift) }}" class="mb-6 inline-block rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white">Open worker checklist →</a>
    <h2 class="mb-4 text-xl font-semibold">Assigned checklist</h2>
    <ol class="space-y-4">
        @foreach($shift->checks as $check)
            <li class="rounded-xl border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3"><h3 class="break-words font-semibold"><a class="underline" href="{{ route('shifts.checks.show', [$shift, $check]) }}">{{ $check->position }}. {{ $check->title }}</a></h3><x-status-badge :status="$check->status" /></div>
                <p class="mt-3 whitespace-pre-line break-words text-sm text-slate-600">{{ $check->instructions }}</p>
                <p class="mt-3 text-xs font-medium text-slate-500">{{ $check->is_required ? 'Required' : 'Optional' }}</p>
            </li>
        @endforeach
    </ol>
    @if($shift->status === App\Enums\ShiftStatus::AwaitingReview)
        @can('review', $shift)
            <section class="mt-8 rounded-2xl border border-amber-200 bg-white p-5 sm:p-6" aria-label="Administrator decision">
                <h2 class="text-xl font-semibold">Administrator decision</h2>
                <p class="mt-3 text-sm text-slate-600">Review the worker's explanation and photo attempts below. Approval closes the inspection with remarks; recognition results remain unchanged.</p>
                @if($assessment['blocking_reason'])<p class="mt-3 text-sm text-red-700">{{ $assessment['blocking_reason'] }} Return the inspection for another check; it cannot be approved without valid evidence.</p>@endif
                <form class="mt-5 space-y-4" method="POST" action="{{ route('admin.shifts.review', $shift) }}">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="revision" value="{{ $shift->revision }}">
                    <label class="block text-sm font-medium">Decision<select name="decision" required class="mt-2 block w-full rounded-lg border border-slate-300 p-3"><option value="">Choose a decision</option><option value="return_for_check" @selected(old('decision') === 'return_for_check')>Return for another check</option><option value="approve_with_remarks" @disabled($assessment['blocking_reason']) @selected(old('decision') === 'approve_with_remarks')>Approve with remarks and close</option></select></label>
                    <label class="block text-sm font-medium">Reason / instructions<textarea name="comment" required maxlength="2000" rows="4" class="mt-2 block w-full rounded-lg border border-slate-300 p-3">{{ old('comment') }}</textarea></label>
                    <button class="rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white">Record decision</button>
                </form>
            </section>
        @endcan
    @endif
    <x-shift-workflow-history :shift="$shift" :transitions="$transitions" />
</x-layout>
