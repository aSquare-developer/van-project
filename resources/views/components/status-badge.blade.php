@props(['status'])
@php
    $colors = match ($status->value) {
        'passed', 'completed', 'completed_with_remarks' => 'bg-teal-50 text-teal-800',
        'failed' => 'bg-red-50 text-red-800',
        'inconclusive', 'analysis_error' => 'bg-amber-50 text-amber-900',
        'awaiting_review', 'returned_for_check' => 'bg-indigo-50 text-indigo-800',
        default => 'bg-slate-100 text-slate-700',
    };
@endphp
<span {{ $attributes->class(['inline-block rounded-full px-3 py-1 text-xs font-semibold', $colors]) }}>{{ $status->label() }}</span>
