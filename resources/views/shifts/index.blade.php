<x-layout title="My shifts">
    <h1 class="text-3xl font-semibold tracking-tight">My shifts</h1>
    <p class="mb-8 mt-2 text-slate-500">Open a shift to see your assigned checks and instructions.</p>
    <div class="space-y-4">
        @forelse($shifts as $shift)
            <article class="rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 class="min-w-0 break-words text-lg font-semibold"><a class="underline decoration-slate-300 underline-offset-4" href="{{ route('shifts.show', $shift) }}">{{ $shift->title }}</a></h2>
                    <x-status-badge :status="$shift->status" />
                </div>
                <p class="mt-3 break-words text-sm text-slate-500"><time datetime="{{ $shift->shift_date->format('Y-m-d') }}">{{ $shift->shift_date->format('d M Y') }}</time> · {{ $shift->van_name }}{{ $shift->van_registration ? ' — '.$shift->van_registration : '' }}</p>
                <x-checklist-progress :passed="$shift->passed_required_checks_count" :total="$shift->required_checks_count" class="mt-5" />
                <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                    <span class="text-sm text-slate-500">{{ $shift->checks_count }} checklist items</span>
                    <a href="{{ route('shifts.show', $shift) }}" aria-label="Open checklist for {{ $shift->title }}" class="rounded-lg bg-slate-900 px-4 py-3 text-sm font-medium text-white">Open checklist →</a>
                </div>
            </article>
        @empty
            <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-8">
                <h2 class="font-semibold">No shifts assigned</h2>
                <p class="mt-2 text-sm text-slate-500">Your assigned shifts will appear here. Contact an administrator if you expected a shift.</p>
            </section>
        @endforelse
    </div>
    <div class="mt-6">{{ $shifts->links() }}</div>
</x-layout>
