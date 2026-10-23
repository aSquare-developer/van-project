<x-layout title="Shift checklist">
    <a href="{{ auth()->user()->can('create', App\Models\Shift::class) ? route('admin.shifts.index') : route('shifts.index') }}" class="inline-block py-2 text-sm underline">← Back to shifts</a>
    <div class="mb-6 mt-4 flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0"><h1 class="break-words text-3xl font-semibold tracking-tight">{{ $shift->title }}</h1><p class="mt-3 break-words text-sm text-slate-500">{{ $shift->shift_date->format('d M Y') }} · {{ $shift->van_name }}{{ $shift->van_registration ? ' — '.$shift->van_registration : '' }}</p></div>
        <x-status-badge :status="$shift->status" />
    </div>
    @can('create', App\Models\Shift::class)
        <p class="mb-6 rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900">Reviewing the checklist assigned to {{ $shift->worker->name }}.</p>
    @endcan
    <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-label="Checklist progress">
        <h2 class="mb-4 font-semibold">Required checks</h2>
        <x-checklist-progress :passed="$passedCount" :total="$requiredCount" />
        <p class="mt-4 text-sm text-slate-500">Optional items are listed below and do not count towards required progress.</p>
    </section>
    <h2 class="mb-4 text-xl font-semibold">Your checklist</h2>
    <ol class="space-y-4">
        @forelse($shift->checks as $check)
            <li class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h3 class="min-w-0 break-words font-semibold"><a class="underline decoration-slate-300 underline-offset-4" href="{{ route('shifts.checks.show', [$shift, $check]) }}">{{ $check->position }}. {{ $check->title }}</a></h3>
                    <x-status-badge :status="$check->status" />
                </div>
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $check->is_required ? 'Required' : 'Optional' }}</p>
                <p class="mt-3 text-sm text-slate-600">{{ $check->status->description() }}</p>
                <a href="{{ route('shifts.checks.show', [$shift, $check]) }}" aria-label="Open item {{ $check->position }}: {{ $check->title }}" class="mt-5 inline-block rounded-lg border border-slate-300 px-4 py-3 text-sm font-medium">View instructions →</a>
            </li>
        @empty
            <li class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">No checklist items are available. Contact an administrator.</li>
        @endforelse
    </ol>
    @can('submit', $shift)
        <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6" aria-label="Finish inspection">
            <h2 class="text-xl font-semibold">Finish inspection</h2>
            @if($assessment['can_complete'])
                <p class="mt-3 text-sm text-slate-600">All required items have confirmed passing results. Completing locks this checklist.</p>
                <form class="mt-5" method="POST" action="{{ route('shifts.complete', $shift) }}">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="revision" value="{{ $shift->revision }}">
                    <button class="rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white">Complete inspection</button>
                </form>
            @elseif($assessment['can_request_review'])
                <p class="mt-3 text-sm text-slate-600">Some required items were not confirmed. Explain the problem and ask an administrator to review your saved photos. This does not mark those items as passed.</p>
                <form class="mt-5 space-y-4" method="POST" action="{{ route('shifts.review-request', $shift) }}">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ Illuminate\Support\Str::uuid() }}">
                    <input type="hidden" name="revision" value="{{ $shift->revision }}">
                    <label class="block text-sm font-medium">Explanation<textarea name="comment" required maxlength="2000" rows="4" class="mt-2 block w-full rounded-lg border border-slate-300 p-3">{{ old('comment') }}</textarea></label>
                    <button class="rounded-lg bg-slate-900 px-5 py-3 text-sm font-medium text-white">Send for administrator review</button>
                </form>
            @elseif($shift->status === App\Enums\ShiftStatus::AwaitingReview)
                <p class="mt-3 text-sm text-slate-600">Waiting for an administrator. Photo uploads are locked until a decision is recorded.</p>
            @elseif(in_array($shift->status, [App\Enums\ShiftStatus::Completed, App\Enums\ShiftStatus::CompletedWithRemarks], true))
                <p class="mt-3 text-sm text-slate-600">This inspection is closed. Photos and results cannot be changed.</p>
            @else
                <p class="mt-3 text-sm text-slate-600">{{ $assessment['blocking_reason'] }}</p>
            @endif
        </section>
    @endcan
    <x-shift-workflow-history :shift="$shift" :transitions="$transitions" />
</x-layout>
